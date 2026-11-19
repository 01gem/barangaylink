<?php
/**
 * BarangayLink - List complaint types for the management console. Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET -> {types, limits}. Every type, inactive included, each with its fields
 * and options (inactive included), version numbers, and usage counts.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_admin.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();
header('Cache-Control: private, no-store');

try {
  $types = complaint_types_admin_list(get_db());
} catch (Throwable $error) {
  json_db_error('Failed to list complaint types', $error->getMessage());
}
json_success([
  'types' => $types,
  'limits' => [
    'max_active_fields' => COMPLAINT_TYPE_MAX_ACTIVE_FIELDS,
    'max_options' => COMPLAINT_TYPE_MAX_OPTIONS,
    'max_text_length' => COMPLAINT_TYPE_MAX_TEXT_LENGTH,
  ],
]);
