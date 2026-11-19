<?php
/**
 * BarangayLink - Create a document type (Phase 5A).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * A new type is inserted with status 'active' but no template version, so
 * residents cannot see or request it (document_registry_requestable) until a
 * first DOCX is uploaded, checked, test-rendered and activated.
 */

require_once __DIR__ . '/_registry.php';
require_once __DIR__ . '/../requests/_workflow.php';

/**
 * Global field catalogue: every placeholder the renderer knows how to fill.
 * Definitions match the seeded document_type_fields rows exactly (the 5A
 * suite asserts this). Order here is the sort order for a new type.
 */
const DOCUMENT_FIELD_CATALOGUE = [
    'full_name'          => ['label' => 'Full name',          'source' => 'resident_record',   'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
    'owner_name'         => ['label' => 'Owner name',         'source' => 'resident_record',   'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
    'address'            => ['label' => 'Address',            'source' => 'resident_record',   'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
    'business_address'   => ['label' => 'Business address',   'source' => 'resident_record',   'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
    'age'                => ['label' => 'Age',                'source' => 'resident_supplied', 'data_type' => 'int',  'min_value' => 0,    'max_value' => 120,  'max_length' => null, 'is_required' => 0],
    'civil_status'       => ['label' => 'Civil status',       'source' => 'resident_supplied', 'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => 100,  'is_required' => 0],
    'years_of_residency' => ['label' => 'Years of residency', 'source' => 'resident_supplied', 'data_type' => 'int',  'min_value' => 0,    'max_value' => 120,  'max_length' => null, 'is_required' => 0],
    'business_name'      => ['label' => 'Business name',      'source' => 'resident_supplied', 'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => 100,  'is_required' => 0],
    'business_type'      => ['label' => 'Business type',      'source' => 'resident_supplied', 'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => 100,  'is_required' => 0],
    'purpose'            => ['label' => 'Purpose',            'source' => 'staff_entered',     'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
    'date_issued'        => ['label' => 'Date issued',        'source' => 'staff_entered',     'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
    'reference_no'       => ['label' => 'Reference number',   'source' => 'system',            'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
    'official_name'      => ['label' => 'Official name',      'source' => 'system',            'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
    'official_title'     => ['label' => 'Official title',     'source' => 'system',            'data_type' => 'text', 'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 1],
];
/** Always added to a new type; shown ticked and disabled in the UI. */
const DOCUMENT_ALWAYS_FIELDS = ['date_issued', 'reference_no', 'official_name', 'official_title'];
/** Ticked by default in the UI (can be unticked). */
const DOCUMENT_DEFAULT_FIELDS = ['full_name', 'address', 'purpose'];

const DOCUMENT_TYPE_CODE_PATTERN = '/^[a-z][a-z0-9_]{2,49}$/';
const DOCUMENT_TYPE_MSG_NAME = 'Enter a document name of 3 to 100 characters.';
const DOCUMENT_TYPE_MSG_NAME_CHARS = 'The document name contains characters that are not allowed.';
const DOCUMENT_TYPE_MSG_CODE = 'Code must be 3 to 50 lowercase letters, numbers or underscores and start with a letter.';
const DOCUMENT_TYPE_MSG_FEE = 'Fee must be a number from 0.00 to 9999.99.';
const DOCUMENT_TYPE_MSG_DAYS = 'Processing days must be a whole number from 0 to 60.';
const DOCUMENT_TYPE_MSG_REQ_LENGTH = 'Requirements must be 1000 characters or fewer.';
const DOCUMENT_TYPE_MSG_REQ_CHARS = 'Requirements contain characters that are not allowed.';
const DOCUMENT_TYPE_MSG_FIELDS = 'Choose fields from the list only.';
const DOCUMENT_TYPE_MSG_NAME_TAKEN = 'A document type with this name already exists.';
const DOCUMENT_TYPE_MSG_CODE_TAKEN = 'A document type with this code already exists.';
const DOCUMENT_TYPE_MSG_NOT_MIGRATED = 'Adding document types needs the 20261115 database migration.';

/** Catalogue for the UI: key, label, source, required, always-included, default-ticked. */
function document_field_catalogue_public(): array {
    $rows = [];
    foreach (DOCUMENT_FIELD_CATALOGUE as $key => $field) {
        $rows[] = ['field_key' => $key, 'label' => $field['label'], 'source' => $field['source'],
            'is_required' => $field['is_required'], 'always' => in_array($key, DOCUMENT_ALWAYS_FIELDS, true),
            'default' => in_array($key, DOCUMENT_ALWAYS_FIELDS, true) || in_array($key, DOCUMENT_DEFAULT_FIELDS, true)];
    }
    return $rows;
}

/** C0/C1 controls, DEL, line/paragraph separators, bidi controls. Newline/tab optional. */
function document_type_has_forbidden_chars(string $value, bool $allowNewlines): bool {
    $pattern = $allowNewlines
        ? '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'
        : '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';
    return preg_match($pattern, $value) === 1;
}

/** Lowercase snake code from a display name: accents dropped (ñ -> n), symbols -> _. May return ''. */
function document_type_derive_code(string $name): string {
    $text = strtr($name, ['ñ' => 'n', 'Ñ' => 'N']);
    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
        if (is_string($decomposed)) $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
    } else {
        $text = strtr($text, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    }
    $code = strtolower($text);
    $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?? '';
    $code = trim($code, '_');
    if ($code !== '' && !preg_match('/^[a-z]/', $code)) $code = 'doc_' . $code;
    $code = rtrim(substr($code, 0, 50), '_');
    return $code;
}

/**
 * Validate and normalize the create payload.
 * @return array{name:string, code:string, fee:float, processing_days:int, requirements:?string, field_keys:list<string>}
 * @throws RequestProblem 422
 */
function document_type_validate(array $input): array {
    $rawName = $input['name'] ?? null;
    if (!is_string($rawName)) throw new RequestProblem(DOCUMENT_TYPE_MSG_NAME, 422);
    if (document_type_has_forbidden_chars($rawName, false)) throw new RequestProblem(DOCUMENT_TYPE_MSG_NAME_CHARS, 422);
    $name = preg_replace('/\s+/u', ' ', trim($rawName)) ?? '';
    $length = mb_strlen($name, 'UTF-8');
    if ($length < 3 || $length > 100 || !preg_match('/\p{L}/u', $name)) throw new RequestProblem(DOCUMENT_TYPE_MSG_NAME, 422);

    $rawCode = $input['code'] ?? '';
    if ($rawCode === null) $rawCode = '';
    if (!is_string($rawCode)) throw new RequestProblem(DOCUMENT_TYPE_MSG_CODE, 422);
    $code = trim($rawCode) === '' ? document_type_derive_code($name) : trim($rawCode);
    if (!preg_match(DOCUMENT_TYPE_CODE_PATTERN, $code)) throw new RequestProblem(DOCUMENT_TYPE_MSG_CODE, 422);

    $rawFee = $input['fee'] ?? null;
    if (is_int($rawFee) || is_float($rawFee)) {
        if (!is_finite((float)$rawFee)) throw new RequestProblem(DOCUMENT_TYPE_MSG_FEE, 422);
        $fee = (float)$rawFee;
        if (round($fee, 2) != $fee) throw new RequestProblem(DOCUMENT_TYPE_MSG_FEE, 422);
    } elseif (is_string($rawFee) && preg_match('/^\d{1,4}(?:\.\d{1,2})?$/', trim($rawFee))) {
        $fee = (float)trim($rawFee);
    } else {
        throw new RequestProblem(DOCUMENT_TYPE_MSG_FEE, 422);
    }
    if ($fee < 0 || $fee > 9999.99) throw new RequestProblem(DOCUMENT_TYPE_MSG_FEE, 422);
    $fee = round($fee, 2);

    $rawDays = $input['processing_days'] ?? null;
    if (is_int($rawDays)) $days = $rawDays;
    elseif (is_string($rawDays) && preg_match('/^\d{1,2}$/', trim($rawDays))) $days = (int)trim($rawDays);
    else throw new RequestProblem(DOCUMENT_TYPE_MSG_DAYS, 422);
    if ($days < 0 || $days > 60) throw new RequestProblem(DOCUMENT_TYPE_MSG_DAYS, 422);

    $rawRequirements = $input['requirements'] ?? '';
    if ($rawRequirements === null) $rawRequirements = '';
    if (!is_string($rawRequirements)) throw new RequestProblem(DOCUMENT_TYPE_MSG_REQ_LENGTH, 422);
    if (document_type_has_forbidden_chars($rawRequirements, true)) throw new RequestProblem(DOCUMENT_TYPE_MSG_REQ_CHARS, 422);
    $requirements = trim(str_replace(["\r\n", "\r"], "\n", $rawRequirements));
    if (mb_strlen($requirements, 'UTF-8') > 1000) throw new RequestProblem(DOCUMENT_TYPE_MSG_REQ_LENGTH, 422);

    $rawKeys = $input['field_keys'] ?? [];
    if (!is_array($rawKeys) || !array_is_list($rawKeys) || count($rawKeys) > count(DOCUMENT_FIELD_CATALOGUE)) {
        throw new RequestProblem(DOCUMENT_TYPE_MSG_FIELDS, 422);
    }
    foreach ($rawKeys as $key) {
        if (!is_string($key) || !array_key_exists($key, DOCUMENT_FIELD_CATALOGUE)) throw new RequestProblem(DOCUMENT_TYPE_MSG_FIELDS, 422);
    }
    $wanted = array_unique(array_merge($rawKeys, DOCUMENT_ALWAYS_FIELDS));
    $keys = array_values(array_filter(array_keys(DOCUMENT_FIELD_CATALOGUE), static fn(string $k): bool => in_array($k, $wanted, true)));

    return ['name' => $name, 'code' => $code, 'fee' => $fee, 'processing_days' => $days,
        'requirements' => $requirements === '' ? null : $requirements, 'field_keys' => $keys];
}

/** Friendly pre-check only. The unique keys decide a race (document_type_insert). */
function document_type_precheck(mysqli $db, array $clean): void {
    $stmt = request_sql($db, 'SELECT 1 FROM document_types WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1', [$clean['name']]);
    $taken = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
    if (!$taken) {
        $stmt = request_sql($db, 'SELECT 1 FROM document_prices WHERE LOWER(TRIM(document_type)) = LOWER(?) LIMIT 1', [$clean['name']]);
        $taken = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
    }
    if ($taken) throw new RequestProblem(DOCUMENT_TYPE_MSG_NAME_TAKEN, 409, 'conflict');
    $stmt = request_sql($db, 'SELECT 1 FROM document_types WHERE code = ? LIMIT 1', [$clean['code']]);
    $codeTaken = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
    if ($codeTaken) throw new RequestProblem(DOCUMENT_TYPE_MSG_CODE_TAKEN, 409, 'conflict');
}

/** Map a duplicate-key failure to the 409 the UI shows. */
function document_type_duplicate_problem(Throwable $e): ?RequestProblem {
    if ((int)$e->getCode() !== 1062) return null;
    if (str_contains($e->getMessage(), 'document_type_code')) return new RequestProblem(DOCUMENT_TYPE_MSG_CODE_TAKEN, 409, 'conflict');
    return new RequestProblem(DOCUMENT_TYPE_MSG_NAME_TAKEN, 409, 'conflict');
}

/**
 * Insert type, fields and price in one transaction. No pre-check here: the
 * unique keys on document_types.name/code and document_prices.document_type
 * are what stop duplicates, including races.
 * @return array{type_id:int, code:string}
 * @throws RequestProblem 409 on a duplicate
 */
function document_type_insert(mysqli $db, array $clean, int $officialId): array {
    try {
        return request_transaction($db, static function () use ($db, $clean, $officialId): array {
            // Phase 7: internal types (Summons, sort_order 1000+) are left out of the resident order.
            $stmt = request_sql($db, 'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM document_types dt WHERE ' . document_types_resident_sql($db));
            $sort = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
            $createdBy = $officialId > 0 ? $officialId : null;
            $stmt = request_sql($db, "INSERT INTO document_types (name, code, template_file, status, sort_order, processing_days, requirements, created_by) VALUES (?, ?, '', 'active', ?, ?, ?, ?)",
                [$clean['name'], $clean['code'], $sort, $clean['processing_days'], $clean['requirements'], $createdBy]);
            $typeId = (int)$stmt->insert_id; $stmt->close();
            $order = 0;
            foreach ($clean['field_keys'] as $key) {
                $f = DOCUMENT_FIELD_CATALOGUE[$key];
                $order++;
                request_exec($db, 'INSERT INTO document_type_fields (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$typeId, $key, $f['label'], $f['source'], $f['data_type'], $f['min_value'], $f['max_value'], $f['max_length'], $f['is_required'], $order]);
            }
            // Plain INSERT: an orphan price row with this name is a clash, never overwritten.
            request_exec($db, 'INSERT INTO document_prices (document_type, unit_price, updated_at) VALUES (?, ?, NOW())', [$clean['name'], $clean['fee']]);
            return ['type_id' => $typeId, 'code' => $clean['code']];
        });
    } catch (RequestProblem $e) {
        throw $e;
    } catch (Throwable $e) {
        $problem = document_type_duplicate_problem($e);
        if ($problem !== null) throw $problem;
        throw $e;
    }
}

/**
 * Validate, pre-check, insert, audit. Audit details carry name and code only.
 * @return array{type_id:int, code:string}
 */
function document_type_create(mysqli $db, array $input, int $officialId, string $officialName): array {
    if (!document_types_phase5a_ready($db)) throw new RequestProblem(DOCUMENT_TYPE_MSG_NOT_MIGRATED, 503, 'configuration_error');
    $clean = document_type_validate($input);
    document_type_precheck($db, $clean);
    $created = document_type_insert($db, $clean, $officialId);
    log_audit($db, $officialId, $officialName, 'Created document type', 'document_type', (string)$created['type_id'],
        'Name: ' . $clean['name'] . '; Code: ' . $clean['code']);
    return $created;
}
