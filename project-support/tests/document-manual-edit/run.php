<?php
/**
 * BarangayLink - Manual template text edit tests (Phase 4C).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * No AI is involved and the provider key is blanked anyway. Database tests use
 * a scratch database named capstone_manual_*, built from database/database.sql
 * (never copied from the real database). Template storage is a temp folder
 * (project-support/tests/_storage.php); the real document_templates/ is only
 * read to copy the seeded templates in. The endpoint test server gets only
 * the scratch database name and the temp storage root.
 *
 *   php project-support/tests/document-manual-edit/run.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}

$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/documents/_manual_edit.php';
require_once $root . '/project-support/tests/_storage.php';
mysqli_report(MYSQLI_REPORT_OFF);
$storage = new TestStorage($root);
$storage->assertActive();

$prefix = 'capstone_manual_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$realDatabase = $DB_NAME;
$templateRoot = $storage->templates;
$passed = 0; $failed = 0;
$admin = null; $scratchDb = null; $server = null;
$tempFiles = []; $tempDirs = []; $templateFiles = [];
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manual-sessions-' . getmypid();
$prepend = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manual-prepend-' . getmypid() . '.php';
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manual-server-' . getmypid();

const ME_W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
const ME_SAMPLES = [
    '{{full_name}}' => 'Maria Luisa Santos', '{{age}}' => '34', '{{civil_status}}' => 'Single', '{{address}}' => 'Purok 4',
    '{{purpose}}' => 'employment application', '{{date_issued}}' => 'November 13, 2026', '{{reference_no}}' => 'BC-2026-000123',
    '{{official_name}}' => 'Hon. Ricardo Villanueva', '{{official_title}}' => 'Barangay Captain',
];
const ME_CLEARANCE_KEYS = ['full_name', 'age', 'civil_status', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'];

function me_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n"; }
}
function me_doc(string ...$paragraphs): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . ME_W . '><w:body>' . implode('', $paragraphs) . '</w:body></w:document>';
}
function me_p(string ...$runs): string { return '<w:p>' . implode('', $runs) . '</w:p>'; }
function me_r(string $text, string $rPr = ''): string {
    return '<w:r>' . ($rPr === '' ? '' : '<w:rPr>' . $rPr . '</w:rPr>') . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</w:t></w:r>';
}
function me_texts(string $xml): array { return document_template_paragraph_text($xml); }
function me_problem(callable $call): ?Throwable {
    try { $call(); } catch (Throwable $e) { return $e; }
    return null;
}
/** The RequestProblem message of a plan call, or '' when it passed, or the class of another error. */
function me_plan_message(mixed $edits, string $xml, array $keys = ME_CLEARANCE_KEYS): string {
    $e = me_problem(static fn() => document_manual_plan($edits, document_manual_paragraphs($xml), $keys));
    if ($e === null) return '';
    return $e instanceof RequestProblem ? ($e->status . ' ' . $e->getMessage()) : get_class($e) . ': ' . $e->getMessage();
}
function me_apply(string $xml, array $edits, array $keys = ME_CLEARANCE_KEYS): array {
    $plan = document_manual_plan($edits, document_manual_paragraphs($xml), $keys);
    $result = document_apply_xml($xml, $plan);
    document_apply_verify($xml, $result['xml'], $result['applied']);
    document_manual_verify_keys($xml, $result['xml'], $result['applied']);
    return $result;
}
function me_read_xml(string $path): string { return document_manual_read_xml($path); }
function me_write_docx(string $seeded, string $destination, string $xml): void {
    if (!copy($seeded, $destination)) throw new RuntimeException('copy failed');
    $zip = new ZipArchive();
    if ($zip->open($destination) !== true) throw new RuntimeException('open failed');
    $zip->addFromString('word/document.xml', $xml); $zip->close();
}
function me_snapshot(string $directory): array {
    $rows = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) $rows[basename($path)] = hash_file('sha256', $path);
    ksort($rows); return $rows;
}
function me_count(mysqli $db, string $sql): int {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return (int)$result->fetch_row()[0];
}
function me_database_names(mysqli $db, string $prefix): array {
    $stmt = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE CONCAT(?, "%")');
    $stmt->bind_param('s', $prefix); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return array_map(static fn(array $row): string => (string)$row['SCHEMA_NAME'], $rows);
}
function me_drop_database(mysqli $db, string $name, string $prefix): void {
    if (str_starts_with($name, $prefix) && preg_match('/^[A-Za-z0-9_]+$/', $name)) $db->query("DROP DATABASE IF EXISTS `$name`");
}
function me_import(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
}
function me_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $headersRaw = substr($raw, 0, $size); $cookie = '';
    if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headersRaw, $match)) $cookie = $match[1];
    $json = json_decode(substr($raw, $size), true);
    return ['status' => $status, 'body' => substr($raw, $size), 'json' => is_array($json) ? $json : [], 'cookie' => $cookie];
}
function me_stop_server($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process); if ($state['running']) proc_terminate($process); proc_close($process);
}

$templateSnapshot = me_snapshot($templateRoot);
$seededPath = $templateRoot . DIRECTORY_SEPARATOR . 'barangay_clearance.docx';
$seededHash = hash_file('sha256', $seededPath);
$realCounts = [];

$cleanup = static function () use (&$admin, &$scratchDb, &$server, &$tempFiles, &$tempDirs, &$templateFiles, $templateRoot, $prefix, $templateSnapshot, $sessionPath, $prepend, $serverLog): void {
    me_stop_server($server); $server = null;
    foreach ($tempFiles as $file) @unlink($file);
    foreach ($tempDirs as $dir) remove_dir_tree($dir);
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath); @unlink($prepend); @unlink($serverLog . '.out'); @unlink($serverLog . '.err');
    if ($scratchDb instanceof mysqli) {
        $result = @$scratchDb->query('SELECT file_name FROM document_template_versions');
        if ($result) foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) $templateFiles[] = (string)$row['file_name'];
    }
    foreach (array_unique($templateFiles) as $file) {
        if (!isset($templateSnapshot[$file]) && preg_match('/^[a-z0-9_]+\.docx$/', $file)) @unlink($templateRoot . DIRECTORY_SEPARATOR . $file);
    }
    if ($admin instanceof mysqli) {
        try { foreach (me_database_names($admin, $prefix) as $name) me_drop_database($admin, $name, $prefix); } catch (Throwable $e) {}
    }
};
register_shutdown_function($cleanup);

