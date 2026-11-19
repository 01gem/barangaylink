<?php
/**
 * BarangayLink - Manage complaint types and their extra fields (complaint revision Phase 3).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * All rules live here; the endpoints in this folder stay thin. Nothing is ever
 * deleted: types, fields and options are switched inactive instead.
 *
 * Every write runs in one transaction, locks the type row (field writes lock
 * the parent type row first, then the field row), checks the caller's version,
 * and on success writes an audit row and clears the per-request type cache.
 * Rule failures throw ComplaintTypeProblem, which carries the HTTP status.
 */

require_once __DIR__ . '/../complaints/_extra_fields.php';

const COMPLAINT_TYPE_MAX_ACTIVE_FIELDS = 15;
const COMPLAINT_TYPE_MAX_OPTIONS = 50;
const COMPLAINT_TYPE_MAX_TEXT_LENGTH = 1000;
const COMPLAINT_TYPE_DEFAULT_TEXT_LENGTH = 255;
const COMPLAINT_TYPE_MAX_SORT = 9999;
const COMPLAINT_TYPE_MAX_NUMBER = 9999999999.99; // DECIMAL(12,2)
// The D modifier matters: without it "$" also matches before a trailing newline.
const COMPLAINT_TYPE_CODE_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/D';
const COMPLAINT_FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,49}$/D';
const COMPLAINT_OPTION_VALUE_PATTERN = '/^[a-z0-9_]{1,50}$/D';
const COMPLAINT_TYPE_RESPONDENT_MODES = ['none', 'optional', 'required'];
const COMPLAINT_FIELD_TYPES = ['select', 'text', 'number', 'date'];
const COMPLAINT_TYPE_MSG_STALE = 'This item was changed by someone else. Reload and try again.';
const COMPLAINT_TYPE_MSG_NAME_TAKEN = 'A complaint type with this name already exists.';
const COMPLAINT_TYPE_MSG_CODE_TAKEN = 'A complaint type with this code already exists.';
const COMPLAINT_TYPE_MSG_KEY_TAKEN = 'This type already has a field with this key.';
const COMPLAINT_TYPE_MSG_OPTIONS_KEPT = 'Options cannot be removed; mark them inactive instead.';
const COMPLAINT_TYPE_MSG_FEE = 'Enter a fee from 0 to 100,000.00.';
const COMPLAINT_TYPE_MAX_FEE_CENTS = 10000000; // 100,000.00 (Phase 10, same rule as api/complaints/_billing.php)

final class ComplaintTypeProblem extends RuntimeException {
  public int $status;
  public function __construct(string $message, int $status = 422) {
    parent::__construct($message);
    $this->status = $status;
  }
}

// ---------------------------------------------------------------------------
// Pure helpers (no database)
// ---------------------------------------------------------------------------

/**
 * Phase 10 default fee: a JSON string /^\d{1,6}(\.\d{1,2})?$/D, 0 .. 100,000.00,
 * read as integer cents (never float). Returns the DECIMAL text 'N.NN'.
 */
function complaint_type_admin_fee(mixed $raw): string {
  if (!is_string($raw) || !preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/D', $raw, $m)) {
    throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_FEE, 422);
  }
  $cents = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
  if ($cents > COMPLAINT_TYPE_MAX_FEE_CENTS) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_FEE, 422);
  return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** DECIMAL text of a stored fee, normalized ('0.00' when the column is missing). */
function complaint_type_admin_fee_text(mixed $value): string {
  return $value === null ? '0.00' : complaint_type_admin_fee((string)$value);
}

/** Lowercase snake code from a display name. May return '' when nothing usable is left. */
function complaint_type_derive_code(string $name): string {
  $code = strtolower(strtr($name, ['ñ' => 'n', 'Ñ' => 'n']));
  $code = trim((string)preg_replace('/[^a-z0-9]+/', '_', $code), '_');
  if ($code !== '' && !preg_match('/^[a-z]/', $code)) $code = 'type_' . $code;
  $code = rtrim(substr($code, 0, 50), '_');
  return preg_match(COMPLAINT_TYPE_CODE_PATTERN, $code) ? $code : '';
}

/** $base, or $base_2, $base_3 ... when taken. Never longer than 50 characters. */
function complaint_type_unique_code(string $base, array $takenCodes): string {
  if (!in_array($base, $takenCodes, true)) return $base;
  for ($n = 2; $n < 10000; $n++) {
    $suffix = '_' . $n;
    $candidate = rtrim(substr($base, 0, 50 - strlen($suffix)), '_') . $suffix;
    if (!in_array($candidate, $takenCodes, true)) return $candidate;
  }
  throw new ComplaintTypeProblem('Enter a code for this type.', 422);
}

/**
 * JSON path of one extra field inside complaints.extra_fields_json.
 * The key is checked against the field-key pattern first; nothing unchecked
 * ever reaches a JSON path.
 */
function complaint_field_json_path(string $fieldKey): string {
  if (!preg_match(COMPLAINT_FIELD_KEY_PATTERN, $fieldKey)) {
    throw new ComplaintTypeProblem('Field key is not valid.', 422);
  }
  return '$."' . $fieldKey . '"';
}

/** Unknown top-level keys are refused. */
function complaint_type_admin_keys(array $input, array $allowed): void {
  foreach (array_keys($input) as $key) {
    if (!in_array($key, $allowed, true)) {
      throw new ComplaintTypeProblem('Unknown field: ' . (is_string($key) ? $key : 'index') . '.', 422);
    }
  }
}

