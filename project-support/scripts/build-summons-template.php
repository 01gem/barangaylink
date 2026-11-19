<?php
/**
 * BarangayLink - Build Summons template version 1 (complaint revision Phase 7).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Reads document_templates/summon_letter.docx (never modified) and rewrites
 * ONLY eight paragraphs into {{placeholders}} with the manual-edit functions of
 * Phase 4C (document_manual_plan -> document_apply_xml -> verify), so every
 * placeholder sits in one run that keeps the original run formatting:
 *
 *   Barangay Case No.: ____   -> Barangay Case No.: {{case_no}}
 *   For: ____                 -> For: {{case_title}}
 *   Complainant/s: ____       -> Complainant/s: {{complainant_names}}
 *   Respondent/s: ____        -> Respondent/s: {{respondent_names}}
 *   TO: ____                  -> TO: {{respondent_to}}
 *   ... on the ____ day of ____ at ____ in the morning/afternoon in _______, then and there ...   (current letter)
 *                             -> ... on the {{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}} in {{venue}}, then and there ...
 *   ... on the ____ day of ____ at ____ in the morning/afternoon, then and there ...              (letter before 2026-11-16)
 *                             -> ... on the {{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}} at {{venue}}, then and there ...
 *   Any other wording of that sentence -> "Expected paragraph not found: You are hereby summoned ...".
 *   Issued this ____ day of ____, 20____, at ...
 *                             -> Issued this {{issued_day}} day of {{issued_month}}, {{issued_year}}, at ...
 *   Hon. Wenchilita D. Salse  -> {{official_name}}   (the renderer prints "Hon. <captain>")
 *
 * Current letter: one pass of 13 edits (11 placeholders, " in the morning/afternoon" -> "",
 * ", 20" -> ", "). Old letter: pass 1 applies 12 edits (10 placeholders,
 * " in the morning/afternoon" -> " at VENUE", ", 20" -> ", "); pass 2 turns the
 * marker VENUE into {{venue}} (manual edits refuse braces in new text). Every
 * other paragraph is verified unchanged.
 *
 * With --apply the result is stored as Summons template v1 through the
 * versions mechanism (tpl_<typeId>_v1_<hex>.docx, sha256, created_by = the 'gem'
 * admin or NULL), test-rendered (LibreOffice) and activated.
 * Idempotent: any active Summons version (v1, or a later one made in the
 * Document Templates tab) -> nothing to do; v1 draft -> resume test + activate.
 * The tests include this file with SUMMONS_BUILD_LIBRARY defined to call
 * summons_build_xml() directly; nothing below the function definitions runs then.
 *
 * Needs the 20261119 migration. Back up the database first, then:
 *   php project-support/scripts/build-summons-template.php                 dry run (default; writes nothing)
 *   php project-support/scripts/build-summons-template.php --apply         store, test render, activate v1
 * --database=NAME points the run at another schema (scratch copies, tests).
 * BARANGAY_STORAGE_ROOT (test-only, see document_storage_override) selects the template folder.
 * Exit code: 0 done / nothing to do, 1 failed, 2 bad arguments.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$projectRoot = is_file(dirname(__DIR__, 2) . '/db.php') ? dirname(__DIR__, 2) : dirname(__DIR__);

$apply = false; $database = null;
$library = defined('SUMMONS_BUILD_LIBRARY');
foreach ($library ? [] : array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; continue; }
    if (preg_match('/^--database=([A-Za-z0-9_]+)$/', $argument, $match)) { $database = $match[1]; continue; }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

require_once $projectRoot . '/db.php';
if ($database !== null) $DB_NAME = $database;
require_once $projectRoot . '/api/common.php';
require_once $projectRoot . '/api/documents/_manual_edit.php';

const SUMMONS_SOURCE_FILE = 'summon_letter.docx';
const SUMMONS_VENUE_MARK = 'VENUE';

/**
 * Original paragraph text => [expected text after the rewrite, edits].
 * $newWording: the letter with the venue blank (" in the morning/afternoon in _______").
 */
