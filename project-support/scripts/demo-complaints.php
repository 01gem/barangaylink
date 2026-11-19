<?php
/**
 * BarangayLink - Panel demo data for the complaint revision (Phase 9).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Seeds clearly labelled demo data into the CURRENT database and removes
 * exactly what it created. Everything is named "DEMO ..."; the two demo
 * residents have no phone number and cannot log in (random password).
 *
 *   php project-support/scripts/demo-complaints.php --seed       add the demo data
 *   php project-support/scripts/demo-complaints.php --cleanup    remove exactly the rows and files listed in the manifest
 *   options: --database=NAME (scratch copies, tests)  --manifest=PATH (default project-support/demo/demo-manifest.json)
 *
 * What --seed creates:
 *   - type "DEMO Boundary Dispute" (respondent required) with one select field;
 *   - one demo household and two demo residents (DEMO Maria Santos, DEMO Jose Ramos);
 *   - complaint 1 OPEN: filed online by DEMO Maria against an unregistered person;
 *   - complaint 2 INVESTIGATING: case BC-DEMO-0001, one UPCOMING session (3 days ahead, 9:00)
 *     and a summons for each of its two respondents (LibreOffice; skipped with a note if it fails);
 *   - complaint 3 RESOLVED: walk-in, case BC-DEMO-0002, one COMPLETED session (settled, public summary).
 * Case numbers BC-DEMO-NNNN never touch complaint_case_counters. No SMS is sent.
 * Every created row (table + id) and file is written to the manifest; side
 * rows made by summons generation (timeline notes, audit rows) are found by
 * id range AND demo complaint / case number, then recorded too.
 * --seed refuses when the manifest exists or demo rows are already present.
 * --cleanup deletes the manifest's rows (children first) and files, then the manifest.
 * Exit code: 0 done, 1 failed, 2 bad arguments.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$projectRoot = dirname(__DIR__, 2);
$mode = null; $database = null; $manifestPath = $projectRoot . '/project-support/demo/demo-manifest.json';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--seed' || $argument === '--cleanup') { $mode = substr($argument, 2); continue; }
    if (preg_match('/^--database=([A-Za-z0-9_]+)$/', $argument, $m)) { $database = $m[1]; continue; }
    if (preg_match('/^--manifest=(.+)$/', $argument, $m)) { $manifestPath = $m[1]; continue; }
    fwrite(STDERR, "Unknown argument: {$argument}\n"); exit(2);
}
if ($mode === null) { fwrite(STDERR, "Use --seed or --cleanup.\n"); exit(2); }
foreach (['GEMINI_API_KEY', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) { putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = ''; }

require_once $projectRoot . '/db.php';
if ($database !== null) $DB_NAME = $database;
require_once $projectRoot . '/api/common.php';
require_once $projectRoot . '/api/hearings/_summons.php';

const DEMO_TYPE_CODE = 'demo_boundary_dispute';
const DEMO_USERNAMES = ['demo-maria-santos', 'demo-jose-ramos'];
const DEMO_HOUSEHOLD = 'DEMO-HH-0001';
/** Delete order: children before parents. */
const DEMO_DELETE_ORDER = ['audit_log', 'complaint_updates', 'complaint_documents', 'complaint_hearing_attendance_hearing', 'complaint_hearings',
    'complaint_parties', 'complaints', 'complaint_type_fields', 'complaint_types', 'residents', 'households'];

function demo_exec(mysqli $db, string $sql, string $types = '', array $values = []): int {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($types !== '') $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); throw new RuntimeException($e); }
    $id = (int)$stmt->insert_id; $stmt->close();
    return $id;
}
function demo_value(mysqli $db, string $sql): mixed { $r = $db->query($sql); $row = $r ? $r->fetch_row() : null; return $row[0] ?? null; }