/** A JSON integer inside [$min, $max]. Strings, floats and booleans are refused. */
function complaint_type_admin_int(array $input, string $key, int $min, int $max): int {
  $value = $input[$key] ?? null;
  if (!is_int($value) || $value < $min || $value > $max) {
    throw new ComplaintTypeProblem('A valid ' . $key . ' is required.', 422);
  }
  return $value;
}

function complaint_type_admin_bool(array $input, string $key): bool {
  if (!array_key_exists($key, $input) || !is_bool($input[$key])) {
    throw new ComplaintTypeProblem('A valid ' . $key . ' is required.', 422);
  }
  return $input[$key];
}

/** Trimmed single-line text of $min..$max characters. */
function complaint_type_admin_text(mixed $value, string $what, int $min, int $max): string {
  if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
    throw new ComplaintTypeProblem($what . ' must be text.', 422);
  }
  $value = trim($value);
  if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value)) {
    throw new ComplaintTypeProblem($what . ' contains characters that are not allowed.', 422);
  }
  $length = mb_strlen($value, 'UTF-8');
  if ($length < $min || $length > $max) {
    throw new ComplaintTypeProblem($min === $max
      ? $what . ' must be ' . $max . ' characters.'
      : $what . ' must be ' . $min . ' to ' . $max . ' characters.', 422);
  }
  return $value;
}

/**
 * The type payload, normalized. Keys that were not sent are left out, so an
 * update only touches what the caller sent. type_name is always required.
 */
function complaint_type_validate_type(array $input, bool $isCreate): array {
  complaint_type_admin_keys($input, ['id', 'version', 'type_name', 'type_code', 'description', 'respondent_mode', 'sort_order', 'default_fee']);
  $clean = ['type_name' => complaint_type_admin_text($input['type_name'] ?? null, 'Type name', 2, 100)];
  if (array_key_exists('type_code', $input) && $input['type_code'] !== null && $input['type_code'] !== '') {
    if (!is_string($input['type_code']) || !preg_match(COMPLAINT_TYPE_CODE_PATTERN, $input['type_code'])) {
      throw new ComplaintTypeProblem('Code must be 2 to 50 lowercase letters, numbers or underscores and start with a letter.', 422);
    }
    $clean['type_code'] = $input['type_code'];
  }
  if (array_key_exists('description', $input)) {
    $description = $input['description'] === null ? '' : complaint_type_admin_text($input['description'], 'Description', 0, 255);
    $clean['description'] = $description === '' ? null : $description;
  }
  if (array_key_exists('respondent_mode', $input)) {
    if (!is_string($input['respondent_mode']) || !in_array($input['respondent_mode'], COMPLAINT_TYPE_RESPONDENT_MODES, true)) {
      throw new ComplaintTypeProblem('Respondent mode must be none, optional or required.', 422);
    }
    $clean['respondent_mode'] = $input['respondent_mode'];
  }
  if (array_key_exists('sort_order', $input)) {
    $clean['sort_order'] = complaint_type_admin_int($input, 'sort_order', 0, COMPLAINT_TYPE_MAX_SORT);
  }
  if (array_key_exists('default_fee', $input)) $clean['default_fee'] = complaint_type_admin_fee($input['default_fee']);
  if ($isCreate) $clean += ['description' => null, 'respondent_mode' => 'none'];
  return $clean;
}

/**
 * Select options, normalized to [{value, label, is_active(bool)}] in the order
 * sent. Every value in $existingOptions must still be present.
 */
function complaint_type_validate_options(mixed $raw, array $existingOptions): array {
  if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
    throw new ComplaintTypeProblem('A select field needs a list of options.', 422);
  }
  if (count($raw) > COMPLAINT_TYPE_MAX_OPTIONS) {
    throw new ComplaintTypeProblem('A select field can have at most ' . COMPLAINT_TYPE_MAX_OPTIONS . ' options.', 422);
  }
  $clean = [];
  $seen = [];
  foreach ($raw as $option) {
    if (!is_array($option) || array_is_list($option)) {
      throw new ComplaintTypeProblem('Each option needs a value and a label.', 422);
    }
    complaint_type_admin_keys($option, ['value', 'label', 'is_active']);
    $value = $option['value'] ?? null;
    if (!is_string($value) || !preg_match(COMPLAINT_OPTION_VALUE_PATTERN, $value)) {
      throw new ComplaintTypeProblem('Option values must be 1 to 50 lowercase letters, numbers or underscores.', 422);
    }
    if (isset($seen[$value])) {
      throw new ComplaintTypeProblem('Option values must be unique within a field.', 422);
    }
    $seen[$value] = true;
    $active = true;
    if (array_key_exists('is_active', $option)) $active = complaint_type_admin_bool($option, 'is_active');
    $clean[] = [
      'value' => $value,
      'label' => complaint_type_admin_text($option['label'] ?? null, 'Option label', 1, 100),
      'is_active' => $active,
    ];
  }
  foreach ($existingOptions as $existing) {
    if (!isset($seen[$existing['value']])) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_OPTIONS_KEPT, 422);
  }
  return $clean;
}

/** A JSON number (or null) that fits DECIMAL(12,2). */
function complaint_type_admin_number(array $input, string $key): ?float {
  $value = $input[$key];
  if ($value === null) return null;
  if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || abs((float)$value) > COMPLAINT_TYPE_MAX_NUMBER) {
    throw new ComplaintTypeProblem('A valid ' . $key . ' is required.', 422);
  }
  return round((float)$value, 2);
}

