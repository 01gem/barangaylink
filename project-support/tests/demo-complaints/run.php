<?php
/**
 * BarangayLink - Test for project-support/scripts/demo-complaints.php (Phase 9).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Scratch database from database/database.sql (+ one official, Summons v1 built
 * into TestStorage). Snapshot of every table's row count and of the temp
 * storage file list -> --seed -> checks -> --seed again (refused) -> --cleanup
 * -> snapshot again: must be identical. The manifest is written to a temp path,
 * never project-support/demo/. Never touches the real database.
 *
 *   php project-support/tests/demo-complaints/run.php                 run the test
 *   php project-support/tests/demo-complaints/run.php --save-sample   also copy one generated
 *        DEMO summons PDF to project-support/demo/sample-summons-DEMO.pdf (the LibreOffice fallback)
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['GEMINI_API_KEY', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) { putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = ''; }
$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/documents/_templates.php';
require_once $root . '/project-support/tests/_storage.php';
mysqli_report(MYSQLI_REPORT_OFF);
$saveSample = in_array('--save-sample', $argv, true);

$storage = new TestStorage($root);
$storage->assertActive();
copy($root . '/document_templates/summon_letter.docx', $storage->track($storage->template('summon_letter.docx')));
$scratch = 'capstone_demo_' . getmypid() . '_' . random_int(1000, 9999);
$manifest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'demo-manifest-' . getmypid() . '.json';
$realDatabase = $DB_NAME;
$passed = 0; $failed = 0; $admin = null;

function dm_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; return; }
    $failed++; echo "FAIL {$name}" . ($detail !== '' ? ' :: ' . mb_substr($detail, 0, 800) : '') . "\n";
}
function dm_run(array $args): array {
    $proc = proc_open(array_merge([PHP_BINARY], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out];
}
function dm_files(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile()) $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir))) . ' ' . hash_file('sha256', $file->getPathname());
    sort($out); return $out;
}

try {
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    $realBefore = (int)$admin->query("SELECT COUNT(*) FROM `{$realDatabase}`.complaints")->fetch_row()[0] . '/' . (int)$admin->query("SELECT COUNT(*) FROM `{$realDatabase}`.residents")->fetch_row()[0];
    $admin->query("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch);
    $db->set_charset('utf8mb4');
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($root . '/database/database.sql'));
    $db->multi_query($sql); do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    $db->query("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES ('Demo','Captain','gem','admin','Purok 1','','x','Captain','active')");
    [$code, $out] = dm_run([$root . '/project-support/scripts/build-summons-template.php', '--database=' . $scratch, '--apply']);
    dm_check('setup: scratch database + Summons v1 in temp storage', $code === 0 && str_contains($out, 'activated Summons v1'), $out);

    $tables = array_column($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$scratch}' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $snapshot = static function () use ($db, $tables, $storage): array {
        $counts = [];
        foreach ($tables as $t) $counts[$t] = (int)$db->query("SELECT COUNT(*) FROM `{$t}`")->fetch_row()[0];
        return ['counts' => $counts, 'max_ids' => [
            'audit_log' => (int)$db->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetch_row()[0],
            'complaint_updates' => (int)$db->query('SELECT COALESCE(MAX(id),0) FROM complaint_updates')->fetch_row()[0]],
            'files' => dm_files($storage->root)];
    };
    $before = $snapshot();
    $script = $root . '/project-support/scripts/demo-complaints.php';

    [$code, $out] = dm_run([$script, '--seed', '--database=' . $scratch, '--manifest=' . $manifest]);
    dm_check('--seed exits 0 and writes the manifest', $code === 0 && is_file($manifest), $out);
    $m = json_decode((string)@file_get_contents($manifest), true) ?: [];
    $status = array_column($db->query("SELECT reference_no, status FROM complaints WHERE reference_no LIKE 'DEMO-%' ORDER BY reference_no")->fetch_all(MYSQLI_ASSOC), 'status', 'reference_no');
    dm_check('three DEMO complaints: open, investigating, resolved', $status === ['DEMO-0001' => 'open', 'DEMO-0002' => 'investigating', 'DEMO-0003' => 'resolved'], json_encode($status));
    $sessions = $db->query("SELECT h.status, h.outcome, h.starts_at > NOW() AS upcoming FROM complaint_hearings h JOIN complaints c ON c.id=h.complaint_id WHERE c.reference_no LIKE 'DEMO-%' ORDER BY h.status = 'scheduled' DESC")->fetch_all(MYSQLI_ASSOC);
    dm_check('one upcoming scheduled session and one completed (settled) session',
        count($sessions) === 2 && $sessions[0]['status'] === 'scheduled' && (int)$sessions[0]['upcoming'] === 1 && $sessions[1]['status'] === 'completed' && $sessions[1]['outcome'] === 'settled', json_encode($sessions));
    $docs = (int)$db->query("SELECT COUNT(*) FROM complaint_documents d JOIN complaints c ON c.id=d.complaint_id WHERE c.case_no='BC-DEMO-0001' AND d.status='current'")->fetch_row()[0];
    dm_check('two summonses for the upcoming session, files on disk and in the manifest', $docs === 2 && count($m['files'] ?? []) === 4
        && array_filter($m['files'] ?? [], static fn($f) => !is_file($f)) === [], json_encode($m['files'] ?? []) . ' ' . json_encode($m['notes'] ?? []));
    $names = $db->query("SELECT CONCAT(fname,' ',lname) n, contact FROM residents WHERE username LIKE 'demo-%'")->fetch_all(MYSQLI_ASSOC);
    $partyNames = array_column($db->query("SELECT DISTINCT p.full_name FROM complaint_parties p JOIN complaints c ON c.id=p.complaint_id WHERE c.reference_no LIKE 'DEMO-%'")->fetch_all(MYSQLI_ASSOC), 'full_name');
    dm_check('demo residents and every party name start with "DEMO"; demo residents have no phone number',
        count($names) === 2 && array_filter($names, static fn($r) => !str_starts_with($r['n'], 'DEMO ') || $r['contact'] !== '') === []
        && $partyNames !== [] && array_filter($partyNames, static fn($n) => !str_starts_with($n, 'DEMO ')) === [], json_encode([$names, $partyNames]));
    dm_check('case numbers BC-DEMO-NNNN do not touch complaint_case_counters', (int)$db->query('SELECT COUNT(*) FROM complaint_case_counters')->fetch_row()[0] === 0);
    $missingRows = 0;
    foreach (($m['rows'] ?? []) as $table => $ids) {
        if ($table === 'complaint_hearing_attendance_hearing' || $ids === []) continue;
        $missingRows += count($ids) - (int)$db->query("SELECT COUNT(*) FROM `{$table}` WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')')->fetch_row()[0];
    }
    $grown = [];
    foreach ($before['counts'] as $t => $n) {
        $now = (int)$db->query("SELECT COUNT(*) FROM `{$t}`")->fetch_row()[0];
        if ($now !== $n) $grown[$t] = $now - $n;
    }
    $recorded = array_map('count', $m['rows'] ?? []);
    $attendance = (int)$db->query('SELECT COUNT(*) FROM complaint_hearing_attendance')->fetch_row()[0] - $before['counts']['complaint_hearing_attendance'];
    $unrecorded = [];
    foreach ($grown as $t => $delta) {
        $have = $t === 'complaint_hearing_attendance' ? $attendance : ($recorded[$t] ?? 0);
        if ($t !== 'complaint_hearing_attendance' && $have !== $delta) $unrecorded[$t] = [$delta, $have];
    }
    dm_check('every row the seed added is in the manifest (tables that grew = tables recorded, same counts)', $missingRows === 0 && $unrecorded === [], json_encode([$grown, $recorded]));
    if ($saveSample && !empty($m['files'])) {
        $pdf = array_values(array_filter($m['files'], static fn($f) => str_ends_with($f, '.pdf')))[0] ?? null;
        $target = $root . '/project-support/demo/sample-summons-DEMO.pdf';
        if ($pdf !== null && copy($pdf, $target)) echo "saved sample: {$target}\n";
    }

    [$code2, $out2] = dm_run([$script, '--seed', '--database=' . $scratch, '--manifest=' . $manifest]);
    dm_check('--seed a second time is refused (exit 1, nothing added)', $code2 === 1 && str_contains($out2, 'already seeded'), $out2);

    [$code3, $out3] = dm_run([$script, '--cleanup', '--database=' . $scratch, '--manifest=' . $manifest]);
    $after = $snapshot();
    dm_check('--cleanup exits 0 and removes the manifest', $code3 === 0 && !is_file($manifest), $out3);
    $diff = [];
    foreach ($before['counts'] as $t => $n) if ($after['counts'][$t] !== $n) $diff[$t] = [$n, $after['counts'][$t]];
    dm_check('row counts per table identical to before the seed (' . count($tables) . ' tables)', $diff === [], json_encode($diff));
    dm_check('file listing (with sha256) of the storage identical to before the seed', $after['files'] === $before['files'], json_encode(array_diff($after['files'], $before['files'])));
    [$code4, $out4] = dm_run([$script, '--cleanup', '--database=' . $scratch, '--manifest=' . $manifest]);
    dm_check('--cleanup without a manifest: nothing to do (exit 0)', $code4 === 0 && str_contains($out4, 'Nothing to clean up'), $out4);
    [$code5, $out5] = dm_run([$script, '--seed', '--database=' . $scratch, '--manifest=' . $manifest]);
    [$code6, $out6] = dm_run([$script, '--cleanup', '--database=' . $scratch, '--manifest=' . $manifest]);
    $again = $snapshot();
    dm_check('seed -> cleanup works a second time (same counts)', $code5 === 0 && $code6 === 0 && $again['counts'] === $before['counts'], $out5 . $out6);
    $realAfter = (int)$admin->query("SELECT COUNT(*) FROM `{$realDatabase}`.complaints")->fetch_row()[0] . '/' . (int)$admin->query("SELECT COUNT(*) FROM `{$realDatabase}`.residents")->fetch_row()[0];
    dm_check('real database untouched (complaints/residents counts)', $realAfter === $realBefore, "{$realBefore} -> {$realAfter}");
} catch (Throwable $e) {
    dm_check('test ran without an unexpected error', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    @unlink($manifest);
    foreach (glob($storage->templates . DIRECTORY_SEPARATOR . 'tpl_*.docx') ?: [] as $file) $storage->track($file);
    foreach (glob($storage->previews . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $file) $storage->track($file);
    $complaintsDir = $storage->root . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'complaints';
    if (is_dir($complaintsDir)) { foreach (glob($complaintsDir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f); @rmdir($complaintsDir); }
    if ($admin instanceof mysqli) { $admin->query("DROP DATABASE IF EXISTS `{$scratch}`"); $admin->close(); }
}
echo $passed . '/' . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
