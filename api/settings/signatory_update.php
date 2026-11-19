<?php
/**
 * BarangayLink - Update the document signatory setting.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../documents/_registry.php';
session_start();
require_official_admin();
require_post();

$input = read_json_input();
$validation = document_validate_signatory_name((string)($input['captain_name'] ?? ''));
if (empty($validation['ok'])) json_error((string)$validation['message'], 422);
$name = (string)$validation['name'];

$db = get_db();
if (!document_registry_table_exists($db)) {
  json_error('Document registry is not installed.', 503);
}
$old = document_setting_get($db, 'captain_name');
try {
  document_setting_set($db, 'captain_name', $name, (int)$_SESSION['official_id']);
} catch (Throwable $e) {
  json_db_error('Failed to update signatory setting', $e->getMessage());
}
log_audit(
  $db,
  (int)$_SESSION['official_id'],
  (string)($_SESSION['official_name'] ?? ''),
  'Updated document signatory',
  'document_setting',
  'captain_name',
  'Changed from "' . (string)$old . '" to "' . $name . '"'
);
json_success(['captain_name' => $name, 'message' => 'Signatory updated.']);
