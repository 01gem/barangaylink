<?php
/**
 * BarangayLink - Complaint types tests (complaint revision Phases 1 to 6).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Two modes, because staff can now add, rename, reorder and deactivate types
 * on the real database:
 *
 *   LIVE (default)  php project-support/tests/complaint-types/run.php
 *     Against the real database (or a copy with --db=<name>). Only rules that
 *     must hold whatever staff have done to the types. Read-only: SELECT only.
 *
 *   SEED            php project-support/tests/complaint-types/run.php --seed --db=<scratch database>
 *     Against a freshly seeded scratch database only (never the real one).
 *     Adds the checks that the seed equals the old hardcoded schema, and the
 *     retired-option / deactivated-type checks, which change rows inside a
 *     transaction and roll back. project-support/tests/review-2026-10-24/run.php
 *     runs this mode on its scratch database.
 *
 * Checks marked [seed] or [live] in their name run in that mode only.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__, 3);
require_once $root . '/db.php';
require_once $root . '/api/complaints/_community.php';
require_once __DIR__ . '/legacy_schema.php';
require_once $root . '/api/complaint_types/_admin.php';
require_once $root . '/api/complaints/_parties.php';

// Phase 0 baseline (project-support/docs/archive/complaint-revision-phase0.md).
const PHASE0_CATEGORY_COUNTS = [
    'Flooding / Drainage' => 1,
    'Noise / Public Disturbance' => 4,
    'Other' => 1,
    'Road / Infrastructure' => 1,
];
const PHASE0_TOTAL = 7;
// Highest complaints.id that existed at Phase 0 (the Phase 1 backfill table lists ids 1-7).
const PHASE0_MAX_COMPLAINT_ID = 7;

// Seed rows the migration must have written, by type_name.
const EXPECTED_TYPES = [
    'Road / Infrastructure'      => ['code' => 'road_infrastructure', 'mode' => 'none',     'sort' => 10],
    'Noise / Public Disturbance' => ['code' => 'noise_disturbance',   'mode' => 'optional', 'sort' => 20],
    'Flooding / Drainage'        => ['code' => 'flooding_drainage',   'mode' => 'none',     'sort' => 30],
    'Public Safety'              => ['code' => 'public_safety',       'mode' => 'optional', 'sort' => 40],
    'Other'                      => ['code' => 'other',               'mode' => 'optional', 'sort' => 50],
];

$realDbName = $DB_NAME;
$dbName = $DB_NAME;
$seedMode = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--db=')) $dbName = substr($arg, 5);
    elseif ($arg === '--seed') $seedMode = true;
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) { fwrite(STDERR, "Invalid database name.\n"); exit(1); }
if ($seedMode && $dbName === $realDbName) {
    fwrite(STDERR, "Seed mode writes inside transactions and expects the untouched seed.\n"
        . "Run it against a scratch database: --seed --db=<scratch database>\n");
    exit(1);
}
// The functions under test connect through get_db(), which reads $DB_NAME.
$DB_NAME = $dbName;

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $dbName);
if ($db->connect_errno) { fwrite(STDERR, "Cannot connect to {$dbName}: {$db->connect_error}\n"); exit(1); }
$db->set_charset('utf8mb4');
echo "Database: {$dbName}\n";
echo 'Mode: ' . ($seedMode ? 'seed (scratch database, seed equality)' : 'live (rules that always hold, read-only)') . "\n";

$passed = 0; $failed = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n"; }
}
/** Rows of a SELECT, or [] when the query fails (for example, table missing). */
function rows(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
function scalar(mysqli $db, string $sql): ?string {
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) return null;
    $row = $result->fetch_row();
    return $row === null || $row[0] === null ? null : (string)$row[0];
}
function column_type(mysqli $db, string $table, string $column): ?string {
    return scalar($db, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'");
}
function foreign_key(mysqli $db, string $table, string $name): ?array {
    $found = rows($db, "SELECT k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_table,
            k.REFERENCED_COLUMN_NAME AS ref_col, r.DELETE_RULE AS on_delete
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = '{$table}' AND k.CONSTRAINT_NAME = '{$name}'");
    return $found[0] ?? null;
}
function has_index(mysqli $db, string $table, string $name): bool {
    return (int)scalar($db, "SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$name}'") > 0;
}

// a) Tables, column, indexes, foreign keys.
foreach (['complaint_types', 'complaint_type_fields'] as $table) {
    $exists = (int)scalar($db, "SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'") === 1;
    check("a) table {$table} exists", $exists);
}
check('a) complaints.complaint_type_id exists and is nullable',
    scalar($db, "SELECT IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'complaint_type_id'") === 'YES');
check('a) index idx_complaint_type exists', has_index($db, 'complaints', 'idx_complaint_type'));
check('a) index idx_type_active exists', has_index($db, 'complaint_types', 'idx_type_active'));
check('a) index idx_type_fields exists', has_index($db, 'complaint_type_fields', 'idx_type_fields'));
check('a) unique key uq_type_field exists', has_index($db, 'complaint_type_fields', 'uq_type_field'));
$expectedKeys = [
    ['complaints', 'fk_complaint_type', 'complaint_type_id', 'complaint_types', 'RESTRICT'],
    ['complaint_type_fields', 'fk_type_field_type', 'complaint_type_id', 'complaint_types', 'RESTRICT'],
    ['complaint_types', 'fk_complaint_types_created_by', 'created_by', 'barangay_officials', 'SET NULL'],
    ['complaint_types', 'fk_complaint_types_updated_by', 'updated_by', 'barangay_officials', 'SET NULL'],
];
foreach ($expectedKeys as [$table, $name, $column, $refTable, $onDelete]) {
    $fk = foreign_key($db, $table, $name);
    check("a) foreign key {$name} -> {$refTable}(id) ON DELETE {$onDelete}",
        $fk !== null && $fk['col'] === $column && $fk['ref_table'] === $refTable
            && $fk['ref_col'] === 'id' && $fk['on_delete'] === $onDelete,
        $fk === null ? 'missing' : json_encode($fk));
}

// Load types and fields once.
$types = [];
foreach (rows($db, 'SELECT id, type_code, type_name, respondent_mode, is_active, sort_order FROM complaint_types') as $row) {
    $types[$row['type_name']] = $row;
}
$fieldsByType = [];
foreach (rows($db, 'SELECT complaint_type_id, field_key, label, field_type, is_required, options_json, max_length, is_active, sort_order
    FROM complaint_type_fields ORDER BY complaint_type_id, sort_order, id') as $row) {
    $fieldsByType[(int)$row['complaint_type_id']][$row['field_key']] = $row;
}

// b) Seed equals the pre-Phase 2 hardcoded schema (kept as a test fixture;
// complaint_extra_fields_schema() itself now reads these same tables).
$schema = complaint_legacy_schema();
if ($seedMode) {
check('[seed] b) seeded type names equal the PHP schema keys',
    array_keys(EXPECTED_TYPES) === array_keys($schema), 'test constants are out of date with the PHP schema');
foreach ($schema as $category => $schemaFields) {
    $type = $types[$category] ?? null;
    check("[seed] b) type exists: {$category}", $type !== null);
    if ($type === null) continue;
    $expected = EXPECTED_TYPES[$category] ?? null;
    check("[seed] b) {$category}: type_code, respondent_mode, sort_order, active",
        $expected !== null && $type['type_code'] === $expected['code'] && $type['respondent_mode'] === $expected['mode']
            && (int)$type['sort_order'] === $expected['sort'] && (int)$type['is_active'] === 1,
        json_encode($type));

    $stored = $fieldsByType[(int)$type['id']] ?? [];
    $storedSchemaFields = $stored;
    if ($category === 'Other') unset($storedSchemaFields['specify']);
    check("[seed] b) {$category}: same field keys in the same order",
        array_keys($storedSchemaFields) === array_keys($schemaFields),
        'stored=' . json_encode(array_keys($storedSchemaFields)));

    foreach ($schemaFields as $key => $field) {
        $row = $stored[$key] ?? null;
        if ($row === null) { check("[seed] b) {$category}.{$key}: field exists", false); continue; }
        check("[seed] b) {$category}.{$key}: label, select, optional, active",
            $row['label'] === $field['label'] && $row['field_type'] === 'select'
                && (int)$row['is_required'] === 0 && (int)$row['is_active'] === 1,
            json_encode([$row['label'], $row['field_type'], $row['is_required'], $row['is_active']]));
        $options = json_decode((string)$row['options_json'], true);
        $pairs = [];
        $shapeOk = is_array($options) && array_is_list($options);
        foreach ($shapeOk ? $options : [] as $option) {
            if (!is_array($option) || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null)
                || ($option['is_active'] ?? null) !== 1) { $shapeOk = false; break; }
            $pairs[] = [$option['value'], $option['label']];
        }
        $expectedPairs = [];
        foreach ($field['options'] as $value => $label) $expectedPairs[] = [(string)$value, $label];
        check("[seed] b) {$category}.{$key}: option values and labels equal, same order",
            $shapeOk && $pairs === $expectedPairs, 'stored=' . json_encode($pairs));
    }
}
check('[seed] b) no types beyond the five seeded', count($types) === count($schema), 'types=' . count($types));
} // seed mode
$badKeys = [];
foreach ($fieldsByType as $fields) {
    foreach (array_keys($fields) as $key) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', (string)$key)) $badKeys[] = $key;
    }
}
check('b) every field_key matches ^[a-z][a-z0-9_]{0,49}$', $fieldsByType !== [] && $badKeys === [], json_encode($badKeys));

// c) The "Other" type (found by its code: the name may be changed) and its
// specify field. The API never lets either be switched off or made optional.
$otherRow = null;
foreach ($types as $type) if ($type['type_code'] === 'other') $otherRow = $type;
$otherId = $otherRow !== null ? (int)$otherRow['id'] : 0;
$otherFields = $fieldsByType[$otherId] ?? [];
$specify = $otherFields['specify'] ?? null;
check('c) the "other" type exists and is active', $otherRow !== null && (int)$otherRow['is_active'] === 1);
check('c) other.specify exists and is an active, required text field',
    $specify !== null && $specify['field_type'] === 'text' && (int)$specify['is_required'] === 1
        && (int)$specify['is_active'] === 1 && $specify['options_json'] === null
        && (int)$specify['max_length'] >= 1 && (int)$specify['max_length'] <= COMPLAINT_TYPE_MAX_TEXT_LENGTH,
    json_encode($specify));
check('c) at least one type is active; every type code matches its pattern',
    count(array_filter($types, static fn(array $type): bool => (int)$type['is_active'] === 1)) >= 1
        && array_filter($types, static fn(array $type): bool => preg_match(COMPLAINT_TYPE_CODE_PATTERN, (string)$type['type_code']) !== 1) === []);
if ($seedMode) {
    $extraKeys = array_values(array_diff(array_keys($otherFields), array_keys($schema['Other'])));
    check('[seed] c) Other has exactly one extra field: specify', $extraKeys === ['specify'], json_encode($extraKeys));
    check('[seed] c) specify is max 150, label "Please specify", sort 10; the type is named "Other"',
        $specify !== null && (int)$specify['max_length'] === 150 && $specify['label'] === 'Please specify'
            && (int)$specify['sort_order'] === 10 && ($otherRow['type_name'] ?? '') === 'Other',
        json_encode($specify));
}

// d) Every complaint is linked to a type. (Which type cannot be checked by
// name: a type may be renamed, and complaints keep the category text they
// were filed with.)
check('d) no complaint has complaint_type_id NULL',
    scalar($db, 'SELECT COUNT(*) FROM complaints WHERE complaint_type_id IS NULL') === '0');

// e) complaints.category untouched: same per-category counts as Phase 0.
// Only complaints that existed at Phase 0 are counted (id <= PHASE0_MAX_COMPLAINT_ID),
// so complaints filed later do not break this check. A scratch database has
// none of those complaints, so this runs in live mode only.
if (!$seedMode) {
$counts = [];
foreach (rows($db, 'SELECT category, COUNT(*) AS n FROM complaints WHERE id <= ' . PHASE0_MAX_COMPLAINT_ID
    . ' GROUP BY category ORDER BY category') as $row) {
    $counts[(string)$row['category']] = (int)$row['n'];
}
check('[live] e) per-category complaint counts equal Phase 0', $counts === PHASE0_CATEGORY_COUNTS, json_encode($counts));
check('[live] e) total complaints equal Phase 0 (' . PHASE0_TOTAL . ')',
    scalar($db, 'SELECT COUNT(*) FROM complaints WHERE id <= ' . PHASE0_MAX_COMPLAINT_ID) === (string)PHASE0_TOTAL);
} // live mode

// f) Id column types line up for the foreign keys.
$complaintsId = column_type($db, 'complaints', 'id');
check('f) complaint_types.id column_type equals complaints.id',
    $complaintsId !== null && column_type($db, 'complaint_types', 'id') === $complaintsId,
    json_encode([column_type($db, 'complaint_types', 'id'), $complaintsId]));
check('f) complaints.complaint_type_id and complaint_type_fields.complaint_type_id use the same column_type',
    $complaintsId !== null && column_type($db, 'complaints', 'complaint_type_id') === $complaintsId
        && column_type($db, 'complaint_type_fields', 'complaint_type_id') === $complaintsId);

// ===========================================================================
// Phase 2: the database-driven schema functions in api/complaints/_extra_fields.php.
// Sections d and e change rows inside a transaction and always roll back.
// ===========================================================================
echo "-- Phase 2\n";
$app = get_db();
check('P2 functions use the database under test',
    scalar($app, 'SELECT DATABASE()') === $dbName);

function throws(callable $fn, ?string $message = null): bool {
    try { $fn(); } catch (InvalidArgumentException $error) {
        return $message === null || $error->getMessage() === $message;
    }
    return false;
}

$legacy = complaint_legacy_schema();
if (!$seedMode) {
    // a) [live] The complaints that existed at Phase 0 still read back the same
    // stored values. Field keys never change and options are never removed, so
    // this holds whatever staff do. (Labels may be edited, so the display text
    // is compared in seed mode only.)
    $different = [];
    $complaintRows = rows($app, 'SELECT id, category, complaint_type_id, extra_fields_json FROM complaints
        WHERE id <= ' . PHASE0_MAX_COMPLAINT_ID . ' ORDER BY id');
    foreach ($complaintRows as $row) {
        $category = (string)$row['category'];
        $type = $row['complaint_type_id'] !== null ? complaint_type_get((int)$row['complaint_type_id'], true) : null;
        $newRead = array_intersect_key(complaint_extra_fields_read($type, $row['extra_fields_json']), $legacy[$category] ?? []);
        if (complaint_legacy_read($category, $row['extra_fields_json']) !== $newRead) $different[] = (int)$row['id'];
    }
    check('[live] P2 a) the ' . count($complaintRows) . ' Phase 0 complaints read back the same stored values',
        count($complaintRows) === PHASE0_TOTAL && $different === [], 'complaint ids=' . json_encode($different));
}
if ($seedMode) {
// a) [seed] Old read and display equal the new ones, for every option of every
// seeded field, for a full payload per type, and for junk input.
$samples = 0; $sampleDiffs = [];
foreach ($legacy as $category => $legacyFields) {
    $type = complaint_type_by_name($category, true);
    $payloads = [null, '', '[]', '"text"', '{"unknown_key":"x"}'];
    $full = [];
    foreach ($legacyFields as $key => $legacyField) {
        foreach (array_keys($legacyField['options']) as $value) $payloads[] = json_encode([$key => (string)$value]);
        $payloads[] = json_encode([$key => 'not_an_option']);
        $payloads[] = json_encode([$key => 5]);
        $payloads[] = json_encode([$key => ' ' . array_key_first($legacyField['options']) . ' ']);
        $full[$key] = (string)array_key_last($legacyField['options']);
    }
    if ($full !== []) $payloads[] = json_encode($full + ['unknown_key' => 'x']);
    foreach ($payloads as $payload) {
        $samples++;
        $oldRead = complaint_legacy_read($category, $payload);
        $newRead = complaint_extra_fields_read($type, $payload);
        if ($oldRead !== $newRead || complaint_legacy_display($category, $oldRead) !== complaint_extra_fields_display($type, $newRead)) {
            $sampleDiffs[] = $category . ' ' . $payload;
        }
    }
}
check("[seed] P2 a) old read and display equal new for {$samples} stored-value samples across the five seeded types",
    $samples > 50 && $sampleDiffs === [], json_encode(array_slice($sampleDiffs, 0, 3)));

// b) Active schema equals the legacy schema, except Other gains specify.
$activeSchema = complaint_extra_fields_schema();
$projected = [];
foreach ($activeSchema as $typeName => $fields) {
    $projected[$typeName] = [];
    foreach ($fields as $key => $field) $projected[$typeName][$key] = ['label' => $field['label'], 'options' => $field['options']];
}
$expectedSchema = $legacy;
$expectedSchema['Other']['specify'] = ['label' => 'Please specify', 'options' => []];
check('[seed] P2 b) active schema equals legacy schema plus Other.specify (names, keys, labels, options, order)',
    $projected === $expectedSchema);
$specifyField = $activeSchema['Other']['specify'] ?? [];
check('[seed] P2 b) schema fields carry type, required, max_length, min, max',
    ($specifyField['type'] ?? null) === 'text' && ($specifyField['required'] ?? null) === true
        && ($specifyField['max_length'] ?? null) === 150
        && array_key_exists('min', $specifyField) && array_key_exists('max', $specifyField)
        && ($activeSchema['Road / Infrastructure']['road_issue']['type'] ?? null) === 'select'
        && ($activeSchema['Road / Infrastructure']['road_issue']['required'] ?? null) === false);
check('[seed] P2 b) complaint_types_all(false) lists five types in sort order',
    array_column(complaint_types_all(false), 'type_code')
        === ['road_infrastructure', 'noise_disturbance', 'flooding_drainage', 'public_safety', 'other']);
} // seed mode

// b) Lookups, on whatever types exist.
$anyType = complaint_types_all(false)[0] ?? null;
$anyName = (string)($anyType['type_name'] ?? '');
$otherCase = $anyName === strtoupper($anyName) ? strtolower($anyName) : strtoupper($anyName);
check('P2 b) complaint_type_by_name is exact and case-sensitive; complaint_type_get finds by id',
    $anyType !== null && complaint_type_by_name($anyName) === $anyType
        && ($otherCase === $anyName || complaint_type_by_name($otherCase) === null)
        && complaint_type_by_name($anyName . ' ') === null
        && complaint_type_get((int)$anyType['id']) === $anyType && complaint_type_get(0) === null);
$allTypes = complaint_types_all(true);
$sortKeys = [];
foreach (rows($db, 'SELECT type_code FROM complaint_types ORDER BY sort_order, id') as $row) $sortKeys[] = $row['type_code'];
check('P2 b) complaint_types_all(true) lists every type in sort order; the active list is the active subset in the same order',
    array_column($allTypes, 'type_code') === $sortKeys
        && array_column(complaint_types_all(false), 'type_code')
            === array_column(array_values(array_filter($allTypes, static fn(array $type): bool => $type['is_active'])), 'type_code'));
check('P2 b) schema keys are the type names; the active schema is a subset of the full one',
    array_keys(complaint_extra_fields_schema(true)) === array_column($allTypes, 'type_name')
        && array_diff_key(complaint_extra_fields_schema(), complaint_extra_fields_schema(true)) === []);

// c) Strict validation. A synthetic type covers field kinds the seed does not have.
$field = static fn(string $key, string $type, array $extra = []): array => array_merge([
    'key' => $key, 'label' => ucfirst($key), 'type' => $type, 'required' => false,
    'max_length' => null, 'min' => null, 'max' => null, 'is_active' => true, 'options' => [],
], $extra);
$synthetic = ['id' => 0, 'type_code' => 'synthetic', 'type_name' => 'Synthetic', 'description' => null,
    'respondent_mode' => 'none', 'is_active' => true, 'fields' => [
        $field('choice', 'select', ['options' => [
            ['value' => 'a', 'label' => 'A', 'is_active' => true],
            ['value' => 'b', 'label' => 'B', 'is_active' => false],
        ]]),
        $field('note', 'text', ['max_length' => 5]),
        $field('amount', 'number', ['min' => 1.0, 'max' => 10.0]),
        $field('day', 'date'),
        $field('old', 'text', ['is_active' => false]),
        $field('must', 'text', ['required' => true, 'max_length' => 150]),
    ]];
$ok = ['must' => 'present'];
$validate = static fn(array $payload): callable
    => static fn() => complaint_extra_fields_validate($synthetic, (object)array_merge($ok, $payload));
check('P2 c) unknown key throws', throws($validate(['nope' => 'x']), 'An additional field is not valid for this category.'));
check('P2 c) inactive field throws', throws($validate(['old' => 'x']), 'An additional field is not valid for this category.'));
check('P2 c) retired option throws', throws($validate(['choice' => 'b']), 'An additional detail option is not valid.'));
check('P2 c) option that never existed throws', throws($validate(['choice' => 'zzz'])));
check('P2 c) non-string select value throws', throws($validate(['choice' => 5])));
check('P2 c) impossible date 2026-02-30 throws', throws($validate(['day' => '2026-02-30']), 'Day must be a valid date.'));
check('P2 c) wrong date format throws', throws($validate(['day' => '02/03/2026'])));
check('P2 c) number above max throws', throws($validate(['amount' => 11]), 'Amount must be at most 10.'));
check('P2 c) number below min throws', throws($validate(['amount' => '0.5'])));
check('P2 c) non-numeric number throws', throws($validate(['amount' => '1e3'])) && throws($validate(['amount' => true])));
check('P2 c) text over max_length throws', throws($validate(['note' => 'sixsix']), 'Note must be 5 characters or fewer.'));
check('P2 c) text containing NUL throws', throws($validate(['note' => "a\0b"])));
check('P2 c) missing required field throws',
    throws(static fn() => complaint_extra_fields_validate($synthetic, new stdClass()), 'Must is required.')
        && throws(static fn() => complaint_extra_fields_validate($synthetic, null), 'Must is required.')
        && throws(static fn() => complaint_extra_fields_validate($synthetic, (object)['must' => '   ']), 'Must is required.'));
check('P2 c) a JSON array instead of an object throws',
    throws(static fn() => complaint_extra_fields_validate($synthetic, ['must' => 'x']), 'Additional details must be a JSON object.'));
$cleaned = null;
try {
    $cleaned = complaint_extra_fields_validate($synthetic, (object)[
        'must' => "  kept\ttext ", 'day' => '2026-02-28', 'amount' => 7, 'note' => 'five5', 'choice' => 'a',
    ]);
} catch (InvalidArgumentException $error) { $cleaned = $error->getMessage(); }
check('P2 c) valid synthetic payload passes: strings, field order, trimmed, control characters stripped',
    $cleaned === ['choice' => 'a', 'note' => 'five5', 'amount' => '7', 'day' => '2026-02-28', 'must' => 'kepttext'],
    json_encode($cleaned));
check('P2 c) empty optional values are left out',
    complaint_extra_fields_validate($synthetic, (object)['must' => 'x', 'choice' => '', 'note' => ' ', 'amount' => '', 'day' => ''])
        === ['must' => 'x']);
// The "Other" type is found by its code; its name and the label of specify may be edited.
$otherType = null;
foreach (complaint_types_all(false) as $type) if ($type['type_code'] === 'other') $otherType = $type;
check('P2 c) Other without specify throws',
    $otherType !== null && throws(static fn() => complaint_extra_fields_validate($otherType, null))
        && throws(static fn() => complaint_extra_fields_validate($otherType, (object)['specify' => ''])));
if ($seedMode) {
    check('[seed] P2 c) Other without specify says "Please specify is required."; over 150 characters throws',
        $otherType !== null && throws(static fn() => complaint_extra_fields_validate($otherType, null), 'Please specify is required.')
            && throws(static fn() => complaint_extra_fields_validate($otherType, (object)['specify' => str_repeat('x', 151)])));
}
// A valid value for any field, whatever staff have configured.
$sampleValue = static function (array $typeField): string {
    if ($typeField['type'] === 'select') return (string)($typeField['options'][0]['value'] ?? '');
    if ($typeField['type'] === 'date') return '2026-01-15';
    if ($typeField['type'] === 'number') {
        $number = $typeField['min'] ?? $typeField['max'] ?? 1.0;
        return rtrim(rtrim(number_format((float)$number, 2, '.', ''), '0'), '.');
    }
    return mb_substr('Sample text', 0, $typeField['max_length'] ?? 255);
};
$payloadFailures = [];
$activeTypes = complaint_types_all(false);
foreach ($activeTypes as $type) {
    $payload = [];
    foreach ($type['fields'] as $typeField) $payload[$typeField['key']] = $sampleValue($typeField);
    $result = null;
    try { $result = complaint_extra_fields_validate($type, (object)$payload); }
    catch (InvalidArgumentException $error) { $result = $error->getMessage(); }
    if ($result !== $payload) $payloadFailures[] = $type['type_code'] . ': ' . json_encode($result);
}
check('P2 c) a valid payload passes for each of the ' . count($activeTypes) . ' active types',
    $activeTypes !== [] && $payloadFailures === [], implode('; ', $payloadFailures));
$script = '<b>x</b><script>alert(1)</script>';
check('P2 c) markup in free text is stored and displayed as plain text, unchanged',
    complaint_extra_fields_validate($synthetic, (object)['must' => $script]) === ['must' => $script]
        && complaint_extra_fields_display($synthetic, ['must' => $script]) === [['label' => 'Must', 'value' => $script]]);
check('P2 c) read with no type returns nothing',
    complaint_extra_fields_read(null, '{"road_issue":"pothole"}') === [] && complaint_extra_fields_display(null, ['a' => 'b']) === []);

// d) and e) change rows inside a transaction and roll back. Scratch database only.
if ($seedMode) {
// d) Retired option: history stays readable, new writes are refused.
$roadType = complaint_type_by_name('Road / Infrastructure');
$app->begin_transaction();
try {
    $roadId = (int)$roadType['id'];
    $stored = json_decode((string)scalar($app, "SELECT options_json FROM complaint_type_fields
        WHERE complaint_type_id = {$roadId} AND field_key = 'road_issue'"), true);
    foreach ($stored as &$option) if ($option['value'] === 'pothole') $option['is_active'] = 0;
    unset($option);
    $update = $app->prepare("UPDATE complaint_type_fields SET options_json = ? WHERE complaint_type_id = ? AND field_key = 'road_issue'");
    $json = json_encode($stored);
    $update->bind_param('si', $json, $roadId);
    $update->execute();
    $update->close();
    complaint_types_cache_reset();
    $history = complaint_type_get($roadId, true);
    $current = complaint_type_get($roadId, false);
    $read = complaint_extra_fields_read($history, '{"road_issue":"pothole","access_impact":"blocked"}');
    check('[seed] P2 d) retired option: read keeps the stored value',
        $read === ['road_issue' => 'pothole', 'access_impact' => 'blocked'], json_encode($read));
    check('[seed] P2 d) retired option: display adds "(retired)"',
        complaint_extra_fields_display($history, $read) === [
            ['label' => 'Infrastructure issue', 'value' => 'Pothole (retired)'],
            ['label' => 'Access impact', 'value' => 'Blocked'],
        ]);
    check('[seed] P2 d) retired option: validate rejects it, other options still pass',
        throws(static fn() => complaint_extra_fields_validate($current, (object)['road_issue' => 'pothole']))
            && throws(static fn() => complaint_extra_fields_validate($history, (object)['road_issue' => 'pothole']))
            && complaint_extra_fields_validate($current, (object)['road_issue' => 'sidewalk']) === ['road_issue' => 'sidewalk']);
    check('[seed] P2 d) retired option: gone from the active schema, present in the full one',
        !array_key_exists('pothole', complaint_extra_fields_schema()['Road / Infrastructure']['road_issue']['options'])
            && array_key_exists('pothole', complaint_extra_fields_schema(true)['Road / Infrastructure']['road_issue']['options']));
} catch (Throwable $error) {
    check('[seed] P2 d) retired option section ran', false, $error->getMessage());
} finally {
    $app->rollback();
    complaint_types_cache_reset();
}

// e) Deactivated type: hidden from new filings, name kept for history.
$app->begin_transaction();
try {
    $app->query("UPDATE complaint_types SET is_active = 0 WHERE type_code = 'public_safety'");
    complaint_types_cache_reset();
    check('[seed] P2 e) deactivated type: excluded from complaint_types_all(false)',
        !in_array('public_safety', array_column(complaint_types_all(false), 'type_code'), true)
            && complaint_type_by_name('Public Safety') === null);
    check('[seed] P2 e) deactivated type: included in complaint_types_all(true)',
        in_array('public_safety', array_column(complaint_types_all(true), 'type_code'), true)
            && complaint_type_by_name('Public Safety', true) !== null);
    // Phase 4: the projection resolves the name by type id, not by the stored category text.
    $safetyId = (int)scalar($app, "SELECT id FROM complaint_types WHERE type_code = 'public_safety'");
    $card = complaint_community_projection(['id' => 1, 'type_id' => $safetyId, 'category' => 'Old text', 'severity' => 'low',
        'status' => 'open', 'date_filed' => 'Oct 24, 2026', 'published_report_count' => 1]);
    $unknownCard = complaint_community_projection(['id' => 2, 'type_id' => 999999, 'severity' => 'low',
        'status' => 'open', 'date_filed' => 'Oct 24, 2026', 'published_report_count' => 1]);
    $noTypeCard = complaint_community_projection(['id' => 3, 'type_id' => null, 'severity' => 'low',
        'status' => 'open', 'date_filed' => 'Oct 24, 2026', 'published_report_count' => 1]);
    check('[seed] P4 e) deactivated type: community projection shows its current name by id; unknown or missing id shows Other',
        $card['type_id'] === $safetyId && $card['type_name'] === 'Public Safety' && !array_key_exists('category', $card)
            && $unknownCard['type_id'] === null && $unknownCard['type_name'] === 'Other'
            && $noTypeCard['type_name'] === 'Other', json_encode([$card, $unknownCard, $noTypeCard]));
} catch (Throwable $error) {
    check('[seed] P2 e) deactivated type section ran', false, $error->getMessage());
} finally {
    $app->rollback();
    complaint_types_cache_reset();
}
check('[seed] P2 d/e) rollback restored the seed: five active types, pothole active',
    count(complaint_types_all(false)) === 5
        && array_key_exists('pothole', complaint_extra_fields_schema()['Road / Infrastructure']['road_issue']['options'])
        && scalar($db, "SELECT COUNT(*) FROM complaint_types WHERE is_active = 1") === '5');
} // seed mode

// ===========================================================================
// Phase 3: rules in api/complaint_types/_admin.php that the HTTP tests do not
// reach. Pure functions and read-only usage counts: nothing is written.
// ===========================================================================
echo "-- Phase 3\n";
$p3Snapshot = static fn(): string => (string)scalar($db, "SELECT CONCAT(
    (SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(version), 0), ':', COALESCE(MAX(updated_at), '')) FROM complaint_types), '|',
    (SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(version), 0), ':', COALESCE(MAX(updated_at), '')) FROM complaint_type_fields), '|',
    (SELECT COUNT(*) FROM audit_log))");
