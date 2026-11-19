<?php
/**
 * TEST FIXTURE ONLY. Production code must never include this file.
 *
 * The hardcoded complaint schema as it was in api/complaints/_extra_fields.php
 * before complaint revision Phase 2, copied verbatim. The tests use it to
 * prove that the database-driven schema reads old complaints identically.
 */
function complaint_legacy_schema(): array {
  return [
    'Road / Infrastructure' => [
      'road_issue' => ['label' => 'Infrastructure issue', 'options' => [
        'pothole' => 'Pothole', 'damaged_road' => 'Damaged road',
        'streetlight' => 'Streetlight', 'sidewalk' => 'Sidewalk', 'other' => 'Other',
      ]],
      'access_impact' => ['label' => 'Access impact', 'options' => [
        'passable' => 'Passable', 'partly_blocked' => 'Partly blocked', 'blocked' => 'Blocked',
      ]],
    ],
    'Noise / Public Disturbance' => [
      'noise_source' => ['label' => 'Noise source', 'options' => [
        'music' => 'Music', 'construction' => 'Construction', 'vehicles' => 'Vehicles',
        'gathering' => 'Gathering', 'other' => 'Other',
      ]],
      'usual_time' => ['label' => 'Usual time', 'options' => [
        'daytime' => 'Daytime', 'evening' => 'Evening', 'overnight' => 'Overnight', 'varies' => 'Varies',
      ]],
    ],
    'Flooding / Drainage' => [
      'drainage_issue' => ['label' => 'Drainage issue', 'options' => [
        'blocked_drain' => 'Blocked drain', 'flooding' => 'Flooding',
        'standing_water' => 'Standing water', 'other' => 'Other',
      ]],
      'affected_area' => ['label' => 'Affected area', 'options' => [
        'one_property' => 'One property', 'street' => 'Street', 'several_streets' => 'Several streets',
      ]],
    ],
    'Public Safety' => [
      'safety_hazard' => ['label' => 'Safety concern', 'options' => [
        'poor_lighting' => 'Poor lighting', 'unsafe_structure' => 'Unsafe structure',
        'blocked_path' => 'Blocked path', 'other' => 'Other',
      ]],
      'ongoing' => ['label' => 'Is the concern still present?', 'options' => [
        'yes' => 'Yes', 'no' => 'No', 'unsure' => 'Unsure',
      ]],
    ],
    'Other' => [],
  ];
}

/** The pre-Phase 2 tolerant read, copied verbatim (schema source renamed). */
function complaint_legacy_read(string $category, ?string $json): array {
  if ($json === null || $json === '') return [];
  $raw = json_decode($json);
  if (!$raw instanceof stdClass) return [];
  $schema = complaint_legacy_schema()[$category] ?? [];
  $clean = [];
  foreach ($schema as $key => $field) {
    if (!property_exists($raw, $key) || !is_string($raw->{$key})) continue;
    $value = trim($raw->{$key});
    if (array_key_exists($value, $field['options'])) $clean[$key] = $value;
  }
  return $clean;
}

/** The pre-Phase 2 display projection, copied verbatim (schema source renamed). */
function complaint_legacy_display(string $category, array $values): array {
  $display = [];
  foreach (complaint_legacy_schema()[$category] ?? [] as $key => $field) {
    $value = $values[$key] ?? null;
    if (!is_string($value) || !array_key_exists($value, $field['options'])) continue;
    $display[] = ['label' => $field['label'], 'value' => $field['options'][$value]];
  }
  return $display;
}
