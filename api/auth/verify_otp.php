<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Step 2 of password recovery: POST {code}. The account comes only from the
 * recovery session written by request_otp.php; any account_type/username in the
 * body is ignored. Does not consume the code (reset_password.php does).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_recovery.php';

require_post();
$input = read_json_input();
$code = trim((string)($input['code'] ?? ''));

session_start();
$state = recovery_session_state();
session_write_close();

if ($state === null || !preg_match('/^\d{6}$/', $code)) {
  json_error('Invalid or expired code.');
}

$accountType = $state['account_type'];
$accountId = $state['account_id'];
$db = get_db();
$table = recovery_account_table($accountType);
$started = false;

try {
  if (!$db->begin_transaction()) {
    throw new RuntimeException('Could not start code verification.');
  }
  $started = true;
  $account = recovery_lock_by_id($db, $table, $accountId);
  $otp = $account === null ? null : recovery_latest_code($db, $accountType, $accountId);
  if ($otp === null || (int)$otp['attempts'] >= 5) {
    if (!$db->rollback()) throw new RuntimeException('Could not finish code verification.');
    $started = false;
    json_error('Invalid or expired code.');
  }

  if (!hash_equals((string)$otp['code'], $code)) {
    recovery_bump_attempt($db, (int)$otp['id']);
    if (!$db->commit()) throw new RuntimeException('Could not record the code attempt.');
    $started = false;
    json_error('Invalid or expired code.');
  }

  if (!$db->commit()) throw new RuntimeException('Could not finish code verification.');
  $started = false;
} catch (Throwable $error) {
  if ($started) recovery_rollback($db);
  error_log('Password recovery verify failed: ' . $error->getMessage());
  json_error('Unable to verify code.', 500);
}

json_success(['message' => 'Code verified.']);
