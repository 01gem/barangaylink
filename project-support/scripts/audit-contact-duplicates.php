<?php
/**
 * BarangayLink - Read-only audit of contact numbers before the unique-contact migration.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Usage (CLI only):
 *   php project-support/scripts/audit-contact-duplicates.php <database_name>
 *
 * Reports, per table (residents, barangay_officials):
 *   - duplicate groups: ids sharing one normalized number (shown masked)
 *   - non-blank contacts that are not a valid PH mobile number (id only)
 *   - blank contacts (count)
 *   - usernames that look like phone numbers (id only)
 *   - valid numbers not stored as +639XXXXXXXXX (count)
 * Never prints a full number. Never writes: the session is READ ONLY.
 *
 * Exit codes: 0 = clean (migration 20261121_contact_unique.sql will pass),
 *             1 = duplicates or invalid values (fix the rows by hand first),
 *             2 = usage or connection error.
 *
 * The normalization is the SQL expression of the contact_key generated column
 * (database/database.sql, project-support/migrations/20261121_contact_unique.sql),
 * evaluated by MySQL itself, so the audit and the unique key always agree.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$dbName = (string)($argv[1] ?? '');
if ($dbName === '' || preg_match('/^[A-Za-z0-9_]+$/', $dbName) !== 1) {
  fwrite(STDERR, "Usage: php project-support/scripts/audit-contact-duplicates.php <database_name>\n");
  fwrite(STDERR, "A database name (letters, digits, underscore) is required.\n");
  exit(2);
}

require_once __DIR__ . '/../../db.php';

/** Strip the separators normalize_ph_phone() strips. */
const AUDIT_STRIPPED = "REGEXP_REPLACE(TRIM(%1\$s), '[[:space:]().-]', '')";
/** Accepted PH mobile forms after stripping: +63/63/0/none, then 9 and 9 digits. */
const AUDIT_PATTERN = "'^[+]?(63|0)?9[0-9]{9}\$'";

function audit_valid_sql(string $column): string {
  return 'REGEXP_LIKE(' . sprintf(AUDIT_STRIPPED, $column) . ', ' . AUDIT_PATTERN . ')';
}

/** Same expression as the contact_key generated column. */
function audit_key_sql(string $column): string {
  return 'CASE WHEN TRIM(' . $column . ") = '' THEN NULL"
    . ' WHEN ' . audit_valid_sql($column)
    . " THEN CONCAT('+63', RIGHT(" . sprintf(AUDIT_STRIPPED, $column) . ', 10))'
    . ' ELSE TRIM(' . $column . ') END';
}

function audit_mask(string $key): string {
  if (preg_match('/^\+639[0-9]{9}$/', $key) !== 1) return '(invalid value)';
  return '+63 9** *** *' . substr($key, -3);
}

/** @return list<array<string,mixed>> */
function audit_rows(mysqli $db, string $sql): array {
  $result = $db->query($sql);
  if ($result === false) {
    fwrite(STDERR, 'Query failed: ' . $db->error . "\n");
    exit(2);
  }
  $rows = $result->fetch_all(MYSQLI_ASSOC);
  $result->free();
  return $rows;
}

mysqli_report(MYSQLI_REPORT_OFF);
try {
  $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $dbName);
} catch (Throwable $error) {
  fwrite(STDERR, "Could not connect to database {$dbName}.\n");
  exit(2);
}
if ($db->connect_error) {
  fwrite(STDERR, "Could not connect to database {$dbName}.\n");
  exit(2);
}
$db->set_charset('utf8mb4');
if (!$db->query('SET SESSION TRANSACTION READ ONLY') || !$db->query('START TRANSACTION READ ONLY')) {
  fwrite(STDERR, 'Could not open a read-only transaction: ' . $db->error . "\n");
  exit(2);
}

$problems = 0;
echo "Contact audit for database {$dbName}\n";

foreach (['residents', 'barangay_officials'] as $table) {
  echo "\n== {$table} ==\n";
  $exists = audit_rows($db, "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'");
  if ((int)$exists[0]['c'] !== 1) {
    echo "  table not found, skipped\n";
    continue;
  }

  $key = audit_key_sql('contact');
  $valid = audit_valid_sql('contact');

  $total = (int)audit_rows($db, "SELECT COUNT(*) AS c FROM `{$table}`")[0]['c'];
  echo "  rows: {$total}\n";

  $groups = audit_rows($db,
    "SELECT k, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id SEPARATOR ', ') AS ids
       FROM (SELECT id, {$key} AS k FROM `{$table}`) keyed
      WHERE k IS NOT NULL
      GROUP BY k
     HAVING COUNT(*) > 1
      ORDER BY MIN(id)");
  echo '  duplicate groups: ' . count($groups) . "\n";
  foreach ($groups as $group) {
    echo '    ' . audit_mask((string)$group['k']) . ' x' . (int)$group['n'] . ' -> ids ' . $group['ids'] . "\n";
  }

  $invalid = audit_rows($db, "SELECT id FROM `{$table}` WHERE TRIM(contact) <> '' AND NOT {$valid} ORDER BY id");
  echo '  invalid (non-blank, not a PH mobile): ' . count($invalid) . "\n";
  if ($invalid) {
    echo '    ids ' . implode(', ', array_map(static fn(array $r): string => (string)$r['id'], $invalid)) . "\n";
  }

  $blank = (int)audit_rows($db, "SELECT COUNT(*) AS c FROM `{$table}` WHERE TRIM(contact) = ''")[0]['c'];
  echo "  blank (allowed, cannot reset by SMS): {$blank}\n";

  $phoneUsers = audit_rows($db, 'SELECT id FROM `' . $table . '` WHERE ' . audit_valid_sql('username') . ' ORDER BY id');
  echo '  usernames that look like phone numbers (warning only): ' . count($phoneUsers) . "\n";
  if ($phoneUsers) {
    echo '    ids ' . implode(', ', array_map(static fn(array $r): string => (string)$r['id'], $phoneUsers)) . "\n";
  }

  $nonE164 = (int)audit_rows($db,
    "SELECT COUNT(*) AS c FROM `{$table}` WHERE TRIM(contact) <> '' AND {$valid} AND TRIM(contact) <> ({$key})")[0]['c'];
  echo "  valid but not stored as +639XXXXXXXXX (informational): {$nonE164}\n";

  $problems += count($groups) + count($invalid);
}

$db->query('ROLLBACK');
$db->close();

if ($problems > 0) {
  echo "\nRESULT: {$problems} problem(s). Fix these rows by hand, then run the audit again before the migration.\n";
  exit(1);
}
echo "\nRESULT: clean. Migration 20261121_contact_unique.sql can run.\n";
exit(0);
