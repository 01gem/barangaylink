<?php
/**
 * BarangayLink - Locked password-recovery operations.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Callers start the transaction, lock the account, then commit or roll back.
 * These helpers throw instead of emitting a response.
 */

function recovery_account_table(string $accountType): string {
  if ($accountType === 'resident') return 'residents';
  if ($accountType === 'official') return 'barangay_officials';
  error_log('Password recovery rejected an account type.');
  throw new RuntimeException('Could not process password recovery.');
}

function recovery_rollback(mysqli $db): void {
  try {
    if (!$db->rollback()) {
      error_log('Password recovery rollback failed: ' . $db->error);
    }
  } catch (Throwable $error) {
    error_log('Password recovery rollback failed: ' . $error->getMessage());
  }
}

// Every request_otp response takes at least this long (tests may define a
// smaller value before this file loads; HTTP requests cannot).
if (!defined('RECOVERY_MIN_RESPONSE_MS')) define('RECOVERY_MIN_RESPONSE_MS', 3000);

const RECOVERY_THROTTLE_LIMIT = 5;
const RECOVERY_THROTTLE_WINDOW_MINUTES = 15;

/** The hint/IP-hash key, or null (no hints) when missing or shorter than 32 characters. */
function recovery_hint_key(): ?string {
  $key = trim((string)(getenv('PASSWORD_RESET_HINT_KEY') ?: ''));
  if (strlen($key) < 32) {
    error_log('Password reset hint key missing or short.');
    return null;
  }
  return $key;
}

/** '+639XXXXXXXXX' -> '+63 9** *** *NNN'. */
function recovery_mask_phone(string $e164): string {
  return '+63 9** *** *' . substr($e164, -3);
}

/** A stable made-up hint for an identifier with no number to show. */
function recovery_fake_hint(string $accountType, string $identifier, string $key): string {
  $mac = hash_hmac('sha256', $accountType . '|' . mb_strtolower(trim($identifier)), $key, true);
  $value = unpack('N', substr($mac, 0, 4))[1];
  return '+63 9** *** *' . str_pad((string)($value % 1000), 3, '0', STR_PAD_LEFT);
}

/** Only active accounts can recover; an inactive one is treated as not found. */
function recovery_active_row(?array $row): ?array {
  if ($row === null || (string)($row['status'] ?? '') !== 'active') return null;
  return $row;
}

/**
 * Locks the account an identifier names: a PH mobile number is looked up by
 * contact_key first, then (no row) as a username; anything else as a username.
 * Returns {id, contact_key, status} or null (not found or inactive).
 */
function recovery_find_account(mysqli $db, string $table, string $identifier): ?array {
  if ($table === 'residents') {
    $byKey = 'SELECT id, contact_key, status FROM residents WHERE contact_key = ? LIMIT 1 FOR UPDATE';
    $byUser = 'SELECT id, contact_key, status FROM residents WHERE username = ? LIMIT 1 FOR UPDATE';
  } elseif ($table === 'barangay_officials') {
    $byKey = 'SELECT id, contact_key, status FROM barangay_officials WHERE contact_key = ? LIMIT 1 FOR UPDATE';
    $byUser = 'SELECT id, contact_key, status FROM barangay_officials WHERE username = ? LIMIT 1 FOR UPDATE';
  } else {
    error_log('Password recovery rejected an account table.');
    throw new RuntimeException('Could not process password recovery.');
  }
  $phone = normalize_ph_phone($identifier);
  if ($phone !== null) {
    $row = recovery_execute($db, $byKey, 's', [$phone], true);
    if ($row !== null) return recovery_active_row($row);
  }
  return recovery_active_row(recovery_execute($db, $byUser, 's', [$identifier], true));
}

/** Locks an account by id (from the recovery session). Null when missing or inactive. */
function recovery_lock_by_id(mysqli $db, string $table, int $id): ?array {
  if ($table === 'residents') {
    $sql = 'SELECT id, contact_key, status FROM residents WHERE id = ? LIMIT 1 FOR UPDATE';
  } elseif ($table === 'barangay_officials') {
    $sql = 'SELECT id, contact_key, status FROM barangay_officials WHERE id = ? LIMIT 1 FOR UPDATE';
  } else {
    error_log('Password recovery rejected an account table.');
    throw new RuntimeException('Could not process password recovery.');
  }
  if ($id <= 0) return null;
  return recovery_active_row(recovery_execute($db, $sql, 'i', [$id], true));
}

/** Hash of the client IP (REMOTE_ADDR only; forwarded headers are never trusted). */
function recovery_ip_hash(?string $key): string {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  return $key !== null ? hash_hmac('sha256', 'ip|' . $ip, $key) : hash('sha256', 'ip|' . $ip);
}

function recovery_account_hash(string $accountType, int $accountId): string {
  return hash('sha256', $accountType . ':' . $accountId);
}

/** Drops throttle rows older than a day. */
function recovery_throttle_purge(mysqli $db): void {
  recovery_execute($db, 'DELETE FROM password_reset_throttle WHERE created_at < NOW() - INTERVAL 1 DAY', '', [], false);
}

/**
 * True when $hash already has RECOVERY_THROTTLE_LIMIT requests in the window
 * (blocked). Otherwise records this request and returns false.
 */
