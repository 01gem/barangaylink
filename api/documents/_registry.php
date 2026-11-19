<?php
/**
 * BarangayLink - Document registry helpers.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

require_once __DIR__ . '/_registry_legacy.php';

const DOCUMENT_SIGNATORY_HONORIFIC = 'Hon.';
const DOCUMENT_SIGNATORY_TITLE = 'Barangay Captain';

/**
 * Phase 7: the internal Summons type (audience 'internal', code 'summons') and
 * its 13 system fields in registry order (20261119 migration). Filled by
 * api/hearings/_summons.php; never requested by residents.
 */
const DOCUMENT_SUMMONS_CODE = 'summons';
const DOCUMENT_SUMMONS_FIELDS = [
    'case_no', 'case_title', 'complainant_names', 'respondent_names', 'respondent_to',
    'hearing_day', 'hearing_month_year', 'hearing_time', 'venue',
    'issued_day', 'issued_month', 'issued_year', 'official_name',
];

/**
 * TEST-ONLY storage override. BARANGAY_STORAGE_ROOT is honored only when it
 * resolves (after following links/junctions) to a directory directly inside
 * the system temp directory whose name is capstone_tpl_<digits>. Anything
 * else is ignored and the real project folders are used. Production never
 * sets it.
 */
function document_storage_override(): ?string {
    $raw = trim((string)(getenv('BARANGAY_STORAGE_ROOT') ?: ''));
    if ($raw === '') return null;
    $real = realpath($raw);
    $temp = realpath(sys_get_temp_dir());
    if ($real === false || $temp === false || !is_dir($real)) return null;
    if (!preg_match('/^capstone_tpl_\d+$/', basename($real))) return null;
    $parent = realpath(dirname($real));
    if ($parent === false || strcasecmp(rtrim($parent, '/\\'), rtrim($temp, '/\\')) !== 0) return null;
    return $real;
}

/** Template folder ('templates') or test-render preview folder ('previews'). Not created here. */
function document_storage_dir(string $kind): string {
    $base = document_storage_override() ?? dirname(__DIR__, 2);
    return match ($kind) {
        'templates' => $base . DIRECTORY_SEPARATOR . 'document_templates',
        'previews' => $base . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'previews',
        // Phase 7: generated summons (<file_uuid>.pdf / .docx), served only by api/hearings/summons_view.php.
        'complaints' => $base . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'complaints',
    };
}

function document_registry_table_exists(mysqli $db): bool {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'document_types' LIMIT 1");
    if (!$stmt) throw new RuntimeException($db->error);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $result = $stmt->get_result();
    $exists = (bool)($result && $result->fetch_row());
    $stmt->close();
    return $exists;
}

function document_registry_type(mysqli $db, string $name): ?array {
    $rows = document_registry_types($db, false);
    foreach ($rows as $row) {
        if ($row['name'] === $name) return $row;
    }
    return null;
}