/**
 * The field payload for a field of type $fieldType, normalized. Keys that were
 * not sent are left out. label is always required. A setting that does not
 * belong to the field type is refused unless it is null.
 */
function complaint_type_validate_field(array $input, string $fieldType, bool $isCreate, array $existingOptions = []): array {
  $clean = ['label' => complaint_type_admin_text($input['label'] ?? null, 'Label', 1, 100)];
  if (array_key_exists('is_required', $input)) $clean['is_required'] = complaint_type_admin_bool($input, 'is_required');
  if (array_key_exists('sort_order', $input)) {
    $clean['sort_order'] = complaint_type_admin_int($input, 'sort_order', 0, COMPLAINT_TYPE_MAX_SORT);
  }
  $belongs = [
    'text' => ['max_length'], 'number' => ['min_value', 'max_value'], 'date' => [], 'select' => ['options'],
  ][$fieldType];
  foreach (['max_length', 'min_value', 'max_value', 'options'] as $setting) {
    if (!in_array($setting, $belongs, true) && ($input[$setting] ?? null) !== null) {
      throw new ComplaintTypeProblem($setting . ' does not apply to a ' . $fieldType . ' field.', 422);
    }
  }
  if ($fieldType === 'text') {
    if (array_key_exists('max_length', $input) && $input['max_length'] !== null) {
      $clean['max_length'] = complaint_type_admin_int($input, 'max_length', 1, COMPLAINT_TYPE_MAX_TEXT_LENGTH);
    } elseif ($isCreate) {
      $clean['max_length'] = COMPLAINT_TYPE_DEFAULT_TEXT_LENGTH;
    }
  }
  if ($fieldType === 'number') {
    foreach (['min_value', 'max_value'] as $bound) {
      if (array_key_exists($bound, $input)) $clean[$bound] = complaint_type_admin_number($input, $bound);
    }
  }
  if ($fieldType === 'select') {
    if (array_key_exists('options', $input) && $input['options'] !== null) {
      $clean['options'] = complaint_type_validate_options($input['options'], $existingOptions);
    } elseif ($isCreate) {
      throw new ComplaintTypeProblem('A select field needs a list of options.', 422);
    }
  }
  if ($isCreate) $clean += ['is_required' => false];
  return $clean;
}

// ---------------------------------------------------------------------------
// Database helpers
// ---------------------------------------------------------------------------

/** Prepare, bind (int -> i, float -> d, anything else -> s) and execute. */
function complaint_type_admin_stmt(mysqli $db, string $sql, array $params = []): mysqli_stmt {
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException($db->error, $db->errno);
  if ($params !== []) {
    $types = '';
    $refs = [];
    foreach (array_values($params) as $index => $value) {
      $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
      $refs[$index] = $value;
    }
    $args = [$types];
    foreach ($refs as $index => $_) $args[] = &$refs[$index];
    call_user_func_array([$stmt, 'bind_param'], $args);
  }
  if (!$stmt->execute()) {
    $error = $stmt->error;
    $code = $stmt->errno;
    $stmt->close();
    throw new RuntimeException($error, $code);
  }
  return $stmt;
}

function complaint_type_admin_rows(mysqli $db, string $sql, array $params = []): array {
  $stmt = complaint_type_admin_stmt($db, $sql, $params);
  $result = $stmt->get_result();
  $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}

/** Affected row count of a write. */
function complaint_type_admin_exec(mysqli $db, string $sql, array $params = []): int {
  $stmt = complaint_type_admin_stmt($db, $sql, $params);
  $affected = (int)$stmt->affected_rows;
  $stmt->close();
  return $affected;
}

/** Run $work in a transaction. A duplicate-key error becomes $duplicateMessage (409). */
function complaint_type_admin_transaction(mysqli $db, callable $work, string $duplicateMessage): array {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error, $db->errno);
  try {
    $result = $work();
    if (!$db->commit()) throw new RuntimeException($db->error, $db->errno);
    return $result;
  } catch (Throwable $error) {
    $db->rollback();
    if (!$error instanceof ComplaintTypeProblem && (int)$error->getCode() === 1062) {
      throw new ComplaintTypeProblem($duplicateMessage, 409);
    }
    throw $error;
  }
}

