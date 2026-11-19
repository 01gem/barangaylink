<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../documents/_registry.php';
session_start();
enforce_active_session();

require_post();

$db = get_db();
$input = read_json_input();

$documentType = trim((string)($input['document_type'] ?? ''));
$purpose = trim((string)($input['purpose'] ?? ''));
$copies = (int)($input['copies'] ?? 1);
if ($documentType === '' || $purpose === '') {
  json_error('Document type and purpose are required.');
}
if ($copies < 1) $copies = 1;
if ($copies > 5) $copies = 5;

$rawFields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
$allowed = [];
if (document_registry_table_exists($db)) {
  foreach (document_registry_resident_fields($db, $documentType) as $field) {
    $allowed[$field['field_key']] = [
      'data_type' => $field['data_type'],
      'min_value' => $field['min_value'] === null ? null : (int)$field['min_value'],
      'max_value' => $field['max_value'] === null ? null : (int)$field['max_value'],
      'max_length' => $field['max_length'] === null ? null : (int)$field['max_length'],
      'label' => (string)$field['label'],
      'is_required' => (int)$field['is_required'],
    ];
  }
} else {
  $allowed = LEGACY_RESIDENT_FIELDS[$documentType] ?? [];
}
$cleanFields = [];
foreach ($allowed as $key => $rules) {
  $raw = $rawFields[$key] ?? null;
  if ($raw === null) continue;
  if (($rules['data_type'] ?? '') === 'int') {
    if ($raw === '' || !is_numeric($raw)) continue;
    $n = (int)$raw;
    if (($rules['min_value'] ?? null) !== null && $n < $rules['min_value']) continue;
    if (($rules['max_value'] ?? null) !== null && $n > $rules['max_value']) continue;
    $cleanFields[$key] = $n;
  } else {
    $s = trim((string)$raw);
    if ($s === '') continue;
    $maxLength = (int)($rules['max_length'] ?? 100);
    $cleanFields[$key] = mb_substr($s, 0, $maxLength);
  }
}
$requestFieldsJson = $cleanFields
  ? json_encode($cleanFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
  : null;

$residentId = !empty($_SESSION['resident_id']) ? (int)$_SESSION['resident_id'] : 0;
$residentName = trim((string)($_SESSION['resident_name'] ?? ''));

if ($residentId <= 0) {
  json_error('Resident session is missing required account linkage. Please log in again.', 401);
}
if ($residentName === '') {
  json_error('Resident session expired. Please log in again.', 401);
}
if (document_registry_table_exists($db)) {
  $registeredType = document_registry_type($db, $documentType);
  if ($registeredType === null || $registeredType['status'] !== 'active') {
    json_error('This document type is no longer available.', 422);
  }
  // Not archived, but no active template version yet (e.g. a newly added type).
  if (document_registry_requestable($db, $documentType) === null) {
    json_error('This document is not available yet.', 422);
  }
  // Phase 5B: a resident field the registry marks required must have a usable value.
  foreach ($allowed as $key => $rules) {
    if (($rules['is_required'] ?? 0) === 1 && !array_key_exists($key, $cleanFields)) {
      json_error(document_field_message('required', ['label' => $rules['label']]), 422);
    }
  }
}

$prefix = 'REQ-' . date('Ymd') . '-';
$ref = $prefix . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
$dateRequested = date('M d, Y');
$status = 'pending';

$priceStmt = $db->prepare('SELECT unit_price FROM document_prices WHERE document_type = ? LIMIT 1');
if (!$priceStmt) json_error('Failed to prepare document price lookup.', 500);
$priceStmt->bind_param('s', $documentType);
$priceRows = db_query_all($priceStmt);
$priceStmt->close();
if (!isset($priceRows[0]['unit_price'])) {
  json_error('This document type has no posted price. Please contact the barangay hall.', 422);
}
$unitPrice = (float)$priceRows[0]['unit_price'];
$totalAmount = round($unitPrice * $copies, 2);
$paymentStatus = $totalAmount <= 0 ? 'waived' : 'unpaid';

try {
  $stmt = $db->prepare("INSERT INTO document_requests
  (reference_no, resident_id, resident_name, document_type, purpose,
   request_fields_json, copies, total_amount, payment_status,
   date_requested, status)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  if (!$stmt) json_error('Failed to prepare request insert.', 500);
  $stmt->bind_param('sissssidsss',
    $ref, $residentId, $residentName, $documentType, $purpose,
    $requestFieldsJson, $copies, $totalAmount, $paymentStatus,
    $dateRequested, $status);

  if (!$stmt->execute()) {
    json_db_error('Failed to submit request', $stmt->error);
  }
  $stmt->close();
} catch (Throwable $e) {
  json_db_error('Failed to submit request', $e->getMessage());
}

json_success(['reference_no' => $ref, 'message' => 'Request submitted successfully.']);
?>