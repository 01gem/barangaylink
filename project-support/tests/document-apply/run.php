<?php
/**
 * BarangayLink - Apply confirmed placeholder suggestions tests (Phase 4A).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * No real AI call is made anywhere in this file: the provider key is blanked
 * before anything is loaded, and analyses are produced with a fake $chat.
 * Database tests use a scratch database named capstone_apply_*. Template
 * storage is a temp folder (project-support/tests/_storage.php); the real
 * document_templates/ is only read to copy the seeded templates in.
 *
 *   php project-support/tests/document-apply/run.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Must run before db.php's load_env(), which skips keys already present.
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}

$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/documents/_apply.php';
require_once $root . '/project-support/tests/_storage.php';
mysqli_report(MYSQLI_REPORT_OFF);
$storage = new TestStorage($root);
$storage->assertActive();

$prefix = 'capstone_apply_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$realDatabase = $DB_NAME;
$templateRoot = $storage->templates;
$passed = 0; $failed = 0;
$admin = null; $scratchDb = null;
$tempFiles = []; $tempDirs = []; $templateFiles = [];

const APPLY_SAMPLES = [
    'full_name' => 'Maria Luisa Santos', 'owner_name' => 'Ramon Dela Paz', 'age' => '34', 'civil_status' => 'Single',
    'address' => 'Purok 4', 'business_address' => 'Purok 2', 'business_name' => 'Dela Paz Sari-Sari Store',
    'business_type' => 'retail store', 'years_of_residency' => '12', 'purpose' => 'employment application',
    'date_issued' => 'November 13, 2026', 'reference_no' => 'REF-2026-000123',
    'official_name' => 'Hon. Ricardo Villanueva', 'official_title' => 'Barangay Captain',
];
const APPLY_SEEDED = ['barangay_clearance', 'certificate_of_residency', 'certificate_of_indigency',
    'certificate_of_good_moral_character', 'business_permit_endorsement'];
const APPLY_W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

function apply_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n"; }
}
function apply_doc(string ...$paragraphs): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . APPLY_W . '><w:body>' . implode('', $paragraphs) . '</w:body></w:document>';
}
function apply_p(string ...$runs): string { return '<w:p>' . implode('', $runs) . '</w:p>'; }
function apply_r(string $text, string $rPr = ''): string {
    return '<w:r>' . ($rPr === '' ? '' : '<w:rPr>' . $rPr . '</w:rPr>') . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</w:t></w:r>';
}
function apply_edit(int $index, int $paragraph, int $start, string $text, string $key): array {
    return ['index' => $index, 'paragraph' => $paragraph, 'start' => $start, 'text' => $text, 'key' => $key];
}
function apply_texts(string $xml): array { return document_template_paragraph_text($xml); }
function apply_xpath(string $xml): DOMXPath { return document_apply_load($xml)[1]; }
function apply_problem(callable $call): ?Throwable {
    try { $call(); } catch (Throwable $e) { return $e; }
    return null;
}
function apply_reasons(array $result): array {
    return array_map(static fn(array $row): string => $row['index'] . ':' . $row['reason'], $result['skipped']);
}
/** Same construction as analysis-accuracy.php: placeholders replaced by sample values. */
function apply_expected(string $seededXml): array {
    $map = [];
    foreach (APPLY_SAMPLES as $key => $value) $map['{{' . $key . '}}'] = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    $suggestions = [];
    foreach (document_template_paragraph_text($seededXml) as $index => $text) {
        $out = ''; $cursor = 0;
        preg_match_all('/\{\{([a-z_][a-z0-9_]*)\}\}/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $match[1][0];
            if (!isset(APPLY_SAMPLES[$key])) throw new RuntimeException('No sample value for ' . $key);
            $out .= substr($text, $cursor, $match[0][1] - $cursor);
            $suggestions[] = ['paragraph' => $index, 'start' => mb_strlen($out, 'UTF-8'), 'text' => APPLY_SAMPLES[$key], 'key' => $key,
                'occurrence' => substr_count($out, APPLY_SAMPLES[$key]) + 1];
            $out .= APPLY_SAMPLES[$key];
            $cursor = $match[0][1] + strlen($match[0][0]);
        }
    }
    return [strtr($seededXml, $map), $suggestions];
}
function apply_sample_copy(string $seeded, string $destination, string $filledXml): void {
    if (!copy($seeded, $destination)) throw new RuntimeException('sample copy failed');
    $zip = new ZipArchive();
    if ($zip->open($destination) !== true) throw new RuntimeException('sample open failed');
    $zip->addFromString('word/document.xml', $filledXml); $zip->close();
}
function apply_read_xml(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('cannot open ' . basename($path));
    $xml = $zip->getFromName('word/document.xml'); $zip->close();
    if ($xml === false) throw new RuntimeException('missing document.xml');
    return $xml;
}
function apply_snapshot(string $directory): array {
    $rows = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) $rows[basename($path)] = hash_file('sha256', $path);
    ksort($rows); return $rows;
}
function apply_database_names(mysqli $db, string $prefix): array {
    $stmt = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE CONCAT(?, "%")');
    $stmt->bind_param('s', $prefix); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return array_map(static fn(array $row): string => (string)$row['SCHEMA_NAME'], $rows);
}
function apply_drop_database(mysqli $db, string $name, string $prefix): void {
    if (str_starts_with($name, $prefix) && preg_match('/^[A-Za-z0-9_]+$/', $name)) $db->query("DROP DATABASE IF EXISTS `$name`");
}
function apply_import(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
}
function apply_count(mysqli $db, string $sql): int {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return (int)$result->fetch_row()[0];
}

$templateSnapshot = apply_snapshot($templateRoot);
$seededHashes = [];
foreach (APPLY_SEEDED as $name) $seededHashes[$name] = hash_file('sha256', $templateRoot . DIRECTORY_SEPARATOR . $name . '.docx');
$realCounts = [];

