<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Step 1 of password recovery: POST {account_type, identifier} where identifier
 * is a username or a mobile number.
 * Every outcome (code sent, not found, inactive, no number, cooldown, account
 * throttled, SMS failure) returns the same body and status and takes at least
 * RECOVERY_MIN_RESPONSE_MS. The real reason only goes to error_log, without the
 * identifier, number or IP. The account is remembered in the session; verify and
 * reset use only that, never a client identifier.
 */

$recoveryStartedAt = microtime(true);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_recovery.php';
require_once __DIR__ . '/_recovery_transport.php';

const RECOVERY_GENERIC_MESSAGE = "If an account matches, a reset code was sent to the mobile number on file. Didn't get a code? Contact the barangay office.";
const RECOVERY_THROTTLE_MESSAGE = 'Too many requests. Please try again later.';

require_post();
$input = read_json_input();
$accountType = trim((string)($input['account_type'] ?? ''));
$rawIdentifier = $input['identifier'] ?? '';
$identifier = is_string($rawIdentifier) ? trim($rawIdentifier) : '';

if (!in_array($accountType, ['resident', 'official'], true)
    || $identifier === ''
    || mb_strlen($identifier) > 100
    || preg_match('/[\p{Cc}\p{Cf}]/u', $identifier) !== 0) {
  json_error('Account type and username or mobile number are required.');
}

session_start();
session_regenerate_id(true);

$db = get_db();
$table = recovery_account_table($accountType);
$key = recovery_hint_key();
$started = false;
$account = null;
$send = false;
$code = '';

try {
  recovery_throttle_purge($db);
  if (recovery_throttle_hit($db, 'ip', recovery_ip_hash($key))) {
    error_log('Password recovery throttled (ip).');
    session_write_close();
    recovery_pad($recoveryStartedAt);
    json_error(RECOVERY_THROTTLE_MESSAGE, 429);
  }

  if (!$db->begin_transaction()) {
    throw new RuntimeException('Could not start password recovery.');
  }
  $started = true;
  $account = recovery_find_account($db, $table, $identifier);
  $reason = 'sent';
  if ($account === null) {
    $reason = 'no account';
  } elseif ($account['contact_key'] === null || $account['contact_key'] === '') {
    $reason = 'no contact';
  } elseif (recovery_throttle_hit($db, 'account', recovery_account_hash($accountType, (int)$account['id']))) {
    $reason = 'account throttled';
  } elseif (recovery_recent_code($db, $accountType, (int)$account['id']) !== null) {
    $reason = 'cooldown';
  } else {
    $send = true;
    recovery_consume_unused($db, $accountType, (int)$account['id']);
    $code = (string)random_int(100000, 999999);
    recovery_insert_code($db, $accountType, (int)$account['id'], $code);
  }
  if (!$db->commit()) {
    throw new RuntimeException('Could not commit password recovery.');
  }
  $started = false;
  if (!$send) error_log('Password recovery: no code sent (' . $reason . ', ' . $accountType . ').');
} catch (Throwable $error) {
  if ($started) recovery_rollback($db);
  error_log('Password recovery request failed: ' . $error->getMessage());
  session_write_close();
  recovery_pad($recoveryStartedAt);
  json_error('Unable to process password recovery.', 500);
}

// Hint: the typed number, else the account's number, else a stable fake one.
$hint = null;
if ($key !== null) {
  $typed = normalize_ph_phone($identifier);
  if ($typed !== null) {
    $hint = recovery_mask_phone($typed);
  } elseif ($account !== null && (string)($account['contact_key'] ?? '') !== '') {
    $hint = recovery_mask_phone((string)$account['contact_key']);
  } else {
    $hint = recovery_fake_hint($accountType, $identifier, $key);
  }
}

$_SESSION['pw_reset'] = [
  'account_type' => $accountType,
  'account_id' => $account !== null ? (int)$account['id'] : 0,
  'expires' => time() + 600,
];
session_write_close();

if ($send) {
  $sent = recovery_send_reset_sms((string)$account['contact_key'], $code);
  if (empty($sent['ok'])) {
    error_log('Password reset SMS failed (' . $accountType . ', status ' . (int)($sent['status'] ?? 0) . ').');
  }
}

recovery_pad($recoveryStartedAt);
json_success(['message' => RECOVERY_GENERIC_MESSAGE, 'hint' => $hint]);
