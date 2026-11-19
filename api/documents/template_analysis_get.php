<?php
/**
 * BarangayLink - Latest AI placeholder analysis for a template version.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_analysis.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);

$versionId = (int)($_GET['version_id'] ?? 0);
if ($versionId <= 0) json_error('Version is required.', 422);
try {
    $db = get_db();
    $version = document_template_version($db, $versionId);
    $analysis = $version ? document_analysis_latest($db, $versionId) : null;
    $stale = $analysis ? document_analysis_is_stale($analysis, $version) : false;
} catch (Throwable $e) {
    json_db_error('Template analysis lookup failed', $e->getMessage());
}
if (!$version) json_error('Template version is unavailable.', 404);
if (!$analysis) json_error('No analysis exists for this version.', 404, 'no_analysis');

$suggestions = json_decode((string)$analysis['suggestions'], true);
// Server file names and hashes are never returned.
json_success([
    'analysis_id' => (int)$analysis['id'],
    'status' => (string)$analysis['status'],
    'paragraph_count' => (int)$analysis['paragraph_count'],
    'suggestions' => is_array($suggestions) ? $suggestions : [],
    'dropped_count' => (int)$analysis['dropped_count'],
    'created_at' => (string)$analysis['created_at'],
    'stale' => $stale,
    // True when a failed attempt newer than the analysis returned here exists.
    'last_attempt_failed' => (bool)$analysis['last_attempt_failed'],
]);
