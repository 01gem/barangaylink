<?php
/**
 * BarangayLink - Document template version helpers.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/_registry.php';
require_once __DIR__ . '/_template_check.php';
require_once __DIR__ . '/../requests/_render.php';

const DOCUMENT_TEMPLATE_MAX_BYTES = 2097152;
const DOCUMENT_TEMPLATE_MAX_ENTRIES = 300;
const DOCUMENT_TEMPLATE_MAX_UNCOMPRESSED = 26214400;

function document_template_root(): string {
    $root = realpath(document_storage_dir('templates'));
    if ($root === false || !is_dir($root)) throw new RuntimeException('Template directory is unavailable.');
    return $root;
}

function document_template_version_path(string $fileName): string {
    if (!preg_match('/^[a-z0-9_]+\.docx$/', $fileName)) throw new RuntimeException('Invalid template filename.');
    $root = document_template_root();
    $path = realpath($root . DIRECTORY_SEPARATOR . $fileName);
    if ($path === false || !is_file($path) || !str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Document template is unavailable.');
    }
    return $path;
}

function document_template_upload_error(string $message, int $status = 422): never {
    throw new RequestProblem($message, $status);
}

function document_template_validate_upload(array $file): void {
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        document_template_upload_error('The file is larger than the server allows (limit 2 MB).', 413);
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        document_template_upload_error('Upload failed. Select a DOCX file and try again.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > DOCUMENT_TEMPLATE_MAX_BYTES) {
        document_template_upload_error('Template files must be 2 MB or smaller.');
    }
    $name = (string)($file['name'] ?? '');
    if (!preg_match('/\.docx$/i', $name)) document_template_upload_error('Only .docx templates are accepted.');
    $path = (string)($file['tmp_name'] ?? '');
    $handle = fopen($path, 'rb');
    $magic = $handle ? fread($handle, 4) : false;
    if (is_resource($handle)) fclose($handle);
    if ($magic !== "PK\x03\x04") document_template_upload_error('The uploaded file is not a valid DOCX archive.');

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) document_template_upload_error('The uploaded DOCX archive could not be opened.');
    if ($zip->numFiles > DOCUMENT_TEMPLATE_MAX_ENTRIES) {
        $zip->close(); document_template_upload_error('The DOCX archive contains too many entries.');
    }
    $total = 0;
    $hasContentTypes = false; $hasDocument = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string)$zip->getNameIndex($i);
        if ($entry === '[Content_Types].xml') $hasContentTypes = true;
        if ($entry === 'word/document.xml') $hasDocument = true;
        if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_contains($entry, '\\')) {
            $zip->close(); document_template_upload_error('The DOCX archive contains an unsafe path.');
        }
        if (preg_match('/(?:vbaProject|activeX|word\/embeddings)/i', $entry)) {
            $zip->close(); document_template_upload_error('Macros and embedded objects are not allowed.');
        }
        $stat = $zip->statIndex($i);
        $uncompressed = (int)($stat['size'] ?? 0); $compressed = (int)($stat['comp_size'] ?? 0);
        $total += $uncompressed;
        if ($total > DOCUMENT_TEMPLATE_MAX_UNCOMPRESSED ||
            ($uncompressed > 1048576 && $compressed > 0 && $uncompressed / $compressed > 100)) {
            $zip->close(); document_template_upload_error('The DOCX archive is too large or highly compressed.');
        }
        if (str_ends_with(strtolower($entry), '.xml')) {
            $xml = $zip->getFromIndex($i);
            if ($xml !== false && preg_match('/macroEnabled|vbaProject/i', $xml)) {
                $zip->close(); document_template_upload_error('Macro-enabled DOCX content is not allowed.');
            }
        }
        if (str_ends_with(strtolower($entry), '.rels')) {
            $xml = $zip->getFromIndex($i);
            if ($xml !== false && preg_match_all('/<Relationship\b[^>]*TargetMode\s*=\s*["\']External["\'][^>]*>/i', $xml, $rels)) {
                foreach ($rels[0] as $rel) {
                    if (!preg_match('/Type\s*=\s*["\'][^"\']*\/hyperlink["\']/i', $rel)) {
                        $zip->close(); document_template_upload_error('External DOCX relationships are not allowed.');
                    }
                }
            }
        }
    }
    $zip->close();
    if (!$hasContentTypes || !$hasDocument) document_template_upload_error('The DOCX archive is missing required parts.');
}

function document_template_sanitized_original_name(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '', $name) ?? '';
    return mb_substr(trim($name), 0, 255);
}

function document_template_new_file_name(int $typeId, int $versionNo): string {
    return 'tpl_' . $typeId . '_v' . $versionNo . '_' . bin2hex(random_bytes(4)) . '.docx';
}

function document_template_version(mysqli $db, int $versionId, bool $forUpdate = false): ?array {
    $sql = 'SELECT v.*, dt.name AS type_name, dt.template_file AS current_template_file
            FROM document_template_versions v JOIN document_types dt ON dt.id = v.document_type_id
            WHERE v.id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('i', $versionId);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

function document_template_log(mysqli $db, string $action, string $target, string $details = ''): void {
    log_audit($db, (int)($_SESSION['official_id'] ?? 0), (string)($_SESSION['official_name'] ?? ''), $action, 'document_template_version', $target, $details);
}

/**
 * Phase 7: test-render values for the internal Summons fields, so its preview
 * reads like a real summons. Only keys no resident type uses; every other key
 * keeps the 'SAMPLE' / '1' test value (resident previews are unchanged).
 */
