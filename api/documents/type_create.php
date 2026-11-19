<?php
/**
 * BarangayLink - Create a document type (Phase 5A). Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {name, code?, fee, processing_days, requirements?, field_keys:[...]}
 * -> {type_id, code}. The new type has no template version yet, so it stays
 * hidden from residents until a first version is activated.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_type_create.php';
session_start();
require_official_session();
require_post();

$db = get_db();
try {
    $created = document_type_create($db, read_json_input(), (int)$_SESSION['official_id'], (string)($_SESSION['official_name'] ?? ''));
} catch (RequestProblem $e) {
    json_error($e->getMessage(), $e->status);
} catch (Throwable $e) {
    json_db_error('Failed to create document type', $e->getMessage());
}
json_success(['type_id' => $created['type_id'], 'code' => $created['code']]);
