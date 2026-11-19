<?php
/**
 * BarangayLink - Paragraph text of a template version for manual editing.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?version_id=N. Read-only. Same paragraph indexes as the AI analysis.
 * Exception to "hashes are never returned": sha256 of the stored file is
 * returned here (official session only) so a save can prove it edits the
 * file the official saw (expected_sha256 in template_manual_edit.php).
 */
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_manual_edit.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();

$versionId = (int)($_GET['version_id'] ?? 0);
if ($versionId <= 0) json_error('Version is required.', 422);
try {
    $db = get_db();
    $version = document_template_version($db, $versionId);
    if ($version) {
        $path = document_template_version_path((string)$version['file_name']);
        $sha = hash_file('sha256', $path);
        if (!is_string($sha)) throw new RuntimeException('Cannot hash template.');
        $document = document_manual_paragraphs(document_manual_read_xml($path));
    }
} catch (Throwable $e) {
    json_db_error('Template paragraphs lookup failed', $e->getMessage());
}
if (!$version) json_error('Template version is unavailable.', 404);

json_success([
    'version_id' => (int)$version['id'],
    'status' => (string)$version['status'],
    'sha256' => $sha,
    'paragraphs' => $document['paragraphs'],
    'placeholders' => $document['placeholders'],
]);