function summons_rewrites(bool $newWording = true): array {
    $single = static fn(string $prefix, int $blank, string $key): array => [
        $prefix . str_repeat('_', $blank),
        $prefix . '{{' . $key . '}}',
        [['op' => 'placeholder', 'start' => mb_strlen($prefix, 'UTF-8'), 'text' => str_repeat('_', $blank), 'key' => $key]],
    ];
    $body13 = 'You are hereby summoned to appear before me in person, together with your witnesses, on the ';
    $tail13 = ', then and there to answer a complaint made before me, a copy of which is attached hereto, for mediation/conciliation of your dispute with the complainant/s.';
    $p = mb_strlen($body13, 'UTF-8');
    $edits13 = [
        ['op' => 'placeholder', 'start' => $p, 'text' => '____', 'key' => 'hearing_day'],
        ['op' => 'placeholder', 'start' => $p + 12, 'text' => '____________', 'key' => 'hearing_month_year'],
        ['op' => 'placeholder', 'start' => $p + 28, 'text' => '____________', 'key' => 'hearing_time'],
    ];
    if ($newWording) {
        // " in the morning/afternoon" goes (hearing_time already says "in the morning"); the blank becomes {{venue}}.
        $orig13 = $body13 . '____ day of ____________ at ____________ in the morning/afternoon in _______' . $tail13;
        $after13 = $body13 . '{{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}} in {{venue}}' . $tail13;
        $edits13[] = ['op' => 'replace_text', 'start' => $p + 40, 'text' => ' in the morning/afternoon', 'new_text' => ''];
        $edits13[] = ['op' => 'placeholder', 'start' => $p + 69, 'text' => '_______', 'key' => 'venue'];
    } else {
        $orig13 = $body13 . '____ day of ____________ at ____________ in the morning/afternoon' . $tail13;
        $after13 = $body13 . '{{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}} at {{venue}}' . $tail13;
        $edits13[] = ['op' => 'replace_text', 'start' => $p + 40, 'text' => ' in the morning/afternoon', 'new_text' => ' at ' . SUMMONS_VENUE_MARK];
    }
    $tail16 = ', at Barangay Sampaguita, Tagana-an, Surigao del Norte.';
    $orig16 = 'Issued this ____ day of ____________, 20____' . $tail16;
    $edits16 = [
        ['op' => 'placeholder', 'start' => 12, 'text' => '____', 'key' => 'issued_day'],
        ['op' => 'placeholder', 'start' => 24, 'text' => '____________', 'key' => 'issued_month'],
        ['op' => 'replace_text', 'start' => 36, 'text' => ', 20', 'new_text' => ', '],
        ['op' => 'placeholder', 'start' => 40, 'text' => '____', 'key' => 'issued_year'],
    ];
    $rows = [
        $single('Barangay Case No.: ', 20, 'case_no'),
        $single('For: ', 34, 'case_title'),
        $single('Complainant/s: ', 36, 'complainant_names'),
        $single('Respondent/s: ', 36, 'respondent_names'),
        $single('TO: ', 44, 'respondent_to'),
        [$orig13, $after13, $edits13],
        [$orig16, 'Issued this {{issued_day}} day of {{issued_month}}, {{issued_year}}' . $tail16, $edits16],
        ['Hon. Wenchilita D. Salse', '{{official_name}}',
            [['op' => 'placeholder', 'start' => 0, 'text' => 'Hon. Wenchilita D. Salse', 'key' => 'official_name']]],
    ];
    $out = [];
    foreach ($rows as [$before, $after, $edits]) $out[$before] = ['after' => $after, 'edits' => $edits];
    return $out;
}