/** Audit, then drop the cached types. Called only after a successful commit. */
function complaint_type_admin_finish(mysqli $db, int $officialId, string $officialName, array $audit): void {
  $details = json_encode($audit['changes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  log_audit($db, $officialId, $officialName, $audit['action'], $audit['target_type'],
    mb_substr($audit['reference'], 0, 100), $details === false ? '' : $details);
  complaint_types_cache_reset();
}

function complaint_type_admin_version(array $input): int {
  if (!array_key_exists('version', $input)) {
    throw new ComplaintTypeProblem('A valid version is required.', 422);
  }
  return complaint_type_admin_int($input, 'version', 1, PHP_INT_MAX);
}

// ---------------------------------------------------------------------------
// Usage counts
// ---------------------------------------------------------------------------

/** Complaints filed under this type. */
function complaint_type_usage(mysqli $db, int $typeId): int {
  return (int)complaint_type_admin_rows($db,
    'SELECT COUNT(*) AS n FROM complaints WHERE complaint_type_id = ?', [$typeId])[0]['n'];
}

/** Complaints of this type that stored a value for this field. */
function complaint_field_usage(mysqli $db, int $typeId, string $fieldKey): int {
  return (int)complaint_type_admin_rows($db,
    "SELECT COUNT(*) AS n FROM complaints
      WHERE complaint_type_id = ? AND extra_fields_json IS NOT NULL
        AND JSON_CONTAINS_PATH(extra_fields_json, 'one', ?)",
    [$typeId, complaint_field_json_path($fieldKey)])[0]['n'];
}

/** [option value => complaints of this type that stored it] for one field. */
function complaint_option_usage(mysqli $db, int $typeId, string $fieldKey): array {
  $path = complaint_field_json_path($fieldKey);
  $usage = [];
  $rows = complaint_type_admin_rows($db,
    "SELECT JSON_UNQUOTE(JSON_EXTRACT(extra_fields_json, ?)) AS v, COUNT(*) AS n FROM complaints
      WHERE complaint_type_id = ? AND extra_fields_json IS NOT NULL
        AND JSON_CONTAINS_PATH(extra_fields_json, 'one', ?)
      GROUP BY v",
    [$path, $typeId, $path]);
  foreach ($rows as $row) {
    if ($row['v'] !== null) $usage[(string)$row['v']] = (int)$row['n'];
  }
  return $usage;
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

/** Stored options_json as [{value, label, is_active(bool)}]. */
function complaint_type_admin_options(?string $json): array {
  $options = [];
  $decoded = $json === null ? null : json_decode($json, true);
  foreach (is_array($decoded) ? $decoded : [] as $option) {
    if (!is_array($option) || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null)) continue;
    $options[] = [
      'value' => $option['value'],
      'label' => $option['label'],
      'is_active' => !array_key_exists('is_active', $option) || (int)$option['is_active'] === 1,
    ];
  }
  return $options;
}

function complaint_type_admin_options_json(array $options): string {
  $stored = [];
  foreach ($options as $option) {
    $stored[] = ['value' => $option['value'], 'label' => $option['label'], 'is_active' => $option['is_active'] ? 1 : 0];
  }
  return json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** One complaint_type_fields row in the admin shape, with usage counts. */
function complaint_type_admin_field(mysqli $db, array $row): array {
  $typeId = (int)$row['complaint_type_id'];
  $key = (string)$row['field_key'];
  $options = complaint_type_admin_options($row['options_json']);
  if ($row['field_type'] === 'select') {
    $optionUsage = complaint_option_usage($db, $typeId, $key);
    foreach ($options as &$option) $option['usage'] = $optionUsage[$option['value']] ?? 0;
    unset($option);
  }
  return [
    'id' => (int)$row['id'],
    'complaint_type_id' => $typeId,
    'field_key' => $key,
    'label' => (string)$row['label'],
    'field_type' => (string)$row['field_type'],
    'is_required' => (int)$row['is_required'] === 1,
    'sort_order' => (int)$row['sort_order'],
    'version' => (int)$row['version'],
    'is_active' => (int)$row['is_active'] === 1,
    'max_length' => $row['max_length'] === null ? null : (int)$row['max_length'],
    'min_value' => $row['min_value'] === null ? null : (float)$row['min_value'],
    'max_value' => $row['max_value'] === null ? null : (float)$row['max_value'],
    'usage' => complaint_field_usage($db, $typeId, $key),
    'options' => $options,
  ];
}

/** One complaint_types row in the admin shape, with its fields and usage. */
function complaint_type_admin_type(mysqli $db, array $row): array {
  $typeId = (int)$row['id'];
  $fields = [];
  foreach (complaint_type_admin_rows($db,
    'SELECT * FROM complaint_type_fields WHERE complaint_type_id = ? ORDER BY sort_order, id', [$typeId]) as $fieldRow) {
    $fields[] = complaint_type_admin_field($db, $fieldRow);
  }
  return [
    'id' => $typeId,
    'type_code' => (string)$row['type_code'],
    'type_name' => (string)$row['type_name'],
    'description' => $row['description'] === null ? null : (string)$row['description'],
    'respondent_mode' => (string)$row['respondent_mode'],
    'default_fee' => complaint_type_admin_fee_text($row['default_fee'] ?? null),
    'is_active' => (int)$row['is_active'] === 1,
    'sort_order' => (int)$row['sort_order'],
    'version' => (int)$row['version'],
    'usage' => complaint_type_usage($db, $typeId),
    'fields' => $fields,
  ];
}

/** Every type, inactive included, with every field, option and usage count. */
function complaint_types_admin_list(mysqli $db): array {
  $types = [];
  foreach (complaint_type_admin_rows($db, 'SELECT * FROM complaint_types ORDER BY sort_order, id') as $row) {
    $types[] = complaint_type_admin_type($db, $row);
  }
  return $types;
}

function complaint_type_admin_get(mysqli $db, int $typeId): ?array {
  $rows = complaint_type_admin_rows($db, 'SELECT * FROM complaint_types WHERE id = ?', [$typeId]);
  return $rows ? complaint_type_admin_type($db, $rows[0]) : null;
}

function complaint_field_admin_get(mysqli $db, int $fieldId): ?array {
  $rows = complaint_type_admin_rows($db, 'SELECT * FROM complaint_type_fields WHERE id = ?', [$fieldId]);
  return $rows ? complaint_type_admin_field($db, $rows[0]) : null;
}

/** An ACTIVE type with its ACTIVE fields and options, for filing forms. Null when missing or inactive. */
function complaint_type_public_fields(int $typeId): ?array {
  $type = complaint_type_get($typeId, false);
  if ($type === null) return null;
  $fields = [];
  foreach ($type['fields'] as $field) {
    $options = [];
    foreach ($field['options'] as $option) $options[] = ['value' => $option['value'], 'label' => $option['label']];
    $fields[] = [
      'key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'], 'required' => $field['required'],
      'max_length' => $field['max_length'], 'min' => $field['min'], 'max' => $field['max'], 'options' => $options,
    ];
  }
  return [
    'id' => $type['id'], 'type_code' => $type['type_code'], 'type_name' => $type['type_name'],
    'description' => $type['description'], 'respondent_mode' => $type['respondent_mode'], 'fields' => $fields,
  ];
}

// ---------------------------------------------------------------------------
// Locks and shared checks
// ---------------------------------------------------------------------------

function complaint_type_admin_lock_type(mysqli $db, int $typeId): array {
  $rows = complaint_type_admin_rows($db, 'SELECT * FROM complaint_types WHERE id = ? FOR UPDATE', [$typeId]);
  if (!$rows) throw new ComplaintTypeProblem('Complaint type not found.', 404);
  return $rows[0];
}

function complaint_type_admin_lock_field(mysqli $db, int $fieldId, int $typeId): array {
  $rows = complaint_type_admin_rows($db,
    'SELECT * FROM complaint_type_fields WHERE id = ? AND complaint_type_id = ? FOR UPDATE', [$fieldId, $typeId]);
  if (!$rows) throw new ComplaintTypeProblem('Field not found.', 404);
  return $rows[0];
}

function complaint_type_admin_name_free(mysqli $db, string $name, int $exceptId): void {
  $taken = complaint_type_admin_rows($db,
    'SELECT id FROM complaint_types WHERE LOWER(type_name) = LOWER(?) AND id <> ? LIMIT 1', [$name, $exceptId]);
  if ($taken) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_NAME_TAKEN, 409);
}