try {
    $db = get_db();
    $current = (string)demo_value($db, 'SELECT DATABASE()');
    echo strtoupper($mode) . " on database {$current}\n";

    if ($mode === 'cleanup') {
        if (!is_file($manifestPath)) { echo "No manifest at {$manifestPath}. Nothing to clean up.\n"; exit(0); }
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest) || ($manifest['database'] ?? null) !== $current) throw new RuntimeException('The manifest belongs to database ' . (string)($manifest['database'] ?? '?') . ', not ' . $current . '.');
        $rows = $manifest['rows'] ?? [];
        $db->begin_transaction();
        try {
            foreach (DEMO_DELETE_ORDER as $table) {
                $ids = array_map('intval', $rows[$table] ?? []);
                if ($ids === []) continue;
                $in = implode(',', $ids);
                if ($table === 'complaint_hearing_attendance_hearing') { $db->query("DELETE FROM complaint_hearing_attendance WHERE hearing_id IN ({$in})"); continue; }
                if ($table === 'residents' && !empty($rows['households'])) {
                    // The demo household points at its head (a demo resident): unlink before deleting residents.
                    $db->query('UPDATE households SET head_resident_id = NULL WHERE id IN (' . implode(',', array_map('intval', $rows['households'])) . ')');
                }
                if ($table === 'complaint_hearings') {
                    // rescheduled_from_id is RESTRICT: newer sessions first.
                    $db->query("DELETE FROM complaint_hearings WHERE id IN ({$in}) AND rescheduled_from_id IS NOT NULL");
                }
                if (!$db->query("DELETE FROM `{$table}` WHERE id IN ({$in})")) throw new RuntimeException($db->error);
                echo "deleted {$table}: " . count($ids) . "\n";
            }
            $db->commit();
        } catch (Throwable $e) { $db->rollback(); throw $e; }
        foreach ($manifest['files'] ?? [] as $file) {
            if (is_string($file) && is_file($file)) { @unlink($file); echo "deleted file {$file}\n"; }
        }
        // A folder the seed created (with its .htaccess) goes too, but only when nothing else is in it.
        foreach ($manifest['created_dirs'] ?? [] as $dir) {
            if (!is_string($dir) || !is_dir($dir)) continue;
            $left = array_values(array_diff(scandir($dir) ?: [], ['.', '..', '.htaccess']));
            if ($left !== []) { echo "kept folder {$dir} (not empty)
"; continue; }
            @unlink($dir . DIRECTORY_SEPARATOR . '.htaccess');
            @rmdir($dir);
            echo "deleted folder {$dir}
";
        }
        unlink($manifestPath);
        echo "Cleanup complete. Manifest removed.\n";
        exit(0);
    }

    // ------------------------------------------------------------------ seed
    if (is_file($manifestPath)) throw new RuntimeException("Demo data already seeded (manifest exists: {$manifestPath}). Run --cleanup first.");
    $already = (int)demo_value($db, "SELECT (SELECT COUNT(*) FROM complaint_types WHERE type_code='" . DEMO_TYPE_CODE . "')
        + (SELECT COUNT(*) FROM residents WHERE username IN ('" . implode("','", DEMO_USERNAMES) . "'))
        + (SELECT COUNT(*) FROM complaints WHERE reference_no LIKE 'DEMO-%' OR case_no LIKE 'BC-DEMO-%')");
    if ($already > 0) throw new RuntimeException('Demo rows are already present without a manifest. Remove them by hand first.');
    $official = $db->query("SELECT id, CONCAT(fname, ' ', lname) AS name FROM barangay_officials WHERE status='active' ORDER BY (role='admin') DESC, id LIMIT 1")->fetch_assoc();
    if (!$official) throw new RuntimeException('No active official to preside and generate.');
    $officialId = (int)$official['id'];

    $rows = [];
    $add = static function (string $table, int $id) use (&$rows): int { $rows[$table][] = $id; return $id; };
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $tz);
    $nowText = $now->format('Y-m-d H:i:s');
    $upcoming = $now->modify('+3 days')->setTime(9, 0);
    if ($upcoming->format('N') === '7') $upcoming = $upcoming->modify('+1 day');
    $done = $now->modify('-5 days')->setTime(14, 0);
    $fmt = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
    $filed = static fn(DateTimeImmutable $d): string => $d->format('M d, Y');

    $db->begin_transaction();
    try {
        $typeId = $add('complaint_types', demo_exec($db, "INSERT INTO complaint_types (type_code,type_name,description,respondent_mode,is_active,sort_order,created_by,updated_by)
            VALUES (?, 'DEMO Boundary Dispute', 'DEMO type for the panel walkthrough', 'required', 1, 900, ?, ?)", 'sii', [DEMO_TYPE_CODE, $officialId, $officialId]));
        $options = json_encode([['value' => 'front', 'label' => 'Front', 'is_active' => 1], ['value' => 'back', 'label' => 'Back', 'is_active' => 1], ['value' => 'side', 'label' => 'Side', 'is_active' => 1]]);
        $add('complaint_type_fields', demo_exec($db, "INSERT INTO complaint_type_fields (complaint_type_id,field_key,label,field_type,is_required,options_json,sort_order)
            VALUES (?, 'boundary_side', 'Which side of the lot', 'select', 1, ?, 10)", 'is', [$typeId, $options]));
        $household = $add('households', demo_exec($db, "INSERT INTO households (household_code,address,household_size) VALUES (?, 'DEMO Purok 2', 2)", 's', [DEMO_HOUSEHOLD]));
        $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);   // nobody knows it: demo residents cannot log in
        $maria = $add('residents', demo_exec($db, "INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id)
            VALUES ('DEMO Maria', 'Santos', ?, 'DEMO Purok 2', '', ?, 'active', ?)", 'ssi', [DEMO_USERNAMES[0], $hash, $household]));
        $jose = $add('residents', demo_exec($db, "INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id)
            VALUES ('DEMO Jose', 'Ramos', ?, 'DEMO Purok 2', '', ?, 'active', ?)", 'ssi', [DEMO_USERNAMES[1], $hash, $household]));
        $db->query("UPDATE households SET head_resident_id={$maria} WHERE id={$household}");

        $complaint = static function (string $ref, ?string $caseNo, ?int $residentId, string $by, string $status, DateTimeImmutable $on, string $desc) use ($db, $typeId, $officialId, $add): int {
            $official = $by === 'official' ? $officialId : null;
            return $add('complaints', demo_exec($db, "INSERT INTO complaints (resident_id,reference_no,case_no,resident_name,filed_by_type,filed_by_official_id,category,complaint_type_id,severity,location_text,description,date_filed,status,official_note,extra_fields_json)
                VALUES (?,?,?,?,?,?, 'DEMO Boundary Dispute', ?, 'medium', 'DEMO Purok 2, lot line', ?, ?, ?, ?, ?)", 'issssiisssss',
                [$residentId, $ref, $caseNo, $residentId ? 'DEMO Maria Santos' : 'DEMO Ana Lopez', $by, $official, $typeId, $desc, $on->format('M d, Y'), $status,
                 $status === 'resolved' ? 'Settled at mediation (DEMO).' : 'Received. Pending review.', json_encode(['boundary_side' => 'back'])]));
        };
        $party = static function (int $complaintId, string $role, ?int $residentId, string $name, int $sort) use ($db, $add): int {
            return $add('complaint_parties', demo_exec($db, "INSERT INTO complaint_parties (complaint_id,role,resident_id,full_name,address,sort_order,created_by_type)
                VALUES (?,?,?,?, 'DEMO Purok 2', ?, 'system')", 'isisi', [$complaintId, $role, $residentId, $name, $sort]));
        };
        $hearing = static function (int $complaintId, DateTimeImmutable $start, string $status, ?string $outcome, ?string $summary, array $parties, string $attendance) use ($db, $officialId, $add, $fmt): int {
            $end = $start->modify('+1 hour');
            $id = $add('complaint_hearings', demo_exec($db, "INSERT INTO complaint_hearings (complaint_id,session_no,starts_at,ends_at,venue,venue_key,presiding_official_id,status,outcome,public_summary,created_by,updated_by,completed_at)
                VALUES (?, 1, ?, ?, 'DEMO Barangay Hall', 'demo barangay hall', ?, ?, ?, ?, ?, ?, ?)", 'ississsiis',
                [$complaintId, $fmt($start), $fmt($end), $officialId, $status, $outcome, $summary, $officialId, $officialId, $status === 'completed' ? $fmt($end) : null]));
            foreach ($parties as $partyId) demo_exec($db, 'INSERT INTO complaint_hearing_attendance (hearing_id,party_id,status) VALUES (?,?,?)', 'iis', [$id, $partyId, $attendance]);
            $GLOBALS['demoAttendanceHearings'][] = $id;
            return $id;
        };

        // 1 OPEN: online, against an unregistered person.
        $c1 = $complaint('DEMO-0001', null, $maria, 'resident', 'open', $now->modify('-2 days'), 'DEMO: the neighbour started building a fence past the lot marker.');
        $party($c1, 'complainant', $maria, 'DEMO Maria Santos', 0);
        $party($c1, 'respondent', null, 'DEMO Pedro Reyes', 1);
        // 2 INVESTIGATING: upcoming session, summonses.
        $c2 = $complaint('DEMO-0002', 'BC-DEMO-0001', $maria, 'resident', 'investigating', $now->modify('-6 days'), 'DEMO: a wall was built over the shared drainage line.');
        $p2c = $party($c2, 'complainant', $maria, 'DEMO Maria Santos', 0);
        $p2a = $party($c2, 'respondent', $jose, 'DEMO Jose Ramos', 1);
        $p2b = $party($c2, 'respondent', null, 'DEMO Lito Cruz', 2);
        $h2 = $hearing($c2, $upcoming, 'scheduled', null, null, [$p2c, $p2a, $p2b], 'not_recorded');
        // 3 RESOLVED: walk-in, completed and settled.
        $c3 = $complaint('DEMO-0003', 'BC-DEMO-0002', null, 'official', 'resolved', $now->modify('-12 days'), 'DEMO: walk-in about a tree on the lot line.');
        $p3c = $party($c3, 'complainant', null, 'DEMO Ana Lopez', 0);
        $p3r = $party($c3, 'respondent', $jose, 'DEMO Jose Ramos', 1);
        $hearing($c3, $done, 'completed', 'settled', 'DEMO: both sides agreed to trim the tree and share the cost.', [$p3c, $p3r], 'present');
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    $rows['complaint_hearing_attendance_hearing'] = $GLOBALS['demoAttendanceHearings'] ?? [];

    // Summonses for the upcoming session (outside the transaction: files + their own transaction).
    $auditMark = (int)demo_value($db, 'SELECT COALESCE(MAX(id), 0) FROM audit_log');
    $updateMark = (int)demo_value($db, 'SELECT COALESCE(MAX(id), 0) FROM complaint_updates');
    $files = [];
    $notes = [];
    // A fresh install has no generated_documents/complaints yet: the first summons creates it with its deny rule.
    $createdDirs = is_dir(summons_storage_dir(false)) ? [] : [summons_storage_dir(false)];
    foreach ([$p2a, $p2b] as $partyId) {
        try {
            $result = summons_generate($db, ['hearing_id' => $h2, 'party_id' => $partyId], $officialId, (string)$official['name']);
            $docId = (int)($result['document']['id'] ?? 0);
            $rows['complaint_documents'][] = $docId;
            $uuid = (string)demo_value($db, "SELECT file_uuid FROM complaint_documents WHERE id={$docId}");
            foreach (['pdf', 'docx'] as $ext) { $path = summons_file_path($uuid, $ext); if ($path !== null) $files[] = $path; }
        } catch (Throwable $e) {
            $notes[] = 'Summons not generated for party ' . $partyId . ': ' . $e->getMessage();
        }
    }
    $demoIds = implode(',', $rows['complaints']);
    foreach ($db->query("SELECT id FROM complaint_updates WHERE id > {$updateMark} AND complaint_id IN ({$demoIds})")->fetch_all(MYSQLI_ASSOC) as $r) $rows['complaint_updates'][] = (int)$r['id'];
    foreach ($db->query("SELECT id FROM audit_log WHERE id > {$auditMark} AND target_reference IN ('BC-DEMO-0001', 'BC-DEMO-0002')")->fetch_all(MYSQLI_ASSOC) as $r) $rows['audit_log'][] = (int)$r['id'];

    if (!is_dir(dirname($manifestPath))) mkdir(dirname($manifestPath), 0700, true);
    file_put_contents($manifestPath, json_encode(['database' => $current, 'created_at' => $nowText, 'rows' => $rows, 'files' => $files, 'created_dirs' => $createdDirs, 'notes' => $notes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    foreach ($rows as $table => $ids) echo "created {$table}: " . count($ids) . "\n";
    echo 'created files: ' . count($files) . "\n";
    foreach ($notes as $note) echo "NOTE {$note}\n";
    echo "Manifest: {$manifestPath}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
