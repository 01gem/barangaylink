<?php
/**
 * BarangayLink - Search active residents by name, for the party editor (complaint revision Phase 5). Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?q=<2..50 characters> -> {residents: [{id, full_name, purok_zone, address}]}, at most 10.
 * No contact numbers. % and _ in q match literally.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_community.php';
require_once __DIR__ . '/_parties.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();
header('Cache-Control: private, no-store');

$q = $_GET['q'] ?? null;
if (!is_string($q)) json_error('Enter 2 to 50 characters.', 422);
try {
  $residents = complaint_party_search_residents(get_db(), $q);
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to search residents', $error->getMessage());
}
json_success(['residents' => $residents]);