/** Pure: the rewritten word/document.xml and the paragraph indexes it touched. Throws on any surprise. */
function summons_build_xml(string $xml, array $fieldKeys): array {
    $document = document_manual_paragraphs($xml);
    $texts = array_map(static fn(array $row): string => (string)$row['text'], $document['paragraphs']);
    // The old wording is used only when the new one is absent; anything else fails below as before.
    $oldOnly = array_diff(array_keys(summons_rewrites(false)), array_keys(summons_rewrites(true)));   // the old body sentence
    $newWording = array_intersect($oldOnly, $texts) === [];
    $rewrites = summons_rewrites($newWording);
    $found = [];
    foreach ($document['paragraphs'] as $row) {
        if (!isset($rewrites[$row['text']])) continue;
        if (isset($found[$row['text']])) throw new RuntimeException('Paragraph found twice: ' . $row['text']);
        if (empty($row['editable'])) throw new RuntimeException('Paragraph is not editable: ' . $row['text']);
        $found[$row['text']] = (int)$row['index'];
    }
    foreach (array_keys($rewrites) as $before) {
        if (!isset($found[$before])) throw new RuntimeException('Expected paragraph not found: ' . mb_substr($before, 0, 60, 'UTF-8'));
    }

    // Pass 1: every blank, the old name, and the two wording changes.
    $edits = [];
    foreach ($rewrites as $before => $rewrite) {
        foreach ($rewrite['edits'] as $edit) $edits[] = ['paragraph' => $found[$before]] + $edit;
    }
    $xml1 = summons_apply_pass($xml, $edits, $fieldKeys);

    // Pass 2 (old wording only): the venue marker becomes {{venue}}.
    $xml2 = $newWording ? $xml1 : summons_venue_pass($xml1, $rewrites, $found, $fieldKeys);

    // Exact after-texts; no blank left in a rewritten paragraph; nothing else changed (verify did that per pass).
    $after = document_template_paragraph_text($xml2);
    foreach ($rewrites as $before => $rewrite) {
        $text = $after[$found[$before]] ?? null;
        if ($text !== $rewrite['after']) throw new RuntimeException('Rewritten paragraph differs: ' . (string)$text);
        if (str_contains($text, '____')) throw new RuntimeException('Blank left in: ' . $text);
    }
    return ['xml' => $xml2, 'paragraphs' => array_values($found), 'wording' => $newWording ? 'new' : 'old'];
}

function summons_venue_pass(string $xml1, array $rewrites, array $found, array $fieldKeys): string {
    $index13 = null;
    foreach ($rewrites as $before => $rewrite) if (str_contains($rewrite['after'], '{{venue}}')) $index13 = $found[$before];
    $text13 = null;
    foreach (document_manual_paragraphs($xml1)['paragraphs'] as $row) if ((int)$row['index'] === $index13) $text13 = (string)$row['text'];
    $mark = $text13 === null ? false : mb_strpos($text13, ' at ' . SUMMONS_VENUE_MARK . ',', 0, 'UTF-8');
    if ($mark === false) throw new RuntimeException('Venue marker not found after pass 1.');
    return summons_apply_pass($xml1, [['op' => 'placeholder', 'paragraph' => $index13, 'start' => $mark + 4,
        'text' => SUMMONS_VENUE_MARK, 'key' => 'venue']], $fieldKeys);
}

function summons_apply_pass(string $xml, array $edits, array $fieldKeys): string {
    $plan = document_manual_plan($edits, document_manual_paragraphs($xml), $fieldKeys);
    $result = document_apply_xml($xml, $plan);
    if ($result['skipped'] !== [] || count($result['applied']) !== count($plan)) {
        throw new RuntimeException('Edits skipped: ' . json_encode($result['skipped']));
    }
    document_apply_verify($xml, $result['xml'], $result['applied']);
    document_manual_verify_keys($xml, $result['xml'], $result['applied']);
    return $result['xml'];
}

