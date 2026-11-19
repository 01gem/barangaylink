<?php
/**
 * Complaint types and their extra fields: the single entry point for
 * validation, form choices, and display labels. The definitions live in the
 * complaint_types and complaint_type_fields tables.
 *
 * Type shape:  id, type_code, type_name, description, respondent_mode,
 *              is_active (bool), fields (list)
 * Field shape: key, label, type (select|text|number|date), required (bool),
 *              max_length (int|null), min (float|null), max (float|null),
 *              is_active (bool), options (list)
 * Option shape: value, label, is_active (bool)
 */

/**
 * Every type with every field and option, inactive ones included, in display
 * order. Loaded once per request. There is no fallback when the tables cannot
 * be read: the request fails.
 */
function complaint_types_store(bool $reset = false): array {
  static $all = null;
  if ($reset) $all = null;
  if ($all !== null) return $all;

  try {
    $db = get_db();
    $typeResult = $db->query('SELECT id, type_code, type_name, description, respondent_mode, is_active
      FROM complaint_types ORDER BY sort_order, id');
    if (!$typeResult) throw new RuntimeException($db->error);
    $fieldResult = $db->query('SELECT complaint_type_id, field_key, label, field_type, is_required,
        options_json, max_length, min_value, max_value, is_active
      FROM complaint_type_fields ORDER BY sort_order, id');
    if (!$fieldResult) throw new RuntimeException($db->error);
  } catch (Throwable $error) {
    error_log('Complaint types are unavailable: ' . $error->getMessage());
    if (function_exists('json_error')) json_error('Complaint types are unavailable.', 500);
    throw new RuntimeException('Complaint types are unavailable.');
  }

  $fieldsByType = [];
  foreach ($fieldResult->fetch_all(MYSQLI_ASSOC) as $row) {
    $options = [];
    $decoded = $row['options_json'] === null ? null : json_decode((string)$row['options_json'], true);
    if (is_array($decoded)) {
      foreach ($decoded as $option) {
        if (!is_array($option) || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null)) continue;
        $options[] = [
          'value' => $option['value'],
          'label' => $option['label'],
          'is_active' => !array_key_exists('is_active', $option) || (int)$option['is_active'] === 1,
        ];
      }
    }
    $fieldsByType[(int)$row['complaint_type_id']][] = [
      'key' => (string)$row['field_key'],
      'label' => (string)$row['label'],
      'type' => (string)$row['field_type'],
      'required' => (int)$row['is_required'] === 1,
      'max_length' => $row['max_length'] === null ? null : (int)$row['max_length'],
      'min' => $row['min_value'] === null ? null : (float)$row['min_value'],
      'max' => $row['max_value'] === null ? null : (float)$row['max_value'],
      'is_active' => (int)$row['is_active'] === 1,
      'options' => $options,
    ];
  }

  $all = [];
  foreach ($typeResult->fetch_all(MYSQLI_ASSOC) as $row) {
    $id = (int)$row['id'];
    $all[] = [
      'id' => $id,
      'type_code' => (string)$row['type_code'],
      'type_name' => (string)$row['type_name'],
      'description' => $row['description'] === null ? null : (string)$row['description'],
      'respondent_mode' => (string)$row['respondent_mode'],
      'is_active' => (int)$row['is_active'] === 1,
      'fields' => $fieldsByType[$id] ?? [],
    ];
  }
  return $all;
}

/** Forget the per-request cache. Tests use this after changing type rows. */
function complaint_types_cache_reset(): void {
  complaint_types_store(true);
}

/**
 * Types in display order. By default only active types, with only their
 * active fields and active options. With $includeInactive, everything.
 */
function complaint_types_all(bool $includeInactive = false): array {
  $all = complaint_types_store();
  if ($includeInactive) return $all;
  $active = [];
  foreach ($all as $type) {
    if (!$type['is_active']) continue;
    $fields = [];
    foreach ($type['fields'] as $field) {
      if (!$field['is_active']) continue;
      $field['options'] = array_values(array_filter($field['options'], static fn(array $option): bool => $option['is_active']));
      $fields[] = $field;
    }
    $type['fields'] = $fields;
    $active[] = $type;
  }
  return $active;
}

function complaint_type_get(int $id, bool $includeInactive = false): ?array {
  foreach (complaint_types_all($includeInactive) as $type) {
    if ($type['id'] === $id) return $type;
  }
  return null;
}

/** Exact, case-sensitive match on type_name. For legacy input and old rows only. */
function complaint_type_by_name(string $name, bool $includeInactive = false): ?array {
  foreach (complaint_types_all($includeInactive) as $type) {
    if ($type['type_name'] === $name) return $type;
  }
  return null;
}

/**
 * Choices for a "filter by type" control: active types in display order, then
 * inactive types labelled "(no longer used)". Value is the type id.
 * Returns [['value' => int, 'label' => string]]; the caller escapes on output.
 */
function complaint_type_filter_options(): array {
  $active = [];
  $inactive = [];
  foreach (complaint_types_all(true) as $type) {
    if ($type['is_active']) $active[] = ['value' => $type['id'], 'label' => $type['type_name']];
    else $inactive[] = ['value' => $type['id'], 'label' => $type['type_name'] . ' (no longer used)'];
  }
  return array_merge($active, $inactive);
}

/**
 * The schema keyed by type name, for callers that only need names, labels and
 * select options: [type_name => [field_key => ['label', 'options' => [value => label], ...]]].
 */
function complaint_extra_fields_schema(bool $includeInactive = false): array {
  $schema = [];
  foreach (complaint_types_all($includeInactive) as $type) {
    $fields = [];
    foreach ($type['fields'] as $field) {
      $options = [];
      foreach ($field['options'] as $option) $options[$option['value']] = $option['label'];
      $fields[$field['key']] = [
        'label' => $field['label'],
        'options' => $options,
        'type' => $field['type'],
        'required' => $field['required'],
        'max_length' => $field['max_length'],
        'min' => $field['min'],
        'max' => $field['max'],
      ];
    }
    $schema[$type['type_name']] = $fields;
  }
  return $schema;
}

/**
 * One submitted value, checked against its field. Returns the stored string,
 * or '' when the value was not provided.
 */
function complaint_extra_field_clean(array $field, mixed $value): string {
  $label = $field['label'];
  if ($field['type'] === 'number') {
    if (is_int($value) || is_float($value)) $value = (string)$value;
    if (!is_string($value)) throw new InvalidArgumentException($label . ' must be a number.');
    $value = trim($value);
    if ($value === '') return '';
    if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) throw new InvalidArgumentException($label . ' must be a number.');
    $number = (float)$value;
    if ($field['min'] !== null && $number < $field['min']) {
      throw new InvalidArgumentException($label . ' must be at least ' . ($field['min'] + 0) . '.');
    }
    if ($field['max'] !== null && $number > $field['max']) {
      throw new InvalidArgumentException($label . ' must be at most ' . ($field['max'] + 0) . '.');
    }
    return $value;
  }

  if (!is_string($value)) {
    throw new InvalidArgumentException($field['type'] === 'select'
      ? 'Additional detail values must be text options.'
      : $label . ' must be text.');
  }

  if ($field['type'] === 'select') {
    $value = trim($value);
    if ($value === '') return '';
    foreach ($field['options'] as $option) {
      if ($option['value'] === $value && $option['is_active']) return $value;
    }
    throw new InvalidArgumentException('An additional detail option is not valid.');
  }

  if ($field['type'] === 'date') {
    $value = trim($value);
    if ($value === '') return '';
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
      ? DateTimeImmutable::createFromFormat('!Y-m-d', $value)
      : false;
    if (!$date || $date->format('Y-m-d') !== $value) {
      throw new InvalidArgumentException($label . ' must be a valid date.');
    }
    return $value;
  }

  if ($field['type'] === 'text') {
    if (str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
      throw new InvalidArgumentException($label . ' contains characters that are not allowed.');
    }
    $value = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value));
    if ($value === '') return '';
    $max = $field['max_length'] ?? 255;
    if (mb_strlen($value, 'UTF-8') > $max) {
      throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
    }
    return $value;
  }

  throw new InvalidArgumentException('An additional field is not valid for this category.');
}