function complaint_type_admin_active_fields(mysqli $db, int $typeId, int $exceptFieldId = 0): int {
  return (int)complaint_type_admin_rows($db,
    'SELECT COUNT(*) AS n FROM complaint_type_fields WHERE complaint_type_id = ? AND is_active = 1 AND id <> ?',
    [$typeId, $exceptFieldId])[0]['n'];
}

function complaint_type_admin_room_for_field(mysqli $db, int $typeId, int $exceptFieldId = 0): void {
  if (complaint_type_admin_active_fields($db, $typeId, $exceptFieldId) >= COMPLAINT_TYPE_MAX_ACTIVE_FIELDS) {
    throw new ComplaintTypeProblem('A type can have at most ' . COMPLAINT_TYPE_MAX_ACTIVE_FIELDS . ' active fields.', 422);
  }
}

function complaint_type_admin_has_active_option(array $options): bool {
  foreach ($options as $option) if ($option['is_active']) return true;
  return false;
}

/** Other.specify is the only way an "Other" complaint describes itself. */
function complaint_type_admin_is_other_specify(array $typeRow, array $fieldRow): bool {
  return $typeRow['type_code'] === 'other' && $fieldRow['field_key'] === 'specify';
}

/** {added, retired, restored, relabeled} option values between two option lists. */
function complaint_type_admin_option_changes(array $old, array $new): array {
  $before = [];
  foreach ($old as $option) $before[$option['value']] = $option;
  $changes = ['added' => [], 'retired' => [], 'restored' => [], 'relabeled' => []];
  foreach ($new as $option) {
    $was = $before[$option['value']] ?? null;
    if ($was === null) { $changes['added'][] = $option['value']; continue; }
    if ($was['is_active'] && !$option['is_active']) $changes['retired'][] = $option['value'];
    if (!$was['is_active'] && $option['is_active']) $changes['restored'][] = $option['value'];
    if ($was['label'] !== $option['label']) $changes['relabeled'][] = $option['value'];
  }
  // Reordered: the old values no longer appear in their old relative order.
  $oldOrder = array_column($old, 'value');
  if (array_values(array_intersect(array_column($new, 'value'), $oldOrder)) !== $oldOrder) $changes['reordered'] = true;
  return array_filter($changes, static fn($value): bool => $value !== []);
}

// ---------------------------------------------------------------------------
// Writes: types
// ---------------------------------------------------------------------------

/**
 * Create (no id) or update (id + version) a type.
 * @return array{changed:bool, type:array}
 */
