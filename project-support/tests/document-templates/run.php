<?php
/**
 * BarangayLink - Document template validation tests.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__, 3) . '/api/documents/_templates.php';
require_once dirname(__DIR__, 3) . '/api/documents/_template_check.php';

$passed = 0; $failed = 0; $files = [];
function template_test(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}\n"; }
}
function fixture(string $name, array $entries): string {
    global $files;
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'template-test-' . bin2hex(random_bytes(6)) . '-' . $name . '.docx';
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $content) $zip->addFromString($entry, $content);
    $zip->close(); $files[] = $path; return $path;
}
function valid_entries(string $xml): array {
    return [
        '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        'word/document.xml' => $xml,
    ];
}
function rejects(string $path): bool {
    try { document_template_validate_upload(['error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'name' => 'x.docx', 'tmp_name' => $path]); return false; }
    catch (RequestProblem $e) { return true; }
}
$xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{full_name}}</w:t></w:r></w:p></w:body></w:document>';
$valid = fixture('valid', valid_entries($xml));
template_test('valid DOCX accepted', !rejects($valid));
template_test('wrong extension rejected', (function () use ($valid): bool {
    try { document_template_validate_upload(['error'=>UPLOAD_ERR_OK,'size'=>filesize($valid),'name'=>'x.pdf','tmp_name'=>$valid]); return false; } catch (RequestProblem $e) { return true; }
})());
template_test('wrong magic rejected', (function (): bool {
    $path = tempnam(sys_get_temp_dir(), 'bad-docx-'); file_put_contents($path, 'not zip');
    try { document_template_validate_upload(['error'=>UPLOAD_ERR_OK,'size'=>7,'name'=>'x.docx','tmp_name'=>$path]); return false; } catch (RequestProblem $e) { return true; } finally { @unlink($path); }
})());
template_test('missing document part rejected', rejects(fixture('missing-document', ['[Content_Types].xml' => '<Types/>'])));
template_test('macro part rejected', rejects(fixture('macro', valid_entries($xml) + ['word/vbaProject.bin' => 'macro'])));
template_test('unsafe path rejected', rejects(fixture('unsafe', valid_entries($xml) + ['../evil.txt' => 'x'])));
template_test('external non-hyperlink relationship rejected', rejects(fixture('external-relationship', valid_entries($xml) + [
    'word/_rels/document.xml.rels' => '<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/oleObject" Target="https://example.test/x" TargetMode="External"/></Relationships>',
])));
template_test('unknown placeholder detected', in_array('unknown placeholders: unknown', document_template_check(fixture('unknown', valid_entries(str_replace('{{full_name}}', '{{unknown}}', $xml))), ['full_name']), true));
template_test('malformed placeholder detected', count(document_template_check(fixture('malformed', valid_entries(str_replace('{{full_name}}', '{{Full_Name}}', $xml))), ['full_name'])) > 0);
template_test('required placeholder detected', count(document_template_check(fixture('required', valid_entries('<w:document/>')), ['full_name'], ['full_name'])) > 0);
$split = fixture('split', valid_entries('<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{full_</w:t></w:r><w:r><w:t>name}}</w:t></w:r></w:p></w:body></w:document>'));
template_test('split placeholder detected', count(document_template_check($split, ['full_name'])) > 0);

foreach ($files as $file) @unlink($file);
echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