function document_registry_types(mysqli $db, bool $activeOnly = true): array {
    $where = $activeOnly ? " WHERE status = 'active'" : '';
    // Phase 7: audience ('resident' | 'internal'); 'resident' before the 20261119 migration.
    $audience = document_types_audience_ready($db) ? 'audience' : "'resident' AS audience";
    $stmt = $db->prepare("SELECT id, name, template_file, status, sort_order, {$audience} FROM document_types{$where} ORDER BY sort_order, id");
    if (!$stmt) throw new RuntimeException($db->error);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function document_registry_fields(mysqli $db, int $typeId): array {
    $stmt = $db->prepare('SELECT field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order FROM document_type_fields WHERE document_type_id = ? ORDER BY sort_order, id');
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('i', $typeId);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function document_registry_resident_fields(mysqli $db, string $name): array {
    $type = document_registry_type($db, $name);
    if ($type === null) return [];
    return array_values(array_filter(document_registry_fields($db, (int)$type['id']), static fn(array $field): bool => $field['source'] === 'resident_supplied'));
}

/**
 * Phase 5B: 422 texts for the registry field rules. {label} is the registry
 * label as stored. js/official.js mirrors this map as DOC_FIELD_MESSAGES; the
 * document-forms suite reads both files and fails when they differ.
 */
const DOCUMENT_FIELD_MESSAGES = [
    'required' => '{label} is required.',
    'int' => '{label} must be a whole number.',
    'between' => '{label} must be between {min} and {max}.',
    'min' => '{label} must be at least {min}.',
    'max' => '{label} must be at most {max}.',
    'max_length' => '{label} must be {max} characters or fewer.',
];

function document_field_message(string $rule, array $field): string {
    $max = $rule === 'max_length' ? ($field['max_length'] ?? null) : ($field['max_value'] ?? null);
    return strtr(DOCUMENT_FIELD_MESSAGES[$rule], [
        '{label}' => (string)$field['label'],
        '{min}' => (string)(int)($field['min_value'] ?? 0),
        '{max}' => (string)(int)($max ?? 0),
    ]);
}

/**
 * The one implementation of the registry field rules (is_required, data_type,
 * min_value / max_value, max_length). Returns the message, or null when the
 * value passes. An empty value only fails a required field.
 */
function document_field_rule_error(array $field, string $value): ?string {
    $value = trim($value);
    if ($value === '') return (int)$field['is_required'] === 1 ? document_field_message('required', $field) : null;
    if ($field['data_type'] === 'int') {
        if (!preg_match('/^-?\d{1,9}$/', $value)) return document_field_message('int', $field);
        $number = (int)$value;
        $min = $field['min_value'] === null ? null : (int)$field['min_value'];
        $max = $field['max_value'] === null ? null : (int)$field['max_value'];
        if (($min !== null && $number < $min) || ($max !== null && $number > $max)) {
            return document_field_message($min !== null && $max !== null ? 'between' : ($min !== null ? 'min' : 'max'), $field);
        }
        return null;
    }
    if ($field['max_length'] !== null && mb_strlen($value, 'UTF-8') > (int)$field['max_length']) return document_field_message('max_length', $field);
    return null;
}

/**
 * Generation: staff_entered values must satisfy the registry rules. A key the
 * modal did not send counts as empty. Other sources keep their fallbacks.
 * @throws RequestProblem 422 with the first failing field's message
 */
function document_validate_staff_fields(array $registryFields, array $submitted): void {
    foreach ($registryFields as $field) {
        if ($field['source'] !== 'staff_entered') continue;
        $raw = $submitted[$field['field_key']] ?? '';
        $error = document_field_rule_error($field, is_scalar($raw) ? (string)$raw : '');
        if ($error !== null) throw new RequestProblem($error, 422);
    }
}

function document_registry_template_path(string $templateFile): string {
    if (!preg_match('/^[a-z0-9_]+\.docx$/', $templateFile)) throw new RuntimeException('Invalid document template filename.');
    $dir = document_storage_dir('templates');
    $root = realpath($dir);
    $path = realpath($dir . DIRECTORY_SEPARATOR . $templateFile);
    if ($root === false || $path === false || !str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR)) || !is_file($path)) {
        throw new RuntimeException('Document template is unavailable.');
    }
    return $path;
}

function document_setting_get(mysqli $db, string $key): ?string {
    $stmt = $db->prepare('SELECT setting_value FROM document_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row === null ? null : (string)$row['setting_value'];
}

function document_setting_set(mysqli $db, string $key, string $value, ?int $officialId): void {
    $stmt = $db->prepare('INSERT INTO document_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP');
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('ssi', $key, $value, $officialId);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $stmt->close();
}

function document_validate_signatory_name(string $value): array {
    $name = trim($value);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 80) {
        return ['ok' => false, 'message' => 'Enter a valid signatory name.'];
    }
    if (!preg_match('/^[\p{L}][\p{L} .\'-]*[\p{L}.]$/u', $name)) {
        return ['ok' => false, 'message' => 'Enter a valid signatory name.'];
    }
    if (preg_match('/^(?:Hon(?:\.|\b)|Honorable\b)/iu', $name)) {
        return ['ok' => false, 'message' => 'Enter the name only, without a title'];
    }
    return ['ok' => true, 'name' => $name];
}

