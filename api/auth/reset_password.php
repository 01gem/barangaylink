<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Step 3 of password recovery: POST {code, new_password}. The account comes only
 * from the recovery session written by request_otp.php; any account_type/username
 * in the body is ignored. Success consumes every open code, clears the recovery
 * state and issues a new session id.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_recovery.php';

require_post();
$input = read_json_input();
$code = trim((string)($input['code'] ?? ''));
$newPassword = (string)($input['new_password'] ?? '');

// The session stays open (and locked) until the state is cleared below, so two
// submits with the same cookie run one after the other.
session_start();
$state = recovery_session_state();

if ($state === null || !preg_match('/^\d{6}$/', $code)) {
  session_write_close();
  json_error('Invalid or expired reset code.');
}
if ($newPassword === '') {
  session_write_close();
  json_error('A 6-digit code and a new password are required.');
}

$accountType = $state['account_type'];
$accountId = $state['account_id'];
$db = get_db();
$table = recovery_account_table($accountType);
$started = false;

try {
  if (!$db->begin_transaction()) {
    throw new RuntimeException('Could not start password reset.');
  }
  $started = true;
  $account = recovery_lock_by_id($db, $table, $accountId);
  $otp = $account === null ? null : recovery_latest_code($db, $accountType, $accountId);
  if ($otp === null || (int)$otp['attempts'] >= 5) {
    if (!$db->rollback()) throw new RuntimeException('Could not finish password reset.');
    $started = false;
    session_write_close();
    json_error('Invalid or expired reset code.');
  }

  if (!hash_equals((string)$otp['code'], $code)) {
    recovery_bump_attempt($db, (int)$otp['id']);
    if (!$db->commit()) throw new RuntimeException('Could not record the reset attempt.');
    $started = false;
    session_write_close();
    json_error('Invalid or expired reset code.');
  }

  $hash = password_hash($newPassword, PASSWORD_DEFAULT);
  if ($hash === false) throw new RuntimeException('Could not hash the new password.');
  recovery_set_password($db, $table, $accountId, $hash);
  recovery_consume_unused($db, $accountType, $accountId);
  if (!$db->commit()) throw new RuntimeException('Could not commit password reset.');
  $started = false;
} catch (Throwable $error) {
  if ($started) recovery_rollback($db);
  error_log('Password reset failed: ' . $error->getMessage());
  session_write_close();
  json_error('Unable to process password reset.', 500);
}

unset($_SESSION['pw_reset']);
session_regenerate_id(true);
session_write_close();

json_success(['message' => 'Your password has been reset. You can now log in.']);