$p3Before = $p3Snapshot();
$problem = static function (callable $fn, int $status = 422, ?string $message = null): bool {
    try { $fn(); } catch (ComplaintTypeProblem $error) {
        return $error->status === $status && ($message === null || $error->getMessage() === $message);
    }
    return false;
};

check('P3 code: derived from the name (lowercase, symbols to one underscore, trimmed)',
    complaint_type_derive_code('Stray Animals') === 'stray_animals'
        && complaint_type_derive_code('  Road / Infrastructure!! ') === 'road_infrastructure'
        && complaint_type_derive_code('Niño--Señal') === 'nino_senal');
check('P3 code: a name that starts with a digit gets a letter prefix',
    complaint_type_derive_code('24-hour noise') === 'type_24_hour_noise');
check('P3 code: nothing usable gives an empty code',
    complaint_type_derive_code('!!!') === '' && complaint_type_derive_code('x') === '' && complaint_type_derive_code('') === '');
$longCode = complaint_type_derive_code(str_repeat('abcde ', 20));
check('P3 code: capped at 50 characters with no trailing underscore',
    strlen($longCode) <= 50 && !str_ends_with($longCode, '_') && preg_match(COMPLAINT_TYPE_CODE_PATTERN, $longCode) === 1, $longCode);
check('P3 code: clash adds _2, then _3',
    complaint_type_unique_code('noise', []) === 'noise'
        && complaint_type_unique_code('noise', ['noise']) === 'noise_2'
        && complaint_type_unique_code('noise', ['noise', 'noise_2']) === 'noise_3');