function complaint_type_save(mysqli $db, array $input, int $officialId, string $officialName): array {
  $isCreate = !array_key_exists('id', $input) || $input['id'] === null;
  $clean = complaint_type_validate_type($input, $isCreate);
  $actor = $officialId > 0 ? $officialId : null;

  if ($isCreate) {
    $done = complaint_type_admin_transaction($db, static function () use ($db, $clean, $actor): array {
      complaint_type_admin_name_free($db, $clean['type_name'], 0);
      $taken = array_column(complaint_type_admin_rows($db, 'SELECT type_code FROM complaint_types FOR UPDATE'), 'type_code');
      if (isset($clean['type_code'])) {
        if (in_array($clean['type_code'], $taken, true)) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_CODE_TAKEN, 409);
        $code = $clean['type_code'];
      } else {
        $base = complaint_type_derive_code($clean['type_name']);
        if ($base === '') throw new ComplaintTypeProblem('Enter a code for this type.', 422);
        $code = complaint_type_unique_code($base, $taken);
      }
      $sort = $clean['sort_order'] ?? min(COMPLAINT_TYPE_MAX_SORT,
        (int)complaint_type_admin_rows($db, 'SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM complaint_types')[0]['n']);
      // The fee column exists only after the 20261120 migration; '0.00' is its default.
      $fee = $clean['default_fee'] ?? '0.00';
      $feeSql = $fee !== '0.00' ? [', default_fee', ', ?', [$fee]] : ['', '', []];
      $stmt = complaint_type_admin_stmt($db,
        'INSERT INTO complaint_types (type_code, type_name, description, respondent_mode, sort_order, created_by, updated_by' . $feeSql[0] . ')
         VALUES (?, ?, ?, ?, ?, ?, ?' . $feeSql[1] . ')',
        array_merge([$code, $clean['type_name'], $clean['description'], $clean['respondent_mode'], $sort, $actor, $actor], $feeSql[2]));
      $id = (int)$stmt->insert_id;
      $stmt->close();
      return ['id' => $id, 'audit' => [
        'action' => 'Created complaint type', 'target_type' => 'complaint_type', 'reference' => $code,
        'changes' => ['type_name' => [null, $clean['type_name']], 'respondent_mode' => [null, $clean['respondent_mode']]]
          + ($fee !== '0.00' ? ['default_fee' => [null, $fee]] : []),
      ]];
    }, COMPLAINT_TYPE_MSG_NAME_TAKEN);
  } else {
    $id = complaint_type_admin_int($input, 'id', 1, PHP_INT_MAX);
    $version = complaint_type_admin_version($input);
    $done = complaint_type_admin_transaction($db, static function () use ($db, $clean, $actor, $id, $version): array {
      $row = complaint_type_admin_lock_type($db, $id);
      if ((int)$row['version'] !== $version) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_STALE, 409);
      if (isset($clean['type_code']) && $clean['type_code'] !== $row['type_code']) {
        throw new ComplaintTypeProblem('The code of a type cannot be changed.', 422);
      }
      $changes = [];
      foreach (['type_name', 'description', 'respondent_mode', 'sort_order', 'default_fee'] as $column) {
        if (!array_key_exists($column, $clean)) continue;
        if ($column === 'default_fee' && !array_key_exists('default_fee', $row)) {
          if ($clean['default_fee'] === '0.00') continue;
          throw new ComplaintTypeProblem('Fees need the 20261120 database migration.', 503);
        }
        $old = $column === 'sort_order' ? (int)$row[$column] : ($column === 'default_fee' ? complaint_type_admin_fee_text($row[$column]) : $row[$column]);
        if ($old !== $clean[$column]) $changes[$column] = [$old, $clean[$column]];
      }
      if ($changes === []) return ['id' => $id, 'audit' => null];
      if (isset($changes['type_name'])) complaint_type_admin_name_free($db, $clean['type_name'], $id);
      $sets = [];
      $params = [];
      foreach ($changes as $column => [, $new]) { $sets[] = "`{$column}` = ?"; $params[] = $new; }
      $params[] = $actor; $params[] = $id; $params[] = $version;
      $affected = complaint_type_admin_exec($db,
        'UPDATE complaint_types SET ' . implode(', ', $sets) . ', version = version + 1, updated_by = ? WHERE id = ? AND version = ?', $params);
      if ($affected !== 1) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_STALE, 409);
      return ['id' => $id, 'audit' => [
        'action' => 'Updated complaint type', 'target_type' => 'complaint_type', 'reference' => $row['type_code'], 'changes' => $changes,
      ]];
    }, COMPLAINT_TYPE_MSG_NAME_TAKEN);
  }

  if ($done['audit'] !== null) complaint_type_admin_finish($db, $officialId, $officialName, $done['audit']);
  return ['changed' => $done['audit'] !== null, 'type' => complaint_type_admin_get($db, $done['id'])];
}

/**
 * Activate or deactivate a type. "Other" always stays active, and at least one
 * type must stay active.
 * @return array{changed:bool, type:array}
 */
function complaint_type_toggle(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_type_admin_keys($input, ['id', 'version', 'is_active']);
  $id = complaint_type_admin_int($input, 'id', 1, PHP_INT_MAX);
  $version = complaint_type_admin_version($input);
  $active = complaint_type_admin_bool($input, 'is_active');
  $actor = $officialId > 0 ? $officialId : null;

  $done = complaint_type_admin_transaction($db, static function () use ($db, $id, $version, $active, $actor): array {
    // Lock every type so two deactivations cannot both leave "one other active type".
    complaint_type_admin_rows($db, 'SELECT id FROM complaint_types ORDER BY id FOR UPDATE');
    $row = complaint_type_admin_lock_type($db, $id);
    if ((int)$row['version'] !== $version) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_STALE, 409);
    if (((int)$row['is_active'] === 1) === $active) return ['audit' => null];
    if (!$active) {
      if ($row['type_code'] === 'other') throw new ComplaintTypeProblem('The "Other" type cannot be deactivated.', 422);
      $others = (int)complaint_type_admin_rows($db,
        'SELECT COUNT(*) AS n FROM complaint_types WHERE is_active = 1 AND id <> ?', [$id])[0]['n'];
      if ($others === 0) throw new ComplaintTypeProblem('At least one complaint type must stay active.', 422);
    }
    $affected = complaint_type_admin_exec($db,
      'UPDATE complaint_types SET is_active = ?, version = version + 1, updated_by = ? WHERE id = ? AND version = ?',
      [$active ? 1 : 0, $actor, $id, $version]);
    if ($affected !== 1) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_STALE, 409);
    return ['audit' => [
      'action' => ($active ? 'Activated' : 'Deactivated') . ' complaint type', 'target_type' => 'complaint_type',
      'reference' => $row['type_code'], 'changes' => ['is_active' => [!$active, $active]],
    ]];
  }, COMPLAINT_TYPE_MSG_NAME_TAKEN);

  if ($done['audit'] !== null) complaint_type_admin_finish($db, $officialId, $officialName, $done['audit']);
  return ['changed' => $done['audit'] !== null, 'type' => complaint_type_admin_get($db, $id)];
}