$cleanup = static function () use (&$admin, &$scratchDb, &$tempFiles, &$tempDirs, &$templateFiles, $templateRoot, $prefix, $templateSnapshot): void {
    foreach ($tempFiles as $file) @unlink($file);
    foreach ($tempDirs as $dir) remove_dir_tree($dir);
    // Files created by run() are listed in the scratch database; only names that
    // did not exist before this suite started are ever removed.
    if ($scratchDb instanceof mysqli) {
        $result = @$scratchDb->query('SELECT file_name FROM document_template_versions');
        if ($result) foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) $templateFiles[] = (string)$row['file_name'];
    }
    foreach (array_unique($templateFiles) as $file) {
        if (!isset($templateSnapshot[$file]) && preg_match('/^[a-z0-9_]+\.docx$/', $file)) @unlink($templateRoot . DIRECTORY_SEPARATOR . $file);
    }
    if ($admin instanceof mysqli) {
        try { foreach (apply_database_names($admin, $prefix) as $name) apply_drop_database($admin, $name, $prefix); } catch (Throwable $e) {}
    }
};
register_shutdown_function($cleanup);

try {
    apply_check('provider key is blank in this process', trim((string)(getenv('GEMINI_API_KEY') ?: '')) === '');

    // ------------------------------------------------------------- plan (pure)
    $stored = [
        ['paragraph' => 0, 'start' => 0, 'text' => 'Juan', 'occurrence' => 1, 'key' => 'full_name', 'reason' => 'r', 'confidence' => 0.9],
        ['paragraph' => 1, 'start' => 3, 'text' => 'Purok 4', 'occurrence' => 1, 'key' => 'address', 'reason' => 'r', 'confidence' => 0.9],
        ['paragraph' => 2, 'start' => 0, 'text' => 'x', 'occurrence' => 1, 'key' => 'retired_key', 'reason' => 'r', 'confidence' => 0.9],
    ];
    $keys = ['full_name', 'address', 'owner_name'];
    $plan = document_apply_plan($stored, [['index' => 1], ['index' => 0, 'key' => 'owner_name']], $keys);
    apply_check('plan uses stored span data and the key override', $plan === [
        apply_edit(1, 1, 3, 'Purok 4', 'address'), apply_edit(0, 0, 0, 'Juan', 'owner_name')], json_encode($plan));
    $planCases = [
        'empty selection' => [],
        'not a list' => ['a' => ['index' => 0]],
        'duplicate index' => [['index' => 0], ['index' => 0]],
        'negative index' => [['index' => -1]],
        'index past the end' => [['index' => 3]],
        'string index' => [['index' => '0']],
        'unknown override key' => [['index' => 0, 'key' => 'admin_password']],
        'null override key' => [['index' => 0, 'key' => null]],
        'stored key no longer registered' => [['index' => 2]],
        'client sends span text' => [['index' => 0, 'text' => 'Juan']],
        'client sends offsets' => [['index' => 0, 'start' => 0]],
        'selection is not an object' => [0],
        'more than 40 selections' => array_map(static fn(int $i): array => ['index' => $i], range(0, 40)),
    ];
    foreach ($planCases as $label => $selection) {
        $problem = apply_problem(static fn() => document_apply_plan($stored, $selection, $keys));
        apply_check('plan rejects ' . $label . ' with 422', $problem instanceof RequestProblem && $problem->status === 422, $problem ? $problem->getMessage() : 'accepted');
    }
    $forty = array_fill(0, 40, $stored[0]);
    apply_check('plan accepts exactly 40 selections', count(document_apply_plan($forty, array_map(static fn(int $i): array => ['index' => $i], range(0, 39)), $keys)) === 40);
    apply_check('plan rejects a non-array selections value', (apply_problem(static fn() => document_apply_plan($stored, 'all', $keys))?->status ?? 0) === 422);

    // ------------------------------------------------------------ apply (pure)
    $xml = apply_doc(apply_p(apply_r('This is to certify that Juan Cruz, of age.', '<w:b/>')));
    $one = document_apply_xml($xml, [apply_edit(0, 0, 24, 'Juan Cruz', 'full_name')]);
    apply_check('span inside one run', apply_texts($one['xml']) === ['This is to certify that {{full_name}}, of age.'] && count($one['applied']) === 1 && $one['skipped'] === []
        && apply_xpath($one['xml'])->query('//w:r')->length === 1 && apply_xpath($one['xml'])->query('//w:r/w:rPr/w:b')->length === 1);
    document_apply_verify($xml, $one['xml'], $one['applied']);
    apply_check('verify accepts the single-run result', true);

    $xml = apply_doc(apply_p(apply_r('Name: Ju'), apply_r('an Cruz end', '<w:b/>')));
    $two = document_apply_xml($xml, [apply_edit(0, 0, 6, 'Juan Cruz', 'full_name')]);
    $x = apply_xpath($two['xml']);
    apply_check('span across 2 runs: placeholder in the starting run, rest trimmed',
        apply_texts($two['xml']) === ['Name: {{full_name}} end'] && $x->query('//w:r')->length === 2
        && $x->query('//w:r[not(w:rPr)]/w:t')->item(0)?->textContent === 'Name: {{full_name}}'
        && $x->query('//w:r[w:rPr/w:b]/w:t')->item(0)?->textContent === ' end');

    $xml = apply_doc(apply_p(apply_r('Name: '), apply_r('Juan ', '<w:b/>'), apply_r('Cruz', '<w:i/>'), apply_r(' end')));
    $bold = document_apply_xml($xml, [apply_edit(0, 0, 6, 'Juan Cruz', 'full_name')]);
    $x = apply_xpath($bold['xml']);
    apply_check('span starting in a bold run: {{key}} keeps bold, consumed italic run removed',
        apply_texts($bold['xml']) === ['Name: {{full_name}} end'] && $x->query('//w:r[w:rPr/w:b]/w:t')->item(0)?->textContent === '{{full_name}}'
        && $x->query('//w:i')->length === 0 && $x->query('//w:r')->length === 3);

    $xml = apply_doc(apply_p(apply_r('A Ma', '<w:b/>'), apply_r('ria Lu', '<w:i/>'), apply_r('isa B', '<w:u w:val="single"/>')));
    $three = document_apply_xml($xml, [apply_edit(0, 0, 2, 'Maria Luisa', 'full_name')]);
    $x = apply_xpath($three['xml']);
    apply_check('span across 3 runs with different w:rPr',
        apply_texts($three['xml']) === ['A {{full_name}} B'] && $x->query('//w:r')->length === 2
        && $x->query('//w:r[w:rPr/w:b]/w:t')->item(0)?->textContent === 'A {{full_name}}'
        && $x->query('//w:r[w:rPr/w:u]/w:t')->item(0)?->textContent === ' B' && $x->query('//w:i')->length === 0);
    document_apply_verify($xml, $three['xml'], $three['applied']);

    $xml = apply_doc(apply_p(apply_r('Hello '), apply_r('Juan', '<w:b/>'), apply_r(' there')));
    $boundary = document_apply_xml($xml, [apply_edit(0, 0, 6, 'Juan', 'full_name')]);
    $x = apply_xpath($boundary['xml']);
    apply_check('span exactly on a run boundary replaces only that run text',
        apply_texts($boundary['xml']) === ['Hello {{full_name}} there'] && $x->query('//w:r')->length === 3
        && $x->query('//w:r[w:rPr/w:b]/w:t')->item(0)?->textContent === '{{full_name}}');
    $xml = apply_doc(apply_p(apply_r('Hello Juan'), apply_r(', there')));
    $endBoundary = document_apply_xml($xml, [apply_edit(0, 0, 6, 'Juan', 'full_name')]);
    apply_check('span ending at a run boundary leaves the next run alone',
        apply_texts($endBoundary['xml']) === ['Hello {{full_name}}, there'] && apply_xpath($endBoundary['xml'])->query('//w:t')->item(1)?->textContent === ', there');

    $xml = apply_doc(apply_p(apply_r('Juan Cruz, 34 years old')));
    $pair = document_apply_xml($xml, [apply_edit(0, 0, 0, 'Juan Cruz', 'full_name'), apply_edit(1, 0, 11, '34', 'age')]);
    apply_check('two spans in one paragraph (given first to last, applied last to first)',
        apply_texts($pair['xml']) === ['{{full_name}}, {{age}} years old'] && count($pair['applied']) === 2);
    document_apply_verify($xml, $pair['xml'], $pair['applied']);

    $xml = apply_doc(apply_p(apply_r('Juan met Juan today')));
    $second = document_apply_xml($xml, [apply_edit(0, 0, 9, 'Juan', 'full_name')]);
    apply_check('second occurrence is replaced, first kept', apply_texts($second['xml']) === ['Juan met {{full_name}} today']);

    $xml = apply_doc(apply_p(apply_r('Señor Niño of Purok 5')), apply_p(apply_r('Jo'), apply_r('sé Peña, é')));
    $unicode = document_apply_xml($xml, [apply_edit(0, 0, 6, 'Niño', 'full_name'), apply_edit(1, 0, 14, 'Purok 5', 'address'), apply_edit(2, 1, 0, 'José Peña', 'owner_name')]);
    apply_check('Unicode (ñ, é) offsets count characters, not bytes',
        apply_texts($unicode['xml']) === ['Señor {{full_name}} of {{address}}', '{{owner_name}}, é'] && $unicode['skipped'] === [], json_encode(apply_texts($unicode['xml']), JSON_UNESCAPED_UNICODE));
    document_apply_verify($xml, $unicode['xml'], $unicode['applied']);

    $xml = apply_doc(apply_p('<w:r><w:t>Mr Juan</w:t></w:r><w:r><w:t>Cruz here</w:t></w:r>'), apply_p('<w:r><w:t>Juan is </w:t></w:r>'));
    $spaces = document_apply_xml($xml, [apply_edit(0, 0, 3, 'JuanCruz', 'full_name'), apply_edit(1, 1, 0, 'Juan', 'full_name')]);
    $x = apply_xpath($spaces['xml']);
    $preserve = static fn(string $text): bool => (static function () use ($x, $text): bool {
        foreach ($x->query('//w:t') as $node) if ($node->textContent === $text) return $node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'space') === 'preserve';
        return false;
    })();
    apply_check('leading space gets xml:space="preserve"', $preserve(' here'));
    apply_check('trailing space gets xml:space="preserve"', $preserve('{{full_name}} is '));
    apply_check('text without edge spaces gets no new xml:space', !$preserve('Mr {{full_name}}'));

    $xml = apply_doc(apply_p(apply_r('Tom & Jerry <Co> "Juan Cruz" > end')));
    $escape = document_apply_xml($xml, [apply_edit(0, 0, 18, 'Juan Cruz', 'full_name')]);
    apply_check('surrounding & < > stay escaped',
        str_contains($escape['xml'], 'Tom &amp; Jerry &lt;Co&gt; "{{full_name}}" &gt; end') && apply_texts($escape['xml']) === ['Tom & Jerry <Co> "{{full_name}}" > end'], $escape['xml']);

    $xml = apply_doc(apply_p(apply_r('Juan Cruz')));
    $mismatch = document_apply_xml($xml, [apply_edit(4, 0, 0, 'Juan Crux', 'full_name'), apply_edit(5, 9, 0, 'Juan', 'full_name'), apply_edit(6, 0, 7, 'Cruz', 'full_name')]);
    apply_check('text mismatch, missing paragraph and wrong offset are skipped',
        apply_reasons($mismatch) === ['4:text mismatch', '5:text mismatch', '6:text mismatch'] && $mismatch['applied'] === [] && apply_texts($mismatch['xml']) === ['Juan Cruz']);

    $xml = apply_doc(apply_p(apply_r('Juan Cruz Reyes')));
    $overlap = document_apply_xml($xml, [apply_edit(0, 0, 0, 'Juan Cruz', 'full_name'), apply_edit(1, 0, 5, 'Cruz Reyes', 'owner_name')]);
    apply_check('overlapping selections: one applied, one skipped', apply_reasons($overlap) === ['0:overlaps another selection'] && apply_texts($overlap['xml']) === ['Juan {{owner_name}}']);

    $crossCases = [
        'w:tab' => [apply_p('<w:r><w:t>Juan</w:t><w:tab/><w:t>Cruz</w:t></w:r>'), 'crosses a tab or break'],
        'w:br' => [apply_p(apply_r('Juan'), '<w:r><w:br/></w:r>', apply_r('Cruz')), 'crosses a tab or break'],
        'w:cr' => [apply_p('<w:r><w:t>Juan</w:t><w:cr/><w:t>Cruz</w:t></w:r>'), 'crosses a tab or break'],
        'complex field (inside result)' => [apply_p(apply_r('Ju'), '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> DATE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>', apply_r('anCruz'), '<w:r><w:fldChar w:fldCharType="end"/></w:r>'), 'crosses a field code'],
        'complex field (spanned over)' => [apply_p(apply_r('Juan'), '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>', apply_r('Cruz')), 'crosses a field code'],
        'w:fldSimple' => [apply_p(apply_r('Ju'), '<w:fldSimple w:instr=" DATE "><w:r><w:t>anCruz</w:t></w:r></w:fldSimple>'), 'crosses a field code'],
        'hyperlink boundary' => [apply_p(apply_r('Ju'), '<w:hyperlink w:anchor="x"><w:r><w:t>anCruz</w:t></w:r></w:hyperlink>'), 'crosses a hyperlink boundary'],
        'w:sym' => [apply_p(apply_r('Juan'), '<w:r><w:sym w:font="Symbol" w:char="F0B7"/></w:r>', apply_r('Cruz')), 'crosses non-text content'],
        'w:drawing' => [apply_p(apply_r('Juan'), '<w:r><w:drawing/></w:r>', apply_r('Cruz')), 'crosses non-text content'],
        'w:noBreakHyphen' => [apply_p('<w:r><w:t>Juan</w:t><w:noBreakHyphen/><w:t>Cruz</w:t></w:r>'), 'crosses non-text content'],
    ];
    foreach ($crossCases as $label => [$paragraph, $reason]) {
        $xml = apply_doc($paragraph);
        $result = document_apply_xml($xml, [apply_edit(7, 0, 0, 'JuanCruz', 'full_name')]);
        apply_check('span crossing ' . $label . ' is skipped', apply_reasons($result) === ['7:' . $reason] && $result['applied'] === []
            && apply_texts($result['xml']) === apply_texts($xml), json_encode($result['skipped']));
    }
    $xml = apply_doc(apply_p(apply_r('Visit '), '<w:hyperlink w:anchor="x"><w:r><w:t>Juan</w:t></w:r><w:r><w:t> Cruz</w:t></w:r></w:hyperlink>'));
    $inside = document_apply_xml($xml, [apply_edit(0, 0, 6, 'Juan Cruz', 'full_name')]);
    apply_check('span entirely inside one hyperlink is applied', apply_texts($inside['xml']) === ['Visit {{full_name}}'] && $inside['skipped'] === []);
    $xml = apply_doc(apply_p(apply_r('Outer '), '<w:r><w:pict><w:txbxContent>' . apply_p(apply_r('Juan')) . '</w:txbxContent></w:pict></w:r>'), apply_p(apply_r('Body Juan')));
    $box = document_apply_xml($xml, [apply_edit(0, 0, 6, 'Juan', 'full_name'), apply_edit(1, 1, 0, 'Juan', 'full_name'), apply_edit(2, 2, 5, 'Juan', 'full_name')]);
    apply_check('paragraph containing or inside w:txbxContent is skipped, plain paragraph applied',
        apply_reasons($box) === ['0:text box', '1:text box'] && apply_texts($box['xml']) === ['Outer Juan', 'Juan', 'Body {{full_name}}'], json_encode($box['skipped']));
    apply_check('DOCTYPE in document.xml is refused', apply_problem(static fn() => document_apply_xml('<!DOCTYPE x [<!ENTITY a "b">]>' . apply_doc(apply_p(apply_r('x'))), [])) instanceof RuntimeException);

    // verify() throws on tampering.
    $xml = apply_doc(apply_p(apply_r('Juan Cruz, 34')), apply_p(apply_r('Fixed text')));
    $good = document_apply_xml($xml, [apply_edit(0, 0, 0, 'Juan Cruz', 'full_name')]);
    $tampers = [
        'renamed placeholder' => str_replace('{{full_name}}', '{{full_nam}}', $good['xml']),
        'other paragraph changed' => str_replace('Fixed text', 'Fixed text!', $good['xml']),
        'extra paragraph' => str_replace('</w:body>', apply_p(apply_r('x')) . '</w:body>', $good['xml']),
        'unparseable XML' => substr($good['xml'], 0, -20),
        'unapplied result' => $xml,
    ];
    foreach ($tampers as $label => $tampered) {
        apply_check('verify throws on ' . $label, apply_problem(static fn() => document_apply_verify($xml, $tampered, $good['applied'])) instanceof RuntimeException);
    }
    apply_check('verify throws when an applied span does not match the original',
        apply_problem(static fn() => document_apply_verify($xml, $good['xml'], [apply_edit(0, 0, 0, 'Juan Crux', 'full_name')])) instanceof RuntimeException);

    // Endpoint never passes the test-only $afterWrite seam.
    $endpointSource = (string)file_get_contents($root . '/api/documents/template_apply_suggestions.php');
    preg_match_all('/document_apply_run\s*\(([^;]*)\)\s*;/', $endpointSource, $calls);
    $arguments = isset($calls[1][0]) ? array_map('trim', explode(',', $calls[1][0])) : [];
    apply_check('endpoint calls document_apply_run exactly once with exactly five arguments (no $afterWrite seam)',
        count($calls[1]) === 1 && $arguments === ['$db', '$versionId', '$analysisId', '$selections', '$officialId']
        && !preg_match('/afterWrite|function\s*\(|fn\s*\(/', $calls[0][0]), json_encode($calls[0]));

    // --------------------------------------------- round trip, five templates
    $allowedKeys = array_keys(APPLY_SAMPLES);
    foreach (APPLY_SEEDED as $number => $name) {
        $seeded = $templateRoot . DIRECTORY_SEPARATOR . $name . '.docx';
        $seededXml = apply_read_xml($seeded);
        [$filledXml, $suggestions] = apply_expected($seededXml);
        $sample = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apply-sample-' . getmypid() . '-' . $number . '.docx';
        $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apply-result-' . getmypid() . '-' . $number . '.docx';
        $tempFiles[] = $sample; $tempFiles[] = $out; @unlink($out);
        apply_sample_copy($seeded, $sample, $filledXml);
        $sampleXml = apply_read_xml($sample);
        $edits = document_apply_plan($suggestions, array_map(static fn(int $i): array => ['index' => $i], array_keys($suggestions)), $allowedKeys);
        $result = document_apply_xml($sampleXml, $edits);
        document_apply_verify($sampleXml, $result['xml'], $result['applied']);
        document_apply_build_docx($sample, $result['xml'], $out);
        document_apply_verify_archive($sample, $out, $result['xml']);

        $zip = new ZipArchive(); $opens = $zip->open($out, ZipArchive::RDONLY) === true;
        $resultXml = $opens ? (string)$zip->getFromName('word/document.xml') : ''; if ($opens) $zip->close();
        $parses = apply_problem(static fn() => document_apply_load($resultXml)) === null;
        $uploadOk = apply_problem(static fn() => document_template_validate_upload(['error' => UPLOAD_ERR_OK, 'size' => (int)filesize($out), 'name' => 'result.docx', 'tmp_name' => $out])) === null;
        apply_check("round trip {$name}: all " . count($suggestions) . ' suggestions applied, none skipped', count($result['applied']) === count($suggestions) && $result['skipped'] === [] && count($suggestions) >= 6);
        apply_check("round trip {$name}: paragraph texts equal the seeded template", apply_texts($resultXml) === apply_texts($seededXml));
        apply_check("round trip {$name}: opens, parses, same w:p count", $opens && $parses && apply_xpath($resultXml)->query('//w:p')->length === apply_xpath($seededXml)->query('//w:p')->length);
        apply_check("round trip {$name}: passes the user-upload validation", $uploadOk);
        apply_check("round trip {$name}: document_template_check has no errors", document_template_check($out, $allowedKeys) === [], json_encode(document_template_check($out, $allowedKeys)));
    }
    $seededSame = true;
    foreach (APPLY_SEEDED as $name) if (hash_file('sha256', $templateRoot . DIRECTORY_SEPARATOR . $name . '.docx') !== $seededHashes[$name]) $seededSame = false;
    apply_check('seeded templates byte-for-byte unchanged after round trips', $seededSame);
    $tamperedArchive = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apply-tampered-' . getmypid() . '.docx'; $tempFiles[] = $tamperedArchive; @unlink($tamperedArchive);
    copy($out, $tamperedArchive);
    $zip = new ZipArchive(); $zip->open($tamperedArchive); $zip->addFromString('word/styles.xml', $zip->getFromName('word/styles.xml') . ' '); $zip->close();
    apply_check('archive verify throws when another entry changed', apply_problem(static fn() => document_apply_verify_archive($sample, $tamperedArchive, $result['xml'])) instanceof RuntimeException);
    $zip = new ZipArchive(); $zip->open($tamperedArchive); $zip->addFromString('word/extra.xml', '<x/>'); $zip->close();
    apply_check('archive verify throws when an entry is added', apply_problem(static fn() => document_apply_verify_archive($sample, $tamperedArchive, $result['xml'])) instanceof RuntimeException);

    // ------------------------------------------------------- scratch database
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_error) throw new RuntimeException('MySQL connection refused: ' . $admin->connect_error);
    $admin->set_charset('utf8mb4');
    apply_check('scratch database differs from real database', $scratch !== $realDatabase && str_starts_with($scratch, $prefix));
    foreach (['audit_log', 'document_template_versions', 'document_template_analyses'] as $table) {
        $realCounts[$table] = apply_count($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    }
    foreach (apply_database_names($admin, $prefix) as $old) apply_drop_database($admin, $old, $prefix);
    $schema = file_get_contents($root . '/database/database.sql');
    if ($schema === false) throw new RuntimeException('schema unavailable');
    if (!$admin->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $scratchDb->set_charset('utf8mb4');
    $current = (string)$scratchDb->query('SELECT DATABASE()')->fetch_row()[0];
    if ($current !== $scratch) throw new RuntimeException('scratch database assertion failed');
    apply_check('scratch database prefix asserted before writes', str_starts_with($current, $prefix));
    apply_import($scratchDb, $schema);

    $hash = password_hash('Apply-test-2026!', PASSWORD_DEFAULT);
    $stmt = $scratchDb->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES ('Scratch','Applier','apply-staff','staff','Purok 1','+639171110095',?,'Tester','active')");
    $stmt->bind_param('s', $hash); $stmt->execute(); $officialId = (int)$stmt->insert_id; $stmt->close();
    if ($officialId <= 0) throw new RuntimeException('scratch official insert failed');
    $typeId = apply_count($scratchDb, "SELECT id FROM document_types WHERE name='Barangay Clearance'");

    $seeded = $templateRoot . DIRECTORY_SEPARATOR . 'barangay_clearance.docx';
    [$filledXml, $clearanceSuggestions] = apply_expected(apply_read_xml($seeded));
    $fakeReply = (string)json_encode(['suggestions' => array_map(static fn(array $s): array => ['paragraph' => $s['paragraph'], 'text' => $s['text'],
        'occurrence' => $s['occurrence'], 'key' => $s['key'], 'reason' => 'varies', 'confidence' => 0.9], $clearanceSuggestions)], JSON_UNESCAPED_UNICODE);
    $fakeChat = static fn(...$arguments): array => ['success' => true, 'content' => $fakeReply, 'model' => 'fake-model'];
    $versionCounter = 900;
    $addDraft = static function (string $letter) use (&$templateFiles, &$versionCounter, $templateRoot, $seeded, $filledXml, $scratchDb, $typeId): array {
        $file = 'tpl_apply_test_' . getmypid() . '_' . $letter . '.docx';
        $templateFiles[] = $file;
        apply_sample_copy($seeded, $templateRoot . DIRECTORY_SEPARATOR . $file, $filledXml);
        $versionNo = max(++$versionCounter, apply_count($scratchDb, "SELECT COALESCE(MAX(version_no), 0) + 1 FROM document_template_versions WHERE document_type_id={$typeId}"));
        $versionCounter = $versionNo;
        $stmt = $scratchDb->prepare("INSERT INTO document_template_versions (document_type_id,version_no,file_name,original_name,status) VALUES (?,?,?,?,'draft')");
        $stmt->bind_param('iiss', $typeId, $versionNo, $file, $file);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $id = (int)$stmt->insert_id; $stmt->close();
        return document_template_version($scratchDb, $id);
    };
    $analyze = static fn(array $version): array => document_analysis_run($scratchDb, $version, $officialId, $fakeChat);
    $allIndexes = array_map(static fn(int $i): array => ['index' => $i], array_keys($clearanceSuggestions));
    $versionRow = static fn(int $id): array => $scratchDb->query("SELECT * FROM document_template_versions WHERE id={$id}")->fetch_assoc() ?: [];
    $versionCount = static fn(): int => apply_count($scratchDb, "SELECT COUNT(*) FROM document_template_versions WHERE document_type_id={$typeId}");
    $maxVersion = static fn(): int => apply_count($scratchDb, "SELECT MAX(version_no) FROM document_template_versions WHERE document_type_id={$typeId}");

    $draftA = $addDraft('a'); $idA = (int)$draftA['id'];
    $analysisA = $analyze($draftA);
    apply_check('fake analysis completed with all nine suggestions', $analysisA['status'] === 'completed' && count($analysisA['suggestions']) === 9, json_encode($analysisA));
    $sourceRowBefore = $versionRow($idA); $sourcePath = $templateRoot . DIRECTORY_SEPARATOR . $draftA['file_name']; $sourceHash = hash_file('sha256', $sourcePath);
    $countBefore = $versionCount(); $expectedNo = $maxVersion() + 1; $dirBefore = apply_snapshot($templateRoot);
    $applied = document_apply_run($scratchDb, $idA, (int)$analysisA['analysis_id'], $allIndexes, $officialId);
    $newRow = $versionRow((int)$applied['new_version_id']);
    $newPath = $templateRoot . DIRECTORY_SEPARATOR . ($newRow['file_name'] ?? 'missing');
    if (isset($newRow['file_name'])) $templateFiles[] = $newRow['file_name'];
    apply_check('run returns the public response shape', array_keys($applied) === ['new_version_id', 'version_no', 'applied', 'skipped', 'check_ok', 'check_errors']
        && $applied['applied'] === 9 && $applied['skipped'] === [] && $applied['check_ok'] === true && $applied['check_errors'] === [], json_encode($applied));
    apply_check('run creates exactly one new draft with the next version_no', $versionCount() === $countBefore + 1 && $applied['version_no'] === $expectedNo
        && (int)$newRow['version_no'] === $expectedNo && $newRow['status'] === 'draft' && (int)$newRow['document_type_id'] === $typeId);
    apply_check('new row metadata', $newRow['original_name'] === 'ai-assisted-from-v' . $draftA['version_no'] . '.docx'
        && $newRow['notes'] === 'AI-assisted from v' . $draftA['version_no'] . ': 9 placeholder(s) applied'
        && (int)$newRow['check_ok'] === 1 && json_decode((string)$newRow['check_report'], true) === ['errors' => []]
        && (int)$newRow['test_ok'] === 0 && (int)$newRow['created_by'] === $officialId && (int)$newRow['size_bytes'] === (int)filesize($newPath), json_encode($newRow));
    apply_check('new file has a server-generated name and the stored sha256', preg_match('/^tpl_' . $typeId . '_v' . $expectedNo . '_[a-f0-9]{8}\.docx$/', (string)$newRow['file_name']) === 1
        && is_file($newPath) && hash_file('sha256', $newPath) === $newRow['sha256']);
    apply_check('new file reproduces the seeded paragraph texts', apply_texts(apply_read_xml($newPath)) === apply_texts(apply_read_xml($seeded)));
    $dirAfter = apply_snapshot($templateRoot);
    apply_check('exactly one file added to the template directory', array_keys(array_diff_key($dirAfter, $dirBefore)) === [$newRow['file_name']] && array_diff_key($dirBefore, $dirAfter) === []);
    apply_check('source row and source file unchanged', $versionRow($idA) === $sourceRowBefore && hash_file('sha256', $sourcePath) === $sourceHash);
    $audit = $scratchDb->query("SELECT * FROM audit_log WHERE action='Applied AI placeholder suggestions' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    apply_check('audit row has counts only', count($audit) === 1 && (int)$audit[0]['official_id'] === $officialId && $audit[0]['official_name'] === 'Scratch Applier'
        && $audit[0]['target_type'] === 'document_template_version' && $audit[0]['target_reference'] === (string)$applied['new_version_id']
        && $audit[0]['details'] === 'applied=9; skipped=0; source_version=' . $idA . '; new_version=' . $applied['new_version_id'], json_encode($audit));
    // Only details can carry free text; the other columns are pinned by the check above.
    $auditBlob = implode("\n", array_column($audit, 'details')); $leak = false;
    foreach (array_merge(array_values(APPLY_SAMPLES), ['This is to certify', $draftA['file_name'], (string)$newRow['file_name'], $sourceHash, (string)$newRow['sha256']]) as $needle) {
        if (str_contains($auditBlob, $needle)) $leak = true;
    }
    apply_check('audit row holds no document text, file name or hash', !$leak);

    // One real LibreOffice test render of the round-trip result.
    $renderDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apply-render-' . getmypid(); $tempDirs[] = $renderDir;
    if (!is_dir($renderDir)) mkdir($renderDir, 0700, true);
    $newVersion = document_template_version($scratchDb, (int)$applied['new_version_id']);
    $renderFields = [];
    foreach (document_registry_fields($scratchDb, $typeId) as $field) $renderFields[$field['field_key']] = $field['data_type'] === 'int' ? '1' : 'SAMPLE';
    $files = request_render_document(['document_type' => $newVersion['type_name'], 'reference_no' => 'APPLY-TEST', 'resident_name' => 'Sample Resident',
        'address' => 'Sample Address', 'purpose' => 'Apply test'], $renderFields, $renderDir, 'APPLY-TEST', document_template_sample_context($scratchDb, $newVersion));
    $renderedXml = apply_read_xml($files['docx']);
    apply_check('one real LibreOffice render of the round-trip result', request_valid_pdf($files['pdf']) && !str_contains($renderedXml, '{{') && str_contains($renderedXml, 'SAMPLE'));
    remove_dir_tree($renderDir);

    // Partial selection with a key override and a skipped suggestion.
    $draftB = $addDraft('b'); $idB = (int)$draftB['id'];
    $analysisB = $analyze($draftB);
    $suggestionsB = json_decode((string)$scratchDb->query("SELECT suggestions FROM document_template_analyses WHERE id={$analysisB['analysis_id']}")->fetch_row()[0], true);
    $suggestionsB[1]['text'] = 'Not in the document';
    $tamperedJson = $scratchDb->real_escape_string((string)json_encode($suggestionsB, JSON_UNESCAPED_UNICODE));
    $scratchDb->query("UPDATE document_template_analyses SET suggestions='{$tamperedJson}' WHERE id={$analysisB['analysis_id']}");
    $partial = document_apply_run($scratchDb, $idB, (int)$analysisB['analysis_id'], [['index' => 0], ['index' => 1], ['index' => 2, 'key' => 'full_name']], $officialId);
    $partialRow = $versionRow((int)$partial['new_version_id']); $templateFiles[] = (string)$partialRow['file_name'];
    $partialTexts = apply_texts(apply_read_xml($templateRoot . DIRECTORY_SEPARATOR . $partialRow['file_name']));
    apply_check('partial apply reports the skipped index and reason', $partial['applied'] === 2 && $partial['skipped'] === [['index' => 1, 'reason' => 'text mismatch']]
        && $partialRow['notes'] === 'AI-assisted from v' . $draftB['version_no'] . ': 2 placeholder(s) applied', json_encode($partial));
    apply_check('key override is written into the new file', str_contains($partialTexts[7], 'Maria Luisa Santos, {{full_name}} years of age') && str_starts_with($partialTexts[5], 'Reference No.: {{reference_no}}'), (string)$partialTexts[7]);
    apply_check('check_ok reflects the registry check of the new file', $partial['check_ok'] === false && $partial['check_errors'] !== [] && (int)$partialRow['check_ok'] === 0);

    // Refusals: nothing is written.
    $refusal = static function (callable $call) use ($versionCount, $templateRoot): array {
        $before = $versionCount(); $dir = apply_snapshot($templateRoot);
        $problem = apply_problem($call);
        return [$problem, $versionCount() === $before && apply_snapshot($templateRoot) === $dir];
    };
    [$none, $clean] = $refusal(static fn() => document_apply_run($scratchDb, $idB, (int)$analysisB['analysis_id'], [['index' => 1]], $officialId));
    apply_check('none applicable: 422, no version, no file', $none instanceof RequestProblem && $none->status === 422 && $none->getMessage() === 'None of the selected suggestions could be applied.' && $clean);
    [$unknownKey, $clean] = $refusal(static fn() => document_apply_run($scratchDb, $idA, (int)$analysisA['analysis_id'], [['index' => 0, 'key' => 'official_position']], $officialId));
    apply_check('key not in registry: 422, nothing written', $unknownKey instanceof RequestProblem && $unknownKey->status === 422 && $clean);
    [$noVersion, $clean] = $refusal(static fn() => document_apply_run($scratchDb, 999999, (int)$analysisA['analysis_id'], $allIndexes, $officialId));
    apply_check('unknown version: 404', $noVersion instanceof RequestProblem && $noVersion->status === 404 && $clean);
    [$foreign, $clean] = $refusal(static fn() => document_apply_run($scratchDb, $idA, (int)$analysisB['analysis_id'], $allIndexes, $officialId));
    apply_check('analysis of another version: 404', $foreign instanceof RequestProblem && $foreign->status === 404 && $clean);
    [$noAnalysis, $clean] = $refusal(static fn() => document_apply_run($scratchDb, $idA, 999999, $allIndexes, $officialId));
    apply_check('unknown analysis: 404', $noAnalysis instanceof RequestProblem && $noAnalysis->status === 404 && $clean);

    $draftC = $addDraft('c'); $analysisC = $analyze($draftC);
    $scratchDb->query("UPDATE document_template_analyses SET file_sha256='" . str_repeat('0', 64) . "' WHERE id={$analysisC['analysis_id']}");
    [$stale, $clean] = $refusal(static fn() => document_apply_run($scratchDb, (int)$draftC['id'], (int)$analysisC['analysis_id'], $allIndexes, $officialId));
    apply_check('stale analysis refused with 409', $stale instanceof RequestProblem && $stale->status === 409 && $stale->getMessage() === 'The analysis is out of date. Analyze the document again.' && $clean);
    $draftD = $addDraft('d'); $analysisD = document_analysis_run($scratchDb, $draftD, $officialId, static fn(...$a): array => ['success' => false, 'error' => 'x']);
    [$failedAnalysis, $clean] = $refusal(static fn() => document_apply_run($scratchDb, (int)$draftD['id'], (int)$analysisD['analysis_id'], [['index' => 0]], $officialId));
    apply_check('failed analysis refused with 409', $analysisD['status'] === 'failed' && $failedAnalysis instanceof RequestProblem && $failedAnalysis->status === 409 && $failedAnalysis->getMessage() === 'The analysis is out of date. Analyze the document again.' && $clean);
    $draftE = $addDraft('e'); $analysisE = $analyze($draftE);
    $scratchDb->query("UPDATE document_template_versions SET status='discarded' WHERE id={$draftE['id']}");
    [$nonDraft, $clean] = $refusal(static fn() => document_apply_run($scratchDb, (int)$draftE['id'], (int)$analysisE['analysis_id'], $allIndexes, $officialId));
    apply_check('non-draft version refused with 409', $nonDraft instanceof RequestProblem && $nonDraft->status === 409 && $clean);

    // Forced failure after the file write: no file, no row.
    $draftF = $addDraft('f'); $analysisF = $analyze($draftF);
    $seenPath = null; $seenId = null;
    [$forced, $clean] = $refusal(static function () use ($scratchDb, $draftF, $analysisF, $allIndexes, $officialId, &$seenPath, &$seenId): void {
        document_apply_run($scratchDb, (int)$draftF['id'], (int)$analysisF['analysis_id'], $allIndexes, $officialId, static function (string $path, int $id) use (&$seenPath, &$seenId): void {
            $seenPath = $path; $seenId = $id;
            if (!is_file($path)) throw new LogicException('file was not written before the hook');
            throw new RuntimeException('forced failure after write');
        });
    });
    apply_check('forced failure after write: exception surfaces, file and row are gone',
        $forced instanceof RuntimeException && $forced->getMessage() === 'forced failure after write' && is_string($seenPath) && !is_file($seenPath)
        && apply_count($scratchDb, "SELECT COUNT(*) FROM document_template_versions WHERE id=" . (int)$seenId) === 0 && $clean);

    // Child processes: two concurrent applies, and a fatal error after the write.
    $child = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apply-child-' . getmypid() . '.php'; $tempFiles[] = $child;
    file_put_contents($child, '<?php
foreach (["GEMINI_API_KEY", "GEMINI_MODEL", "HTTPSMS_API_KEY", "HTTPSMS_FROM_NUMBER"] as $k) { putenv($k . "="); $_ENV[$k] = ""; $_SERVER[$k] = ""; }
if (PHP_SAPI !== "cli" || !str_starts_with((string)($argv[1] ?? ""), "capstone_apply_")) exit(3);
require ' . var_export($root . '/api/common.php', true) . ';
require ' . var_export($root . '/api/documents/_apply.php', true) . ';
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $argv[1]); $db->set_charset("utf8mb4");
if ((string)$db->query("SELECT DATABASE()")->fetch_row()[0] !== $argv[1]) exit(4);
$selections = json_decode($argv[5], true);
$hook = null;
if ($argv[6] === "slow") $hook = static function (): void { usleep(700000); };
if ($argv[6] === "fatal") $hook = static function (string $path, int $id): void {
    echo "WROTE " . $id . " " . $path . "\n"; fflush(STDOUT);
    ini_set("memory_limit", "64M"); $hog = [];
    while (true) $hog[] = str_repeat("x", 1048576);
};
try {
    echo json_encode(document_apply_run($db, (int)$argv[2], (int)$argv[3], $selections, (int)$argv[4], $hook)), "\n";
} catch (Throwable $e) { echo "ERROR " . get_class($e) . "\n"; exit(1); }
');
    $spawn = static function (array $version, array $analysis, string $mode) use ($child, $scratch, $officialId, $allIndexes): array {
        $command = [PHP_BINARY, '-d', 'display_errors=stderr', $child, $scratch, (string)$version['id'], (string)$analysis['analysis_id'], (string)$officialId, (string)json_encode($allIndexes), $mode];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('child process failed to start');
        fclose($pipes[0]);
        return [$process, $pipes];
    };
    $collect = static function (array $handle): array {
        [$process, $pipes] = $handle;
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['out' => (string)$out, 'err' => (string)$err, 'code' => proc_close($process)];
    };

    $draftG = $addDraft('g'); $analysisG = $analyze($draftG);
    $countBefore = $versionCount(); $maxBefore = $maxVersion();
    $first = $spawn($draftG, $analysisG, 'slow'); $secondChild = $spawn($draftG, $analysisG, 'slow');
    $runs = [$collect($first), $collect($secondChild)];
    $results = array_map(static fn(array $run): ?array => json_decode(trim($run['out']), true), $runs);
    $numbers = array_map(static fn(?array $r): int => (int)($r['version_no'] ?? 0), $results); sort($numbers);
    $concurrentFiles = [];
    foreach ($results as $r) if (is_array($r)) { $row = $versionRow((int)$r['new_version_id']); $concurrentFiles[] = (string)($row['file_name'] ?? ''); $templateFiles[] = (string)($row['file_name'] ?? ''); }
    apply_check('two concurrent applies both succeed', $runs[0]['code'] === 0 && $runs[1]['code'] === 0 && is_array($results[0]) && is_array($results[1]), json_encode($runs));
    apply_check('concurrent applies get distinct, consecutive version numbers', $numbers === [$maxBefore + 1, $maxBefore + 2] && $versionCount() === $countBefore + 2, json_encode($numbers));
    apply_check('concurrent applies get distinct files on disk', count(array_unique($concurrentFiles)) === 2 && is_file($templateRoot . DIRECTORY_SEPARATOR . $concurrentFiles[0]) && is_file($templateRoot . DIRECTORY_SEPARATOR . $concurrentFiles[1]));

    $draftH = $addDraft('h'); $analysisH = $analyze($draftH);
    $countBefore = $versionCount(); $dirBefore = apply_snapshot($templateRoot);
    $fatal = $collect($spawn($draftH, $analysisH, 'fatal'));
    $wrote = preg_match('/^WROTE (\d+) (.+\.docx)$/m', $fatal['out'], $match) === 1;
    if ($wrote) $templateFiles[] = basename($match[2]);
    apply_check('fatal child really wrote the file and then died with a fatal error', $wrote && $fatal['code'] === 255 && str_contains($fatal['out'] . $fatal['err'], 'Allowed memory size'), substr($fatal['out'] . $fatal['err'], 0, 300));
    apply_check('fatal error after write leaves no file and no row', $wrote && !is_file($match[2]) && $versionCount() === $countBefore
        && apply_count($scratchDb, 'SELECT COUNT(*) FROM document_template_versions WHERE id=' . (int)$match[1]) === 0 && apply_snapshot($templateRoot) === $dirBefore);

    $seededSame = true;
    foreach (APPLY_SEEDED as $name) if (hash_file('sha256', $templateRoot . DIRECTORY_SEPARATOR . $name . '.docx') !== $seededHashes[$name]) $seededSame = false;
    apply_check('seeded templates byte-for-byte unchanged after all runs', $seededSame);
} catch (Throwable $e) {
    apply_check('suite completed without an unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    if ($scratchDb instanceof mysqli) { @$scratchDb->close(); $scratchDb = null; }
    apply_check('template directory unchanged', apply_snapshot($templateRoot) === $templateSnapshot,
        json_encode(array_keys(array_diff_key(apply_snapshot($templateRoot), $templateSnapshot))));
    if ($admin instanceof mysqli) {
        foreach ($realCounts as $table => $count) {
            apply_check('real row count unchanged ' . $table, apply_count($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`") === $count);
        }
        apply_check('no scratch database left behind', apply_database_names($admin, $prefix) === []);
    }
}
echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