function summons_one(mysqli $db, string $sql, array $params = []): ?array {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

if ($library) return;   // tests: functions only

$workspace = null; $created = null; $committed = false;
// exit() skips finally: the workspace (and an uncommitted new file) are removed at shutdown.
register_shutdown_function(static function () use (&$workspace, &$created, &$committed): void {
    if (!$committed && is_string($created) && is_file($created)) @unlink($created);
    if (is_string($workspace) && is_dir($workspace)) remove_dir_tree($workspace);
});
try {
    $db = get_db();
    $current = (string)$db->query('SELECT DATABASE()')->fetch_row()[0];
    echo ($apply ? 'APPLY' : 'DRY RUN (add --apply to write)') . " on database {$current}\n";

    if (!document_types_audience_ready($db) || !document_registry_active_version_table_exists($db)) {
        throw new RuntimeException('Run project-support/migrations/20261119_summons.sql first.');
    }
    $type = summons_one($db, "SELECT id, name, status, audience FROM document_types WHERE code = ? AND audience = 'internal'", [DOCUMENT_SUMMONS_CODE]);
    if ($type === null) throw new RuntimeException('Summons type is missing. Run the 20261119 migration first.');
    $typeId = (int)$type['id'];
    $fields = document_registry_fields($db, $typeId);
    $fieldKeys = array_map(static fn(array $f): string => (string)$f['field_key'], $fields);
    $required = array_values(array_map(static fn(array $f): string => (string)$f['field_key'],
        array_filter($fields, static fn(array $f): bool => (int)$f['is_required'] === 1)));
    if ($fieldKeys !== DOCUMENT_SUMMONS_FIELDS || $required !== DOCUMENT_SUMMONS_FIELDS) {
        throw new RuntimeException('Summons registry fields differ from the 20261119 migration.');
    }

    $source = document_template_root() . DIRECTORY_SEPARATOR . SUMMONS_SOURCE_FILE;
    if (!is_file($source)) throw new RuntimeException('document_templates/' . SUMMONS_SOURCE_FILE . ' is missing.');
    $shaBefore = hash_file('sha256', $source);
    echo "source sha256 before: {$shaBefore}\n";

    $v1 = summons_one($db, 'SELECT id, status, file_name, check_ok FROM document_template_versions WHERE document_type_id = ? AND version_no = 1', [$typeId]);
    $activeVersion = summons_one($db, "SELECT version_no FROM document_template_versions WHERE document_type_id = ? AND status = 'active'", [$typeId]);
    if ($activeVersion !== null) {
        echo 'Summons v' . (int)$activeVersion['version_no'] . " is already active. Nothing to do.\n";
        echo 'source sha256 after:  ' . hash_file('sha256', $source) . "\n";
        exit(0);
    }
    if ($v1 !== null && ($v1['status'] !== 'draft' || (int)$v1['check_ok'] !== 1)) {
        throw new RuntimeException("Summons v1 exists with status {$v1['status']}; resolve it in the Document Templates tab.");
    }

    if ($v1 === null) {
        $built = summons_build_xml(document_manual_read_xml($source), $fieldKeys);
        echo 'rewrote paragraphs: ' . implode(', ', $built['paragraphs']) . " ({$built['wording']} wording)\n";
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barangay-summons-build-' . bin2hex(random_bytes(8));
        if (!mkdir($workspace, 0700, true)) throw new RuntimeException('Cannot create workspace.');
        $result = $workspace . DIRECTORY_SEPARATOR . 'summons.docx';
        document_apply_build_docx($source, $built['xml'], $result);
        document_apply_verify_archive($source, $result, $built['xml']);
        $size = filesize($result);
        document_template_validate_upload(['error' => UPLOAD_ERR_OK, 'size' => $size, 'name' => 'summons.docx', 'tmp_name' => $result]);
        $errors = document_template_check($result, $fieldKeys, $required);
        if ($errors !== []) throw new RuntimeException('Template check failed: ' . implode('; ', $errors));
        $keys = [];
        foreach (document_template_paragraph_text($built['xml']) as $text) $keys = array_merge($keys, document_template_keys($text));
        sort($keys); $want = DOCUMENT_SUMMONS_FIELDS; sort($want);
        if ($keys !== $want) throw new RuntimeException('Placeholders are not exactly the 13 Summons keys once each.');
        echo "template check: OK (13 placeholders, each once)\n";

        if (!$apply) {
            echo 'source sha256 after:  ' . hash_file('sha256', $source) . "\n";
            echo "Dry run complete. Nothing was written.\n";
            exit(0);
        }

        $gem = summons_one($db, "SELECT id FROM barangay_officials WHERE username = 'gem' AND role = 'admin' LIMIT 1");
        $createdBy = $gem === null ? null : (int)$gem['id'];
        $fileName = document_template_new_file_name($typeId, 1);
        $target = document_template_root() . DIRECTORY_SEPARATOR . $fileName;
        $out = @fopen($target, 'xb');
        if ($out === false) throw new RuntimeException('Cannot create the template file.');
        $created = $target;
        $in = fopen($result, 'rb');
        $copied = $in ? stream_copy_to_stream($in, $out) : false;
        if (is_resource($in)) fclose($in);
        fclose($out);
        if ($copied !== $size) throw new RuntimeException('Cannot store the template file.');
        $sha = hash_file('sha256', $target);
        $report = json_encode(['errors' => []]);
        $original = SUMMONS_SOURCE_FILE; $notes = 'Built from summon_letter.docx (complaint revision Phase 7)';
        $status = 'draft'; $versionNo = 1; $checkOk = 1; $testOk = 0;
        $stmt = $db->prepare('INSERT INTO document_template_versions
            (document_type_id, version_no, file_name, original_name, sha256, size_bytes, status, notes, check_report, check_ok, test_ok, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException($db->error);
        $stmt->bind_param('iisssisssiii', $typeId, $versionNo, $fileName, $original, $sha, $size, $status, $notes, $report, $checkOk, $testOk, $createdBy);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
        $stmt->close();
        $committed = true; // autocommit: the row now owns the file
        echo "stored v1 as draft ({$fileName})\n";
    } elseif (!$apply) {
        echo "Summons v1 is a draft. Run with --apply to test render and activate it.\n";
        exit(0);
    }

    // Test render (LibreOffice) + activation, same checks as template_activate.php.
    $versionId = (int)summons_one($db, 'SELECT id FROM document_template_versions WHERE document_type_id = ? AND version_no = 1', [$typeId])['id'];
    document_template_test_version($db, document_template_version($db, $versionId));
    echo "test render: OK\n";
    $gem = summons_one($db, "SELECT id FROM barangay_officials WHERE username = 'gem' AND role = 'admin' LIMIT 1");
    $activatedBy = $gem === null ? null : (int)$gem['id'];
    $db->begin_transaction();
    try {
        $version = document_template_version($db, $versionId, true);
        $active = summons_one($db, "SELECT id FROM document_template_versions WHERE document_type_id = ? AND status = 'active' FOR UPDATE", [$typeId]);
        if ($active !== null) throw new RuntimeException('Another Summons version is already active.');
        $path = document_template_version_path((string)$version['file_name']);
        $sha = hash_file('sha256', $path);
        if (!$sha || !(int)$version['check_ok'] || !(int)$version['test_ok'] || $version['tested_sha256'] !== $sha
            || $version['sha256'] === null || !hash_equals((string)$version['sha256'], $sha)) {
            throw new RuntimeException('Version is not checked and tested.');
        }
        $stmt = $db->prepare("UPDATE document_template_versions SET status = 'active', activated_by = ?, activated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ii', $activatedBy, $versionId); $stmt->execute(); $stmt->close();
        $stmt = $db->prepare('UPDATE document_types SET template_file = ? WHERE id = ?');
        $file = (string)$version['file_name'];
        $stmt->bind_param('si', $file, $typeId); $stmt->execute(); $stmt->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    log_audit($db, $activatedBy, 'build-summons-template', 'Activated document template version', 'document_template_version', (string)$versionId, 'type=summons; version=1; script');
    echo "activated Summons v1 (version id {$versionId})\n";
    $shaAfter = hash_file('sha256', $source);
    echo "source sha256 after:  {$shaAfter}\n";
    if ($shaAfter !== $shaBefore) throw new RuntimeException('The source template changed during the run.');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