// ---------------------------------------------------------------------------
// Writes: fields
// ---------------------------------------------------------------------------

/**
 * Create (no id) or update (id + version) a field of type type_id.
 * field_key and field_type are set at creation and never change.
 * @return array{changed:bool, field:array}
 */
function complaint_field_save(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_type_admin_keys($input, ['type_id', 'id', 'version', 'field_key', 'label', 'field_type', 'is_required',
    'sort_order', 'max_length', 'min_value', 'max_value', 'options']);
  $typeId = complaint_type_admin_int($input, 'type_id', 1, PHP_INT_MAX);
  $isCreate = !array_key_exists('id', $input) || $input['id'] === null;

  if ($isCreate) {
    $key = $input['field_key'] ?? null;
    if (!is_string($key) || !preg_match(COMPLAINT_FIELD_KEY_PATTERN, $key)) {
      throw new ComplaintTypeProblem('Field key must be 1 to 50 lowercase letters, numbers or underscores and start with a letter.', 422);
    }
    $fieldType = $input['field_type'] ?? null;
    if (!is_string($fieldType) || !in_array($fieldType, COMPLAINT_FIELD_TYPES, true)) {
      throw new ComplaintTypeProblem('Field type must be select, text, number or date.', 422);
    }
    $clean = complaint_type_validate_field($input, $fieldType, true);
    if ($fieldType === 'number' && isset($clean['min_value'], $clean['max_value']) && $clean['min_value'] > $clean['max_value']) {
      throw new ComplaintTypeProblem('min_value cannot be greater than max_value.', 422);
    }
    if ($fieldType === 'select' && !complaint_type_admin_has_active_option($clean['options'])) {
      throw new ComplaintTypeProblem('An active select field needs at least one active option.', 422);
    }
    $done = complaint_type_admin_transaction($db, static function () use ($db, $typeId, $key, $fieldType, $clean): array {
      $type = complaint_type_admin_lock_type($db, $typeId);
      if (complaint_type_admin_rows($db,
        'SELECT id FROM complaint_type_fields WHERE complaint_type_id = ? AND field_key = ? LIMIT 1', [$typeId, $key])) {
        throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_KEY_TAKEN, 409);
      }
      complaint_type_admin_room_for_field($db, $typeId);
      $sort = $clean['sort_order'] ?? min(COMPLAINT_TYPE_MAX_SORT, (int)complaint_type_admin_rows($db,
        'SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM complaint_type_fields WHERE complaint_type_id = ?', [$typeId])[0]['n']);
      $stmt = complaint_type_admin_stmt($db,
        'INSERT INTO complaint_type_fields
           (complaint_type_id, field_key, label, field_type, is_required, options_json, max_length, min_value, max_value, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$typeId, $key, $clean['label'], $fieldType, $clean['is_required'] ? 1 : 0,
         isset($clean['options']) ? complaint_type_admin_options_json($clean['options']) : null,
         $clean['max_length'] ?? null, $clean['min_value'] ?? null, $clean['max_value'] ?? null, $sort]);
      $id = (int)$stmt->insert_id;
      $stmt->close();
      return ['id' => $id, 'audit' => [
        'action' => 'Created complaint type field', 'target_type' => 'complaint_type_field',
        'reference' => $type['type_code'] . '.' . $key,
        'changes' => ['label' => [null, $clean['label']], 'field_type' => [null, $fieldType], 'is_required' => [null, $clean['is_required']]],
      ]];
    }, COMPLAINT_TYPE_MSG_KEY_TAKEN);
  } else {
    $fieldId = complaint_type_admin_int($input, 'id', 1, PHP_INT_MAX);
    $version = complaint_type_admin_version($input);
    $done = complaint_type_admin_transaction($db, static function () use ($db, $input, $typeId, $fieldId, $version): array {
      $type = complaint_type_admin_lock_type($db, $typeId);
      $row = complaint_type_admin_lock_field($db, $fieldId, $typeId);
      if ((int)$row['version'] !== $version) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_STALE, 409);
      if (isset($input['field_key']) && $input['field_key'] !== $row['field_key']) {
        throw new ComplaintTypeProblem('The key of a field cannot be changed.', 422);
      }
      if (isset($input['field_type']) && $input['field_type'] !== $row['field_type']) {
        throw new ComplaintTypeProblem('The type of a field cannot be changed.', 422);
      }
      $fieldType = (string)$row['field_type'];
      $oldOptions = complaint_type_admin_options($row['options_json']);
      $clean = complaint_type_validate_field($input, $fieldType, false, $oldOptions);
      $active = (int)$row['is_active'] === 1;

      if (complaint_type_admin_is_other_specify($type, $row) && ($clean['is_required'] ?? true) === false) {
        throw new ComplaintTypeProblem('"Please specify" on the Other type must stay required.', 422);
      }
      $current = [
        'label' => (string)$row['label'],
        'is_required' => (int)$row['is_required'] === 1,
        'sort_order' => (int)$row['sort_order'],
        'max_length' => $row['max_length'] === null ? null : (int)$row['max_length'],
        'min_value' => $row['min_value'] === null ? null : (float)$row['min_value'],
        'max_value' => $row['max_value'] === null ? null : (float)$row['max_value'],
      ];
      $changes = [];
      foreach ($current as $column => $old) {
        if (array_key_exists($column, $clean) && $clean[$column] !== $old) $changes[$column] = [$old, $clean[$column]];
      }
      if ($fieldType === 'number') {
        $min = array_key_exists('min_value', $clean) ? $clean['min_value'] : $current['min_value'];
        $max = array_key_exists('max_value', $clean) ? $clean['max_value'] : $current['max_value'];
        if ($min !== null && $max !== null && $min > $max) {
          throw new ComplaintTypeProblem('min_value cannot be greater than max_value.', 422);
        }
      }
      $optionsJson = null;
      $optionChanges = [];
      if (isset($clean['options'])) {
        if ($active && !complaint_type_admin_has_active_option($clean['options'])) {
          throw new ComplaintTypeProblem('An active select field needs at least one active option.', 422);
        }
        if ($clean['options'] !== $oldOptions) {
          $optionsJson = complaint_type_admin_options_json($clean['options']);
          $optionChanges = complaint_type_admin_option_changes($oldOptions, $clean['options']);
        }
      }
      if ($changes === [] && $optionsJson === null) return ['id' => $fieldId, 'audit' => null];

      $sets = [];
      $params = [];
      foreach ($changes as $column => [, $new]) {
        $sets[] = "`{$column}` = ?";
        $params[] = is_bool($new) ? ($new ? 1 : 0) : $new;
      }
      if ($optionsJson !== null) { $sets[] = 'options_json = ?'; $params[] = $optionsJson; }
      $params[] = $fieldId; $params[] = $version;
      $affected = complaint_type_admin_exec($db,
        'UPDATE complaint_type_fields SET ' . implode(', ', $sets) . ', version = version + 1 WHERE id = ? AND version = ?', $params);
      if ($affected !== 1) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_STALE, 409);
      if ($optionsJson !== null) $changes['options'] = $optionChanges;
      return ['id' => $fieldId, 'audit' => [
        'action' => 'Updated complaint type field', 'target_type' => 'complaint_type_field',
        'reference' => $type['type_code'] . '.' . $row['field_key'], 'changes' => $changes,
      ]];
    }, COMPLAINT_TYPE_MSG_KEY_TAKEN);
  }

  if ($done['audit'] !== null) complaint_type_admin_finish($db, $officialId, $officialName, $done['audit']);
  return ['changed' => $done['audit'] !== null, 'field' => complaint_field_admin_get($db, $done['id'])];
}

