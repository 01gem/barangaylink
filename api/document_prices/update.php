<?php
/**
 * BarangayLink - Update a document unit price.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../documents/_registry.php';
session_start();
require_official_session();
require_post();

$input = read_json_input();
$id = (int)($input['id'] ?? 0);
$price = $input['unit_price'] ?? null;

if ($id <= 0) {
  json_error('Document price id is required.');
}
if (!is_numeric($price)) {
  json_error('Unit price must be a number.');
}
$unitPrice = round((float)$price, 2);
if ($unitPrice < 0 || $unitPrice > 9999.99) {
  json_error('Unit price must be between 0.00 and 9999.99.');
}

$db = get_db();
// Phase 7: a price of an internal document type is not editable (same 404 as a missing row).
$internal = document_types_audience_ready($db)
  ? " AND NOT EXISTS (SELECT 1 FROM document_types dt WHERE dt.name = p.document_type AND dt.audience <> 'resident')"
  : '';
$check = $db->prepare("SELECT p.id, p.document_type FROM document_prices p WHERE p.id = ?{$internal} LIMIT 1");
if (!$check) json_error('Failed to prepare document price lookup.', 500);
$check->bind_param('i', $id);
$rows = db_query_all($check);
$check->close();
if (!$rows) {
  json_error('Document price was not found.', 404);
}

$stmt = $db->prepare('UPDATE document_prices SET unit_price = ?, updated_at = NOW() WHERE id = ?');
if (!$stmt) json_error('Failed to prepare document price update.', 500);
$stmt->bind_param('di', $unitPrice, $id);
if (!$stmt->execute()) {
  json_db_error('Failed to update document price', $stmt->error);
}
$stmt->close();

log_audit(
  $db,
  isset($_SESSION['official_id']) ? (int)$_SESSION['official_id'] : null,
  (string)($_SESSION['official_name'] ?? ''),
  'Updated document price',
  'document_price',
  (string)$rows[0]['document_type'],
  'Unit price: ' . number_format($unitPrice, 2, '.', '')
);

json_success(['message' => 'Document price updated.']);
