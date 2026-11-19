<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

/**
 * Complaint revision, Phase 0: read-only baseline of stored complaint data.
 * Runs SELECT statements only. Usage: php project-support/scripts/complaint-baseline.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/api/common.php';
require_once $root . '/api/complaints/_extra_fields.php';

$db = get_db();
// Include inactive types, fields and retired options: stored history is valid under them.
$schema = complaint_extra_fields_schema(true);

function baseline_rows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    if (!$result) {
        fwrite(STDERR, 'Query failed: ' . $db->error . PHP_EOL);
        exit(1);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function baseline_heading(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

function baseline_show(mixed $value): string
{
    if ($value === null) return 'NULL';
    if (is_string($value)) return "'" . $value . "'";
    return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

echo 'Complaint baseline (read-only) - ' . date('Y-m-d H:i:s') . PHP_EOL;
echo 'Database: ' . baseline_rows($db, 'SELECT DATABASE() AS d')[0]['d'] . PHP_EOL;

// a) Complaints per category.
baseline_heading('a) COMPLAINTS PER CATEGORY');
$categoryRows = baseline_rows($db, 'SELECT category, COUNT(*) AS n FROM complaints GROUP BY category ORDER BY category');
if ($categoryRows === []) echo '(none)' . PHP_EOL;
foreach ($categoryRows as $row) {
    echo str_pad(baseline_show($row['category']), 34) . $row['n'] . PHP_EOL;
}

// b) Distinct (category, key, value) triples stored in extra_fields_json.
$triples = [];
$invalid = [];
$undecodable = 0;
$extraRows = baseline_rows($db, 'SELECT id, category, extra_fields_json FROM complaints WHERE extra_fields_json IS NOT NULL ORDER BY id');
foreach ($extraRows as $row) {
    $category = (string)($row['category'] ?? '');
    $decoded = json_decode((string)$row['extra_fields_json']);
    if (!$decoded instanceof stdClass) {
        $undecodable++;
        $label = baseline_show($category) . ' | (not a JSON object) | ' . baseline_show($row['extra_fields_json']);
        $invalid[$label] = ($invalid[$label] ?? 0) + 1;
        continue;
    }
    foreach (get_object_vars($decoded) as $key => $value) {
        $label = baseline_show($category) . ' | ' . $key . ' | ' . baseline_show($value);
        $triples[$label] = ($triples[$label] ?? 0) + 1;
        // Select values must be a known option; text, number and date values
        // (possible since Phase 2) only need to be strings.
        $field = $schema[$category][$key] ?? null;
        $valid = $field !== null && is_string($value)
            && (($field['type'] ?? 'select') !== 'select' || array_key_exists($value, $field['options']));
        if (!$valid) $invalid[$label] = ($invalid[$label] ?? 0) + 1;
    }
}
ksort($triples);
ksort($invalid);

baseline_heading('b) EXTRA FIELD TRIPLES (category | key | value  count)');
echo 'Rows with extra_fields_json NOT NULL: ' . count($extraRows) . PHP_EOL;
echo 'Rows whose JSON is not an object: ' . $undecodable . PHP_EOL;
if ($triples === []) echo '(none)' . PHP_EOL;
foreach ($triples as $label => $count) echo $label . '  ' . $count . PHP_EOL;

// c) Triples the current schema would reject.
baseline_heading('INVALID STORED VALUES');
if ($invalid === []) echo '(none)' . PHP_EOL;
foreach ($invalid as $label => $count) echo $label . '  ' . $count . PHP_EOL;

// d) Categories the current schema does not know.
baseline_heading('UNKNOWN CATEGORIES');
$unknown = 0;
foreach ($categoryRows as $row) {
    if ($row['category'] !== null && array_key_exists((string)$row['category'], $schema)) continue;
    echo str_pad(baseline_show($row['category']), 34) . $row['n'] . PHP_EOL;
    $unknown++;
}
if ($unknown === 0) echo '(none)' . PHP_EOL;

// e) Totals.
baseline_heading('e) TOTALS');
echo 'Total complaints: ' . baseline_rows($db, 'SELECT COUNT(*) AS n FROM complaints')[0]['n'] . PHP_EOL;
echo 'resident_id IS NULL: ' . baseline_rows($db, 'SELECT COUNT(*) AS n FROM complaints WHERE resident_id IS NULL')[0]['n'] . PHP_EOL;
echo 'Per status:' . PHP_EOL;
foreach (baseline_rows($db, 'SELECT status, COUNT(*) AS n FROM complaints GROUP BY status ORDER BY status') as $row) {
    echo '  ' . str_pad(baseline_show($row['status']), 20) . $row['n'] . PHP_EOL;
}

// f) Id column types.
baseline_heading('f) ID COLUMN TYPES');
$columns = baseline_rows($db, "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS d, COLUMN_TYPE AS ct, IS_NULLABLE AS n, COLUMN_KEY AS k, EXTRA AS x
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'id'
    AND TABLE_NAME IN ('complaints', 'residents', 'barangay_officials')
  ORDER BY TABLE_NAME");
if ($columns === []) echo '(no matching tables)' . PHP_EOL;
foreach ($columns as $col) {
    echo $col['t'] . '.' . $col['c'] . ': data_type=' . $col['d'] . ', column_type=' . $col['ct']
        . ', nullable=' . $col['n'] . ', key=' . $col['k'] . ', extra=' . $col['x'] . PHP_EOL;
}

// g) Server version.
baseline_heading('g) SERVER VERSION');
echo baseline_rows($db, 'SELECT VERSION() AS v')[0]['v'] . PHP_EOL;