function recovery_throttle_hit(mysqli $db, string $scope, string $hash): bool {
  if (!in_array($scope, ['ip', 'account'], true) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
    throw new RuntimeException('Could not process password recovery.');
  }
  $row = recovery_execute(
    $db,
    'SELECT COUNT(*) AS c FROM password_reset_throttle
      WHERE scope = ? AND key_hash = ? AND created_at > NOW() - INTERVAL ' . RECOVERY_THROTTLE_WINDOW_MINUTES . ' MINUTE',
    'ss',
    [$scope, $hash],
    true
  );
  if ((int)($row['c'] ?? 0) >= RECOVERY_THROTTLE_LIMIT) return true;
  recovery_execute($db, 'INSERT INTO password_reset_throttle (scope, key_hash) VALUES (?, ?)', 'ss', [$scope, $hash], false);
  return false;
}

/** Sleeps until RECOVERY_MIN_RESPONSE_MS have passed since $startedAt (microtime(true)). */
function recovery_pad(float $startedAt): void {
  $remaining = RECOVERY_MIN_RESPONSE_MS / 1000 - (microtime(true) - $startedAt);
  if ($remaining > 0) usleep((int)round($remaining * 1000000));
}

/**
 * The recovery session state, or null when missing, malformed or expired.
 * @return array{account_type:string,account_id:int,expires:int}|null
 */
function recovery_session_state(): ?array {
  $state = $_SESSION['pw_reset'] ?? null;
  if (!is_array($state)) return null;
  $type = $state['account_type'] ?? null;
  $id = $state['account_id'] ?? null;
  $expires = $state['expires'] ?? null;
  if (!in_array($type, ['resident', 'official'], true) || !is_int($id) || $id < 0 || !is_int($expires) || $expires < time()) {
    return null;
  }
  return ['account_type' => $type, 'account_id' => $id, 'expires' => $expires];
}

function recovery_recent_code(mysqli $db, string $accountType, int $accountId): ?array {
  return recovery_execute(
    $db,
    'SELECT id FROM otp_codes
      WHERE account_type = ? AND account_id = ? AND used = 0 AND expires_at > NOW()
        AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
      ORDER BY created_at DESC, id DESC
      LIMIT 1
      FOR UPDATE',
    'si',
    [$accountType, $accountId],
    true
  );
}

function recovery_latest_code(mysqli $db, string $accountType, int $accountId): ?array {
  return recovery_execute(
    $db,
    'SELECT id, code, attempts FROM otp_codes
      WHERE account_type = ? AND account_id = ? AND used = 0 AND expires_at > NOW()
      ORDER BY created_at DESC, id DESC
      LIMIT 1
      FOR UPDATE',
    'si',
    [$accountType, $accountId],
    true
  );
}

function recovery_consume_unused(mysqli $db, string $accountType, int $accountId): void {
  recovery_execute(
    $db,
    'UPDATE otp_codes SET used = 1 WHERE account_type = ? AND account_id = ? AND used = 0',
    'si',
    [$accountType, $accountId],
    false
  );
}

function recovery_insert_code(mysqli $db, string $accountType, int $accountId, string $code): void {
  recovery_execute(
    $db,
    'INSERT INTO otp_codes (account_type, account_id, code, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 10 MINUTE)',
    'sis',
    [$accountType, $accountId, $code],
    false
  );
}

function recovery_bump_attempt(mysqli $db, int $otpId): void {
  recovery_execute(
    $db,
    'UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?',
    'i',
    [$otpId],
    false
  );
}

function recovery_set_password(mysqli $db, string $table, int $accountId, string $hash): void {
  if ($table === 'residents') {
    $sql = 'UPDATE residents SET password = ?, failed_logins = 0, locked_until = NULL WHERE id = ?';
  } elseif ($table === 'barangay_officials') {
    $sql = 'UPDATE barangay_officials SET password = ?, failed_logins = 0, locked_until = NULL WHERE id = ?';
  } else {
    error_log('Password recovery rejected an account table.');
    throw new RuntimeException('Could not process password recovery.');
  }
  recovery_execute($db, $sql, 'si', [$hash, $accountId], false);
}

function recovery_execute(mysqli $db, string $sql, string $types, array $values, bool $fetch): ?array {
  $stmt = $db->prepare($sql);
  if (!$stmt) {
    error_log('Password recovery prepare failed: ' . $db->error);
    throw new RuntimeException('Could not process password recovery.');
  }
  if ($types !== '') {
    $bound = [];
    foreach ($values as $index => $value) {
      $bound[$index] = $value;
    }
    $args = [$types];
    foreach ($bound as $index => $_) {
      $args[] = &$bound[$index];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $args)) {
      $detail = $stmt->error;
      $stmt->close();
      error_log('Password recovery bind failed: ' . $detail);
      throw new RuntimeException('Could not process password recovery.');
    }
  }
  if (!$stmt->execute()) {
    $detail = $stmt->error;
    $stmt->close();
    error_log('Password recovery execute failed: ' . $detail);
    throw new RuntimeException('Could not process password recovery.');
  }
  if (!$fetch) {
    $stmt->close();
    return null;
  }
  $result = $stmt->get_result();
  if (!$result) {
    $detail = $stmt->error;
    $stmt->close();
    error_log('Password recovery result failed: ' . $detail);
    throw new RuntimeException('Could not process password recovery.');
  }
  $row = $result->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}
