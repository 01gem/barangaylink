<?php
/**
 * BarangayLink - Stream one generated summons to a signed-in official (complaint revision Phase 7).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?id=<complaint_documents.id>&format=pdf|docx. generated_documents/ is
 * closed to direct HTTP access; this is the only read path. The file path comes
 * from the database row only (file_uuid), never from the request. Superseded
 * copies stay viewable. PDF inline (for printing), DOCX as an attachment.
 * Nothing is modified.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_summons.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();

$unavailable = static function (): never { json_error('Summons is unavailable.', 404); };
$id = hearing_query_id($_GET['id'] ?? null);
$format = $_GET['format'] ?? 'pdf';
if ($id === null || !in_array($format, ['pdf', 'docx'], true)) $unavailable();

try {
  $row = summons_document(get_db(), $id);
} catch (ComplaintProblem $problem) {
  $unavailable();
} catch (Throwable $error) {
  json_db_error('Summons lookup failed', $error->getMessage());
}

$path = summons_file_path((string)$row['file_uuid'], $format);
if ($path === null) $unavailable();
if ($format === 'pdf' && !request_valid_pdf($path)) $unavailable();
if ($format === 'docx') {
  $handle = fopen($path, 'rb');
  $magic = $handle ? fread($handle, 4) : false;
  if (is_resource($handle)) fclose($handle);
  if ($magic !== "PK\x03\x04") $unavailable();
}

$name = $row['download_base'] . '.' . $format;
header_remove('Content-Type');
header($format === 'pdf' ? 'Content-Type: application/pdf'
  : 'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: ' . ($format === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