$fifty = str_repeat('a', 50);
$suffixed = complaint_type_unique_code($fifty, [$fifty]);
check('P3 code: a clash suffix still fits in 50 characters',
    strlen($suffixed) === 50 && str_ends_with($suffixed, '_2') && preg_match(COMPLAINT_TYPE_CODE_PATTERN, $suffixed) === 1, $suffixed);

check('P3 JSON path: a valid key is quoted', complaint_field_json_path('road_issue') === '$."road_issue"');
$badKeys = ['', 'Road', '1abc', 'a-b', 'a b', 'a.b', 'a"b', "a'b", '$', 'a]', str_repeat('a', 51), "a\n"];
$allRefused = true;
foreach ($badKeys as $badKey) {
    if (!$problem(static fn() => complaint_field_json_path($badKey))) $allRefused = false;
}
check('P3 JSON path: ' . count($badKeys) . ' unsafe keys are all refused before reaching SQL', $allRefused);
check('P3 usage: unsafe key is refused by the usage helpers too',
    $problem(static fn() => complaint_field_usage($app, 1, 'a"b')) && $problem(static fn() => complaint_option_usage($app, 1, 'a.b')));

// Usage counts against stored complaints (read-only), checked against PHP-side counting.
$expectedTypeUsage = []; $expectedFieldUsage = []; $expectedOptionUsage = [];
foreach (rows($app, 'SELECT complaint_type_id, extra_fields_json FROM complaints') as $row) {
    $tid = (int)$row['complaint_type_id'];
    $expectedTypeUsage[$tid] = ($expectedTypeUsage[$tid] ?? 0) + 1;
    $stored = $row['extra_fields_json'] === null ? null : json_decode((string)$row['extra_fields_json'], true);
    foreach (is_array($stored) ? $stored : [] as $key => $value) {
        $expectedFieldUsage[$tid][$key] = ($expectedFieldUsage[$tid][$key] ?? 0) + 1;
        if (is_string($value)) $expectedOptionUsage[$tid][$key][$value] = ($expectedOptionUsage[$tid][$key][$value] ?? 0) + 1;
    }
}
$usageOk = true; $usageDetail = '';
$adminTypes = complaint_types_admin_list($app);
foreach ($adminTypes as $adminType) {
    if ($adminType['usage'] !== ($expectedTypeUsage[$adminType['id']] ?? 0)) { $usageOk = false; $usageDetail = 'type ' . $adminType['type_code']; }
    foreach ($adminType['fields'] as $adminField) {
        if ($adminField['usage'] !== ($expectedFieldUsage[$adminType['id']][$adminField['field_key']] ?? 0)) {
            $usageOk = false; $usageDetail = 'field ' . $adminField['field_key'];
        }
        foreach ($adminField['options'] as $adminOption) {
            if (($adminOption['usage'] ?? null) !== ($expectedOptionUsage[$adminType['id']][$adminField['field_key']][$adminOption['value']] ?? 0)) {
                $usageOk = false; $usageDetail = 'option ' . $adminField['field_key'] . '=' . $adminOption['value'];
            }
        }
    }
}
check('P3 usage: type, field and option counts equal a PHP-side count of stored complaints',
    $adminTypes !== [] && $usageOk, $usageDetail);
