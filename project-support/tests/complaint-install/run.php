<?php
/**
 * BarangayLink - Fresh install = migrated install (complaint revision Phase 9).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * A: empty scratch database + database/database.sql.
 * B: scratch database restored from the pre-P1 backup
 *    (C:/laragon/backups/barangay_before_complaint_types.sql), then the
 *    migrations 20261116 -> 20261120 in order, then
 *    build-summons-template.php --apply against B only (template storage in
 *    TestStorage, never document_templates/).
 * Compares A and B from information_schema (tables, columns with type / null /
 * default / extra / generated expression, indexes, foreign keys, CHECK
 * constraints; enum values are part of COLUMN_TYPE) and the seed rows of
 * complaint_types, complaint_type_fields and the Summons type and fields.
 * Then runs every migration a second time on B: no error, no change.
 * Both scratch databases are dropped at the end. The real database is only
 * counted (before = after); the backup file is only read.
 *
 *   php project-support/tests/complaint-install/run.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}
$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/documents/_templates.php';
require_once $root . '/project-support/tests/_storage.php';
mysqli_report(MYSQLI_REPORT_OFF);

const INSTALL_BACKUP = 'C:/laragon/backups/barangay_before_complaint_types.sql';
const INSTALL_MIGRATIONS = ['20261116_complaint_types.sql', '20261117_complaint_parties.sql', '20261118_complaint_hearings.sql', '20261119_summons.sql',
    '20261120_complaint_billing.sql'];

$storage = new TestStorage($root);
$storage->assertActive();
$realSource = $root . '/document_templates/summon_letter.docx';
$realSha = hash_file('sha256', $realSource);
copy($realSource, $storage->track($storage->template('summon_letter.docx')));

$tag = getmypid() . '_' . random_int(1000, 9999);
$dbA = 'capstone_install_a_' . $tag;
$dbB = 'capstone_install_b_' . $tag;
$realDatabase = $DB_NAME;
$passed = 0; $failed = 0; $admin = null;

function in_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; return; }
    $failed++; echo "FAIL {$name}" . ($detail !== '' ? ' :: ' . mb_substr($detail, 0, 1500) : '') . "\n";
}
function in_rows(mysqli $db, string $sql, array $params = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return $rows;
}
function in_import(string $host, string $user, string $pass, string $database, string $sql): ?string {
    $db = new mysqli($host, $user, $pass, $database);
    $db->set_charset('utf8mb4');
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    $error = null;
    if (!$db->multi_query($sql)) $error = $db->error;
    else {
        do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
        if ($db->errno) $error = $db->error;
    }
    $db->close();
    return $error;
}
/** The schema of one database as comparable string lines, grouped. */
function in_schema(mysqli $db, string $schema): array {
    $out = ['tables' => [], 'columns' => [], 'positions' => [], 'indexes' => [], 'fks' => [], 'checks' => []];
    foreach (in_rows($db, 'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=\'BASE TABLE\' ORDER BY TABLE_NAME', [$schema]) as $r) {
        $out['tables'][$r['TABLE_NAME']] = "{$r['TABLE_NAME']} engine={$r['ENGINE']} collation={$r['TABLE_COLLATION']}";
    }
    foreach (in_rows($db, 'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION, COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$schema]) as $r) {
        $key = $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'];
        $extra = trim(str_replace('DEFAULT_GENERATED', '', (string)$r['EXTRA']));
        $out['columns'][$key] = sprintf('%s %s null=%s default=%s extra=%s gen=%s coll=%s', $key, $r['COLUMN_TYPE'], $r['IS_NULLABLE'],
            $r['COLUMN_DEFAULT'] === null ? 'NULL' : "'" . $r['COLUMN_DEFAULT'] . "'", $extra, (string)$r['GENERATION_EXPRESSION'], (string)$r['COLLATION_NAME']);
        $out['positions'][$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
    }
    foreach ($out['positions'] as $table => $cols) $out['positions'][$table] = $table . ': ' . implode(', ', $cols);
    $idx = [];
    foreach (in_rows($db, 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [$schema]) as $r) {
        $k = $r['TABLE_NAME'] . '.' . $r['INDEX_NAME'];
        $idx[$k]['unique'] = $r['NON_UNIQUE'] === '0' || $r['NON_UNIQUE'] === 0 ? 'unique' : 'non-unique';
        $idx[$k]['cols'][] = $r['COLUMN_NAME'] . ($r['SUB_PART'] !== null ? '(' . $r['SUB_PART'] . ')' : '');
    }
    foreach ($idx as $k => $v) $out['indexes'][$k] = $k . ' ' . $v['unique'] . ' (' . implode(', ', $v['cols']) . ')';
    $fk = [];
    foreach (in_rows($db, 'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
            WHERE k.TABLE_SCHEMA=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION', [$schema]) as $r) {
        $k = $r['TABLE_NAME'] . '.' . $r['CONSTRAINT_NAME'];
        $fk[$k]['cols'][] = $r['COLUMN_NAME']; $fk[$k]['ref'] = $r['REFERENCED_TABLE_NAME']; $fk[$k]['refcols'][] = $r['REFERENCED_COLUMN_NAME'];
        $fk[$k]['rules'] = "on update {$r['UPDATE_RULE']} on delete {$r['DELETE_RULE']}";
    }
    foreach ($fk as $k => $v) $out['fks'][$k] = "{$k} (" . implode(', ', $v['cols']) . ") -> {$v['ref']}(" . implode(', ', $v['refcols']) . ") {$v['rules']}";
    foreach (in_rows($db, 'SELECT t.TABLE_NAME, c.CONSTRAINT_NAME, c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c
            JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME
            WHERE c.CONSTRAINT_SCHEMA=? ORDER BY t.TABLE_NAME, c.CONSTRAINT_NAME', [$schema]) as $r) {
        $out['checks'][$r['TABLE_NAME'] . '.' . $r['CONSTRAINT_NAME']] = $r['TABLE_NAME'] . '.' . $r['CONSTRAINT_NAME'] . ' ' . $r['CHECK_CLAUSE'];
    }
    return $out;
}
/** Seed rows compared by natural key (no ids, no timestamps, no audit columns). */
function in_seeds(mysqli $db, string $schema): array {
    $q = static fn(string $sql): array => in_rows($db, str_replace('{S}', '`' . $schema . '`', $sql));
    return [
        'complaint_types' => $q('SELECT type_code, type_name, description, respondent_mode, default_fee, is_active, sort_order FROM {S}.complaint_types ORDER BY type_code'),
        'complaint_type_fields' => $q('SELECT t.type_code, f.field_key, f.label, f.field_type, f.is_required, f.options_json, f.max_length, f.min_value, f.max_value, f.is_active, f.sort_order
            FROM {S}.complaint_type_fields f JOIN {S}.complaint_types t ON t.id=f.complaint_type_id ORDER BY t.type_code, f.field_key'),
        'summons_type' => $q("SELECT code, name, audience, status, sort_order, processing_days, requirements FROM {S}.document_types WHERE code='summons'"),
        'summons_fields' => $q("SELECT f.field_key, f.label, f.source, f.data_type, f.min_value, f.max_value, f.max_length, f.is_required, f.sort_order FROM {S}.document_type_fields f JOIN {S}.document_types t ON t.id=f.document_type_id
            WHERE t.code='summons' ORDER BY f.sort_order, f.field_key"),
    ];
}
function in_diff(array $a, array $b): array {
    $lines = [];
    foreach (array_diff_key($a, $b) as $k => $v) $lines[] = "only in A: {$v}";
    foreach (array_diff_key($b, $a) as $k => $v) $lines[] = "only in B: {$v}";
    foreach (array_intersect_key($a, $b) as $k => $v) if ($v !== $b[$k]) $lines[] = "differs: A {$v}\n          B {$b[$k]}";
    return $lines;
}

$exitCode = 1;
try {
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_errno) throw new RuntimeException('MySQL unavailable.');
    $realCounts = [];
    foreach (['complaints', 'complaint_types', 'document_types', 'document_template_versions', 'audit_log'] as $t) {
        $realCounts[$t] = (int)$admin->query("SELECT COUNT(*) FROM `{$realDatabase}`.`{$t}`")->fetch_row()[0];
    }
    foreach ([$dbA, $dbB] as $name) {
        if (!$admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    }

    // A: fresh install.
    $errA = in_import($DB_HOST, $DB_USER, $DB_PASS, $dbA, (string)file_get_contents($root . '/database/database.sql'));
    in_check('A: database/database.sql imports into an empty database', $errA === null, (string)$errA);

    // B: pre-P1 backup + the five migrations in order.
    if (!is_file(INSTALL_BACKUP)) throw new RuntimeException('Backup not found: ' . INSTALL_BACKUP);
    $errB = in_import($DB_HOST, $DB_USER, $DB_PASS, $dbB, (string)file_get_contents(INSTALL_BACKUP));
    in_check('B: pre-P1 backup restores', $errB === null, (string)$errB);
    in_check('B: the backup is really pre-P1 (no complaint_types table)',
        (int)$admin->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$dbB}' AND TABLE_NAME='complaint_types'")->fetch_row()[0] === 0);
    foreach (INSTALL_MIGRATIONS as $file) {
        $err = in_import($DB_HOST, $DB_USER, $DB_PASS, $dbB, (string)file_get_contents($root . '/project-support/migrations/' . $file));
        in_check("B: migration {$file}", $err === null, (string)$err);
    }
    $proc = proc_open([PHP_BINARY, $root . '/project-support/scripts/build-summons-template.php', '--database=' . $dbB, '--apply'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($proc);
    in_check('B: build-summons-template.php --apply (temp storage) activates Summons v1', $code === 0 && str_contains($out, 'activated Summons v1'), $out);

    // Compare.
    $schemaA = in_schema($admin, $dbA);
    $schemaB = in_schema($admin, $dbB);
    $report = [];
    foreach (['tables', 'columns', 'indexes', 'fks', 'checks'] as $part) {
        $d = in_diff($schemaA[$part], $schemaB[$part]);
        $report[$part] = $d;
        echo "--- {$part}: " . count($d) . " difference(s)\n";
        foreach ($d as $line) echo "    {$line}\n";
    }
    $orderDiff = in_diff($schemaA['positions'], $schemaB['positions']);
    echo '--- column order (information only): ' . count($orderDiff) . " table(s) differ\n";
    foreach ($orderDiff as $line) echo "    {$line}\n";
    $complaintTables = static fn(array $lines): array => array_values(array_filter($lines, static fn(string $l): bool => (bool)preg_match('/\b(complaint\w*|document_types|document_type_fields|document_template_versions)\b/', $l)));
    foreach (['tables', 'columns', 'indexes', 'fks', 'checks'] as $part) {
        in_check("schema {$part}: no difference in the complaint-revision tables", $complaintTables($report[$part]) === [], implode("\n", $complaintTables($report[$part])));
    }
    $seedA = in_seeds($admin, $dbA);
    $seedB = in_seeds($admin, $dbB);
    // Documented difference (docs/archive/complaint-revision-phase5.md, "Follow-up: every type allows a person involved"):
    // database.sql seeds every type 'optional'; 20261116 keeps its original seed ('none' for these two) and the
    // real database was switched through the Complaint Types screen. Anything else must be equal.
    $documented = ['road_infrastructure', 'flooding_drainage'];
    $typeDiff = [];
    foreach ($seedA['complaint_types'] as $i => $rowA) {
        $rowB = $seedB['complaint_types'][$i] ?? null;
        if ($rowB === $rowA) continue;
        $sameButMode = is_array($rowB) && array_diff_key($rowA, ['respondent_mode' => 1]) == array_diff_key($rowB, ['respondent_mode' => 1])
            && in_array($rowA['type_code'], $documented, true) && $rowA['respondent_mode'] === 'optional' && $rowB['respondent_mode'] === 'none';
        $typeDiff[] = ($sameButMode ? 'documented: ' : 'UNEXPECTED: ') . $rowA['type_code'] . ' A=' . $rowA['respondent_mode'] . ' B=' . ($rowB['respondent_mode'] ?? '-');
    }
    echo '--- complaint_types seed: ' . count($typeDiff) . " difference(s)
";
    foreach ($typeDiff as $line) echo "    {$line}
";
    in_check('seed rows: complaint_types (' . count($seedA['complaint_types']) . ' rows) equal except the documented P5 respondent_mode switch of road_infrastructure and flooding_drainage',
        count($seedA['complaint_types']) === count($seedB['complaint_types']) && count($typeDiff) === 2
        && array_filter($typeDiff, static fn(string $l): bool => str_starts_with($l, 'UNEXPECTED')) === [], implode(' | ', $typeDiff));
    foreach (['complaint_type_fields', 'summons_type', 'summons_fields'] as $set) {
        in_check("seed rows equal: {$set} (" . count($seedA[$set]) . ' rows)', $seedA[$set] === $seedB[$set] && $seedA[$set] !== [], json_encode(['A' => $seedA[$set], 'B' => $seedB[$set]]));
    }

    // Second run of every migration on B: no error, no change.
    $snapshot = static fn() => json_encode([in_schema($admin, $dbB), in_seeds($admin, $dbB),
        in_rows($admin, "SELECT COUNT(*) AS c FROM `{$dbB}`.complaints WHERE complaint_type_id IS NULL"),
        in_rows($admin, "SELECT COUNT(*) AS p FROM `{$dbB}`.complaint_parties"),
        in_rows($admin, "SELECT COUNT(*) AS v FROM `{$dbB}`.document_template_versions")]);
    $before = $snapshot();
    foreach (INSTALL_MIGRATIONS as $file) {
        $err = in_import($DB_HOST, $DB_USER, $DB_PASS, $dbB, (string)file_get_contents($root . '/project-support/migrations/' . $file));
        in_check("B: second run of {$file}: no error", $err === null, (string)$err);
    }
    in_check('B: second run changed nothing (schema, seeds, backfill counts, versions)', $snapshot() === $before);

    $after = [];
    foreach (array_keys($realCounts) as $t) $after[$t] = (int)$admin->query("SELECT COUNT(*) FROM `{$realDatabase}`.`{$t}`")->fetch_row()[0];
    in_check('real database row counts unchanged', $after === $realCounts, json_encode([$realCounts, $after]));
    in_check('real summon_letter.docx unchanged', hash_file('sha256', $realSource) === $realSha);
    $exitCode = $failed === 0 ? 0 : 1;
} catch (Throwable $e) {
    in_check('suite ran without an unexpected error', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    foreach (glob($storage->templates . DIRECTORY_SEPARATOR . 'tpl_*.docx') ?: [] as $file) $storage->track($file);
    foreach (glob($storage->previews . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $file) $storage->track($file);
    if ($admin instanceof mysqli) {
        foreach ([$dbA, $dbB] as $name) if (str_starts_with($name, 'capstone_install_')) $admin->query("DROP DATABASE IF EXISTS `{$name}`");
        $left = (int)$admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('{$dbA}', '{$dbB}')")->fetch_row()[0];
        in_check('both scratch databases dropped', $left === 0);
        $admin->close();
    }
}
echo ($passed) . '/' . ($passed + $failed) . " passed\n";
exit($failed === 0 ? $exitCode : 1);