/**
 * Activate or deactivate a field. Other.specify always stays active.
 * @return array{changed:bool, field:array}
 */
function complaint_field_toggle(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_type_admin_keys($input, ['id', 'version', 'is_active']);
  $fieldId = complaint_type_admin_int($input, 'id', 1, PHP_INT_MAX);
  $version = complaint_type_admin_version($input);
  $active = complaint_type_admin_bool($input, 'is_active');

  $done = complaint_type_admin_transaction($db, static function () use ($db, $fieldId, $version, $active): array {
    // Type row first, then the field row: the same lock order as every other field write.
    $found = complaint_type_admin_rows($db, 'SELECT complaint_type_id FROM complaint_type_fields WHERE id = ?', [$fieldId]);
    if (!$found) throw new ComplaintTypeProblem('Field not found.', 404);
    $typeId = (int)$found[0]['complaint_type_id'];
    $type = complaint_type_admin_lock_type($db, $typeId);
    $row = complaint_type_admin_lock_field($db, $fieldId, $typeId);
    if ((int)$row['version'] !== $version) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_STALE, 409);
    if (((int)$row['is_active'] === 1) === $active) return ['audit' => null];
    if (!$active && complaint_type_admin_is_other_specify($type, $row)) {
      throw new ComplaintTypeProblem('"Please specify" on the Other type cannot be deactivated.', 422);
    }
    if ($active) {
      complaint_type_admin_room_for_field($db, $typeId, $fieldId);
      if ($row['field_type'] === 'select'
          && !complaint_type_admin_has_active_option(complaint_type_admin_options($row['options_json']))) {
        throw new ComplaintTypeProblem('An active select field needs at least one active option.', 422);
      }
    }
    $affected = complaint_type_admin_exec($db,
      'UPDATE complaint_type_fields SET is_active = ?, version = version + 1 WHERE id = ? AND version = ?',
      [$active ? 1 : 0, $fieldId, $version]);
    if ($affected !== 1) throw new ComplaintTypeProblem(COMPLAINT_TYPE_MSG_STALE, 409);
    return ['audit' => [
      'action' => ($active ? 'Activated' : 'Deactivated') . ' complaint type field', 'target_type' => 'complaint_type_field',
      'reference' => $type['type_code'] . '.' . $row['field_key'], 'changes' => ['is_active' => [!$active, $active]],
    ]];
  }, COMPLAINT_TYPE_MSG_KEY_TAKEN);

  if ($done['audit'] !== null) complaint_type_admin_finish($db, $officialId, $officialName, $done['audit']);
  return ['changed' => $done['audit'] !== null, 'field' => complaint_field_admin_get($db, $fieldId)];
}
