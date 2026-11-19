<?php
/**
 * BarangayLink - Shared complaint filing for residents and walk-ins (complaint revision Phase 5).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * create.php (resident) and create_official.php (walk-in at the barangay hall)
 * validate the type, location, description, severity and extra fields here, and
 * insert the complaint row and its first timeline row here, so both follow the
 * same rules and messages. Rule failures throw ComplaintProblem.
 */

require_once __DIR__ . '/_problem.php';
require_once __DIR__ . '/_extra_fields.php';
require_once __DIR__ . '/_parties.php';

const COMPLAINT_SEVERITIES = ['low', 'medium', 'high', 'critical'];

/** CSR-YYYYMMDD-XXXXXX. The unique key on reference_no is the safety net. */
function complaint_new_reference_no(): string {
  return 'CSR-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
}

/**
 * The request body as an object map. Nested objects stay stdClass, which the
 * extra-field validator needs to tell {} from [].
 */
function complaint_filing_read_body(): array {
  $raw = json_decode((string)file_get_contents('php://input'));
  if (!$raw instanceof stdClass) throw new ComplaintProblem('A valid JSON object is required.', 422);
  return get_object_vars($raw);
}

/**
 * Type, location, description, severity and extra fields, as create.php has
 * always checked them (same messages and status codes).
 * The type arrives as complaint_type_id, or (residents only, legacy) as the category name.
 * @return array{type:array, type_id:int, category:string, location:string, description:string,
 *               severity:string, extra_fields_json:?string}
 */
function complaint_filing_validate(array $input, bool $allowLegacyCategory): array {
  foreach (['category', 'location_text', 'description', 'severity'] as $field) {
    if (array_key_exists($field, $input) && $input[$field] !== null && !is_string($input[$field])) {
      throw new ComplaintProblem('Complaint fields must be text.', 422);
    }
  }
  $typeId = $input['complaint_type_id'] ?? null;
  if ($typeId !== null && (!is_int($typeId) || $typeId <= 0)) {
    throw new ComplaintProblem('Select a valid type of concern.', 422);
  }
  $category = $allowLegacyCategory ? trim((string)($input['category'] ?? '')) : '';
  $location = trim((string)($input['location_text'] ?? ''));
  $description = trim((string)($input['description'] ?? ''));
  $severity = strtolower(trim((string)($input['severity'] ?? 'medium')));
  if (!in_array($severity, COMPLAINT_SEVERITIES, true)) {
    throw new ComplaintProblem('Invalid severity. Allowed values are: low, medium, high, critical.', 422);
  }
  if (($typeId === null && $category === '') || $location === '' || $description === '') {
    throw new ComplaintProblem('Category, location, and description are required.', 400);
  }

  // Only active types can be filed. When both forms are sent they must agree.
  $type = $typeId !== null ? complaint_type_get($typeId) : complaint_type_by_name($category);
  if ($type === null || ($typeId !== null && $category !== '' && $type['type_name'] !== $category)) {
    throw new ComplaintProblem('Select a valid type of concern.', 422);
  }

  try {
    $extraFields = complaint_extra_fields_validate($type, $input['extra_fields'] ?? null);
    $extraFieldsJson = $extraFields
      ? json_encode((object)$extraFields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
      : null;
  } catch (InvalidArgumentException $error) {
    throw new ComplaintProblem($error->getMessage(), 422);
  }

  return [
    'type' => $type,
    'type_id' => $type['id'],
    'category' => $type['type_name'],
    'location' => $location,
    'description' => $description,
    'severity' => $severity,
    'extra_fields_json' => $extraFieldsJson,
  ];
}

/**
 * Insert the complaint row (inside the caller's transaction).
 * $filer: resident_id (?int), resident_name, filed_by_type, filed_by_official_id (?int), official_note.
 * @return array{id:int, reference_no:string}
 */
function complaint_filing_insert(mysqli $db, array $filing, array $filer): array {
  $reference = complaint_new_reference_no();
  $stmt = complaint_party_stmt($db,
    'INSERT INTO complaints
       (resident_id, reference_no, resident_name, filed_by_type, filed_by_official_id, category, complaint_type_id,
        severity, location_text, description, date_filed, status, official_note, extra_fields_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$filer['resident_id'], $reference, mb_substr($filer['resident_name'], 0, 100, 'UTF-8'),
     $filer['filed_by_type'], $filer['filed_by_official_id'], $filing['category'], $filing['type_id'],
     $filing['severity'], $filing['location'], $filing['description'], date('M d, Y'), 'open',
     $filer['official_note'], $filing['extra_fields_json']]);
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return ['id' => $id, 'reference_no' => $reference];
}

/** The first timeline row ("open"). Throws on failure, so the caller rolls back. */
function complaint_filing_update_row(mysqli $db, int $complaintId, string $actorType, ?int $actorId, string $note): void {
  complaint_party_exec($db,
    'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
     VALUES (?, ?, ?, ?, ?, ?)',
    [$complaintId, $actorType, $actorId, null, 'open', $note]);
}

/** "Hi Juan, your complaint CSR-... was received ..." (unchanged text). */
function complaint_filing_sms_body(string $firstName, string $reference): string {
  $firstName = trim($firstName) === '' ? 'there' : trim($firstName);
  return "Hi {$firstName}, your complaint {$reference} was received by Brgy. Sampaguita. "
       . "We'll update you on any changes.";
}
