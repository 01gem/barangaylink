<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

// Session cookie hardening. Must run before any session_start().
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');

/**
 * Per-request nonce for the few inline <script>/<style> blocks the pages carry.
 * The same value is placed in the Content-Security-Policy header below.
 */
function csp_nonce(): string {
  static $nonce = null;
  if ($nonce === null) {
    $nonce = base64_encode(random_bytes(18));
  }
  return $nonce;
}

/**
 * Security headers for every response. db.php is loaded by every page and every
 * API file, so the 'page' profile is sent as soon as this file loads;
 * api/common.php then switches the policy to 'api' for JSON and file responses.
 *
 * Page policy notes:
 * - <script>/<style> elements: same origin, the two font/icon CDNs (styles
 *   only), or a block carrying the request nonce. Injected blocks are refused.
 * - *-attr 'unsafe-inline': the pages and the JS-built markup still use inline
 *   onclick="" handlers and style="" attributes.
 * - script-src / style-src are the fallback for browsers without the
 *   -elem / -attr directives.
 * - blob: covers the template PDF preview frame and local photo previews.
 */
function send_security_headers(string $profile = 'page'): void {
  if (PHP_SAPI === 'cli' || headers_sent()) {
    return;
  }
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: same-origin');
  header('X-Frame-Options: SAMEORIGIN');

  if ($profile === 'api') {
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
    return;
  }

  $nonce = "'nonce-" . csp_nonce() . "'";
  $styleHosts = 'https://fonts.googleapis.com https://cdnjs.cloudflare.com';
  header('Content-Security-Policy: ' . implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline'",
    "script-src-elem 'self' " . $nonce,
    "script-src-attr 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline' " . $styleHosts,
    "style-src-elem 'self' " . $nonce . ' ' . $styleHosts,
    "style-src-attr 'unsafe-inline'",
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
    "img-src 'self' blob:",
    "connect-src 'self'",
    "frame-src 'self' blob:",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'self'",
  ]));
}

send_security_headers();

function load_env($filePath = __DIR__ . '/.env') {
  if (!file_exists($filePath)) {
    return;
  }

  $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$lines) {
    return;
  }

  foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || $line[0] === '#') {
      continue;
    }

    if (strpos($line, '=') === false) {
      continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);

    if (!empty($key) && !isset($_ENV[$key]) && !isset($_SERVER[$key])) {
      putenv("$key=$value");
      $_ENV[$key] = $value;
    }
  }
}

load_env(__DIR__ . '/.env');
load_env(__DIR__ . '/.env.sms');
load_env();

// Database settings come from .env; the fallbacks match a default local Laragon install.
$DB_HOST = getenv('DB_HOST') !== false ? (string)getenv('DB_HOST') : 'localhost';
$DB_USER = getenv('DB_USER') !== false ? (string)getenv('DB_USER') : 'root';
$DB_PASS = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '';
$DB_NAME = getenv('DB_NAME') !== false ? (string)getenv('DB_NAME') : 'barangay_system_test_environment';

/**
 * Open the shared database connection.
 * A failed attempt is never cached. Callers that must not terminate the process
 * use this function. get_db() keeps the existing die-on-failure behavior.
 *
 * @throws RuntimeException when the connection or charset setup fails.
 */
function db_connection(): mysqli {
  global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
  static $db = null;
  if ($db instanceof mysqli) {
    return $db;
  }

  // PHP >= 8.1 throws mysqli_sql_exception by default; this codebase checks return
  // values, so keep the return-false behavior.
  mysqli_report(MYSQLI_REPORT_OFF);
  try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
  } catch (Throwable $error) {
    error_log('Database connection failed: ' . $error->getMessage());
    throw new RuntimeException('Database connection failed.');
  }
  if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    throw new RuntimeException('Database connection failed.');
  }
  if (!$conn->set_charset('utf8mb4')) {
    $detail = $conn->error;
    $conn->close();
    error_log('Database charset failed: ' . $detail);
    throw new RuntimeException('Database connection failed.');
  }

  $db = $conn;
  return $db;
}

function get_db() {
  try {
    return db_connection();
  } catch (RuntimeException $error) {
    die('Database connection failed.');
  }
}
?>
