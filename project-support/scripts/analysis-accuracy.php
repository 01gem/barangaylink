<?php
/**
 * BarangayLink - Manual accuracy check for AI placeholder analysis (Phase 3).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * MANUAL ONLY. No test suite runs this file. It makes REAL calls to the AI
 * provider configured in .env (five calls, one per seeded template).
 *
 *   php project-support/scripts/analysis-accuracy.php --confirm-external
 *   php project-support/scripts/analysis-accuracy.php --confirm-external --apply
 *
 * --apply (Phase 4A): after scoring, apply every accepted suggestion to the
 * temp copy's word/document.xml in memory (plan, apply, verify; no extra AI
 * call, no extra database write) and report per template whether the round
 * trip reproduces the seeded paragraph texts.
 *
 * What is sent: the text of the five seeded templates with every placeholder
 * replaced by FICTIONAL sample values. No resident data is read or sent.
 * What is written: a scratch database named capstone_accuracy_* (dropped at
 * the end) and temporary tpl_accuracy_* copies in document_templates/ (deleted
 * at the end). The real database is never opened.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (!in_array('--confirm-external', array_slice($argv, 1), true)) {
    fwrite(STDERR, "This script sends template text (with fictional sample values) to the AI provider.\n"
        . "Re-run with --confirm-external to proceed:\n"
        . "  php project-support/scripts/analysis-accuracy.php --confirm-external\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require_once $root . '/api/common.php';
require_once $root . '/api/documents/_analysis.php';
require_once $root . '/api/documents/_apply.php';
mysqli_report(MYSQLI_REPORT_OFF);

if (trim((string)(getenv('GEMINI_API_KEY') ?: '')) === '') {
    fwrite(STDERR, "GEMINI_API_KEY is not set in .env. Nothing was sent.\n");
    exit(1);
}

const ACCURACY_SAMPLES = [
    'full_name' => 'Maria Luisa Santos',
    'owner_name' => 'Ramon Dela Paz',
    'age' => '34',
    'civil_status' => 'Single',
    'address' => 'Purok 4',
    'business_address' => 'Purok 2',
    'business_name' => 'Dela Paz Sari-Sari Store',
    'business_type' => 'retail store',
    'years_of_residency' => '12',
    'purpose' => 'employment application',
    'date_issued' => 'November 13, 2026',
    'reference_no' => 'REF-2026-000123',
    'official_name' => 'Hon. Ricardo Villanueva',
    'official_title' => 'Barangay Captain',
];

$applyMode = in_array('--apply', array_slice($argv, 1), true);
$prefix = 'capstone_accuracy_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$templateRoot = $root . DIRECTORY_SEPARATOR . 'document_templates';
$admin = null; $db = null; $tempFiles = [];

$cleanup = static function () use (&$admin, &$tempFiles, $templateRoot, $prefix): void {
    foreach ($tempFiles as $file) @unlink($templateRoot . DIRECTORY_SEPARATOR . $file);
    if ($admin instanceof mysqli) {
        $stmt = @$admin->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE CONCAT(?, "%")');
        if ($stmt) {
            $stmt->bind_param('s', $prefix); $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $name = (string)$row['SCHEMA_NAME'];
                if (str_starts_with($name, $prefix) && preg_match('/^[A-Za-z0-9_]+$/', $name)) $admin->query("DROP DATABASE IF EXISTS `$name`");
            }
            $stmt->close();
        }
    }
};
register_shutdown_function($cleanup);

/** Replace placeholders in one paragraph; return the new text and the expected spans. */
function accuracy_expected(string $text): array {
    $out = ''; $expected = []; $cursor = 0;
    preg_match_all('/\{\{([a-z_][a-z0-9_]*)\}\}/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    foreach ($matches as $match) {
        $key = $match[1][0];
        if (!isset(ACCURACY_SAMPLES[$key])) throw new RuntimeException('No sample value for placeholder ' . $key);
        $out .= substr($text, $cursor, $match[0][1] - $cursor);
        $expected[] = ['start' => mb_strlen($out, 'UTF-8'), 'text' => ACCURACY_SAMPLES[$key], 'key' => $key];
        $out .= ACCURACY_SAMPLES[$key];
        $cursor = $match[0][1] + strlen($match[0][0]);
    }
    return [$out . substr($text, $cursor), $expected];
}