const DOCUMENT_SAMPLE_VALUES = [
    'case_no' => 'BC-2026-0001',
    'case_title' => 'Sample Complaint Type',
    'complainant_names' => 'Juan Dela Cruz',
    'respondent_names' => 'Pedro Santos and Maria Reyes',
    'respondent_to' => 'Pedro Santos, Purok 1',
    'hearing_day' => '28th',
    'hearing_month_year' => 'November 2026',
    'hearing_time' => '9:00 in the morning',
    'venue' => 'Barangay Hall',
    'issued_day' => '16th',
    'issued_month' => 'November',
    'issued_year' => '2026',
];

function document_template_sample_context(mysqli $db, array $version): array {
    $type = document_registry_type($db, (string)$version['type_name']);
    if ($type === null) throw new RuntimeException('Document type is unavailable.');
    $captain = document_setting_get($db, 'captain_name');
    if ($captain === null || trim($captain) === '') throw new RuntimeException('Signatory is not configured.');
    return ['type' => array_merge($type, ['template_file' => $version['file_name']]),
        'version' => $version, 'fields' => document_registry_fields($db, (int)$version['document_type_id']),
        'captain_name' => $captain];
}

function document_template_test_version(mysqli $db, array $version): string {
    $lock = $db->query("SELECT GET_LOCK('barangay_document_template_test_render', 0) AS acquired");
    $acquired = $lock ? (int)$lock->fetch_assoc()['acquired'] : 0;
    if ($acquired !== 1) throw new RequestProblem('Another template test is already running.', 409, 'busy');
    $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barangay-template-test-' . bin2hex(random_bytes(8));
    $previewDir = document_storage_dir('previews');
    try {
        if (!mkdir($workspace, 0700, true)) throw new RuntimeException('Cannot create test workspace.');
        if (!is_dir($previewDir) && !mkdir($previewDir, 0700, true)) throw new RuntimeException('Cannot create preview directory.');
        foreach (glob($previewDir . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $old) {
            if (is_file($old) && filemtime($old) !== false && filemtime($old) < time() - 3600) @unlink($old);
        }
        $fields = [];
        foreach (document_registry_fields($db, (int)$version['document_type_id']) as $field) {
            $fields[$field['field_key']] = DOCUMENT_SAMPLE_VALUES[$field['field_key']]
                ?? ($field['data_type'] === 'int' ? '1' : 'SAMPLE');
        }
        $request = ['document_type' => $version['type_name'], 'reference_no' => 'TEMPLATE-TEST',
            'resident_name' => 'Sample Resident', 'address' => 'Sample Address', 'purpose' => 'Template test'];
        $files = request_render_document($request, $fields, $workspace, 'TEMPLATE-TEST', document_template_sample_context($db, $version));
        if (!request_valid_pdf($files['pdf'])) throw new RuntimeException('Test render did not produce a valid PDF.');
        $token = bin2hex(random_bytes(16));
        if (!copy($files['pdf'], $previewDir . DIRECTORY_SEPARATOR . $token . '.pdf')) throw new RuntimeException('Cannot store preview.');
        $sha = hash_file('sha256', document_template_version_path((string)$version['file_name']));
        if ($sha === false) throw new RuntimeException('Cannot hash template.');
        $stmt = $db->prepare('UPDATE document_template_versions SET sha256=COALESCE(sha256, ?), tested_sha256=?, tested_at=NOW(), test_ok=1, size_bytes=? WHERE id=?');
        $size = filesize(document_template_version_path((string)$version['file_name'])) ?: 0;
        $stmt->bind_param('ssii', $sha, $sha, $size, $version['id']); $stmt->execute(); $stmt->close();
        return $token;
    } finally {
        remove_dir_tree($workspace);
        $db->query("SELECT RELEASE_LOCK('barangay_document_template_test_render')");
    }
}
