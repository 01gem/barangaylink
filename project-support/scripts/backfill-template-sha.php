<?php
/**
 * BarangayLink - One-off backfill of document_template_versions.sha256.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * The seeded version rows were inserted without a checksum. This script hashes
 * each template file on disk and fills sha256 only where it IS NULL. No other
 * column and no file is changed, and running it again is a no-op.
 *
 * Back up the database first, then:
 *   php project-support/scripts/backfill-template-sha.php            dry run (default)
 *   php project-support/scripts/backfill-template-sha.php --apply    write the checksums
 *
 * --database=NAME points the run at another schema (used by the scratch-database proof).
 * Exit code: 0 done, 1 at least one row skipped or failed, 2 bad arguments.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Source layout: project-support/scripts/. Deployed layout: scripts/.
$projectRoot = is_file(dirname(__DIR__, 2) . '/db.php') ? dirname(__DIR__, 2) : dirname(__DIR__);

$apply = false; $database = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; continue; }
    if (preg_match('/^--database=([A-Za-z0-9_]+)$/', $argument, $match)) { $database = $match[1]; continue; }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

require_once $projectRoot . '/db.php';
if ($database !== null) $DB_NAME = $database;
require_once $projectRoot . '/api/documents/_templates.php';

$db = get_db();
$current = (string)$db->query('SELECT DATABASE()')->fetch_row()[0];
echo ($apply ? 'APPLY' : 'DRY RUN (add --apply to write)') . " on database {$current}\n";

if (!document_registry_active_version_table_exists($db)) {
    echo "No document_template_versions table. Nothing to do.\n";
    exit(0);
}

$result = $db->query('SELECT id, document_type_id, version_no, file_name, status FROM document_template_versions WHERE sha256 IS NULL ORDER BY id');
if (!$result) { fwrite(STDERR, "Could not read template versions.\n"); exit(1); }
$rows = $result->fetch_all(MYSQLI_ASSOC);
if (!$rows) { echo "Every version already has a checksum. Nothing to do.\n"; exit(0); }

$update = $db->prepare('UPDATE document_template_versions SET sha256 = ? WHERE id = ? AND sha256 IS NULL');
if (!$update) { fwrite(STDERR, "Could not prepare the update.\n"); exit(1); }

$filled = 0; $problems = 0;
foreach ($rows as $row) {
    $id = (int)$row['id'];
    $label = sprintf('id=%d type=%d v%d %s [%s]', $id, (int)$row['document_type_id'], (int)$row['version_no'], (string)$row['file_name'], (string)$row['status']);
    try {
        // Same path guard the application uses; the file is only read.
        $sha = hash_file('sha256', document_template_version_path((string)$row['file_name']));
    } catch (Throwable $e) {
        $sha = false;
    }
    if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
        echo "SKIP   {$label}: template file is missing or unreadable\n";
        $problems++;
        continue;
    }
    if (!$apply) {
        echo "WOULD  {$label} -> {$sha}\n";
        $filled++;
        continue;
    }
    $update->bind_param('si', $sha, $id);
    if (!$update->execute()) {
        echo "FAIL   {$label}: update failed\n";
        $problems++;
        continue;
    }
    if ($update->affected_rows === 1) {
        echo "FILLED {$label} -> {$sha}\n";
        $filled++;
    } else {
        echo "KEPT   {$label}: a checksum was stored meanwhile\n";
    }
}
$update->close();

echo ($apply ? 'Filled' : 'Would fill') . " {$filled} of " . count($rows) . " row(s) without a checksum; {$problems} skipped or failed.\n";
exit($problems > 0 ? 1 : 0);