check('P3 list: admin list carries id, version, sort_order and inactive entries',
    $adminTypes !== [] && isset($adminTypes[0]['version'], $adminTypes[0]['sort_order'], $adminTypes[0]['fields'][0]['version'])
        && array_column($adminTypes, 'type_code') === array_column(complaint_types_all(true), 'type_code'));

// Validators.
check('P3 type: name must be 2 to 100 characters of plain text',
    $problem(static fn() => complaint_type_validate_type(['type_name' => 'x'], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => str_repeat('x', 101)], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => "Bad\tName"], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => 5], true))
        && $problem(static fn() => complaint_type_validate_type([], true)));
check('P3 type: create fills defaults; update leaves unsent keys out',
    complaint_type_validate_type(['type_name' => '  Stray Animals '], true)
        === ['type_name' => 'Stray Animals', 'description' => null, 'respondent_mode' => 'none']
        && complaint_type_validate_type(['id' => 1, 'version' => 1, 'type_name' => 'Stray Animals'], false) === ['type_name' => 'Stray Animals']);
check('P3 type: bad code, mode, sort order, description length and unknown keys are refused',
    $problem(static fn() => complaint_type_validate_type(['type_name' => 'Ok name', 'type_code' => 'Bad Code'], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => 'Ok name', 'type_code' => 'a'], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => 'Ok name', 'respondent_mode' => 'maybe'], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => 'Ok name', 'sort_order' => '10'], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => 'Ok name', 'sort_order' => 10000], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => 'Ok name', 'description' => str_repeat('x', 256)], true))
        && $problem(static fn() => complaint_type_validate_type(['type_name' => 'Ok name', 'is_active' => false], true)));