/**
 * Strict writes. Raw nested JSON must retain stdClass vs array identity.
 * Only active fields and active options are accepted. Returns [key => string]
 * in field order; a missing or empty value is left out unless the field is
 * required, which is an error.
 */
function complaint_extra_fields_validate(array $type, mixed $raw): array {
  if ($raw === null) $raw = new stdClass();
  if (!$raw instanceof stdClass) {
    throw new InvalidArgumentException('Additional details must be a JSON object.');
  }
  $input = get_object_vars($raw);
  $active = [];
  foreach ($type['fields'] ?? [] as $field) {
    if ($field['is_active']) $active[$field['key']] = $field;
  }
  foreach (array_keys($input) as $key) {
    if (!array_key_exists($key, $active)) {
      throw new InvalidArgumentException('An additional field is not valid for this category.');
    }
  }
  $clean = [];
  foreach ($active as $key => $field) {
    $value = array_key_exists($key, $input) ? complaint_extra_field_clean($field, $input[$key]) : '';
    if ($value === '') {
      if ($field['required']) throw new InvalidArgumentException($field['label'] . ' is required.');
      continue;
    }
    $clean[$key] = $value;
  }
  return $clean;
}

/**
 * Tolerant reads: never expose unknown stored keys or invalid select values.
 * Pass a type loaded with $includeInactive = true so inactive fields and
 * retired options stay readable and history never disappears.
 */
function complaint_extra_fields_read(?array $type, ?string $json): array {
  if ($type === null || $json === null || $json === '') return [];
  $raw = json_decode($json);
  if (!$raw instanceof stdClass) return [];
  $clean = [];
  foreach ($type['fields'] ?? [] as $field) {
    $key = $field['key'];
    if (!property_exists($raw, $key) || !is_string($raw->{$key})) continue;
    $value = $raw->{$key};
    if ($field['type'] === 'select') {
      $value = trim($value);
      foreach ($field['options'] as $option) {
        if ($option['value'] === $value) { $clean[$key] = $value; break; }
      }
      continue;
    }
    if ($value !== '') $clean[$key] = $value;
  }
  return $clean;
}

/**
 * Human-readable projection in field order, shared by both portals.
 * Values are plain text: the client escapes them when it builds HTML.
 */
function complaint_extra_fields_display(?array $type, array $values): array {
  $display = [];
  foreach ($type['fields'] ?? [] as $field) {
    $value = $values[$field['key']] ?? null;
    if (!is_string($value) || $value === '') continue;
    if ($field['type'] !== 'select') {
      $display[] = ['label' => $field['label'], 'value' => $value];
      continue;
    }
    foreach ($field['options'] as $option) {
      if ($option['value'] !== $value) continue;
      $display[] = [
        'label' => $field['label'],
        'value' => $option['label'] . ($option['is_active'] ? '' : ' (retired)'),
      ];
      break;
    }
  }
  return $display;
}