try {
    me_check('provider key is blank in this process', trim((string)(getenv('GEMINI_API_KEY') ?: '')) === '');
    me_check('template storage resolves to the temp folder', strcasecmp((string)realpath(document_template_root()), (string)realpath($storage->templates)) === 0);

    // ---------------------------------------------------------- paragraphs()
    $seededXml = me_read_xml($seededPath);
    $info = document_manual_paragraphs($seededXml);
    $expectedIndexes = [];
    foreach (me_texts($seededXml) as $i => $text) if (trim($text) !== '') $expectedIndexes[$i] = $text;
    $gotIndexes = [];
    foreach ($info['paragraphs'] as $row) $gotIndexes[$row['index']] = $row['text'];
    me_check('paragraphs: same indexes and text as the analysis (blank ones omitted)', $gotIndexes === $expectedIndexes && !isset($gotIndexes[10]));
    me_check('paragraphs: seeded clearance is fully editable', array_filter($info['paragraphs'], static fn(array $r): bool => !$r['editable']) === []);
    $keysFound = array_column($info['placeholders'], 'key');
    me_check('placeholders: all nine seeded keys listed in order', $keysFound === ['reference_no', 'full_name', 'age', 'civil_status', 'address', 'purpose', 'date_issued', 'official_name', 'official_title']);
    $fullName = array_values(array_filter($info['placeholders'], static fn(array $p): bool => $p['key'] === 'full_name'))[0];
    me_check('placeholders: start is a code-point offset into the paragraph', $fullName['paragraph'] === 7 && mb_substr($gotIndexes[7], $fullName['start'], 13, 'UTF-8') === '{{full_name}}' && $fullName['removable'] === true);

    $boxXml = me_doc(
        me_p(me_r('Plain line')),
        '<w:p><w:r><w:t>Before box </w:t></w:r><w:r><w:pict><w:txbxContent><w:p><w:r><w:t>Inside box</w:t></w:r></w:p></w:txbxContent></w:pict></w:r></w:p>',
        '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText>PAGE</w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r><w:r><w:t> of pages</w:t></w:r></w:p>',
        '<w:p><w:fldSimple w:instr="DATE"><w:r><w:t>today</w:t></w:r></w:fldSimple></w:p>',
        me_p(me_r('   ')),
        me_p(me_r('Name: {{ full_name }} and {{age}}'))
    );
    $boxInfo = document_manual_paragraphs($boxXml);
    $byIndex = [];
    foreach ($boxInfo['paragraphs'] as $row) $byIndex[$row['index']] = $row;
    me_check('paragraphs: text box paragraphs (outer and inner) flagged "text box"',
        ($byIndex[1]['editable'] ?? true) === false && ($byIndex[1]['reason'] ?? '') === 'text box' && ($byIndex[2]['reason'] ?? '') === 'text box');
    me_check('paragraphs: complex field and fldSimple paragraphs flagged "field code"',
        ($byIndex[3]['reason'] ?? '') === 'field code' && ($byIndex[4]['reason'] ?? '') === 'field code' && $byIndex[3]['editable'] === false);
    me_check('paragraphs: whitespace-only paragraph omitted, index kept for the next one', !isset($byIndex[5]) && isset($byIndex[6]) && $byIndex[0]['editable'] === true);
    $spaced = array_values(array_filter($boxInfo['placeholders'], static fn(array $p): bool => $p['paragraph'] === 6));
    me_check('placeholders: spaced {{ full_name }} listed but not removable; {{age}} removable',
        count($spaced) === 2 && $spaced[0]['key'] === 'full_name' && $spaced[0]['text'] === '{{ full_name }}' && $spaced[0]['removable'] === false && $spaced[1]['removable'] === true);

    // -------------------------------------------------------- plan: shape
    $simple = me_doc(me_p(me_r('Hello Juan Dela Cruz, welcome.')), me_p(me_r('Second line {{age}} here.')));
    $ph = static fn(int $p, int $s, string $t, string $k = 'full_name'): array => ['op' => 'placeholder', 'paragraph' => $p, 'start' => $s, 'text' => $t, 'key' => $k];
    $rt = static fn(int $p, int $s, string $t, mixed $n): array => ['op' => 'replace_text', 'paragraph' => $p, 'start' => $s, 'text' => $t, 'new_text' => $n];
    $rm = static fn(int $p, int $s, string $k, mixed $n = ''): array => ['op' => 'remove_placeholder', 'paragraph' => $p, 'start' => $s, 'key' => $k, 'new_text' => $n];
    $M = static fn(string $m): string => '422 ' . $m;

    me_check('plan: empty list refused', me_plan_message([], $simple) === $M('Add at least one edit.'));
    me_check('plan: non-list refused as bad shape', me_plan_message(['a' => $ph(0, 6, 'Juan')], $simple) === $M('A version, the file hash and a list of edits are required.'));
    me_check('plan: string instead of list refused as bad shape', me_plan_message('edits', $simple) === $M('A version, the file hash and a list of edits are required.'));
    $forty = []; $longText = str_repeat('ab ', 40);
    $longDoc = me_doc(me_p(me_r($longText)));
    for ($i = 0; $i < 40; $i++) $forty[] = $rt(0, $i * 3, 'ab', 'cd');
    me_check('plan: 40 edits accepted', me_plan_message($forty, $longDoc) === '');
    $forty[] = $rt(0, 0, 'a', 'x');
    me_check('plan: 41 edits refused', me_plan_message($forty, $longDoc) === $M('Save at most 40 edits at a time.'));

    $invalid = $M('An edit is not valid.');
    me_check('plan: unknown op refused', me_plan_message([['op' => 'delete', 'paragraph' => 0, 'start' => 0, 'text' => 'H']], $simple) === $invalid);
    me_check('plan: missing op refused', me_plan_message([['paragraph' => 0, 'start' => 0, 'text' => 'H', 'key' => 'age']], $simple) === $invalid);
    me_check('plan: extra field refused', me_plan_message([$ph(0, 6, 'Juan') + ['index' => 1]], $simple) === $invalid);
    me_check('plan: missing field refused', me_plan_message([['op' => 'placeholder', 'paragraph' => 0, 'start' => 6, 'text' => 'Juan']], $simple) === $invalid);
    me_check('plan: field of another op refused', me_plan_message([$ph(0, 6, 'Juan') + ['new_text' => 'x']], $simple) === $invalid);
    me_check('plan: string paragraph refused', me_plan_message([['op' => 'placeholder', 'paragraph' => '0', 'start' => 6, 'text' => 'Juan', 'key' => 'full_name']], $simple) === $invalid);
    me_check('plan: negative start refused', me_plan_message([$ph(0, -1, 'Juan')], $simple) === $invalid);
    me_check('plan: list item instead of object refused', me_plan_message([['placeholder', 0, 6, 'Juan', 'full_name']], $simple) === $invalid);
    me_check('plan: new_text null refused as not valid', me_plan_message([$rt(0, 6, 'Juan', null)], $simple) === $invalid);
    me_check('plan: new_text number refused as not valid', me_plan_message([$rt(0, 6, 'Juan', 5)], $simple) === $invalid);
    me_check('plan: new_text array refused as not valid', me_plan_message([$rt(0, 6, 'Juan', ['x'])], $simple) === $invalid);
    me_check('plan: remove_placeholder new_text null refused as not valid', me_plan_message([$rm(1, 12, 'age', null)], $simple) === $invalid);
    me_check('plan: text that is not UTF-8 refused as not valid', me_plan_message([$ph(0, 6, "Ju\xC3")], $simple) === $invalid);
    me_check('plan: remove_placeholder key with bad format refused as not valid', me_plan_message([$rm(1, 12, 'Age')], $simple) === $invalid);

    $paragraphMsg = $M('An edit refers to a paragraph that cannot be edited.');
    me_check('plan: unknown paragraph refused', me_plan_message([$ph(9, 0, 'x')], $simple) === $paragraphMsg);
    me_check('plan: text box paragraph refused', me_plan_message([$rt(1, 0, 'Before', 'After')], $boxXml) === $paragraphMsg);
    me_check('plan: field code paragraph refused', me_plan_message([$rt(3, 1, ' of', ' for')], $boxXml) === $paragraphMsg);
    me_check('plan: blank paragraph refused', me_plan_message([$rt(5, 0, ' ', 'x')], $boxXml) === $paragraphMsg);

    // ---------------------------------------------------- plan: content rules
    $mismatch = $M('An edit no longer matches the document text. Reload and try again.');
    me_check('plan: text that does not match the span refused', me_plan_message([$ph(0, 6, 'Jose')], $simple) === $mismatch);
    me_check('plan: right text at the wrong offset refused', me_plan_message([$ph(0, 5, 'Juan')], $simple) === $mismatch);
    me_check('plan: span running past the paragraph end refused', me_plan_message([$rt(0, 25, 'welcome.!', 'x')], $simple) === $mismatch);
    me_check('plan: unregistered key refused', me_plan_message([$ph(0, 6, 'Juan', 'favorite_color')], $simple) === $M('A selected placeholder is not registered for this document type.'));
    me_check('plan: empty placeholder span refused', me_plan_message([$ph(0, 6, '')], $simple) === $M('Selected text must be 1 to 200 characters.'));
    $long201 = str_repeat('x', 201);
    $docLong = me_doc(me_p(me_r($long201 . str_repeat('y', 400))));
    me_check('plan: 200-character placeholder span accepted', me_plan_message([$ph(0, 0, str_repeat('x', 200))], $docLong) === '');
    me_check('plan: 201-character placeholder span refused', me_plan_message([$ph(0, 0, $long201)], $docLong) === $M('Selected text must be 1 to 200 characters.'));
    me_check('plan: 500-character replace span accepted', me_plan_message([$rt(0, 0, mb_substr($long201 . str_repeat('y', 400), 0, 500), 'z')], $docLong) === '');
    me_check('plan: 501-character replace span refused', me_plan_message([$rt(0, 0, mb_substr($long201 . str_repeat('y', 400), 0, 501), 'z')], $docLong) === $M('Selected text must be 1 to 500 characters.'));
    me_check('plan: empty replace span refused', me_plan_message([$rt(0, 0, '', 'z')], $simple) === $M('Selected text must be 1 to 500 characters.'));
    me_check('plan: new_text of 500 characters accepted', me_plan_message([$rt(0, 6, 'Juan', str_repeat('n', 500))], $simple) === '');
    me_check('plan: new_text of 501 characters refused', me_plan_message([$rt(0, 6, 'Juan', str_repeat('n', 501))], $simple) === $M('New text must be at most 500 characters.'));
    me_check('plan: empty new_text accepted', me_plan_message([$rt(0, 6, 'Juan ', '')], $simple) === '');
    $control = $M('New text cannot contain tabs, line breaks or control characters.');
    foreach (['tab' => "a\tb", 'line feed' => "a\nb", 'carriage return' => "a\rb", 'DEL' => "a\x7Fb", 'NUL' => "a\x00b", 'line separator' => "a\u{2028}b", 'bidi override' => "a\u{202E}b"] as $label => $value) {
        me_check("plan: new_text with {$label} refused", me_plan_message([$rt(0, 6, 'Juan', $value)], $simple) === $control);
    }
    me_check('plan: remove_placeholder new_text with a tab refused', me_plan_message([$rm(1, 12, 'age', "3\t4")], $simple) === $control);
    $braces = $M('New text cannot contain { or }.');
    // Beyond the brief's "{{" / "}}": a single brace is refused too, so no edit can form or break a placeholder.
    me_check('plan: new_text with a single "{" refused (single-brace ban)', me_plan_message([$rt(0, 6, 'Juan', 'Ju{an')], $simple) === $braces);
    me_check('plan: new_text with a single "}" refused (single-brace ban)', me_plan_message([$rt(0, 6, 'Juan', 'Ju}an')], $simple) === $braces);
    me_check('plan: new_text with "{{key}}" refused', me_plan_message([$rt(0, 6, 'Juan', '{{age}}')], $simple) === $braces);
    me_check('plan: remove_placeholder new_text with a single "{" refused (single-brace ban)', me_plan_message([$rm(1, 12, 'age', '{')], $simple) === $braces);
    me_check('plan: same text refused', me_plan_message([$rt(0, 6, 'Juan', 'Juan')], $simple) === $M('The new text is the same as the old text.'));
    me_check('plan: unicode and & < > in new_text accepted', me_plan_message([$rt(0, 6, 'Juan', 'Peña & <Co> "x"')], $simple) === '');

    $touches = $M('An edit cannot change part of an existing placeholder.');
    // "Second line {{age}} here." : {{age}} is code points 12..19.
    me_check('plan: replace_text touching the start of a placeholder refused', me_plan_message([$rt(1, 7, 'line {{', 'row')], $simple) === $touches);
    me_check('plan: replace_text touching the end of a placeholder refused', me_plan_message([$rt(1, 17, '}} here', 'x')], $simple) === $touches);
    me_check('plan: replace_text fully containing a placeholder refused', me_plan_message([$rt(1, 7, 'line {{age}} here', 'line here')], $simple) === $touches);
    me_check('plan: replace_text equal to a whole placeholder refused (only remove_placeholder removes)', me_plan_message([$rt(1, 12, '{{age}}', '34')], $simple) === $touches);
    me_check('plan: replace_text inside a placeholder refused', me_plan_message([$rt(1, 14, 'age', 'ages')], $simple) === $touches);
    me_check('plan: placeholder over an existing placeholder refused', me_plan_message([$ph(1, 7, 'line {{age}}')], $simple) === $touches);
    $stray = me_doc(me_p(me_r('Broken {{ marker and }} more')));
    me_check('plan: replace_text containing a stray "{{" refused', me_plan_message([$rt(0, 0, 'Broken {{', 'Fixed')], $stray) === $touches);
    me_check('plan: replace_text right next to a placeholder accepted (adjacent is not touching)', me_plan_message([$rt(1, 7, 'line ', 'row '), $rt(1, 19, ' here', ' there')], $simple) === '');

    $whole = $M('Only a whole placeholder such as {{key}} can be turned into text.');
    me_check('plan: remove_placeholder of {{age}} accepted', me_plan_message([$rm(1, 12, 'age', '34')], $simple) === '');
    me_check('plan: remove_placeholder at a span that is not a placeholder refused as mismatch', me_plan_message([$rm(1, 0, 'age')], $simple) === $mismatch);
    me_check('plan: remove_placeholder with the wrong key refused as mismatch', me_plan_message([$rm(1, 12, 'full_name')], $simple) === $mismatch);
    // Beyond the brief: removal works for ANY well-formed key, registered or not.
    $legacyDoc = me_doc(me_p(me_r('Legacy {{old_field}} value.')));
    me_check('plan: remove_placeholder of an unregistered key accepted (any-key remove)', me_plan_message([$rm(0, 7, 'old_field', 'x')], $legacyDoc) === '');
    me_check('plan: placeholder op with that same unregistered key still refused', me_plan_message([$ph(0, 0, 'Legacy', 'old_field')], $legacyDoc) === $M('A selected placeholder is not registered for this document type.'));
    // Beyond the brief: a spaced placeholder {{ key }} is listed but cannot be removed.
    me_check('plan: remove_placeholder of a spaced {{ full_name }} refused (spaced not removable)', me_plan_message([$rm(6, 6, 'full_name')], $boxXml) === $whole);

    $overlap = $M('Edits must not overlap.');
    me_check('plan: overlapping edits refused', me_plan_message([$ph(0, 6, 'Juan Dela'), $rt(0, 11, 'Dela Cruz', 'Santos')], $simple) === $overlap);
    me_check('plan: the same span twice refused', me_plan_message([$ph(0, 6, 'Juan'), $ph(0, 6, 'Juan', 'age')], $simple) === $overlap);
    me_check('plan: a span inside another refused', me_plan_message([$rt(0, 6, 'Juan Dela Cruz', 'X'), $ph(0, 11, 'Dela')], $simple) === $overlap);
    me_check('plan: touching but not overlapping edits accepted', me_plan_message([$ph(0, 6, 'Juan'), $rt(0, 10, ' Dela', '')], $simple) === '');
    me_check('plan: same offsets in different paragraphs accepted', me_plan_message([$rt(0, 0, 'Hello', 'Hi'), $rt(1, 0, 'Second', 'Next')], $simple) === '');

    // ------------------------------------------------------ apply + verify
    $multi = me_doc(
        me_p(me_r('Certify that '), me_r('Juan ', '<w:b/>'), me_r('Dela Cruz', '<w:b/><w:i/>'), me_r(', 34 years old.')),
        me_p(me_r('Señor Peña & <Co> lives at Purok 4.')),
        me_p(me_r('Remove me entirely')),
        me_p(me_r('Age: '), me_r('{{age}}', '<w:b/>'), me_r(' years'))
    );
    $applied = me_apply($multi, [
        $ph(0, 13, 'Juan Dela Cruz'),
        $rt(0, 27, ', 34 years old', ', thirty-four years old'),
        $ph(1, 27, 'Purok 4', 'address'),
        $rt(1, 0, 'Señor Peña & <Co>', 'Doña Peña & <Co>'),
        $rt(2, 0, 'Remove me entirely', ''),
        $rm(3, 5, 'age', 'thirty-four'),
    ]);
    $texts = me_texts($applied['xml']);
    me_check('apply: every op applied, nothing skipped', count($applied['applied']) === 6 && $applied['skipped'] === []);
    me_check('apply: multi-run bold/italic span became {{full_name}}', $texts[0] === 'Certify that {{full_name}}, thirty-four years old.', $texts[0]);
    me_check('apply: unicode (ñ) and & < > spans edited by code-point offsets', $texts[1] === 'Doña Peña & <Co> lives at {{address}}.', $texts[1]);
    me_check('apply: emptied paragraph is kept with text "" and verify passed', $texts[2] === '' && count($texts) === 4);
    me_check('apply: remove_placeholder turned {{age}} into plain text', $texts[3] === 'Age: thirty-four years');
    $xp = document_apply_load($applied['xml'])[1];
    $firstRuns = $xp->query('//w:p[1]/w:r');
    me_check('apply: placeholder kept the start run formatting (bold) and dropped the other run',
        $firstRuns->length === 3 && $xp->query('w:rPr/w:b', $firstRuns->item(1))->length === 1 && $xp->query('w:rPr/w:i', $firstRuns->item(1))->length === 0
        && $firstRuns->item(1)->textContent === '{{full_name}}');
    me_check('apply: replaced text lives in the start run (bold kept on {{age}} removal)',
        $xp->query('//w:p[4]/w:r[2]/w:rPr/w:b')->length === 1 && $xp->query('//w:p[4]/w:r[2]')->item(0)->textContent === 'thirty-four');
    me_check('apply: XML escaping is correct in the stored XML', str_contains($applied['xml'], 'Doña Peña &amp; &lt;Co&gt;'));
    me_check('apply: emptied paragraph has no w:t left', $xp->query('//w:p[3]//w:t')->length === 0 && $xp->query('//w:p')->length === 4);
    $spaces = me_apply(me_doc(me_p(me_r('A B C'))), [$rt(0, 1, ' B ', '  B  ')]);
    me_check('apply: leading/trailing spaces preserved with xml:space', me_texts($spaces['xml'])[0] === 'A  B  C' && str_contains($spaces['xml'], 'xml:space="preserve"'));
    $emptiedWhole = me_apply(me_doc(me_p(me_r('Only '), me_r('words', '<w:b/>')), me_p(me_r('Next'))), [$rt(0, 0, 'Only words', '')]);
    me_check('apply: multi-run paragraph emptied by replace_text "" passes verify (expected "")', me_texts($emptiedWhole['xml']) === ['', 'Next']);

    $tabDoc = me_doc('<w:p><w:r><w:t>Left</w:t><w:tab/><w:t>Right</w:t></w:r></w:p>', me_p(me_r('Fine text')));
    $tabPlan = document_manual_plan([$rt(0, 2, 'ftRi', 'x'), $rt(1, 0, 'Fine', 'Good')], document_manual_paragraphs($tabDoc), ME_CLEARANCE_KEYS);
    $tabResult = document_apply_xml($tabDoc, $tabPlan);
    me_check('apply: span crossing a tab is skipped with its reason, the rest applied',
        array_map(static fn(array $s): string => $s['index'] . ':' . $s['reason'], $tabResult['skipped']) === ['0:crosses a tab or break'] && count($tabResult['applied']) === 1);

    // round trip on the real seeded clearance: remove then re-add
    $rtStep = me_apply($seededXml, [$rm(7, $fullName['start'], 'full_name', 'Maria Santos')]);
    $rtInfo = document_manual_paragraphs($rtStep['xml']);
    $rtText = '';
    foreach ($rtInfo['paragraphs'] as $row) if ($row['index'] === 7) $rtText = $row['text'];
    $back = me_apply($rtStep['xml'], [$ph(7, $fullName['start'], 'Maria Santos')]);
    me_check('round trip: remove_placeholder then placeholder restores the seeded paragraph texts', me_texts($back['xml']) === me_texts($seededXml) && str_contains($rtText, 'Maria Santos'));
    me_check('round trip: document_template_check is clean again', (static function () use ($seededPath, $back, &$tempFiles): bool {
        $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manual-roundtrip-' . getmypid() . '.docx'; $tempFiles[] = $out; @unlink($out);
        document_apply_build_docx($seededPath, $back['xml'], $out);
        return document_template_check($out, ME_CLEARANCE_KEYS) === [];
    })());

    // verify-step tamper
    $tamperPlan = document_manual_plan([$rt(0, 6, 'Juan', 'Jose')], document_manual_paragraphs($simple), ME_CLEARANCE_KEYS);
    $tamperResult = document_apply_xml($simple, $tamperPlan);
    me_check('verify: tampered new XML (other paragraph changed) throws',
        me_problem(static fn() => document_apply_verify($simple, str_replace('Second line', 'Secnd line', $tamperResult['xml']), $tamperResult['applied'])) instanceof RuntimeException);
    me_check('verify: tampered new XML (edited paragraph differs) throws',
        me_problem(static fn() => document_apply_verify($simple, str_replace('Jose', 'Josh', $tamperResult['xml']), $tamperResult['applied'])) instanceof RuntimeException);
    me_check('verify: an added placeholder that was not requested throws',
        me_problem(static fn() => document_manual_verify_keys($simple, str_replace('welcome.', 'welcome {{purpose}}.', $tamperResult['xml']), $tamperResult['applied'])) instanceof RuntimeException);
    me_check('verify: the untampered result passes both checks',
        me_problem(static function () use ($simple, $tamperResult): void {
            document_apply_verify($simple, $tamperResult['xml'], $tamperResult['applied']);
            document_manual_verify_keys($simple, $tamperResult['xml'], $tamperResult['applied']);
        }) === null);

    // ---------------------------------------------------- scratch database
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_error) throw new RuntimeException('MySQL connection refused: ' . $admin->connect_error);
    $admin->set_charset('utf8mb4');
    me_check('scratch database differs from real database', $scratch !== $realDatabase && str_starts_with($scratch, $prefix));
    // Read-only guard counts on the real database (approved); never a write.
    foreach (['audit_log', 'document_template_versions'] as $table) $realCounts[$table] = me_count($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    foreach (me_database_names($admin, $prefix) as $old) me_drop_database($admin, $old, $prefix);
    $schema = file_get_contents($root . '/database/database.sql');
    if ($schema === false) throw new RuntimeException('schema unavailable');
    if (!$admin->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $scratchDb->set_charset('utf8mb4');
    if ((string)$scratchDb->query('SELECT DATABASE()')->fetch_row()[0] !== $scratch) throw new RuntimeException('scratch database assertion failed');
    me_check('scratch database prefix asserted before writes', str_starts_with((string)$scratchDb->query('SELECT DATABASE()')->fetch_row()[0], $prefix));
    me_import($scratchDb, $schema);
    $password = 'Manual-test-2026!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $scratchDb->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES ('Scratch','Editor','manual-staff','staff','Purok 1','+639171110097',?,'Tester','active')");
    $stmt->bind_param('s', $hash); $stmt->execute(); $officialId = (int)$stmt->insert_id; $stmt->close();
    if ($officialId <= 0) throw new RuntimeException('scratch official insert failed');
    $typeId = me_count($scratchDb, "SELECT id FROM document_types WHERE name='Barangay Clearance'");
    $filledXml = strtr($seededXml, array_map(static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8'), ME_SAMPLES));

    $counter = 0;
    $addDraft = static function (string $xml, string $status = 'draft') use (&$templateFiles, &$counter, $templateRoot, $seededPath, $scratchDb, $typeId): array {
        $counter++;
        $file = 'tpl_manual_test_' . getmypid() . '_' . $counter . '.docx';
        $templateFiles[] = $file;
        me_write_docx($seededPath, $templateRoot . DIRECTORY_SEPARATOR . $file, $xml);
        $versionNo = me_count($scratchDb, "SELECT COALESCE(MAX(version_no), 0) + 1 FROM document_template_versions WHERE document_type_id={$typeId}");
        $stmt = $scratchDb->prepare('INSERT INTO document_template_versions (document_type_id,version_no,file_name,original_name,status) VALUES (?,?,?,?,?)');
        $stmt->bind_param('iisss', $typeId, $versionNo, $file, $file, $status);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $id = (int)$stmt->insert_id; $stmt->close();
        $version = document_template_version($scratchDb, $id);
        $version['_sha'] = hash_file('sha256', $templateRoot . DIRECTORY_SEPARATOR . $file);
        return $version;
    };
    $versionRow = static fn(int $id): array => $scratchDb->query("SELECT * FROM document_template_versions WHERE id={$id}")->fetch_assoc() ?: [];
    $versionCount = static fn(): int => me_count($scratchDb, "SELECT COUNT(*) FROM document_template_versions WHERE document_type_id={$typeId}");
    $refusal = static function (callable $call) use ($versionCount, $templateRoot): array {
        $before = $versionCount(); $dir = me_snapshot($templateRoot);
        $problem = me_problem($call);
        return [$problem, $versionCount() === $before && me_snapshot($templateRoot) === $dir];
    };

    // happy path on a filled clearance draft: all three ops
    $draftA = $addDraft($filledXml); $idA = (int)$draftA['id'];
    $filledInfo = document_manual_paragraphs($filledXml);
    $p7 = ''; foreach ($filledInfo['paragraphs'] as $row) if ($row['index'] === 7) $p7 = $row['text'];
    $nameStart = mb_strpos($p7, 'Maria Luisa Santos', 0, 'UTF-8');
    $ageStart = mb_strpos($p7, ', 34 years', 0, 'UTF-8');
    $sourceRowBefore = $versionRow($idA); $sourcePath = $templateRoot . DIRECTORY_SEPARATOR . $draftA['file_name'];
    $countBefore = $versionCount(); $expectedNo = me_count($scratchDb, "SELECT MAX(version_no) FROM document_template_versions WHERE document_type_id={$typeId}") + 1;
    $dirBefore = me_snapshot($templateRoot);
    $edits = [
        $ph(7, $nameStart, 'Maria Luisa Santos'),
        $rt(7, $ageStart, ', 34 years', ', Peña & <34> years'),
        $ph(11, 0, 'Hon. Ricardo Villanueva', 'official_name'),
    ];
    $saved = document_manual_edit_run($scratchDb, $idA, $draftA['_sha'], $edits, $officialId);
    $newRow = $versionRow((int)$saved['new_version_id']);
    $newPath = $templateRoot . DIRECTORY_SEPARATOR . ($newRow['file_name'] ?? 'missing');
    if (isset($newRow['file_name'])) $templateFiles[] = $newRow['file_name'];
    me_check('save: response shape matches apply', array_keys($saved) === ['new_version_id', 'version_no', 'applied', 'skipped', 'check_ok', 'check_errors']
        && $saved['applied'] === 3 && $saved['skipped'] === [] && is_bool($saved['check_ok']) && is_array($saved['check_errors']));
    me_check('save: new row is a draft with the next version number', ($newRow['status'] ?? '') === 'draft' && (int)$newRow['version_no'] === $expectedNo && $saved['version_no'] === $expectedNo && $versionCount() === $countBefore + 1);
    me_check('save: notes and original name describe a manual edit', $newRow['notes'] === 'Manual edits from v' . $draftA['version_no'] . ': 3 edit(s) applied' && $newRow['original_name'] === 'manual-edit-from-v' . $draftA['version_no'] . '.docx');
    me_check('save: new row hash equals the new file and check report is stored', $newRow['sha256'] === hash_file('sha256', $newPath) && (int)$newRow['test_ok'] === 0 && $newRow['check_report'] !== null);
    me_check('save: source row unchanged', $versionRow($idA) == $sourceRowBefore);
    me_check('save: source file byte-for-byte unchanged', hash_file('sha256', $sourcePath) === $draftA['_sha']);
    $dirAfter = me_snapshot($templateRoot);
    me_check('save: exactly one file added to template storage', array_keys(array_diff_key($dirAfter, $dirBefore)) === [$newRow['file_name']] && array_diff_key($dirBefore, $dirAfter) === []);
    $newTexts = me_texts(me_read_xml($newPath)); $oldTexts = me_texts($filledXml);
    me_check('save: edited paragraphs hold the expected text', $newTexts[7] === 'This is to certify that {{full_name}}, Peña & <34> years of age, Single, is a bona fide resident of Purok 4, Barangay Sampaguita.' && $newTexts[11] === '{{official_name}}', $newTexts[7]);
    $others = true;
    foreach ($oldTexts as $i => $text) if (!in_array($i, [7, 11], true) && $newTexts[$i] !== $text) $others = false;
    me_check('save: every other paragraph identical', $others && count($newTexts) === count($oldTexts));
    me_check('save: check report matches the new file', $saved['check_errors'] === document_template_check($newPath, ME_CLEARANCE_KEYS, array_values(array_map(static fn(array $f): string => (string)$f['field_key'], array_filter(document_registry_fields($scratchDb, $typeId), static fn(array $f): bool => (int)$f['is_required'] === 1)))));
    $audit = $scratchDb->query("SELECT * FROM audit_log WHERE action='Edited template text manually'")->fetch_all(MYSQLI_ASSOC);
    me_check('save: one audit row with op counts and versions only', count($audit) === 1 && $audit[0]['target_reference'] === (string)$saved['new_version_id']
        && $audit[0]['details'] === 'placeholder=2; replace_text=1; remove_placeholder=0; skipped=0; source_version=' . $idA . '; new_version=' . $saved['new_version_id'], json_encode($audit));
    $auditText = json_encode($audit, JSON_UNESCAPED_UNICODE);
    me_check('save: audit row has no document text, file name or hash', !str_contains($auditText, 'Maria') && !str_contains($auditText, 'Peña') && !str_contains($auditText, (string)$newRow['file_name']) && !str_contains($auditText, (string)$newRow['sha256']));

    // remove_placeholder on the real seeded template + any-key remove in a save
    $legacyXml = str_replace('{{official_title}}', '{{old_title_field}}', $seededXml);
    $draftB = $addDraft($legacyXml);
    $legacyInfo = document_manual_paragraphs($legacyXml);
    $legacyAt = array_values(array_filter($legacyInfo['placeholders'], static fn(array $p): bool => $p['key'] === 'old_title_field'))[0];
    $savedB = document_manual_edit_run($scratchDb, (int)$draftB['id'], $draftB['_sha'], [
        $rm($legacyAt['paragraph'], $legacyAt['start'], 'old_title_field', 'Barangay Captain'),
        $rm(7, $fullName['start'], 'full_name', 'Juan Dela Cruz'),
    ], $officialId);
    $rowB = $versionRow((int)$savedB['new_version_id']); $templateFiles[] = (string)$rowB['file_name'];
    $textsB = me_texts(me_read_xml($templateRoot . DIRECTORY_SEPARATOR . $rowB['file_name']));
    me_check('save: unregistered {{old_title_field}} removed (any-key remove) and seeded {{full_name}} removed',
        $savedB['applied'] === 2 && $textsB[12] === 'Barangay Captain' && str_contains($textsB[7], 'certify that Juan Dela Cruz, {{age}}'));
    me_check('save: audit counts remove_placeholder ops', (string)$scratchDb->query("SELECT details FROM audit_log WHERE target_reference='" . (int)$savedB['new_version_id'] . "'")->fetch_row()[0]
        === 'placeholder=0; replace_text=0; remove_placeholder=2; skipped=0; source_version=' . $draftB['id'] . '; new_version=' . $savedB['new_version_id']);
    me_check('save: works with no analysis at all (none exists for these versions)', me_count($scratchDb, 'SELECT COUNT(*) FROM document_template_analyses') === 0);

    // a save of a save: the new draft is itself editable
    $chainSha = hash_file('sha256', $templateRoot . DIRECTORY_SEPARATOR . $rowB['file_name']);
    $savedC = document_manual_edit_run($scratchDb, (int)$savedB['new_version_id'], $chainSha, [$ph(7, $fullName['start'], 'Juan Dela Cruz')], $officialId);
    $templateFiles[] = (string)$versionRow((int)$savedC['new_version_id'])['file_name'];
    me_check('save: a manually edited draft can be edited again', $savedC['applied'] === 1 && $savedC['version_no'] === $savedB['version_no'] + 1);

    // partial: one skipped span, the rest saved
    $tabDraft = $addDraft(str_replace('<w:t>Republic of the Philippines</w:t>', '<w:t>Republic of</w:t><w:tab/><w:t>the Philippines</w:t>', $seededXml));
    $tabDraftXml = me_read_xml($templateRoot . DIRECTORY_SEPARATOR . $tabDraft['file_name']);
    me_check('fixture: tab draft really contains a tab in paragraph 0', str_contains($tabDraftXml, '<w:tab/>') && me_texts($tabDraftXml)[0] === 'Republic ofthe Philippines');
    $partial = document_manual_edit_run($scratchDb, (int)$tabDraft['id'], $tabDraft['_sha'], [
        $rt(0, 9, 'ofthe', 'of the'),
        $rt(4, 0, 'OFFICE', 'Office'),
    ], $officialId);
    $templateFiles[] = (string)$versionRow((int)$partial['new_version_id'])['file_name'];
    me_check('save: crossing span skipped with reason, other edit saved', $partial['applied'] === 1 && $partial['skipped'] === [['index' => 0, 'reason' => 'crosses a tab or break']]);
    [$none, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$tabDraft['id'], $tabDraft['_sha'], [$rt(0, 9, 'ofthe', 'of the')], $officialId));
    me_check('save: nothing applicable -> 422 with skipped list, no row, no file',
        $none instanceof RequestProblem && $none->status === 422 && $none->getMessage() === 'None of the edits could be applied.'
        && ($none->extra['skipped'] ?? null) === [['index' => 0, 'reason' => 'crosses a tab or break']] && $clean);

    // refusals: nothing written
    $draftR = $addDraft($filledXml);
    [$stale, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$draftR['id'], str_repeat('0', 64), [$ph(7, $nameStart, 'Maria Luisa Santos')], $officialId));
    me_check('refuse: stale sha -> 409 "The template changed. Reload and try again."', $stale instanceof RequestProblem && $stale->status === 409 && $stale->getMessage() === 'The template changed. Reload and try again.' && $clean);
    [$badSha, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$draftR['id'], strtoupper($draftR['_sha']), [$ph(7, $nameStart, 'Maria Luisa Santos')], $officialId));
    me_check('refuse: malformed sha (uppercase) -> 422 shape', $badSha instanceof RequestProblem && $badSha->status === 422 && $badSha->getMessage() === 'A version, the file hash and a list of edits are required.' && $clean);
    [$nullSha, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$draftR['id'], null, [$ph(7, $nameStart, 'Maria Luisa Santos')], $officialId));
    me_check('refuse: missing sha -> 422 shape', $nullSha instanceof RequestProblem && $nullSha->status === 422 && $clean);
    [$unknown, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, 999999, $draftR['_sha'], [$ph(7, $nameStart, 'Maria Luisa Santos')], $officialId));
    me_check('refuse: unknown version -> 404', $unknown instanceof RequestProblem && $unknown->status === 404 && $unknown->getMessage() === 'Template version is unavailable.' && $clean);
    $activeId = me_count($scratchDb, "SELECT id FROM document_template_versions WHERE document_type_id={$typeId} AND status='active'");
    $activeVersion = document_template_version($scratchDb, $activeId);
    $activeVersion['_sha'] = (string)hash_file('sha256', document_template_version_path((string)$activeVersion['file_name']));
    foreach (['active' => $activeVersion, 'discarded' => $addDraft($filledXml, 'discarded')] as $status => $nonDraft) {
        [$problem, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$nonDraft['id'], $nonDraft['_sha'], [$ph(7, $nameStart, 'Maria Luisa Santos')], $officialId));
        me_check("refuse: {$status} version -> 409 \"Manual edits can only be saved to a draft version.\"", $problem instanceof RequestProblem && $problem->status === 409 && $problem->getMessage() === 'Manual edits can only be saved to a draft version.' && $clean);
    }
    [$badKey, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$draftR['id'], $draftR['_sha'], [$ph(7, $nameStart, 'Maria Luisa Santos', 'favorite_color')], $officialId));
    me_check('refuse: unregistered key -> 422, nothing written', $badKey instanceof RequestProblem && $badKey->status === 422 && $badKey->getMessage() === 'A selected placeholder is not registered for this document type.' && $clean);
    [$mis, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$draftR['id'], $draftR['_sha'], [$ph(7, $nameStart, 'Maria Luisa Sant0s')], $officialId));
    me_check('refuse: text mismatch -> 422, nothing written', $mis instanceof RequestProblem && $mis->status === 422 && $mis->getMessage() === 'An edit no longer matches the document text. Reload and try again.' && $clean);
    [$ov, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$draftR['id'], $draftR['_sha'], [$ph(7, $nameStart, 'Maria Luisa Santos'), $rt(7, $nameStart + 6, 'Luisa', 'L.')], $officialId));
    me_check('refuse: overlap -> 422, nothing written', $ov instanceof RequestProblem && $ov->getMessage() === 'Edits must not overlap.' && $clean);
    [$brace, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$draftR['id'], $draftR['_sha'], [$rt(7, $nameStart, 'Maria', 'M{aria')], $officialId));
    me_check('refuse: forbidden brace -> 422, nothing written', $brace instanceof RequestProblem && $brace->getMessage() === 'New text cannot contain { or }.' && $clean);

    // the sha check inside the lock: the hook runs after the version row is locked
    $hookDraft = $addDraft($filledXml);
    $hookSeen = []; $hookDir = [];
    [$hooked, $clean] = $refusal(static function () use ($scratchDb, $hookDraft, $templateRoot, &$hookSeen, &$hookDir, $filledXml): void {
        document_apply_store_version($scratchDb, $hookDraft, $templateRoot . DIRECTORY_SEPARATOR . $hookDraft['file_name'], $filledXml,
            ME_CLEARANCE_KEYS, [], 'x.docx', 'n', 'Not draft.', 0, null,
            static function (array $locked) use (&$hookSeen, &$hookDir, $templateRoot): void {
                $hookSeen = $locked; $hookDir = me_snapshot($templateRoot);
                throw new RequestProblem('The template changed. Reload and try again.', 409);
            });
    });
    me_check('lock hook: called with the locked source row before any file is written; its 409 rolls back',
        ($hookSeen['id'] ?? null) == $hookDraft['id'] && ($hookSeen['status'] ?? '') === 'draft' && $hookDir === me_snapshot($templateRoot)
        && $hooked instanceof RequestProblem && $hooked->status === 409 && $clean);
    $notCalled = true;
    $scratchDb->query("UPDATE document_template_versions SET status='discarded' WHERE id=" . (int)$hookDraft['id']);
    [$lockedNotDraft, $clean] = $refusal(static function () use ($scratchDb, $hookDraft, $templateRoot, $filledXml, &$notCalled): void {
        document_apply_store_version($scratchDb, $hookDraft, $templateRoot . DIRECTORY_SEPARATOR . $hookDraft['file_name'], $filledXml,
            ME_CLEARANCE_KEYS, [], 'x.docx', 'n', 'Manual edits can only be saved to a draft version.', 0, null,
            static function () use (&$notCalled): void { $notCalled = false; });
    });
    me_check('lock hook: not called when the locked row is no longer a draft (status checked first)',
        $notCalled && $lockedNotDraft instanceof RequestProblem && $lockedNotDraft->getMessage() === 'Manual edits can only be saved to a draft version.' && $clean);
    $raceDraft = $addDraft($filledXml);
    $racePath = $templateRoot . DIRECTORY_SEPARATOR . $raceDraft['file_name'];
    // The stored file changes after the official loaded it (the client keeps the old hash).
    // The in-lock re-check itself is covered by the two lock-hook checks above.
    me_write_docx($seededPath, $racePath . '.tmp', str_replace('Barangay Sampaguita.', 'Barangay Sampaguita!', $filledXml));
    rename($racePath . '.tmp', $racePath);
    [$raced, $clean] = $refusal(static fn() => document_manual_edit_run($scratchDb, (int)$raceDraft['id'], $raceDraft['_sha'], [$ph(7, $nameStart, 'Maria Luisa Santos')], $officialId));
    me_check('refuse: file changed after it was loaded -> 409, nothing written', $raced instanceof RequestProblem && $raced->status === 409 && $raced->getMessage() === 'The template changed. Reload and try again.' && $clean);

    // forced failure after the write: no file, no row
    $failDraft = $addDraft($filledXml);
    $seenPath = null; $seenId = null;
    [$forced, $clean] = $refusal(static function () use ($scratchDb, $failDraft, $nameStart, $ph, $officialId, &$seenPath, &$seenId): void {
        document_manual_edit_run($scratchDb, (int)$failDraft['id'], $failDraft['_sha'], [$ph(7, $nameStart, 'Maria Luisa Santos')], $officialId,
            static function (string $path, int $id) use (&$seenPath, &$seenId): void {
                $seenPath = $path; $seenId = $id;
                if (!is_file($path)) throw new LogicException('file was not written before the hook');
                throw new RuntimeException('forced failure after write');
            });
    });
    me_check('forced failure after write: exception surfaces, file and row are gone',
        $forced instanceof RuntimeException && $forced->getMessage() === 'forced failure after write' && is_string($seenPath) && !is_file($seenPath)
        && me_count($scratchDb, 'SELECT COUNT(*) FROM document_template_versions WHERE id=' . (int)$seenId) === 0 && $clean);

    // fatal error after the write (child process): shutdown cleanup removes the orphan
    $child = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manual-child-' . getmypid() . '.php'; $tempFiles[] = $child;
    file_put_contents($child, '<?php
foreach (["GEMINI_API_KEY", "GEMINI_MODEL", "HTTPSMS_API_KEY", "HTTPSMS_FROM_NUMBER"] as $k) { putenv($k . "="); $_ENV[$k] = ""; $_SERVER[$k] = ""; }
if (PHP_SAPI !== "cli" || !str_starts_with((string)($argv[1] ?? ""), "capstone_manual_")) exit(3);
require ' . var_export($root . '/api/common.php', true) . ';
require ' . var_export($root . '/api/documents/_manual_edit.php', true) . ';
if (strcasecmp((string)realpath(document_storage_dir("templates")), (string)realpath($argv[6])) !== 0) exit(5);
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $argv[1]); $db->set_charset("utf8mb4");
if ((string)$db->query("SELECT DATABASE()")->fetch_row()[0] !== $argv[1]) exit(4);
echo "CONN " . (int)$db->query("SELECT CONNECTION_ID()")->fetch_row()[0] . "\n"; fflush(STDOUT);
$mode = (string)($argv[7] ?? "fatal");
$hook = null;
if ($mode === "fatal") $hook = static function (string $path, int $id): void {
    echo "WROTE " . $id . " " . $path . "\n"; fflush(STDOUT);
    ini_set("memory_limit", "64M"); $hog = [];
    while (true) $hog[] = str_repeat("x", 1048576);
};
// hold:<flag> keeps the type row locked (inside the transaction) until the flag file exists.
if (str_starts_with($mode, "hold:")) $hook = static function (string $path, int $id) use ($mode): void {
    echo "WROTE " . $id . " " . $path . "\n"; fflush(STDOUT);
    $flag = substr($mode, 5);
    for ($i = 0; $i < 400 && !is_file($flag); $i++) usleep(50000);
};
try { echo json_encode(document_manual_edit_run($db, (int)$argv[2], $argv[3], json_decode($argv[5], true), (int)$argv[4], $hook)), "\n"; }
catch (Throwable $e) { echo "ERROR " . get_class($e) . ($e instanceof RequestProblem ? " " . $e->status . " " . $e->getMessage() : "") . "\n"; exit(1); }
');
    $fatalDraft = $addDraft($filledXml);
    $countBefore = $versionCount(); $dirBefore = me_snapshot($templateRoot);
    $spawn = static function (array $version, string $mode) use ($child, $scratch, $officialId, $ph, $nameStart, $storage): array {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $child, $scratch, (string)$version['id'], $version['_sha'], (string)$officialId,
            (string)json_encode([$ph(7, $nameStart, 'Maria Luisa Santos')]), $storage->templates, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('child process failed to start');
        fclose($pipes[0]);
        return [$process, $pipes];
    };
    $collect = static function (array $handle): array {
        [$process, $pipes] = $handle;
        $out = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return [$out, $err, proc_close($process)];
    };
    [$out, $err, $code] = $collect($spawn($fatalDraft, 'fatal'));
    $wrote = preg_match('/^WROTE (\d+) (.+\.docx)$/m', $out, $match) === 1;
    if ($wrote) $templateFiles[] = basename($match[2]);
    me_check('fatal child used the temp storage, wrote the file and died with a fatal error', $wrote && $code === 255 && str_contains($out . $err, 'Allowed memory size')
        && strcasecmp(dirname($match[2]), (string)realpath($storage->templates)) === 0, substr($out . $err, 0, 300));
    me_check('fatal error after write leaves no file and no row (orphan cleanup)', $wrote && !is_file($match[2]) && $versionCount() === $countBefore
        && me_count($scratchDb, 'SELECT COUNT(*) FROM document_template_versions WHERE id=' . (int)$match[1]) === 0 && me_snapshot($templateRoot) === $dirBefore);

    // Real race for the in-lock hash check: child A holds the type row lock inside its
    // transaction; child B passes the early hash check and waits for that lock; B's
    // source file changes while B waits; B must then be refused by the in-lock check.
    $holdDraft = $addDraft($filledXml); $raceB = $addDraft($filledXml);
    $raceBPath = $templateRoot . DIRECTORY_SEPARATOR . $raceB['file_name'];
    $flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manual-flag-' . getmypid(); $tempFiles[] = $flag; @unlink($flag);
    $countBefore = $versionCount();
    $holder = $spawn($holdDraft, 'hold:' . $flag);
    $holderLine = '';
    for ($i = 0; $i < 5 && !str_contains($holderLine, 'WROTE'); $i++) $holderLine .= (string)fgets($holder[1][1]);
    $waiter = $spawn($raceB, 'plain');
    $waiterConn = preg_match('/^CONN (\d+)/', (string)fgets($waiter[1][1]), $connMatch) === 1 ? (int)$connMatch[1] : 0;
    $waiting = false;
    for ($i = 0; $i < 200 && !$waiting && $waiterConn > 0; $i++) {
        // B is blocked on the type row lock, which is only taken after its early hash check passed.
        $waiting = me_count($admin, "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID={$waiterConn} AND INFO LIKE 'SELECT id FROM document_types WHERE id = % FOR UPDATE'") === 1;
        if (!$waiting) usleep(50000);
    }
    $trxRows = $waiting ? [] : array_merge($admin->query('SELECT trx_mysql_thread_id, trx_state, trx_query FROM information_schema.innodb_trx')?->fetch_all(MYSQLI_ASSOC) ?? [],
        $admin->query("SELECT ID, COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID={$waiterConn}")?->fetch_all(MYSQLI_ASSOC) ?? [],
        [['waiter_status' => proc_get_status($waiter[0])['running']]]);
    me_check('race: B passed the early hash check and is waiting for the type lock', str_contains($holderLine, 'WROTE') && $waiterConn > 0 && $waiting,
        'conn=' . $waiterConn . ' trx=' . json_encode($trxRows));
    me_write_docx($seededPath, $raceBPath . '.tmp', str_replace('Barangay Sampaguita.', 'Barangay Sampaguita!', $filledXml));
    rename($raceBPath . '.tmp', $raceBPath);
    touch($flag);
    [$holderOut, , $holderCode] = $collect($holder);
    [$waiterOut, $waiterErr, $waiterCode] = $collect($waiter);
    $holderJson = null;
    foreach (explode("\n", $holderOut) as $line) if (str_starts_with(trim($line), '{')) $holderJson = json_decode(trim($line), true);
    if (is_array($holderJson)) $templateFiles[] = (string)$versionRow((int)$holderJson['new_version_id'])['file_name'];
    me_check('race: A (holding the lock) saved normally', $holderCode === 0 && is_array($holderJson) && ($holderJson['applied'] ?? 0) === 1, $holderOut);
    me_check('race: B refused by the in-lock hash check (409), no row and no file for B',
        $waiterCode === 1 && str_contains($waiterOut, 'ERROR RequestProblem 409 The template changed. Reload and try again.') && $versionCount() === $countBefore + 1, $waiterOut . $waiterErr);

    // ------------------------------------------------------------ endpoints
    if (!mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) throw new RuntimeException('session directory failed');
    file_put_contents($prepend, "<?php foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
        . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), 'capstone_manual_')) { http_response_code(500); exit; }\n"
        . 'require_once ' . var_export($root . '/db.php', true) . "; \$DB_NAME = getenv('BARANGAY_DB_NAME');");
    $base = null;
    for ($attempt = 0; $attempt < 10 && $base === null; $attempt++) {
        $port = random_int(20000, 45000);
        $environment = ['BARANGAY_DB_NAME' => $scratch, 'BARANGAY_STORAGE_ROOT' => $storage->root,
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '', 'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()];
        $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', $root],
            [0 => ['pipe', 'r'], 1 => ['file', $serverLog . '.out', 'a'], 2 => ['file', $serverLog . '.err', 'a']], $serverPipes, $root, $environment);
        if (!is_resource($server)) continue;
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) { fclose($socket); $base = 'http://127.0.0.1:' . $port; break; }
            usleep(25000);
        }
        if ($base === null) { me_stop_server($server); $server = null; }
    }
    if ($base === null) throw new RuntimeException('Endpoint server did not start.');
    $login = me_http($base . '/pages/login.php', 'POST', ['action' => 'official-login', 'username' => 'manual-staff', 'password' => $password]);
    me_check('endpoint: official login on the scratch database', $login['status'] === 302 && $login['cookie'] !== '');
    $cookie = $login['cookie'];
    $epDraft = $addDraft($filledXml);
    $paragraphsUrl = $base . '/api/documents/template_paragraphs.php?version_id=' . (int)$epDraft['id'];
    me_check('endpoint paragraphs: no session -> 401', me_http($paragraphsUrl)['status'] === 401);
    me_check('endpoint paragraphs: POST -> 405', me_http($paragraphsUrl, 'POST', '{}', $cookie)['status'] === 405);
    me_check('endpoint paragraphs: no version -> 422', me_http($base . '/api/documents/template_paragraphs.php', 'GET', null, $cookie)['status'] === 422);
    me_check('endpoint paragraphs: unknown version -> 404', me_http($base . '/api/documents/template_paragraphs.php?version_id=999999', 'GET', null, $cookie)['status'] === 404);
    $got = me_http($paragraphsUrl, 'GET', null, $cookie);
    $expectedDoc = document_manual_paragraphs($filledXml);
    me_check('endpoint paragraphs: 200 with sha256 of the stored file, paragraphs and placeholders',
        $got['status'] === 200 && ($got['json']['sha256'] ?? '') === $epDraft['_sha'] && ($got['json']['status'] ?? '') === 'draft'
        && ($got['json']['paragraphs'] ?? null) === $expectedDoc['paragraphs'] && ($got['json']['placeholders'] ?? null) === $expectedDoc['placeholders'], substr($got['body'], 0, 200));
    me_check('endpoint paragraphs: no file name in the response', !str_contains($got['body'], (string)$epDraft['file_name']) && !array_key_exists('file_name', $got['json']));

    $editUrl = $base . '/api/documents/template_manual_edit.php';
    $body = static fn(array $extra = []): string => (string)json_encode(array_merge(['version_id' => (int)$epDraft['id'], 'expected_sha256' => $epDraft['_sha'],
        'edits' => [$ph(7, $nameStart, 'Maria Luisa Santos')]], $extra), JSON_UNESCAPED_UNICODE);
    me_check('endpoint edit: no session -> 401', me_http($editUrl, 'POST', $body())['status'] === 401);
    me_check('endpoint edit: GET -> 405', me_http($editUrl, 'GET', null, $cookie)['status'] === 405);
    $shape = 'A version, the file hash and a list of edits are required.';
    foreach (['missing sha' => ['expected_sha256' => null], 'uppercase sha' => ['expected_sha256' => strtoupper($epDraft['_sha'])],
              'edits not a list' => ['edits' => 'x'], 'string version' => ['version_id' => (string)$epDraft['id']]] as $label => $extra) {
        $r = me_http($editUrl, 'POST', $body($extra), $cookie);
        me_check("endpoint edit: {$label} -> 422 shape message", $r['status'] === 422 && ($r['json']['message'] ?? '') === $shape);
    }
    $r = me_http($editUrl, 'POST', $body(['expected_sha256' => str_repeat('a', 64)]), $cookie);
    me_check('endpoint edit: stale sha -> 409', $r['status'] === 409 && ($r['json']['message'] ?? '') === 'The template changed. Reload and try again.');
    $r = me_http($editUrl, 'POST', $body(['edits' => [$rt(7, $ageStart, ', 34 years', ', 3{4 years')]]), $cookie);
    me_check('endpoint edit: brace in new_text -> 422 message', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'New text cannot contain { or }.');
    $epTab = $addDraft(str_replace('<w:t>Republic of the Philippines</w:t>', '<w:t>Republic of</w:t><w:tab/><w:t>the Philippines</w:t>', $seededXml));
    $r = me_http($editUrl, 'POST', (string)json_encode(['version_id' => (int)$epTab['id'], 'expected_sha256' => $epTab['_sha'], 'edits' => [$rt(0, 9, 'ofthe', 'of the')]]), $cookie);
    me_check('endpoint edit: nothing applicable -> 422 with skipped reasons', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'None of the edits could be applied.'
        && ($r['json']['skipped'] ?? null) === [['index' => 0, 'reason' => 'crosses a tab or break']]);
    $r = me_http($editUrl, 'POST', $body(), $cookie);
    $epNew = $r['json']['new_version_id'] ?? 0;
    if ($epNew) $templateFiles[] = (string)$versionRow((int)$epNew)['file_name'];
    me_check('endpoint edit: 200 with the apply response shape', $r['status'] === 200 && ($r['json']['applied'] ?? 0) === 1 && ($r['json']['skipped'] ?? null) === []
        && array_key_exists('check_ok', $r['json']) && array_key_exists('version_no', $r['json']));
    me_check('endpoint edit: no file name or hash of the new version in the response', $epNew > 0 && !str_contains($r['body'], (string)$versionRow((int)$epNew)['file_name'])
        && !str_contains($r['body'], (string)$versionRow((int)$epNew)['sha256']));
    $r2 = me_http($editUrl, 'POST', $body(), $cookie);
    me_check('endpoint edit: repeating the same save on the same source still works (source untouched)', $r2['status'] === 200 && ($r2['json']['new_version_id'] ?? 0) !== $epNew);
    if (!empty($r2['json']['new_version_id'])) $templateFiles[] = (string)$versionRow((int)$r2['json']['new_version_id'])['file_name'];
    $audit = $scratchDb->query("SELECT official_id FROM audit_log WHERE action='Edited template text manually' AND target_reference='" . (int)$epNew . "'")->fetch_row();
    me_check('endpoint edit: audit row carries the session official', $audit !== null && (int)$audit[0] === $officialId);
    me_stop_server($server); $server = null;

    me_check('seeded template copy byte-for-byte unchanged after all runs', hash_file('sha256', $seededPath) === $seededHash);
} catch (Throwable $e) {
    me_check('suite completed without an unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    if ($scratchDb instanceof mysqli) { @$scratchDb->close(); $scratchDb = null; }
    me_check('template storage back to its starting files', me_snapshot($templateRoot) === $templateSnapshot,
        json_encode(array_keys(array_diff_key(me_snapshot($templateRoot), $templateSnapshot))));
    if ($admin instanceof mysqli) {
        foreach ($realCounts as $table => $count) {
            me_check('real row count unchanged ' . $table, me_count($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`") === $count);
        }
        me_check('no scratch database left behind', me_database_names($admin, $prefix) === []);
    }
}
echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
