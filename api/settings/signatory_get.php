<?php
/**
 * BarangayLink - Read the document signatory setting.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../documents/_registry.php';
session_start();
require_official_session();

$db = get_db();
if (!document_registry_table_exists($db)) {
  json_error('Document registry is not installed.', 503);
}
$name = document_setting_get($db, 'captain_name');
if ($name === null || trim($name) === '') {
  json_error('Signatory is not configured.', 500);
}
json_success([
  'captain_name' => $name,
  'honorific' => DOCUMENT_SIGNATORY_HONORIFIC,
  'title' => DOCUMENT_SIGNATORY_TITLE,
]);
