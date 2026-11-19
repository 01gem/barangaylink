<?php
require_once __DIR__ . '/_generation.php';
require_once __DIR__ . '/../documents/_docx_render.php';
function xml_safe_text(string $value): string {
  return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function remove_dir_tree(string $path): void {
  if (!is_dir($path)) return;
  $entries = scandir($path);
  if ($entries === false) return;
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $full = $path . DIRECTORY_SEPARATOR . $entry;
    if (is_dir($full)) {
      remove_dir_tree($full);
    } elseif (file_exists($full)) {
      @unlink($full);
    }
  }
  @rmdir($path);
}


function request_render_error(string $message, int $status = 500): void { throw new RequestProblem($message, $status, 'conversion_failed'); }
function request_render_date(string $value): string {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;
  $date = DateTime::createFromFormat('!Y-m-d', $value);
  $errors = DateTime::getLastErrors();
  return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
    ? $date->format('F d, Y') : $value;
}
function request_render_address(string $value): string {
  $normalized = preg_replace('/(,\s*)?(?:brgy\.?|bgy\.?|barangay)\s+sampaguita\.?$/i', '', $value);
  $normalized = $normalized === null ? $value : rtrim($normalized, ", \t");
  return $normalized === '' ? $value : $normalized;
}
function request_render_document(array $request, array $submittedFields, string $tempDir, string $operation, ?array $context = null): array {
$fixedOfficialName = 'Erwin Astronomo';
$referenceNo = $operation;

$documentType = (string)($request['document_type'] ?? '');
if ($context !== null) {
  $template = $context['type'] ?? null;
  $placeholderKeys = array_map(static fn(array $field): string => (string)$field['field_key'], $context['fields'] ?? []);
  $templateFile = (string)($template['template_file'] ?? '');
  $fixedOfficialName = trim((string)($context['captain_name'] ?? ''));
  if ($template === null || $template['name'] !== $documentType || $fixedOfficialName === '') {
    error_log('Document generation refused: registry context does not match request.');
    request_render_error('Document generation could not be completed.', 500);
  }
} else {
  $templateMap = LEGACY_DOCUMENT_MAP;
  if (!isset($templateMap[$documentType])) {
    error_log('Document generation refused: unknown legacy document type: ' . $documentType);
    request_render_error('Document generation could not be completed.', 400);
  }
  $templateFile = $templateMap[$documentType]['file'];
  $placeholderKeys = $templateMap[$documentType]['placeholders'];
}

try {
  $templatePath = document_registry_template_path($templateFile);
} catch (Throwable $e) {
  error_log('Document template lookup failed: ' . $e->getMessage());
  request_render_error('Document template is unavailable.', 500);
}

$generatedFields = [];
foreach ($submittedFields as $key => $value) {
  if (!is_string($key)) continue;
  if (is_scalar($value) || $value === null) {
    $generatedFields[$key] = trim((string)$value);
  }
}

$residentFullName = trim((string)($request['resident_name'] ?? ''));
if ($residentFullName === '') {
  $residentFullName = trim(((string)($request['fname'] ?? '')) . ' ' . ((string)($request['lname'] ?? '')));
}
$residentAddress = trim((string)($request['address'] ?? ''));

if (($generatedFields['full_name'] ?? '') === '' && $residentFullName !== '') {
  $generatedFields['full_name'] = $residentFullName;
}
if (($generatedFields['owner_name'] ?? '') === '' && $residentFullName !== '') {
  $generatedFields['owner_name'] = $residentFullName;
}
if (($generatedFields['address'] ?? '') === '' && $residentAddress !== '') {
  $generatedFields['address'] = $residentAddress;
}
if (($generatedFields['business_address'] ?? '') === '' && $residentAddress !== '') {
  $generatedFields['business_address'] = $residentAddress;
}
if (($generatedFields['purpose'] ?? '') === '') {
  $generatedFields['purpose'] = trim((string)($request['purpose'] ?? ''));
}
$generatedFields['reference_no'] = (string)($request['reference_no'] ?? '');
if (($generatedFields['date_issued'] ?? '') === '') {
  $generatedFields['date_issued'] = date('F d, Y');
}
$generatedFields['date_issued'] = request_render_date((string)$generatedFields['date_issued']);
foreach (['address', 'business_address'] as $addressKey) {
  if (isset($generatedFields[$addressKey])) {
    $generatedFields[$addressKey] = request_render_address((string)$generatedFields[$addressKey]);
  }
}
$generatedFields['official_name'] = DOCUMENT_SIGNATORY_HONORIFIC . ' ' . ltrim($fixedOfficialName);
$generatedFields['official_title'] = DOCUMENT_SIGNATORY_TITLE;

// Resident-supplied fields from the request form fill any placeholder the
// official left blank. Explicit values typed in the modal still win.
$storedFields = json_decode((string)($request['request_fields_json'] ?? ''), true);
if (is_array($storedFields)) {
  foreach ($storedFields as $k => $v) {
    if (!isset($generatedFields[$k]) || $generatedFields[$k] === '') {
      $generatedFields[$k] = is_scalar($v) ? (string)$v : '';
    }
  }
}

// Derive age from the resident's birthdate when neither the modal nor the
// stored fields supplied one. Barangay Clearance needs this placeholder.
if (($generatedFields['age'] ?? '') === '' && !empty($request['birthdate'])) {
  try {
    $birth = new DateTime((string)$request['birthdate']);
    $generatedFields['age'] = (string)$birth->diff(new DateTime('now'))->y;
  } catch (Throwable $e) {
    // Leave blank; the placeholder resolves to empty in the DOCX.
  }
}

$workingDocx = $tempDir . DIRECTORY_SEPARATOR . $referenceNo . '.docx';
// Shared with summons (api/documents/_docx_render.php); same checks and messages as before.
document_fill_docx($templatePath, $placeholderKeys, $generatedFields, $workingDocx);
$pdf = document_convert_pdf($workingDocx, $tempDir, $operation);
return ['pdf' => $pdf, 'docx' => $workingDocx];
}
