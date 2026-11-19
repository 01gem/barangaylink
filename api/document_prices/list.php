<?php
/**
 * BarangayLink - List document unit prices.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../documents/_registry.php';
session_start();
enforce_active_session();
if (empty($_SESSION['official_id']) && empty($_SESSION['resident_id'])) {
  json_error('Unauthorized', 401);
}

$db = get_db();
// Phase 7: never list a price for an internal document type (Summons has none; defense only).
$internal = document_types_audience_ready($db)
  ? " WHERE NOT EXISTS (SELECT 1 FROM document_types dt WHERE dt.name = p.document_type AND dt.audience <> 'resident')"
  : '';
$stmt = $db->prepare("SELECT p.id, p.document_type, p.unit_price, p.updated_at FROM document_prices p{$internal} ORDER BY p.document_type ASC");
if (!$stmt) json_error('Failed to prepare document prices query.', 500);
$rows = db_query_all($stmt);
$stmt->close();

json_success(['prices' => $rows]);
