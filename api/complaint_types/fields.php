<?php
/**
 * BarangayLink - One active complaint type with its active fields, for filing forms.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?type_id=N -> {type}. Any active session, resident or official.
 * Only active fields and active options are returned. A missing or inactive
 * type answers 404.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_admin.php';
session_start();
enforce_active_session();
// enforce_active_session() only re-checks identities that are present; an
// empty session must be refused here.
if (empty($_SESSION['official_id']) && empty($_SESSION['resident_id'])) json_error('Unauthorized', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();
header('Cache-Control: private, no-store');

$raw = $_GET['type_id'] ?? null;
if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,9}$/', $raw)) json_error('Complaint type not found.', 404);

$type = complaint_type_public_fields((int)$raw);
if ($type === null) json_error('Complaint type not found.', 404);
json_success(['type' => $type]);
