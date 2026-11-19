<?php
/**
 * BarangayLink - BARANGAY_STORAGE_ROOT guard tests (test-only storage override).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * The override is honored only for a directory directly inside the system
 * temp directory named capstone_tpl_<digits>, after links and junctions are
 * resolved. Every other value falls back to the real project folders. This
 * suite only creates folders and one link inside the temp directory and
 * removes exactly those; it never writes into the project.
 *
 *   php project-support/tests/storage-override/run.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__, 3);
require_once $root . '/api/documents/_registry.php';

$passed = 0; $failed = 0;
function storage_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n"; }
}
function storage_with(?string $value): string {
    putenv($value === null ? 'BARANGAY_STORAGE_ROOT' : 'BARANGAY_STORAGE_ROOT=' . $value);
    return document_storage_dir('templates');
}

$temp = (string)realpath(sys_get_temp_dir());
$pid = getmypid();
$realTemplates = $root . DIRECTORY_SEPARATOR . 'document_templates';
$realPreviews = $root . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'previews';

$valid = $temp . DIRECTORY_SEPARATOR . 'capstone_tpl_' . $pid;
$wrongName = $temp . DIRECTORY_SEPARATOR . 'capstone_tplx_' . $pid;
$nestParent = $temp . DIRECTORY_SEPARATOR . 'capstone_storage_check_' . $pid;
$nested = $nestParent . DIRECTORY_SEPARATOR . 'capstone_tpl_' . $pid;
$link = $temp . DIRECTORY_SEPARATOR . 'capstone_tpl_' . $pid . '1';
$created = []; $linkMade = false;

try {
    foreach ([$valid, $wrongName, $nestParent, $nested] as $dir) {
        if (file_exists($dir)) throw new RuntimeException('Fixture already exists: ' . $dir);
        if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create ' . $dir);
        $created[] = $dir;
    }
    if (file_exists($link)) throw new RuntimeException('Fixture already exists: ' . $link);
    // A link or junction whose own name passes, pointing outside the temp root.
    $linkMade = @symlink($nested, $link);
    if (!$linkMade && PHP_OS_FAMILY === 'Windows') {
        exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($nested) . ' >NUL 2>&1', $out, $code);
        $linkMade = $code === 0 && is_dir($link);
    }

    storage_check('unset uses the real template folder', storage_with(null) === $realTemplates);
    storage_check('unset uses the real preview folder', document_storage_dir('previews') === $realPreviews);
    storage_check('empty value is ignored', storage_with('') === $realTemplates);
    storage_check('missing folder is ignored', storage_with($temp . DIRECTORY_SEPARATOR . 'capstone_tpl_9' . $pid . '9') === $realTemplates);
    storage_check('valid temp folder is honored', storage_with($valid) === $valid . DIRECTORY_SEPARATOR . 'document_templates');
    storage_check('valid temp folder is honored for previews',
        document_storage_dir('previews') === $valid . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'previews');
    storage_check('path outside temp is ignored (project root)', storage_with($root) === $realTemplates);
    storage_check('path outside temp root is ignored (right name, nested)', storage_with($nested) === $realTemplates);
    storage_check('relative traversal to a nested folder is ignored',
        storage_with($valid . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'capstone_storage_check_' . $pid . DIRECTORY_SEPARATOR . 'capstone_tpl_' . $pid) === $realTemplates);
    storage_check('wrong basename is ignored', storage_with($wrongName) === $realTemplates);
    storage_check('link or junction was created for the check', $linkMade);
    // Proves the refusal below comes from the parent check after resolution, not from an unreadable link.
    storage_check('link or junction resolves to its target', $linkMade && realpath($link) === realpath($nested));
    storage_check('link or junction pointing outside temp root is ignored', $linkMade && storage_with($link) === $realTemplates);
} catch (Throwable $e) {
    storage_check('suite ran without error', false, $e->getMessage());
} finally {
    putenv('BARANGAY_STORAGE_ROOT');
    // Remove the link itself (never its target), then exactly the folders made here.
    if ($linkMade && file_exists($link)) { if (is_link($link) && PHP_OS_FAMILY !== 'Windows') @unlink($link); else @rmdir($link); }
    foreach (array_reverse($created) as $dir) @rmdir($dir);
}
storage_check('fixtures removed', !file_exists($link) && !file_exists($valid) && !file_exists($wrongName) && !file_exists($nestParent));
echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
