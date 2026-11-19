<?php
/**
 * BarangayLink - Serve a request's generated PDF to a signed-in official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * generated_documents/ is closed to direct HTTP access; this is the only read
 * path. GET ?id=<document_requests.id>. PDF only; nothing is modified.
 */
require_once __DIR__ . '/_generation.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
$requestId = (int)($_GET['id'] ?? 0);
if ($requestId <= 0) json_error('Request is required.', 422);
session_write_close();

$unavailable = static function (): never { json_error('Generated document is unavailable.', 404); };
try {
    $db = get_db();
    $stmt = $db->prepare('SELECT reference_no, generated_document_path FROM document_requests WHERE id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('i', $requestId);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    json_db_error('Generated document lookup failed', $e->getMessage());
}
// Only the name the generator writes (generated_documents/<operation uuid>.pdf), inside storage.
$path = $row ? request_generated_pdf_path($row['generated_document_path']) : null;
if ($path === null || !request_valid_pdf($path)) $unavailable();

$downloadName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$row['reference_no']) ?: 'document';
header_remove('Content-Type');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $downloadName . '.pdf"');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