function document_registry_context(mysqli $db, string $documentType): ?array {
    if (!document_registry_table_exists($db)) return null;
    $type = document_registry_type($db, $documentType);
    if ($type === null) {
        error_log('Document generation refused: registry type is missing or archived: ' . $documentType);
        throw new RequestProblem('Document type is unavailable.', 422);
    }
    // Phase 7: an internal type (Summons) is never generated for a document request.
    if (($type['audience'] ?? 'resident') !== 'resident') {
        error_log('Document generation refused: internal document type on a request: ' . $documentType);
        throw new RequestProblem('Document type is unavailable.', 422);
    }
    $version = document_template_active_version($db, (int)$type['id']);
    if ($version !== null) {
        $type['template_file'] = $version['file_name'];
    } elseif (document_registry_active_version_table_exists($db)) {
        throw new RequestProblem('Document template is unavailable.', 503, 'configuration_error');
    }
    $captain = document_setting_get($db, 'captain_name');
    if ($captain === null || trim($captain) === '') {
        error_log('Document generation refused: captain_name setting is missing or empty.');
        throw new RequestProblem('Document signatory is not configured.', 503, 'configuration_error');
    }
    return ['type' => $type, 'version' => $version, 'fields' => document_registry_fields($db, (int)$type['id']), 'captain_name' => $captain];
}

function document_registry_active_version_table_exists(mysqli $db): bool {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'document_template_versions' LIMIT 1");
    if (!$stmt) throw new RuntimeException($db->error);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $result = $stmt->get_result(); $exists = (bool)($result && $result->fetch_row()); $stmt->close();
    return $exists;
}

/**
 * Phase 5A columns (20261115 migration) present? Cached per request. Readers
 * that use code / processing_days / requirements check this first so the app
 * keeps working on a database the migration has not reached yet.
 */
function document_types_phase5a_ready(mysqli $db): bool {
    static $cache = [];
    $key = spl_object_id($db) . ':' . $db->thread_id;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_types' AND column_name IN ('code','processing_days','requirements','created_by')");
    if (!$stmt) throw new RuntimeException($db->error);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $count = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    return $cache[$key] = ($count === 4);
}

/**
 * Phase 7 audience column (20261119 migration) present? Cached per connection
 * like document_types_phase5a_ready(). Before the migration every type counts
 * as 'resident' and no reader filters.
 */
function document_types_audience_ready(mysqli $db): bool {
    static $cache = [];
    $key = spl_object_id($db) . ':' . $db->thread_id;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_types' AND column_name = 'audience'");
    if (!$stmt) throw new RuntimeException($db->error);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $count = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    return $cache[$key] = ($count === 1);
}

/** SQL condition keeping only resident-audience rows of alias $alias ('1=1' before the migration). */
function document_types_resident_sql(mysqli $db, string $alias = 'dt'): string {
    return document_types_audience_ready($db) ? "{$alias}.audience = 'resident'" : '1=1';
}

/**
 * A resident may request a type only when it is a resident-audience type, not
 * archived AND has an active template version. Returns the type row, or null.
 */
function document_registry_requestable(mysqli $db, string $name): ?array {
    $type = document_registry_type($db, $name);
    if ($type === null || $type['status'] !== 'active') return null;
    if (($type['audience'] ?? 'resident') !== 'resident') return null;
    if (document_registry_active_version_table_exists($db) && document_template_active_version($db, (int)$type['id']) === null) return null;
    return $type;
}

function document_template_active_version(mysqli $db, int $typeId): ?array {
    if (!document_registry_active_version_table_exists($db)) return null;
    $stmt = $db->prepare("SELECT * FROM document_template_versions WHERE document_type_id = ? AND status = 'active' LIMIT 1");
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('i', $typeId);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}