$twoOptions = [['value' => 'a', 'label' => 'A', 'is_active' => true], ['value' => 'b', 'label' => 'B', 'is_active' => true]];
check('P3 options: an existing value cannot be dropped, only marked inactive',
    $problem(static fn() => complaint_type_validate_options([['value' => 'a', 'label' => 'A']], $twoOptions), 422, COMPLAINT_TYPE_MSG_OPTIONS_KEPT)
        && complaint_type_validate_options([['value' => 'b', 'label' => 'B', 'is_active' => false], ['value' => 'a', 'label' => 'A'], ['value' => 'c', 'label' => 'C']], $twoOptions)
            === [['value' => 'b', 'label' => 'B', 'is_active' => false], ['value' => 'a', 'label' => 'A', 'is_active' => true], ['value' => 'c', 'label' => 'C', 'is_active' => true]]);
$fiftyOne = [];
for ($i = 0; $i < 51; $i++) $fiftyOne[] = ['value' => 'v' . $i, 'label' => 'V' . $i];
check('P3 options: bad value, duplicate value, empty label, non-bool flag, empty list and 51 options are refused',
    $problem(static fn() => complaint_type_validate_options([['value' => 'Bad', 'label' => 'x']], []))
        && $problem(static fn() => complaint_type_validate_options([['value' => 'a', 'label' => 'x'], ['value' => 'a', 'label' => 'y']], []))
        && $problem(static fn() => complaint_type_validate_options([['value' => 'a', 'label' => ' ']], []))
        && $problem(static fn() => complaint_type_validate_options([['value' => 'a', 'label' => 'x', 'is_active' => 1]], []))
        && $problem(static fn() => complaint_type_validate_options([], []))
        && $problem(static fn() => complaint_type_validate_options('a,b', []))
        && $problem(static fn() => complaint_type_validate_options($fiftyOne, [])));
check('P3 field: settings that belong to another field type are refused',
    $problem(static fn() => complaint_type_validate_field(['label' => 'L', 'options' => $twoOptions], 'text', true))
        && $problem(static fn() => complaint_type_validate_field(['label' => 'L', 'max_length' => 10], 'select', true))
        && $problem(static fn() => complaint_type_validate_field(['label' => 'L', 'min_value' => 1], 'date', true))
        && $problem(static fn() => complaint_type_validate_field(['label' => 'L', 'max_length' => 10], 'number', true)));
check('P3 field: text length 1..1000 with default 255; number bounds are JSON numbers; select needs options',
    complaint_type_validate_field(['label' => 'L'], 'text', true) === ['label' => 'L', 'max_length' => 255, 'is_required' => false]
        && $problem(static fn() => complaint_type_validate_field(['label' => 'L', 'max_length' => 0], 'text', true))
        && $problem(static fn() => complaint_type_validate_field(['label' => 'L', 'max_length' => 1001], 'text', true))
        && $problem(static fn() => complaint_type_validate_field(['label' => 'L', 'min_value' => '1'], 'number', true))
        && complaint_type_validate_field(['label' => 'L', 'min_value' => 1, 'max_value' => 2.5], 'number', true)
            === ['label' => 'L', 'min_value' => 1.0, 'max_value' => 2.5, 'is_required' => false]
        && $problem(static fn() => complaint_type_validate_field(['label' => 'L'], 'select', true))
        && $problem(static fn() => complaint_type_validate_field(['label' => ''], 'date', true))
        && $problem(static fn() => complaint_type_validate_field(['label' => 'L', 'is_required' => 1], 'date', true)));
check('P3 input: ids and versions must be JSON integers',
    $problem(static fn() => complaint_type_admin_int(['id' => '5'], 'id', 1, PHP_INT_MAX))
        && $problem(static fn() => complaint_type_admin_int(['id' => 5.0], 'id', 1, PHP_INT_MAX))
        && $problem(static fn() => complaint_type_admin_int(['id' => true], 'id', 1, PHP_INT_MAX))
        && $problem(static fn() => complaint_type_admin_int([], 'id', 1, PHP_INT_MAX))
        && $problem(static fn() => complaint_type_admin_version(['id' => 1]))
        && complaint_type_admin_int(['id' => 5], 'id', 1, PHP_INT_MAX) === 5);
$publicOther = $otherType !== null ? complaint_type_public_fields((int)$otherType['id']) : null;
check('P3 fields: public shape has no usage, version or is_active; missing type gives null',
    is_array($publicOther) && array_keys($publicOther) === ['id', 'type_code', 'type_name', 'description', 'respondent_mode', 'fields']
        && array_keys($publicOther['fields'][0]) === ['key', 'label', 'type', 'required', 'max_length', 'min', 'max', 'options']
        && complaint_type_public_fields(999999) === null);
check('P3 nothing written: type, field and audit rows are as they were before this section',
    $p3Snapshot() === $p3Before, $p3Before . ' -> ' . $p3Snapshot());