$exit = 0;
try {
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_error) throw new RuntimeException('MySQL connection refused: ' . $admin->connect_error);
    if ($scratch === $DB_NAME || !str_starts_with($scratch, $prefix)) throw new RuntimeException('Scratch database name is unsafe.');
    $schema = file_get_contents($root . '/database/database.sql');
    if ($schema === false) throw new RuntimeException('database.sql is unavailable.');
    $schema = preg_replace('/^\xEF\xBB\xBF/', '', $schema) ?? $schema;
    if (!$admin->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $db->set_charset('utf8mb4');
    if ((string)$db->query('SELECT DATABASE()')->fetch_row()[0] !== $scratch) throw new RuntimeException('Scratch database assertion failed.');
    if (!$db->multi_query($schema)) throw new RuntimeException($db->error);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);

    $hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES ('Accuracy','Script','accuracy-script','staff','Purok 1','+639171110096',?,'Tester','active')");
    $stmt->bind_param('s', $hash); $stmt->execute(); $officialId = (int)$stmt->insert_id; $stmt->close();
    if ($officialId <= 0) throw new RuntimeException('Scratch official insert failed.');

    // Phase 7: resident templates only; the internal Summons has no accuracy samples.
    $types = $db->query("SELECT id, name, template_file FROM document_types dt WHERE " . document_types_resident_sql($db) . " ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);
    $totalExpected = 0; $totalHits = 0; $totalAccepted = 0; $number = 0;
    echo "Scratch database: {$scratch}\n\n";
    foreach ($types as $type) {
        $number++;
        $seeded = document_registry_template_path((string)$type['template_file']);
        $zip = new ZipArchive();
        if ($zip->open($seeded, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot open seeded template.');
        $xml = (string)$zip->getFromName('word/document.xml'); $zip->close();

        $seededTexts = document_template_paragraph_text($xml);
        $expected = []; $expectedTexts = [];
        foreach (document_template_paragraph_text($xml) as $index => $text) {
            if (trim($text) === '') continue;
            [$newText, $spans] = accuracy_expected($text);
            $expectedTexts[$index] = $newText;
            foreach ($spans as $span) $expected[] = ['paragraph' => $index] + $span;
        }

        $tempName = 'tpl_accuracy_' . getmypid() . '_' . $number . '.docx';
        $tempPath = $templateRoot . DIRECTORY_SEPARATOR . $tempName;
        $tempFiles[] = $tempName;
        if (!copy($seeded, $tempPath)) throw new RuntimeException('Cannot create temp copy.');
        $map = [];
        foreach (ACCURACY_SAMPLES as $key => $value) $map['{{' . $key . '}}'] = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $copy = new ZipArchive();
        if ($copy->open($tempPath) !== true) throw new RuntimeException('Cannot open temp copy.');
        $copy->addFromString('word/document.xml', strtr($xml, $map)); $copy->close();
        if (array_column(document_analysis_extract($tempPath), 'text', 'index') !== $expectedTexts) {
            throw new RuntimeException('Temp copy text does not match the expected sample text for ' . $type['name']);
        }

        $versionNo = 900 + $number;
        $stmt = $db->prepare("INSERT INTO document_template_versions (document_type_id,version_no,file_name,original_name,status) VALUES (?,?,?,?,'draft')");
        $stmt->bind_param('iiss', $type['id'], $versionNo, $tempName, $tempName);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $versionId = (int)$stmt->insert_id; $stmt->close();
        $version = document_template_version($db, $versionId);

        $result = document_analysis_run($db, $version, $officialId);
        echo "== {$type['name']}\n";
        if ($result['status'] !== 'completed') {
            echo "   analysis FAILED (model call or reply); counted as zero recall.\n\n";
            $totalExpected += count($expected); $exit = 1;
            continue;
        }
        $accepted = $result['suggestions']; $used = []; $hits = 0; $misses = [];
        foreach ($expected as $want) {
            $found = false; $near = '';
            foreach ($accepted as $position => $got) {
                if ($got['paragraph'] !== $want['paragraph']) continue;
                if ($got['start'] === $want['start'] && $got['text'] === $want['text'] && $got['key'] === $want['key']) { $found = true; $used[$position] = true; break; }
                $gotEnd = $got['start'] + mb_strlen($got['text'], 'UTF-8'); $wantEnd = $want['start'] + mb_strlen($want['text'], 'UTF-8');
                if ($got['start'] < $wantEnd && $want['start'] < $gotEnd) $near = " (model gave \"{$got['text']}\" as {$got['key']})";
            }
            if ($found) $hits++; else $misses[] = "   MISS  p{$want['paragraph']} {$want['key']} = \"{$want['text']}\"{$near}";
        }
        $extras = [];
        foreach ($accepted as $position => $got) {
            if (!isset($used[$position])) $extras[] = "   EXTRA p{$got['paragraph']} {$got['key']} = \"{$got['text']}\" (confidence {$got['confidence']})";
        }
        $recall = count($expected) ? $hits / count($expected) : 1.0;
        $precision = count($accepted) ? $hits / count($accepted) : 0.0;
        printf("   expected %d, accepted %d, exact hits %d, dropped by validator %d\n", count($expected), count($accepted), $hits, $result['dropped_count']);
        printf("   recall %.2f, precision %.2f\n", $recall, $precision);
        foreach (array_merge($misses, $extras) as $line) echo $line . "\n";
        if ($applyMode) {
            try {
                $fieldKeys = array_map(static fn(array $f): string => (string)$f['field_key'], document_registry_fields($db, (int)$type['id']));
                $copyXml = strtr($xml, $map);
                $edits = $accepted ? document_apply_plan($accepted, array_map(static fn(int $i): array => ['index' => $i], array_keys($accepted)), $fieldKeys) : [];
                $appliedResult = document_apply_xml($copyXml, $edits);
                document_apply_verify($copyXml, $appliedResult['xml'], $appliedResult['applied']);
                $resultTexts = document_template_paragraph_text($appliedResult['xml']);
                $differs = 0;
                foreach ($seededTexts as $index => $text) if (($resultTexts[$index] ?? null) !== $text) $differs++;
                printf("   apply: applied %d, skipped %d, round trip %s\n", count($appliedResult['applied']), count($appliedResult['skipped']),
                    $differs === 0 ? 'REPRODUCES the seeded paragraph texts' : "DIFFERS in {$differs} paragraph(s)");
                foreach ($appliedResult['skipped'] as $skip) echo "   SKIP  #{$skip['index']} {$skip['reason']}\n";
                if ($differs !== 0) $exit = 1;
            } catch (Throwable $e) {
                echo '   apply FAILED: ' . $e->getMessage() . "\n"; $exit = 1;
            }
        }
        echo "\n";
        $totalExpected += count($expected); $totalHits += $hits; $totalAccepted += count($accepted);
    }
    printf("TOTAL recall %.2f (%d/%d), precision %.2f (%d/%d)\n",
        $totalExpected ? $totalHits / $totalExpected : 0.0, $totalHits, $totalExpected,
        $totalAccepted ? $totalHits / $totalAccepted : 0.0, $totalHits, $totalAccepted);
    echo "A hit needs the exact span, offset and key. Nothing was written to any template file"
        . ($applyMode ? " (--apply works on an in-memory copy of the temp XML).\n" : ".\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Accuracy run failed: ' . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    if ($db instanceof mysqli) @$db->close();
    $cleanup();
    echo "Cleanup done: temp copies deleted, scratch database dropped.\n";
}
exit($exit);