// ===========================================================================
// Phase 4: invariants that must hold whatever staff do through the Complaint
// Types screen. Both modes. Read-only: SELECT and pure functions only.
// ===========================================================================
echo "-- Phase 4\n";
$p4Before = $p3Snapshot();
$p4Types = rows($app, 'SELECT id, type_code, type_name FROM complaint_types');
$p4TypeIds = array_map('intval', array_column($p4Types, 'id'));
$orphans = rows($app, 'SELECT c.id FROM complaints c LEFT JOIN complaint_types t ON t.id = c.complaint_type_id
    WHERE c.complaint_type_id IS NULL OR t.id IS NULL');
check('P4 every complaint has a complaint_type_id that exists', $orphans === [], json_encode(array_column($orphans, 'id')));
$lowerNames = array_map(static fn(array $type): string => mb_strtolower((string)$type['type_name'], 'UTF-8'), $p4Types);
check('P4 type names are unique without regard to case', count($lowerNames) === count(array_unique($lowerNames)), json_encode($lowerNames));
$badCodes = array_values(array_filter(array_column($p4Types, 'type_code'),
    static fn($code): bool => preg_match(COMPLAINT_TYPE_CODE_PATTERN, (string)$code) !== 1));
$badFieldKeys = [];
$badOptionValues = [];
foreach (rows($app, 'SELECT complaint_type_id, field_key, field_type, options_json FROM complaint_type_fields') as $fieldRow) {
    if (preg_match(COMPLAINT_FIELD_KEY_PATTERN, (string)$fieldRow['field_key']) !== 1) $badFieldKeys[] = $fieldRow['field_key'];
    foreach (complaint_type_admin_options($fieldRow['options_json']) as $option) {
        if (preg_match(COMPLAINT_OPTION_VALUE_PATTERN, $option['value']) !== 1) $badOptionValues[] = $fieldRow['field_key'] . '=' . $option['value'];
    }
}
check('P4 every type_code, field_key and option value matches its pattern (D modifier)',
    $badCodes === [] && $badFieldKeys === [] && $badOptionValues === [],
    json_encode([$badCodes, $badFieldKeys, $badOptionValues]));
// Every stored select value is still one of that field's options (active or
// retired), and every complaint's display can be built without an error.
$missingValues = [];
$displayErrors = [];
$displayed = 0;
foreach (rows($app, 'SELECT id, complaint_type_id, extra_fields_json FROM complaints') as $complaint) {
    $type = complaint_type_get((int)$complaint['complaint_type_id'], true);
    $stored = $complaint['extra_fields_json'] === null ? null : json_decode((string)$complaint['extra_fields_json'], true);
    foreach ($type['fields'] ?? [] as $typeField) {
        if ($typeField['type'] !== 'select' || !is_array($stored) || !array_key_exists($typeField['key'], $stored)) continue;
        if (!in_array($stored[$typeField['key']], array_column($typeField['options'], 'value'), true)) {
            $missingValues[] = $complaint['id'] . ':' . $typeField['key'] . '=' . json_encode($stored[$typeField['key']]);
        }
    }
    try {
        $display = complaint_extra_fields_display($type, complaint_extra_fields_read($type, $complaint['extra_fields_json']));
        foreach ($display as $item) {
            if (!is_string($item['label'] ?? null) || !is_string($item['value'] ?? null)) throw new RuntimeException('bad item');
        }
        $displayed++;
    } catch (Throwable $error) {
        $displayErrors[] = $complaint['id'] . ': ' . $error->getMessage();
    }
}
check('P4 no stored select value is missing from its field\'s options (active or retired)', $missingValues === [], implode('; ', $missingValues));
check("P4 extra_fields_display works for all {$displayed} complaints", $displayErrors === [], implode('; ', $displayErrors));
// Filter choices: active types in sort order, then inactive ones marked.
$filterOptions = complaint_type_filter_options();
$expectedFilter = [];
foreach (complaint_types_all(true) as $type) if ($type['is_active']) $expectedFilter[] = ['value' => $type['id'], 'label' => $type['type_name']];
foreach (complaint_types_all(true) as $type) if (!$type['is_active']) $expectedFilter[] = ['value' => $type['id'], 'label' => $type['type_name'] . ' (no longer used)'];
check('P4 type filter choices: every type once, active first in sort order, inactive marked "(no longer used)"',
    $filterOptions === $expectedFilter && count($filterOptions) === count($p4TypeIds), json_encode($filterOptions));
check('P4 nothing written', $p3Snapshot() === $p4Before);

// ===========================================================================
// Phase 5: complaint parties. Live mode: invariants (read-only). Seed mode
// also runs the migration's backfill statement and the unique key inside a
// transaction that always rolls back.
// ===========================================================================
echo "-- Phase 5\n";
$p5Table = (int)scalar($db, "SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_parties'") === 1;
check('P5 complaint_parties table exists (migration 20261117_complaint_parties.sql applied)', $p5Table);
$p5Ids = static fn(string $sql): array => array_column(rows($db, $sql), 'id');
if ($p5Table) {
    $p5Snapshot = static fn(): string => (string)scalar($db, "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(version), 0), ':', COALESCE(SUM(is_active), 0)) FROM complaint_parties");
    $p5Before = $p5Snapshot();
    $partyFk = foreign_key($db, 'complaint_parties', 'fk_party_resident');
    $complaintFk = foreign_key($db, 'complaint_parties', 'fk_party_complaint');
    $filedByFk = foreign_key($db, 'complaints', 'fk_complaint_filed_by');
    $uqColumns = array_column(rows($db, "SELECT COLUMN_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_parties' AND INDEX_NAME = 'uq_party_resident'
        ORDER BY SEQ_IN_INDEX"), 'COLUMN_NAME');
    check('P5 schema: party resident FK RESTRICT, complaint FK CASCADE, unique key on the active resident, filed_by columns',
        ($partyFk['on_delete'] ?? '') === 'RESTRICT' && ($complaintFk['on_delete'] ?? '') === 'CASCADE'
        && ($filedByFk['on_delete'] ?? '') === 'SET NULL' && $uqColumns === ['complaint_id', 'role', 'active_resident_id']
        && column_type($db, 'complaints', 'filed_by_type') === "enum('resident','official')"
        && column_type($db, 'complaints', 'filed_by_official_id') === 'int'
        && column_type($db, 'complaint_parties', 'resident_id') === column_type($db, 'residents', 'id')
        && column_type($db, 'complaint_parties', 'complaint_id') === column_type($db, 'complaints', 'id'),
        json_encode([$partyFk, $complaintFk, $filedByFk, $uqColumns]));
    $noComplainant = $p5Ids("SELECT c.id FROM complaints c WHERE NOT EXISTS (SELECT 1 FROM complaint_parties p
        WHERE p.complaint_id = c.id AND p.role = 'complainant' AND p.is_active = 1)");
    check('P5 every complaint has at least one active complainant', $noComplainant === [], implode(',', $noComplainant));
    $noneRespondents = $p5Ids("SELECT p.id FROM complaint_parties p JOIN complaints c ON c.id = p.complaint_id
        JOIN complaint_types t ON t.id = c.complaint_type_id
        WHERE p.role = 'respondent' AND p.is_active = 1 AND t.respondent_mode = 'none' AND p.created_at >= t.updated_at");
    check('P5 no active respondent was added while its type had respondent mode "none"', $noneRespondents === [], implode(',', $noneRespondents));
    $orphans = $p5Ids('SELECT p.id FROM complaint_parties p LEFT JOIN residents r ON r.id = p.resident_id
        WHERE p.resident_id IS NOT NULL AND r.id IS NULL');
    check('P5 every party resident_id exists', $orphans === [], implode(',', $orphans));
    $duplicates = rows($db, 'SELECT complaint_id, role, resident_id FROM complaint_parties
        WHERE is_active = 1 AND resident_id IS NOT NULL GROUP BY complaint_id, role, resident_id HAVING COUNT(*) > 1');
    check('P5 no resident is listed twice in one role on a complaint', $duplicates === [], json_encode($duplicates));
    $bothRoles = rows($db, 'SELECT complaint_id, resident_id FROM complaint_parties
        WHERE is_active = 1 AND resident_id IS NOT NULL GROUP BY complaint_id, resident_id HAVING COUNT(DISTINCT role) > 1');
    check('P5 no resident is both complainant and respondent on a complaint', $bothRoles === [], json_encode($bothRoles));
    $registeredCopies = $p5Ids('SELECT id FROM complaint_parties WHERE resident_id IS NOT NULL AND (address IS NOT NULL OR contact_no IS NOT NULL)');
    check('P5 registered parties store no address or contact (read live from residents)', $registeredCopies === [], implode(',', $registeredCopies));
    $badContacts = [];
    foreach (rows($db, 'SELECT id, contact_no FROM complaint_parties WHERE contact_no IS NOT NULL') as $partyRow) {
        if (!preg_match('/^\+639[0-9]{9}$/D', (string)$partyRow['contact_no'])) $badContacts[] = $partyRow['id'];
    }
    check('P5 every stored contact number is normalized (+639XXXXXXXXX)', $badContacts === [], implode(',', $badContacts));
    $overLimit = rows($db, "SELECT complaint_id, role, COUNT(*) AS n FROM complaint_parties WHERE is_active = 1
        GROUP BY complaint_id, role HAVING (role = 'complainant' AND n > 5) OR (role = 'respondent' AND n > 10)");
    check('P5 active parties within limits (5 complainants, 10 respondents)', $overLimit === [], json_encode($overLimit));
    $walkinOwners = $p5Ids("SELECT c.id FROM complaints c WHERE c.filed_by_type = 'official' AND c.resident_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM complaint_parties p WHERE p.complaint_id = c.id AND p.role = 'complainant' AND p.resident_id = c.resident_id)");
    check('P5 a walk-in complaint\'s resident_id is one of its registered complainants', $walkinOwners === [], implode(',', $walkinOwners));
    check('P5 nothing written (live checks)', $p5Snapshot() === $p5Before);
}
if ($seedMode && $p5Table) {
    // The backfill statement, read from the migration file, run on two legacy
    // complaints (one with a resident, one without) inside a transaction.
    $migration = (string)file_get_contents($root . '/project-support/migrations/20261117_complaint_parties.sql');
    $begin = strpos($migration, '-- BACKFILL BEGIN');
    $end = strpos($migration, '-- BACKFILL END');
    $backfillSql = $begin !== false && $end !== false
        ? trim(implode("\n", array_filter(explode("\n", substr($migration, $begin, $end - $begin)),
            static fn(string $line): bool => !str_starts_with(trim($line), '--'))))
        : '';
    check('P5 [seed] the migration has one backfill statement between its markers', str_starts_with($backfillSql, 'INSERT INTO complaint_parties'));
    $app->begin_transaction();
    try {
        $app->query("INSERT INTO households (household_code, address, purok_zone, household_size) VALUES ('HH-P5-SEED', 'Purok 1', 'Purok 1', 1)");
        $householdId = (int)$app->insert_id;
        $app->query("INSERT INTO residents (fname, lname, username, address, contact, password, status, household_id)
            VALUES ('Seed', 'Filer', 'p5-seed-filer', 'Purok 1', '+639170000001', 'x', 'active', {$householdId})");
        $seedResident = (int)$app->insert_id;
        $legacy = [];
        foreach ([[$seedResident, '  Seed Filer  '], [null, 'Walk In Person']] as $i => [$rid, $name]) {
            $stmt = $app->prepare("INSERT INTO complaints (resident_id, reference_no, resident_name, category, severity, location_text, description, date_filed, status, official_note)
                VALUES (?, ?, ?, 'Other', 'low', 'Here', 'Legacy', 'Nov 17, 2026', 'open', '')");
            $ref = 'P5-SEED-' . $i;
            $stmt->bind_param('iss', $rid, $ref, $name);
            $stmt->execute();
            $legacy[] = (int)$stmt->insert_id;
            $stmt->close();
        }
        $before = (int)scalar($app, 'SELECT COUNT(*) FROM complaints c WHERE NOT EXISTS (SELECT 1 FROM complaint_parties p WHERE p.complaint_id = c.id AND p.role = \'complainant\')');
        $app->query($backfillSql);
        $inserted = $app->affected_rows;
        $after = rows($app, 'SELECT complaint_id, role, resident_id, full_name, sort_order, created_by_type, is_active FROM complaint_parties
            WHERE complaint_id IN (' . implode(',', $legacy) . ') ORDER BY complaint_id');
        check('P5 [seed] backfill wrote exactly one complainant per complaint that had none',
            $inserted === $before && count($after) === 2
            && (int)$after[0]['resident_id'] === $seedResident && $after[0]['full_name'] === 'Seed Filer'
            && $after[1]['resident_id'] === null && $after[1]['full_name'] === 'Walk In Person'
            && array_unique(array_column($after, 'role')) === ['complainant']
            && array_unique(array_column($after, 'created_by_type')) === ['system']
            && array_unique(array_column($after, 'sort_order')) === ['0'], json_encode([$inserted, $before, $after]));
        $app->query($backfillSql);
        check('P5 [seed] running the backfill again adds nothing', $app->affected_rows === 0);
        // Unique key: a second active row for the same resident and role is refused;
        // once the first is removed (inactive) the resident can be added again.
        $dup = $app->query("INSERT INTO complaint_parties (complaint_id, role, resident_id, full_name, created_by_type)
            VALUES ({$legacy[0]}, 'complainant', {$seedResident}, 'Seed Filer', 'system')");
        $dupErrno = $app->errno;
        $app->query("UPDATE complaint_parties SET is_active = 0 WHERE complaint_id = {$legacy[0]}");
        $again = $app->query("INSERT INTO complaint_parties (complaint_id, role, resident_id, full_name, created_by_type)
            VALUES ({$legacy[0]}, 'complainant', {$seedResident}, 'Seed Filer', 'system')");
        check('P5 [seed] uq_party_resident refuses a duplicate active resident and allows re-adding after removal',
            $dup === false && $dupErrno === 1062 && $again === true, (string)$dupErrno);
        $deleteResident = $app->query("DELETE FROM residents WHERE id = {$seedResident}");
        check('P5 [seed] a resident who is a party cannot be deleted (RESTRICT)', $deleteResident === false && $app->errno === 1451, (string)$app->errno);
    } finally {
        $app->rollback();
    }
    check('P5 [seed] backfill test rolled back', (int)scalar($db, "SELECT COUNT(*) FROM complaints WHERE reference_no LIKE 'P5-SEED-%'") === 0);
}

// ===========================================================================
// Phase 6: mediation calendar. Live mode: invariants (read-only). Seed mode
// also checks the untouched migration state and runs the case-number counter
// statement inside a transaction that always rolls back.
// ===========================================================================
echo "-- Phase 6\n";
$p6Tables = (int)scalar($db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('complaint_hearings', 'complaint_hearing_attendance', 'complaint_hearing_guard', 'complaint_case_counters')") === 4
    && column_type($db, 'complaints', 'case_no') !== null;
check('P6 hearing tables and complaints.case_no exist (migration 20261118_complaint_hearings.sql applied)', $p6Tables);
if ($p6Tables) {
    $p6Snapshot = static fn(): string => (string)scalar($db, "SELECT CONCAT(
        (SELECT COUNT(*) FROM complaint_hearings), ':', (SELECT COALESCE(SUM(version), 0) FROM complaint_hearings), ':',
        (SELECT COUNT(*) FROM complaint_hearing_attendance), ':', (SELECT COUNT(*) FROM complaint_case_counters), ':',
        (SELECT COALESCE(SUM(last_no), 0) FROM complaint_case_counters), ':', (SELECT COUNT(case_no) FROM complaints))");
    $p6Before = $p6Snapshot();
    $fk = static fn(string $table, string $name): string => (string)(foreign_key($db, $table, $name)['on_delete'] ?? '');
    check('P6 schema: foreign keys (complaint/official/from RESTRICT, attendance hearing CASCADE, party RESTRICT)',
        $fk('complaint_hearings', 'fk_hearing_complaint') === 'RESTRICT' && $fk('complaint_hearings', 'fk_hearing_presiding') === 'RESTRICT'
        && $fk('complaint_hearings', 'fk_hearing_from') === 'RESTRICT' && $fk('complaint_hearings', 'fk_hearing_created_by') === 'RESTRICT'
        && $fk('complaint_hearings', 'fk_hearing_updated_by') === 'RESTRICT'
        && $fk('complaint_hearing_attendance', 'fk_attendance_hearing') === 'CASCADE'
        && $fk('complaint_hearing_attendance', 'fk_attendance_party') === 'RESTRICT');
    check('P6 schema: signed INT keys match the referenced columns, unique keys present',
        column_type($db, 'complaint_hearings', 'complaint_id') === column_type($db, 'complaints', 'id')
        && column_type($db, 'complaint_hearings', 'presiding_official_id') === column_type($db, 'barangay_officials', 'id')
        && column_type($db, 'complaint_hearing_attendance', 'party_id') === column_type($db, 'complaint_parties', 'id')
        && column_type($db, 'complaint_hearings', 'id') === 'int'
        && has_index($db, 'complaints', 'uq_complaint_case_no') && has_index($db, 'complaint_hearings', 'uq_hearing_session')
        && has_index($db, 'complaint_hearings', 'uq_hearing_request_key'));
    check('P6 the calendar lock row exists (exactly one guard row, id 1)',
        rows($db, 'SELECT id FROM complaint_hearing_guard') === [['id' => '1']]);
    $orphanHearings = $p5Ids('SELECT h.id FROM complaint_hearings h LEFT JOIN complaints c ON c.id = h.complaint_id WHERE c.id IS NULL');
    check('P6 every hearing\'s complaint exists', $orphanHearings === [], implode(',', $orphanHearings));
    $multiScheduled = $p5Ids("SELECT complaint_id AS id FROM complaint_hearings WHERE status = 'scheduled' GROUP BY complaint_id HAVING COUNT(*) > 1");
    check('P6 at most one scheduled hearing per complaint', $multiScheduled === [], implode(',', $multiScheduled));
    $overlaps = rows($db, "SELECT a.id AS a_id, b.id AS b_id,
            IF(a.presiding_official_id = b.presiding_official_id, 'official', 'venue') AS reason
        FROM complaint_hearings a JOIN complaint_hearings b ON a.id < b.id
        WHERE a.status = 'scheduled' AND b.status = 'scheduled'
          AND a.starts_at < b.ends_at AND b.starts_at < a.ends_at
          AND (a.presiding_official_id = b.presiding_official_id OR a.venue_key = b.venue_key)");
    check('P6 no two scheduled hearings overlap for the same official or venue', $overlaps === [], json_encode($overlaps));
    $badTimes = $p5Ids('SELECT id FROM complaint_hearings WHERE ends_at <= starts_at');
    check('P6 every hearing ends after it starts', $badTimes === [], implode(',', $badTimes));
    $caseNos = array_column(rows($db, 'SELECT case_no FROM complaints WHERE case_no IS NOT NULL'), 'case_no');
    $badCaseNos = array_values(array_filter($caseNos, static fn(string $n): bool => preg_match('/^BC-\d{4}-\d{4,}$/D', $n) !== 1));
    check('P6 case numbers are unique and match BC-YYYY-NNNN',
        $badCaseNos === [] && count($caseNos) === count(array_unique($caseNos)), json_encode($badCaseNos));
    $noCaseNo = $p5Ids('SELECT DISTINCT h.complaint_id AS id FROM complaint_hearings h JOIN complaints c ON c.id = h.complaint_id WHERE c.case_no IS NULL');
    check('P6 every complaint with a hearing has a case number', $noCaseNo === [], implode(',', $noCaseNo));
    $badCompleted = $p5Ids("SELECT h.id FROM complaint_hearings h WHERE h.status = 'completed'
        AND (h.outcome IS NULL OR h.public_summary IS NULL OR CHAR_LENGTH(h.public_summary) < 10 OR h.completed_at IS NULL
             OR EXISTS (SELECT 1 FROM complaint_hearing_attendance a WHERE a.hearing_id = h.id AND a.status = 'not_recorded'))");
    check('P6 every completed hearing has an outcome, a summary and no unrecorded attendance', $badCompleted === [], implode(',', $badCompleted));
    $foreignParties = rows($db, 'SELECT a.hearing_id, a.party_id FROM complaint_hearing_attendance a
        JOIN complaint_hearings h ON h.id = a.hearing_id JOIN complaint_parties p ON p.id = a.party_id
        WHERE p.complaint_id <> h.complaint_id');
    check('P6 attendance rows belong to the hearing\'s own complaint', $foreignParties === [], json_encode($foreignParties));
    $badChains = $p5Ids("SELECT h.id FROM complaint_hearings h LEFT JOIN complaint_hearings f ON f.id = h.rescheduled_from_id
        WHERE h.rescheduled_from_id IS NOT NULL AND (f.id IS NULL OR f.complaint_id <> h.complaint_id OR f.status <> 'rescheduled')");
    check('P6 a rescheduled session points to a "rescheduled" session of the same complaint', $badChains === [], implode(',', $badChains));
    $usedByYear = [];
    foreach ($caseNos as $caseNo) {
        if (preg_match('/^BC-(\d{4})-(\d{4,})$/D', $caseNo, $m)) $usedByYear[(int)$m[1]] = max($usedByYear[(int)$m[1]] ?? 0, (int)$m[2]);
    }
    $counters = [];
    foreach (rows($db, 'SELECT year, last_no FROM complaint_case_counters') as $counter) $counters[(int)$counter['year']] = (int)$counter['last_no'];
    $behind = array_filter($usedByYear, static fn(int $max, int $year): bool => ($counters[$year] ?? 0) < $max, ARRAY_FILTER_USE_BOTH);
    check('P6 complaint_case_counters.last_no >= the highest case number used per year', $behind === [], json_encode([$usedByYear, $counters]));
    check('P6 nothing written (live checks)', $p6Snapshot() === $p6Before);
}
if ($seedMode && $p6Tables) {
    check('P6 [seed] migration state is untouched: counters empty, no case numbers, no hearings',
        (int)scalar($db, 'SELECT COUNT(*) FROM complaint_case_counters') === 0
        && (int)scalar($db, 'SELECT COUNT(*) FROM complaints WHERE case_no IS NOT NULL') === 0
        && (int)scalar($db, 'SELECT COUNT(*) FROM complaint_hearings') === 0);
    $app->begin_transaction();
    try {
        $counterSql = 'INSERT INTO complaint_case_counters (year, last_no) VALUES (1999, LAST_INSERT_ID(1))
            ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)';
        $numbers = [];
        foreach ([1, 2] as $_) {
            $app->query($counterSql);
            $numbers[] = (int)scalar($app, 'SELECT LAST_INSERT_ID()');
        }
        check('P6 [seed] the case counter statement gives 1, then 2 for a new year', $numbers === [1, 2], json_encode($numbers));
    } finally {
        $app->rollback();
    }
    check('P6 [seed] counter test rolled back', (int)scalar($db, 'SELECT COUNT(*) FROM complaint_case_counters') === 0);
}

// ===========================================================================
// Phase 7: summons. Live mode only (the review runner's seed database is built
// from the real schema plus migrations 16-18 and has no Phase 7 tables).
// Read-only: schema, at most one current summons per hearing + respondent, and
// every stored file present with its recorded sha256.
// ===========================================================================
if (!$seedMode) {
    echo "-- Phase 7\n";
    $p7Ready = (int)scalar($db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_documents'") === 1
        && column_type($db, 'document_types', 'audience') !== null;
    check('P7 complaint_documents and document_types.audience exist (migration 20261119_summons.sql applied)', $p7Ready);
    if ($p7Ready) {
        $p7Snapshot = static fn(): string => (string)scalar($db, "SELECT CONCAT((SELECT COUNT(*) FROM complaint_documents), ':',
            (SELECT COUNT(*) FROM complaint_documents WHERE status = 'current'), ':', (SELECT COUNT(*) FROM document_types))");
        $p7Before = $p7Snapshot();
        $fk7 = static fn(string $name): string => (string)(foreign_key($db, 'complaint_documents', $name)['on_delete'] ?? '');
        check('P7 schema: complaint/hearing/party/official foreign keys are RESTRICT, one-current unique key present',
            $fk7('fk_cdoc_complaint') === 'RESTRICT' && $fk7('fk_cdoc_hearing') === 'RESTRICT' && $fk7('fk_cdoc_party') === 'RESTRICT'
            && $fk7('fk_cdoc_generated_by') === 'RESTRICT' && has_index($db, 'complaint_documents', 'uq_cdoc_active')
            && has_index($db, 'complaint_documents', 'uq_cdoc_file'));
        $summonsType = rows($db, "SELECT id, audience, status FROM document_types WHERE code = 'summons'");
        check('P7 the Summons type exists, internal, with 13 system fields',
            count($summonsType) === 1 && $summonsType[0]['audience'] === 'internal'
            && (int)scalar($db, "SELECT COUNT(*) FROM document_type_fields WHERE source = 'system' AND document_type_id = " . (int)($summonsType[0]['id'] ?? 0)) === 13);
        $twoCurrent = rows($db, "SELECT hearing_id, party_id, COUNT(*) AS n FROM complaint_documents WHERE status = 'current'
            GROUP BY hearing_id, party_id HAVING COUNT(*) > 1");
        check('P7 at most one current summons per hearing + respondent', $twoCurrent === [], json_encode($twoCurrent));
        $wrongParty = $p5Ids("SELECT d.id FROM complaint_documents d
            JOIN complaint_hearings h ON h.id = d.hearing_id JOIN complaint_parties p ON p.id = d.party_id
            WHERE h.complaint_id <> d.complaint_id OR p.complaint_id <> d.complaint_id OR p.role <> 'respondent'");
        check('P7 every summons belongs to a respondent and a session of its own complaint', $wrongParty === [], implode(',', $wrongParty));
        $storageBase = getenv('BARANGAY_STORAGE_ROOT') ? null : $root . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'complaints';
        $badFiles = [];
        foreach (rows($db, 'SELECT id, file_uuid, pdf_sha256, docx_sha256 FROM complaint_documents ORDER BY id') as $doc) {
            foreach (['pdf' => 'pdf_sha256', 'docx' => 'docx_sha256'] as $ext => $column) {
                $path = $storageBase === null ? null : $storageBase . DIRECTORY_SEPARATOR . $doc['file_uuid'] . '.' . $ext;
                if ($path === null || !is_file($path) || !hash_equals((string)$doc[$column], (string)hash_file('sha256', $path))) $badFiles[] = $doc['id'] . '.' . $ext;
            }
        }
        check('P7 every summons file exists on disk with its recorded sha256', $badFiles === [], implode(',', $badFiles));
        check('P7 nothing written (live checks)', $p7Snapshot() === $p7Before);
    }
}

// ===========================================================================
// Phase 10: complaint billing. Live mode only (same reason as Phase 7).
// A missing migration fails here instead of silently disabling billing (D1).
// Read-only: schema, one bill per complaint, settled rows complete.
// ===========================================================================
if (!$seedMode) {
    echo "-- Phase 10\n";
    $p10Ready = (int)scalar($db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_bills'") === 1
        && column_type($db, 'complaint_types', 'default_fee') !== null;
    check('P10 complaint_bills and complaint_types.default_fee exist (migration 20261120_complaint_billing.sql applied)', $p10Ready);
    if ($p10Ready) {
        $p10Snapshot = static fn(): string => (string)scalar($db, "SELECT CONCAT((SELECT COUNT(*) FROM complaint_bills), ':',
            (SELECT COALESCE(SUM(version), 0) FROM complaint_bills), ':', (SELECT COALESCE(SUM(default_fee), 0) FROM complaint_types))");
        $p10Before = $p10Snapshot();
        $fk10 = static fn(string $name): string => (string)(foreign_key($db, 'complaint_bills', $name)['on_delete'] ?? '');
        check('P10 schema: default_fee DECIMAL(10,2) NOT NULL; complaint/official foreign keys RESTRICT; unique complaint_id',
            strtolower((string)column_type($db, 'complaint_types', 'default_fee')) === 'decimal(10,2)'
            && scalar($db, "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_types' AND COLUMN_NAME = 'default_fee'") === 'NO'
            && $fk10('fk_bill_complaint') === 'RESTRICT' && $fk10('fk_bill_issued_by') === 'RESTRICT' && $fk10('fk_bill_settled_by') === 'RESTRICT'
            && has_index($db, 'complaint_bills', 'uq_bill_complaint'));
        $twoBills = rows($db, 'SELECT complaint_id, COUNT(*) AS n FROM complaint_bills GROUP BY complaint_id HAVING COUNT(*) > 1');
        check('P10 at most one bill per complaint', $twoBills === [], json_encode($twoBills));
        $unsettled = $p5Ids("SELECT id FROM complaint_bills WHERE status IN ('paid', 'waived') AND (settled_by IS NULL OR settled_at IS NULL)");
        check('P10 paid and waived bills have settled_by and settled_at', $unsettled === [], implode(',', $unsettled));
        $openSettled = $p5Ids("SELECT id FROM complaint_bills WHERE status = 'unpaid' AND (settled_by IS NOT NULL OR settled_at IS NOT NULL OR receipt_no IS NOT NULL OR waive_reason IS NOT NULL)");
        check('P10 unpaid bills carry no settlement, receipt or reason', $openSettled === [], implode(',', $openSettled));
        $noReason = $p5Ids("SELECT id FROM complaint_bills WHERE status = 'waived' AND (waive_reason IS NULL OR CHAR_LENGTH(TRIM(waive_reason)) < 5)");
        check('P10 waived bills have a reason', $noReason === [], implode(',', $noReason));
        $badAmount = $p5Ids('SELECT id FROM complaint_bills WHERE amount <= 0 OR amount > 100000.00');
        check('P10 bill amounts are > 0 and <= 100,000.00; type fees 0 .. 100,000.00', $badAmount === []
            && (int)scalar($db, 'SELECT COUNT(*) FROM complaint_types WHERE default_fee < 0 OR default_fee > 100000.00') === 0, implode(',', $badAmount));
        check('P10 nothing written (live checks)', $p10Snapshot() === $p10Before);
    }
}

if ($seedMode) {
    // An import "into a scratch database" once wrote to the real one because
    // database.sql began with USE. It must never select or create a database.
    $freshSchema = (string)file_get_contents($root . '/database/database.sql');
    $selectLines = preg_match_all('/^\s*(USE\s|CREATE\s+DATABASE)/mi', $freshSchema);
    check('P6 [seed] database.sql has no line starting with USE or CREATE DATABASE',
        $freshSchema !== '' && $selectLines === 0, (string)$selectLines);
}

$total = $passed + $failed;
echo "{$passed}/{$total} passed\n";
exit($failed === 0 ? 0 : 1);
