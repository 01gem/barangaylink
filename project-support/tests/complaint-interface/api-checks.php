<?php
// Included by the existing isolated review runner; never use application data.
if (PHP_SAPI !== 'cli' || !isset($dbName) || !str_starts_with($dbName, 'capstone_fix24_')) exit(1);
$complaintPost = static function (string $endpoint, array $body, ?string $cookie) use ($base): array {
  $response = http($base . '/api/complaints/' . $endpoint . '.php', 'POST', json_encode($body), ['Content-Type: application/json'], $cookie);
  $response['json'] = json_decode($response['body'], true);
  return $response;
};
$created = $complaintPost('create', [
  'category' => 'Noise / Public Disturbance', 'location_text' => 'Test location',
  'description' => 'Isolated interface regression complaint', 'severity' => 'medium',
], $cookieFor('resident'));
$interfaceId = (int)($created['json']['id'] ?? 0);
check('interface: resident creates isolated complaint', $created['status'] === 200 && $interfaceId > 0, $created['body']);
if (!$interfaceId) throw new RuntimeException('Interface fixture failed');
$interfaceRow = static fn() => q($db, 'SELECT * FROM complaints WHERE id=?', 'i', [$interfaceId])[0];
$interfaceCounts = static fn() => [
  q($db, 'SELECT COUNT(*) AS n FROM complaint_updates WHERE complaint_id=?', 'i', [$interfaceId])[0]['n'],
  q($db, 'SELECT COUNT(*) AS n FROM notifications WHERE resident_id=?', 'i', [$resident])[0]['n'],
];
check('interface: new default note is pending review', $interfaceRow()['official_note'] === 'Received. Pending review.');
exec_sql($db, 'UPDATE complaints SET assigned_official_id=?,sla_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id=?', 'ii', [$adminB, $interfaceId]);
$assignmentBefore = [$interfaceRow()['assigned_official_id'], $interfaceRow()['sla_due_at']];
$initial = $interfaceCounts();
$unchanged = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'open'], $cookieFor('adminB'));
check('interface: omitted fields and unchanged submissions preserve history', $unchanged['status'] === 200 && $unchanged['json']['changed'] === false && $initial === $interfaceCounts() && $interfaceRow()['severity'] === 'medium');
$note = "Reviewed <script>alert('test')</script> & evidence.\nFollow up tomorrow.";
$saved = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'investigating', 'severity' => 'high', 'official_note' => $note], $cookieFor('adminB'));
check('interface: status severity multiline note persist', $saved['status'] === 200 && $interfaceRow()['official_note'] === $note && $interfaceRow()['severity'] === 'high' && $interfaceRow()['status'] === 'investigating');
check('interface: management updates preserve assignment and response target', $assignmentBefore === [$interfaceRow()['assigned_official_id'], $interfaceRow()['sla_due_at']]);
$afterSave = $interfaceCounts();
check('interface: one history entry and one resident notification on transition', (int)$afterSave[0] === (int)$initial[0] + 1 && (int)$afterSave[1] === (int)$initial[1] + 1);
$same = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'investigating', 'severity' => 'high', 'official_note' => $note], $cookieFor('adminB'));
check('interface: identical save creates no duplicate history or notification', $same['json']['changed'] === false && $afterSave === $interfaceCounts());
foreach (['update_status', 'notify_resolved'] as $endpoint) {
  foreach ([str_repeat('x', 256), ['bad'], null] as $badNote) {
    $bad = $complaintPost($endpoint, ['id' => $interfaceId, 'status' => 'investigating', 'official_note' => $badNote], $cookieFor('adminB'));
    check('interface: ' . $endpoint . ' rejects invalid note ' . gettype($badNote), $bad['status'] === 400 && $interfaceRow()['official_note'] === $note && $afterSave === $interfaceCounts());
  }
  foreach ([null, $cookieFor('resident'), $cookieFor('other')] as $unauthorized) {
    $bad = $complaintPost($endpoint, ['id' => $interfaceId, 'status' => 'resolved', 'official_note' => 'forbidden'], $unauthorized);
    check('interface: ' . $endpoint . ' refuses unauthorized mutation', in_array($bad['status'], [401, 403], true) && $interfaceRow()['official_note'] === $note);
  }
}
$unicodeNote = str_repeat('😀', 255);
$unicode = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'investigating', 'official_note' => $unicodeNote], $cookieFor('adminB'));
check('interface: 255 Unicode characters fit without truncation', $unicode['status'] === 200 && $interfaceRow()['official_note'] === $unicodeNote);
$resolved = $complaintPost('notify_resolved', ['id' => $interfaceId, 'severity' => 'critical', 'official_note' => $note], $cookieFor('adminB'));
check('interface: resolve preserves pending resident response and skips external SMS', $resolved['status'] === 200 && $interfaceRow()['resident_confirmation'] === 'pending' && $resolved['json']['sms_status'] === 'skipped');
$resolvedCounts = $interfaceCounts();
$resolutionId = (int)q($db, "SELECT MAX(id) AS n FROM complaint_updates WHERE complaint_id=? AND to_status='resolved' AND from_status<>'resolved'", 'i', [$interfaceId])[0]['n'];
$again = $complaintPost('notify_resolved', ['id' => $interfaceId, 'severity' => 'critical', 'official_note' => $note], $cookieFor('adminB'));
check('interface: repeat resolution is idempotent', $again['json']['changed'] === false && $resolvedCounts === $interfaceCounts());
$confirmed = $complaintPost('confirm_resolution', ['complaint_id' => $interfaceId, 'expected_resolution_id' => $resolutionId], $cookieFor('resident'));
check('interface: resident can confirm resolution', $confirmed['status'] === 200 && $interfaceRow()['resident_confirmation'] === 'confirmed');
$onlyNote = $complaintPost('notify_resolved', ['id' => $interfaceId, 'official_note' => 'Follow-up note'], $cookieFor('adminB'));
check('interface: note-only resolved edit preserves confirmation and omitted severity', $onlyNote['status'] === 200 && $interfaceRow()['resident_confirmation'] === 'confirmed' && $interfaceRow()['severity'] === 'critical');
$reopened = $complaintPost('reopen_complaint', ['complaint_id' => $interfaceId, 'expected_resolution_id' => $resolutionId, 'reason' => 'Issue remains'], $cookieFor('resident'));
check('interface: resident can reopen resolution', $reopened['status'] === 200 && $interfaceRow()['status'] === 'open' && $interfaceRow()['resident_confirmation'] === 'reopened');
$clear = $complaintPost('update_status', ['id' => $interfaceId, 'status' => 'open', 'official_note' => ''], $cookieFor('adminB'));
check('interface: explicit empty note clears it and preserves reopened response', $clear['status'] === 200 && $interfaceRow()['official_note'] === '' && $interfaceRow()['resident_confirmation'] === 'reopened');
$timeline = http($base . '/api/complaints/timeline.php?complaint_id=' . $interfaceId, 'GET', null, [], $cookieFor('adminB'));
$updates = json_decode($timeline['body'], true)['updates'] ?? [];
$keys = array_map(static fn($u) => $u['created_at'] . str_pad((string)$u['id'], 12, '0', STR_PAD_LEFT), $updates);
$sorted = $keys; sort($sorted);
check('interface: timeline reads chronologically', count($keys) > 2 && $keys === $sorted);
$commentBefore = (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_comments WHERE complaint_id=?', 'i', [$interfaceId])[0]['n'];
$comment = $complaintPost('add_comment', ['complaint_id' => $interfaceId, 'body' => "Safe text <img src=x onerror=alert(1)>\nSecond line"], $cookieFor('adminB'));
$tooLong = $complaintPost('add_comment', ['complaint_id' => $interfaceId, 'body' => str_repeat('x', 2001)], $cookieFor('adminB'));
check('interface: one comment persists and 2000-character limit remains', $comment['status'] === 200 && $tooLong['status'] === 422 && (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_comments WHERE complaint_id=?', 'i', [$interfaceId])[0]['n'] === $commentBefore + 1);
$list = http($base . '/api/complaints/list.php', 'GET', null, [], $cookieFor('adminB'));
$rows = json_decode($list['body'], true)['complaints'] ?? [];
$testRows = array_values(array_filter($rows, static fn($row) => (int)$row['id'] === $interfaceId));
check('interface: list returns real attachment count and stored note', count($testRows) === 1 && (int)$testRows[0]['attachment_count'] === 0 && $testRows[0]['official_note'] === '');
$unauthorizedPhotos = http($base . '/api/complaints/list_attachments.php?complaint_id=' . $interfaceId, 'GET', null, [], $cookieFor('other'));
check('interface: other resident cannot read attachment list', $unauthorizedPhotos['status'] === 403);

// Complaint revision Phase 2: types and extra fields come from the database.
$typeIds = [];
foreach (q($db, 'SELECT id, type_code FROM complaint_types') as $typeRow) $typeIds[$typeRow['type_code']] = (int)$typeRow['id'];
$phase2Ids = [];
$phase2Row = static fn(int $id): array => q($db, 'SELECT * FROM complaints WHERE id=?', 'i', [$id])[0] ?? [];
$phase2Create = static function (array $body) use ($complaintPost, $cookieFor, &$phase2Ids): array {
  $response = $complaintPost('create', $body + ['location_text' => 'Test location', 'description' => 'Phase 2 type check', 'severity' => 'low'], $cookieFor('resident'));
  $response['id'] = (int)($response['json']['id'] ?? 0);
  if ($response['id'] > 0) $phase2Ids[] = $response['id'];
  return $response;
};
check('interface: scratch database has the five seeded complaint types', count($typeIds) === 5, json_encode($typeIds));
check('interface: legacy category create stores the matching complaint_type_id',
  (int)$interfaceRow()['complaint_type_id'] === ($typeIds['noise_disturbance'] ?? 0) && $interfaceRow()['category'] === 'Noise / Public Disturbance');
$byId = $phase2Create(['complaint_type_id' => $typeIds['road_infrastructure'] ?? 0, 'extra_fields' => ['road_issue' => 'pothole']]);
$byIdRow = $byId['id'] ? $phase2Row($byId['id']) : [];
check('interface: create with complaint_type_id stores the id, the type name and the extra field',
  $byId['status'] === 200 && (int)($byIdRow['complaint_type_id'] ?? 0) === $typeIds['road_infrastructure']
    && ($byIdRow['category'] ?? '') === 'Road / Infrastructure'
    && json_decode((string)($byIdRow['extra_fields_json'] ?? 'null'), true) === ['road_issue' => 'pothole'], $byId['body']);
$mismatch = $phase2Create(['complaint_type_id' => $typeIds['road_infrastructure'] ?? 0, 'category' => 'Public Safety']);
$unknownType = $phase2Create(['complaint_type_id' => 999999]);
$unknownName = $phase2Create(['category' => 'Not A Real Type']);
$stringId = $phase2Create(['complaint_type_id' => (string)($typeIds['road_infrastructure'] ?? 0)]);
check('interface: mismatched, unknown, or non-integer type is refused with 422',
  $mismatch['status'] === 422 && $unknownType['status'] === 422 && $unknownName['status'] === 422 && $stringId['status'] === 422
    && ($unknownType['json']['message'] ?? '') === 'Select a valid type of concern.', $mismatch['body'] . $unknownType['body'] . $unknownName['body'] . $stringId['body']);
$otherBare = $phase2Create(['complaint_type_id' => $typeIds['other'] ?? 0]);
$otherEmpty = $phase2Create(['complaint_type_id' => $typeIds['other'] ?? 0, 'extra_fields' => ['specify' => '   ']]);
check('interface: Other without specify is refused with 422',
  $otherBare['status'] === 422 && $otherEmpty['status'] === 422 && ($otherBare['json']['message'] ?? '') === 'Please specify is required.', $otherBare['body']);
$markup = '<b>x</b><script>alert(1)</script>';
$otherFilled = $phase2Create(['complaint_type_id' => $typeIds['other'] ?? 0, 'extra_fields' => ['specify' => $markup]]);
$otherRow = $otherFilled['id'] ? $phase2Row($otherFilled['id']) : [];
check('interface: Other with specify is accepted and stored as plain text',
  $otherFilled['status'] === 200 && ($otherRow['category'] ?? '') === 'Other'
    && json_decode((string)($otherRow['extra_fields_json'] ?? 'null'), true) === ['specify' => $markup], $otherFilled['body']);
$residentList = http($base . '/api/complaints/list.php', 'GET', null, [], $cookieFor('resident'));
$listed = null;
foreach ((json_decode($residentList['body'], true)['complaints'] ?? []) as $item) if ((int)$item['id'] === $otherFilled['id']) $listed = $item;
check('interface: list returns free text unescaped (the client escapes it) with complaint_type_id',
  is_array($listed) && ($listed['extra_fields']['specify'] ?? null) === $markup
    && ($listed['extra_fields_display'] ?? null) === [['label' => 'Please specify', 'value' => $markup]]
    && ($listed['complaint_type_id'] ?? null) === $typeIds['other'], json_encode($listed));
exec_sql($db, "UPDATE complaint_types SET is_active=0 WHERE type_code='public_safety'");
$inactiveById = $phase2Create(['complaint_type_id' => $typeIds['public_safety'] ?? 0]);
$inactiveByName = $phase2Create(['category' => 'Public Safety']);
exec_sql($db, "UPDATE complaint_types SET is_active=1 WHERE type_code='public_safety'");
check('interface: an inactive type cannot be filed, by id or by name',
  $inactiveById['status'] === 422 && $inactiveByName['status'] === 422
    && ($inactiveById['json']['message'] ?? '') === 'Select a valid type of concern.', $inactiveById['body'] . $inactiveByName['body']);
$reactivated = $phase2Create(['complaint_type_id' => $typeIds['public_safety'] ?? 0]);
check('interface: the reactivated type can be filed again', $reactivated['status'] === 200, $reactivated['body']);
foreach ($phase2Ids as $phase2Id) {
  exec_sql($db, 'DELETE FROM complaint_updates WHERE complaint_id=?', 'i', [$phase2Id]);
  exec_sql($db, 'DELETE FROM complaints WHERE id=?', 'i', [$phase2Id]);
}
check('interface: phase 2 complaints cleaned up',
  count($phase2Ids) === 3 && (int)q($db, 'SELECT COUNT(*) AS n FROM complaints WHERE id IN (' . implode(',', $phase2Ids ?: [0]) . ')')[0]['n'] === 0, json_encode($phase2Ids));

// Complaint revision Phase 3: managing complaint types and fields (api/complaint_types/).
$ctCall = static function (string $method, string $endpoint, ?array $body, ?string $cookie, string $contentType = 'application/json') use ($base): array {
  $response = http($base . '/api/complaint_types/' . $endpoint, $method, $body === null ? null : json_encode($body),
    $body === null ? [] : ['Content-Type: ' . $contentType], $cookie);
  $response['json'] = json_decode($response['body'], true);
  return $response;
};
$ctPost = static fn(string $endpoint, array $body, ?string $cookie) => $ctCall('POST', $endpoint . '.php', $body, $cookie);
$ctStatus = static fn(array $responses): array => array_map(static fn(array $r): int => (int)$r['status'], $responses);
$ctAll = static fn(array $responses, int $status): bool => array_unique($ctStatus($responses)) === [$status];
exec_sql($db, 'INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,?,?,?,?,?,?,?,?)',
  'sssssssss', ['Tess', 'Types', 'fix-type-staff', 'staff', 'Purok 3', '+639171110009', password_hash('Fix24-pass', PASSWORD_DEFAULT), 'Clerk', 'active']);
$typeStaff = (int)$db->insert_id;
$sid['typeStaff'] = bin2hex(random_bytes(16));
$craft('typeStaff', ['official_id' => $typeStaff, 'official_role' => 'staff', 'official_name' => 'Tess Types', 'official_position' => 'Clerk']);
$staffCookie = $cookieFor('typeStaff');
$residentCookie = $cookieFor('resident');
$ctTypeIds = [];
$ctComplaintIds = [];
$ctWrites = ['save_type' => ['type_name' => 'Blocked Attempt'], 'toggle_type' => ['id' => 1, 'version' => 1, 'is_active' => false],
  'save_field' => ['type_id' => 1, 'field_key' => 'x', 'label' => 'X', 'field_type' => 'date'], 'toggle_field' => ['id' => 1, 'version' => 1, 'is_active' => false]];
$ctSnapshot = static fn(): string => json_encode(q($db, 'SELECT (SELECT COUNT(*) FROM complaint_types) AS t, (SELECT COALESCE(SUM(version),0) FROM complaint_types) AS tv, (SELECT COUNT(*) FROM complaint_type_fields) AS f, (SELECT COALESCE(SUM(version),0) FROM complaint_type_fields) AS fv, (SELECT COALESCE(SUM(is_active),0) FROM complaint_types) AS ta')[0]);
$ctSeed = $ctSnapshot();

// a) Only officials may write or list.
$asResident = []; $loggedOut = [];
foreach ($ctWrites as $endpoint => $body) { $asResident[] = $ctPost($endpoint, $body, $residentCookie); $loggedOut[] = $ctPost($endpoint, $body, null); }
$asResident[] = $ctCall('GET', 'list.php', null, $residentCookie);
$loggedOut[] = $ctCall('GET', 'list.php', null, null);
$loggedOut[] = $ctCall('GET', 'fields.php?type_id=' . $typeIds['other'], null, null);
check('interface: types a) resident session gets 401 on every write endpoint and on list', $ctAll($asResident, 401) && $ctSnapshot() === $ctSeed, json_encode($ctStatus($asResident)));
check('interface: types a) logged-out caller gets 401 on writes, list and fields', $ctAll($loggedOut, 401) && $ctSnapshot() === $ctSeed, json_encode($ctStatus($loggedOut)));

// b) A staff official creates a type, adds one field of each kind, updates, deactivates, reactivates.
$made = $ctPost('save_type', ['type_name' => '  Stray Animals '], $staffCookie);
$stray = $made['json']['type'] ?? [];
$strayId = (int)($stray['id'] ?? 0);
if ($strayId) $ctTypeIds[] = $strayId;
check('interface: types b) staff creates a type; code is generated, defaults applied',
  $made['status'] === 200 && $strayId > 0 && $stray['type_code'] === 'stray_animals' && $stray['type_name'] === 'Stray Animals'
    && $stray['version'] === 1 && $stray['is_active'] === true && $stray['respondent_mode'] === 'none' && $stray['usage'] === 0 && $stray['fields'] === [], $made['body']);
$fText = $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'details', 'label' => 'Details', 'field_type' => 'text', 'max_length' => 200], $staffCookie);
$fNumber = $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'how_many', 'label' => 'How many', 'field_type' => 'number', 'min_value' => 1, 'max_value' => 50], $staffCookie);
$fDate = $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'seen_on', 'label' => 'Date seen', 'field_type' => 'date'], $staffCookie);
$fSelect = $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'animal', 'label' => 'Animal', 'field_type' => 'select',
  'options' => [['value' => 'dog', 'label' => 'Dog', 'is_active' => true], ['value' => 'cat', 'label' => 'Cat']]], $staffCookie);
$animal = $fSelect['json']['field'] ?? [];
check('interface: types b) staff adds text, number, date and select fields',
  $ctAll([$fText, $fNumber, $fDate, $fSelect], 200)
    && ($fText['json']['field']['max_length'] ?? null) === 200 && ($fNumber['json']['field']['min_value'] ?? null) == 1 && ($fNumber['json']['field']['max_value'] ?? null) == 50
    && ($fDate['json']['field']['field_type'] ?? '') === 'date' && ($fDate['json']['field']['sort_order'] ?? 0) === 30
    && array_column($animal['options'] ?? [], 'value') === ['dog', 'cat'] && ($animal['options'][1]['is_active'] ?? null) === true && ($animal['version'] ?? 0) === 1,
  json_encode($ctStatus([$fText, $fNumber, $fDate, $fSelect])) . $fSelect['body']);
$updated = $ctPost('save_type', ['id' => $strayId, 'version' => 1, 'type_name' => 'Stray Animals', 'description' => 'Loose or stray animals', 'respondent_mode' => 'optional', 'sort_order' => 70], $staffCookie);
$same = $ctPost('save_type', ['id' => $strayId, 'version' => 2, 'type_name' => 'Stray Animals', 'respondent_mode' => 'optional'], $staffCookie);
check('interface: types b) update bumps the version; an unchanged save does not',
  $updated['status'] === 200 && $updated['json']['changed'] === true && $updated['json']['type']['version'] === 2
    && $updated['json']['type']['description'] === 'Loose or stray animals' && $updated['json']['type']['respondent_mode'] === 'optional' && $updated['json']['type']['sort_order'] === 70
    && count($updated['json']['type']['fields']) === 4
    && $same['status'] === 200 && $same['json']['changed'] === false && $same['json']['type']['version'] === 2, $updated['body'] . $same['body']);
$off = $ctPost('toggle_type', ['id' => $strayId, 'version' => 2, 'is_active' => false], $staffCookie);
$filedWhileOff = $complaintPost('create', ['complaint_type_id' => $strayId, 'location_text' => 'Test location', 'description' => 'Should be refused', 'severity' => 'low'], $residentCookie);
$fieldsWhileOff = $ctCall('GET', 'fields.php?type_id=' . $strayId, null, $residentCookie);
$on = $ctPost('toggle_type', ['id' => $strayId, 'version' => 3, 'is_active' => true], $staffCookie);
check('interface: types b) deactivate then reactivate; an inactive type cannot be filed',
  $off['status'] === 200 && $off['json']['type']['is_active'] === false && $off['json']['type']['version'] === 3
    && $filedWhileOff['status'] === 422 && $on['status'] === 200 && $on['json']['type']['is_active'] === true && $on['json']['type']['version'] === 4,
  $off['body'] . $filedWhileOff['body'] . $on['body']);

// c) Names, codes and strict input.
$dupName = $ctPost('save_type', ['type_name' => 'stray ANIMALS'], $staffCookie);
$dupCode = $ctPost('save_type', ['type_name' => 'Another Name', 'type_code' => 'stray_animals'], $staffCookie);
$dupRename = $ctPost('save_type', ['id' => $strayId, 'version' => 4, 'type_name' => 'public safety'], $staffCookie);
check('interface: types c) duplicate name (any case) or code is refused with 409',
  $ctAll([$dupName, $dupCode, $dupRename], 409), json_encode($ctStatus([$dupName, $dupCode, $dupRename])));
$badCode = $ctPost('save_type', ['type_name' => 'Bad Code Type', 'type_code' => 'Bad Code'], $staffCookie);
$changeCode = $ctPost('save_type', ['id' => $strayId, 'version' => 4, 'type_name' => 'Stray Animals', 'type_code' => 'something_else'], $staffCookie);
$sameCode = $ctPost('save_type', ['id' => $strayId, 'version' => 4, 'type_name' => 'Stray Animals', 'type_code' => 'stray_animals'], $staffCookie);
check('interface: types c) bad code is 422; changing the code on update is 422; resending the same code is fine',
  $badCode['status'] === 422 && $changeCode['status'] === 422 && $sameCode['status'] === 200 && $sameCode['json']['changed'] === false,
  json_encode($ctStatus([$badCode, $changeCode, $sameCode])));
$clash = $ctPost('save_type', ['type_name' => 'Stray, Animals'], $staffCookie);
$clashId = (int)($clash['json']['type']['id'] ?? 0);
if ($clashId) $ctTypeIds[] = $clashId;
check('interface: types c) a generated code that is taken gets a _2 suffix', $clash['status'] === 200 && ($clash['json']['type']['type_code'] ?? '') === 'stray_animals_2', $clash['body']);
$strict = [
  $ctPost('save_type', ['type_name' => 'Unknown Key Type', 'colour' => 'red'], $staffCookie),
  $ctPost('save_type', ['id' => (string)$strayId, 'version' => 4, 'type_name' => 'Stray Animals'], $staffCookie),
  $ctPost('save_type', ['id' => $strayId, 'type_name' => 'Stray Animals'], $staffCookie),
  $ctPost('save_type', ['id' => $strayId, 'version' => '4', 'type_name' => 'Stray Animals'], $staffCookie),
  $ctPost('toggle_type', ['id' => $strayId, 'version' => 4, 'is_active' => 0], $staffCookie),
  $ctPost('save_type', ['type_name' => 'x'], $staffCookie),
  $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'Bad Key', 'label' => 'L', 'field_type' => 'date'], $staffCookie),
  $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'colour', 'label' => 'L', 'field_type' => 'colour'], $staffCookie),
];
$notJson = $ctCall('POST', 'save_type.php', ['type_name' => 'Form Post Type'], $staffCookie, 'text/plain');
$wrongMethod = $ctCall('GET', 'save_type.php', null, $staffCookie);
$missing = [
  $ctPost('save_type', ['id' => 999999, 'version' => 1, 'type_name' => 'No Such Type'], $staffCookie),
  $ctPost('toggle_type', ['id' => 999999, 'version' => 1, 'is_active' => false], $staffCookie),
  $ctPost('save_field', ['type_id' => 999999, 'field_key' => 'a', 'label' => 'A', 'field_type' => 'date'], $staffCookie),
  $ctPost('toggle_field', ['id' => 999999, 'version' => 1, 'is_active' => false], $staffCookie),
  $ctPost('save_field', ['type_id' => $typeIds['other'], 'id' => (int)($animal['id'] ?? 0), 'version' => 1, 'label' => 'Wrong parent'], $staffCookie),
];
check('interface: types c) unknown keys, non-integer ids, missing version, non-boolean flags and bad values are 422',
  $ctAll($strict, 422), json_encode($ctStatus($strict)));
check('interface: types c) wrong content type is 415, wrong method is 405, unknown ids are 404',
  $notJson['status'] === 415 && $wrongMethod['status'] === 405 && $ctAll($missing, 404), $notJson['status'] . ' ' . $wrongMethod['status'] . ' ' . json_encode($ctStatus($missing)));

// d) Optimistic locking.
$first = $ctPost('save_type', ['id' => $strayId, 'version' => 4, 'type_name' => 'Stray Animals', 'sort_order' => 71], $staffCookie);
$second = $ctPost('save_type', ['id' => $strayId, 'version' => 4, 'type_name' => 'Stray Animals', 'sort_order' => 72], $staffCookie);
$staleToggle = $ctPost('toggle_type', ['id' => $strayId, 'version' => 4, 'is_active' => false], $staffCookie);
$staleField = $ctPost('save_field', ['type_id' => $strayId, 'id' => (int)$animal['id'], 'version' => 99, 'label' => 'Stale'], $staffCookie);
check('interface: types d) a second write with the same version is refused with 409',
  $first['status'] === 200 && $first['json']['type']['version'] === 5 && $ctAll([$second, $staleToggle, $staleField], 409)
    && $second['json']['message'] === 'This item was changed by someone else. Reload and try again.'
    && (int)q($db, 'SELECT sort_order FROM complaint_types WHERE id=?', 'i', [$strayId])[0]['sort_order'] === 71, $second['body']);

// g) The new type is in the resident form and can be filed.
$formHtml = http($base . '/pages/resident.php', 'GET', null, [], $residentCookie)['body'];
$filed = $complaintPost('create', ['complaint_type_id' => $strayId, 'location_text' => 'Test location', 'description' => 'Stray dog near the school', 'severity' => 'low',
  'extra_fields' => ['details' => 'Brown, no collar', 'how_many' => 2, 'seen_on' => '2026-11-15', 'animal' => 'dog']], $residentCookie);
$filedId = (int)($filed['json']['id'] ?? 0);
if ($filedId) $ctComplaintIds[] = $filedId;
$filedRow = $filedId ? $phase2Row($filedId) : [];
// MySQL stores JSON object keys in its own order, so compare without regard to key order.
$filedExtra = json_decode((string)($filedRow['extra_fields_json'] ?? 'null'), true);
if (is_array($filedExtra)) ksort($filedExtra);
check('interface: types g) the new type and its fields appear in the resident form',
  str_contains($formHtml, '<option value="' . $strayId . '">Stray Animals</option>') && str_contains($formHtml, 'data-complaint-extra-category="' . $strayId . '"')
    && str_contains($formHtml, 'id="complaint-extra-' . $strayId . '-how_many"') && str_contains($formHtml, 'type="date"') && str_contains($formHtml, '<option value="dog">Dog</option>'));
check('interface: types g) a complaint can be filed under the new type with all four field kinds',
  $filed['status'] === 200 && ($filedRow['category'] ?? '') === 'Stray Animals' && (int)($filedRow['complaint_type_id'] ?? 0) === $strayId
    && $filedExtra === ['animal' => 'dog', 'details' => 'Brown, no collar', 'how_many' => '2', 'seen_on' => '2026-11-15'], $filed['body'] . json_encode($filedExtra));
$badFiling = [
  $complaintPost('create', ['complaint_type_id' => $strayId, 'location_text' => 'x', 'description' => 'x', 'extra_fields' => ['how_many' => 51]], $residentCookie),
  $complaintPost('create', ['complaint_type_id' => $strayId, 'location_text' => 'x', 'description' => 'x', 'extra_fields' => ['seen_on' => '2026-02-30']], $residentCookie),
  $complaintPost('create', ['complaint_type_id' => $strayId, 'location_text' => 'x', 'description' => 'x', 'extra_fields' => ['details' => str_repeat('x', 201)]], $residentCookie),
];
check('interface: types g) the new field limits are enforced when filing', $ctAll($badFiling, 422), json_encode($ctStatus($badFiling)));

// e) Options are retired, never removed.
$removed = $ctPost('save_field', ['type_id' => $strayId, 'id' => (int)$animal['id'], 'version' => 1, 'label' => 'Animal', 'options' => [['value' => 'cat', 'label' => 'Cat']]], $staffCookie);
$retired = $ctPost('save_field', ['type_id' => $strayId, 'id' => (int)$animal['id'], 'version' => 1, 'label' => 'Animal type',
  'options' => [['value' => 'dog', 'label' => 'Dog', 'is_active' => false], ['value' => 'cat', 'label' => 'Cat'], ['value' => 'goat', 'label' => 'Goat']]], $staffCookie);
$animal = $retired['json']['field'] ?? $animal;
check('interface: types e) removing an option is 422; marking it inactive and adding one is 200',
  $removed['status'] === 422 && $removed['json']['message'] === 'Options cannot be removed; mark them inactive instead.'
    && $retired['status'] === 200 && $animal['version'] === 2 && $animal['label'] === 'Animal type'
    && array_column($animal['options'], 'is_active', 'value') === ['dog' => false, 'cat' => true, 'goat' => true]
    && array_column($animal['options'], 'usage', 'value') === ['dog' => 1, 'cat' => 0, 'goat' => 0] && $animal['usage'] === 1, $removed['body'] . $retired['body']);
$withRetired = $complaintPost('create', ['complaint_type_id' => $strayId, 'location_text' => 'x', 'description' => 'x', 'extra_fields' => ['animal' => 'dog']], $residentCookie);
$withNew = $complaintPost('create', ['complaint_type_id' => $strayId, 'location_text' => 'Test location', 'description' => 'A goat on the road', 'severity' => 'low', 'extra_fields' => ['animal' => 'goat']], $residentCookie);
if (!empty($withNew['json']['id'])) $ctComplaintIds[] = (int)$withNew['json']['id'];
$oldListed = null;
foreach ((json_decode(http($base . '/api/complaints/list.php', 'GET', null, [], $residentCookie)['body'], true)['complaints'] ?? []) as $item) if ((int)$item['id'] === $filedId) $oldListed = $item;
check('interface: types e) a retired option cannot be filed, the new one can, and the old complaint shows "(retired)"',
  $withRetired['status'] === 422 && $withNew['status'] === 200 && is_array($oldListed)
    && in_array(['label' => 'Animal type', 'value' => 'Dog (retired)'], $oldListed['extra_fields_display'], true)
    && ($oldListed['extra_fields']['animal'] ?? null) === 'dog', $withRetired['body'] . json_encode($oldListed['extra_fields_display'] ?? null));
$fieldRules = [
  $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'animal', 'label' => 'Again', 'field_type' => 'date'], $staffCookie),
];
$fieldRefused = [
  $ctPost('save_field', ['type_id' => $strayId, 'id' => (int)$animal['id'], 'version' => 2, 'label' => 'Animal type', 'field_type' => 'text'], $staffCookie),
  $ctPost('save_field', ['type_id' => $strayId, 'id' => (int)$animal['id'], 'version' => 2, 'label' => 'Animal type', 'field_key' => 'creature'], $staffCookie),
  $ctPost('save_field', ['type_id' => $strayId, 'id' => (int)$animal['id'], 'version' => 2, 'label' => 'Animal type', 'max_length' => 10], $staffCookie),
  $ctPost('save_field', ['type_id' => $strayId, 'id' => (int)$animal['id'], 'version' => 2, 'label' => 'Animal type',
    'options' => [['value' => 'dog', 'label' => 'Dog', 'is_active' => false], ['value' => 'cat', 'label' => 'Cat', 'is_active' => false], ['value' => 'goat', 'label' => 'Goat', 'is_active' => false]]], $staffCookie),
  $ctPost('save_field', ['type_id' => $strayId, 'id' => (int)$fNumber['json']['field']['id'], 'version' => 1, 'label' => 'How many', 'min_value' => 60], $staffCookie),
  $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'bad_text', 'label' => 'Bad', 'field_type' => 'text', 'max_length' => 1001], $staffCookie),
  $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'bad_select', 'label' => 'Bad', 'field_type' => 'select'], $staffCookie),
];
check('interface: types e) duplicate field key is 409; changing key or kind, foreign settings, no active option, min above max are 422',
  $ctAll($fieldRules, 409) && $ctAll($fieldRefused, 422), json_encode($ctStatus($fieldRules)) . json_encode($ctStatus($fieldRefused)));
$extra = [];
for ($n = 5; $n <= 15; $n++) $extra[] = $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'extra_' . $n, 'label' => 'Extra ' . $n, 'field_type' => 'date'], $staffCookie);
$sixteenth = $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'extra_16', 'label' => 'Extra 16', 'field_type' => 'date'], $staffCookie);
$lastExtra = $extra[10]['json']['field'] ?? [];
$extraOff = $ctPost('toggle_field', ['id' => (int)($lastExtra['id'] ?? 0), 'version' => 1, 'is_active' => false], $staffCookie);
$replacement = $ctPost('save_field', ['type_id' => $strayId, 'field_key' => 'extra_16', 'label' => 'Extra 16', 'field_type' => 'date'], $staffCookie);
$extraOn = $ctPost('toggle_field', ['id' => (int)($lastExtra['id'] ?? 0), 'version' => 2, 'is_active' => true], $staffCookie);
check('interface: types e) a type holds at most 15 active fields, on create and on reactivation',
  $ctAll($extra, 200) && $sixteenth['status'] === 422 && $extraOff['status'] === 200 && $extraOff['json']['field']['is_active'] === false
    && $replacement['status'] === 200 && $extraOn['status'] === 422, json_encode($ctStatus([$sixteenth, $extraOff, $replacement, $extraOn])));

// f) Protected rows.
$otherAdmin = null;
foreach (($ctCall('GET', 'list.php', null, $staffCookie)['json']['types'] ?? []) as $listedType) if ($listedType['type_code'] === 'other') $otherAdmin = $listedType;
$specify = $otherAdmin['fields'][0] ?? [];
$otherOff = $ctPost('toggle_type', ['id' => (int)$otherAdmin['id'], 'version' => (int)$otherAdmin['version'], 'is_active' => false], $staffCookie);
$specifyOff = $ctPost('toggle_field', ['id' => (int)$specify['id'], 'version' => (int)$specify['version'], 'is_active' => false], $staffCookie);
$specifyOptional = $ctPost('save_field', ['type_id' => (int)$otherAdmin['id'], 'id' => (int)$specify['id'], 'version' => (int)$specify['version'], 'label' => 'Please specify', 'is_required' => false], $staffCookie);
$specifyRelabel = $ctPost('save_field', ['type_id' => (int)$otherAdmin['id'], 'id' => (int)$specify['id'], 'version' => (int)$specify['version'], 'label' => 'Describe the concern', 'max_length' => 300], $staffCookie);
$specifyRestore = $ctPost('save_field', ['type_id' => (int)$otherAdmin['id'], 'id' => (int)$specify['id'], 'version' => (int)$specify['version'] + 1, 'label' => 'Please specify', 'max_length' => 150], $staffCookie);
check('interface: types f) "Other" cannot be deactivated; Other.specify cannot be deactivated or made optional, but can be relabeled and resized',
  $ctAll([$otherOff, $specifyOff, $specifyOptional], 422) && $specifyRelabel['status'] === 200
    && $specifyRelabel['json']['field']['label'] === 'Describe the concern' && $specifyRelabel['json']['field']['max_length'] === 300
    && $specifyRestore['status'] === 200 && $specifyRestore['json']['field']['label'] === 'Please specify' && $specifyRestore['json']['field']['max_length'] === 150,
  json_encode($ctStatus([$otherOff, $specifyOff, $specifyOptional, $specifyRelabel, $specifyRestore])));
exec_sql($db, 'UPDATE complaint_types SET is_active=0, updated_at=updated_at WHERE id<>?', 'i', [$strayId]);
$lastOne = $ctPost('toggle_type', ['id' => $strayId, 'version' => 5, 'is_active' => false], $staffCookie);
exec_sql($db, 'UPDATE complaint_types SET is_active=1, updated_at=updated_at WHERE id<>?', 'i', [$strayId]);
check('interface: types f) the last active type cannot be deactivated',
  $lastOne['status'] === 422 && $lastOne['json']['message'] === 'At least one complaint type must stay active.'
    && (int)q($db, 'SELECT is_active FROM complaint_types WHERE id=?', 'i', [$strayId])[0]['is_active'] === 1, $lastOne['body']);

// i) fields.php: active entries only.
$seenOnOff = $ctPost('toggle_field', ['id' => (int)$fDate['json']['field']['id'], 'version' => 1, 'is_active' => false], $staffCookie);
$publicFields = $ctCall('GET', 'fields.php?type_id=' . $strayId, null, $residentCookie);
$publicForStaff = $ctCall('GET', 'fields.php?type_id=' . $strayId, null, $staffCookie);
$publicType = $publicFields['json']['type'] ?? [];
$publicByKey = array_column($publicType['fields'] ?? [], null, 'key');
check('interface: types i) fields.php returns the active type with active fields and options only, to residents and officials',
  $seenOnOff['status'] === 200 && $publicFields['status'] === 200 && $publicForStaff['status'] === 200
    && $publicType['type_name'] === 'Stray Animals' && !isset($publicByKey['seen_on']) && !isset($publicByKey['extra_15']) && isset($publicByKey['details'], $publicByKey['extra_16'])
    && $publicByKey['animal']['options'] === [['value' => 'cat', 'label' => 'Cat'], ['value' => 'goat', 'label' => 'Goat']]
    && !str_contains($publicFields['body'], 'usage') && !str_contains($publicFields['body'], 'version'), $publicFields['body']);
$badTypeIds = [$ctCall('GET', 'fields.php?type_id=999999', null, $residentCookie), $ctCall('GET', 'fields.php?type_id=abc', null, $residentCookie),
  $ctCall('GET', 'fields.php', null, $residentCookie), $fieldsWhileOff];
check('interface: types i) fields.php answers 404 for an inactive, missing or malformed type id', $ctAll($badTypeIds, 404), json_encode($ctStatus($badTypeIds)));

// list.php: everything, with usage.
$strayAdmin = null;
$adminList = $ctCall('GET', 'list.php', null, $staffCookie);
foreach (($adminList['json']['types'] ?? []) as $listedType) if ((int)$listedType['id'] === $strayId) $strayAdmin = $listedType;
$adminByKey = array_column($strayAdmin['fields'] ?? [], null, 'field_key');
check('interface: types list.php shows inactive fields and options, versions, limits and usage counts',
  $adminList['status'] === 200 && count($adminList['json']['types']) === 7 && $adminList['json']['limits'] === ['max_active_fields' => 15, 'max_options' => 50, 'max_text_length' => 1000]
    && is_array($strayAdmin) && $strayAdmin['usage'] === 2 && count($strayAdmin['fields']) === 16
    && $adminByKey['seen_on']['is_active'] === false && $adminByKey['seen_on']['usage'] === 1 && $adminByKey['seen_on']['version'] === 2
    && $adminByKey['animal']['usage'] === 2 && array_column($adminByKey['animal']['options'], 'usage', 'value') === ['dog' => 1, 'cat' => 0, 'goat' => 1]
    && $adminByKey['how_many']['usage'] === 1 && $adminByKey['details']['usage'] === 1, json_encode($strayAdmin));

// h) Every successful write left an audit row; refused writes left none.
$auditRows = q($db, "SELECT action, target_type, target_reference, official_id, official_name, details FROM audit_log WHERE target_type IN ('complaint_type','complaint_type_field') ORDER BY id");
$auditHas = static function (string $action, string $reference) use ($auditRows, $typeStaff): bool {
  foreach ($auditRows as $row) {
    if ($row['action'] === $action && $row['target_reference'] === $reference && (int)$row['official_id'] === $typeStaff && $row['official_name'] === 'Tess Types') return true;
  }
  return false;
};
$auditCount = static fn(string $reference): int => count(array_filter($auditRows, static fn(array $row): bool => $row['target_reference'] === $reference));
check('interface: types h) audit rows exist for type writes (action, reference, official)',
  $auditHas('Created complaint type', 'stray_animals') && $auditHas('Updated complaint type', 'stray_animals')
    && $auditHas('Deactivated complaint type', 'stray_animals') && $auditHas('Activated complaint type', 'stray_animals')
    && $auditHas('Created complaint type', 'stray_animals_2') && $auditCount('stray_animals') === 5 && $auditCount('other') === 0,
  json_encode(array_map(static fn(array $row): string => $row['action'] . ' @ ' . $row['target_reference'], $auditRows)));
$animalAudit = array_values(array_filter($auditRows, static fn(array $row): bool => $row['target_reference'] === 'stray_animals.animal' && $row['action'] === 'Updated complaint type field'));
check('interface: types h) audit rows exist for field writes, with the changed keys in details',
  $auditHas('Created complaint type field', 'stray_animals.animal') && $auditHas('Created complaint type field', 'stray_animals.details')
    && $auditHas('Deactivated complaint type field', 'stray_animals.seen_on') && $auditHas('Updated complaint type field', 'other.specify')
    && $auditCount('stray_animals.animal') === 2 && $auditCount('other.specify') === 2 && count($animalAudit) === 1
    && json_decode($animalAudit[0]['details'], true) === ['label' => ['Animal', 'Animal type'], 'options' => ['added' => ['goat'], 'retired' => ['dog']]],
  $animalAudit[0]['details'] ?? 'none');

// Complaint revision Phase 4: displays and filters follow complaint_type_id, so a
// renamed or deactivated type keeps its complaints. Uses the test type above.
$p4Public = static function (string $query) use ($base, $residentCookie): array {
  $response = http($base . '/api/complaints/public_list.php' . $query, 'GET', null, [], $residentCookie);
  $response['json'] = json_decode($response['body'], true);
  return $response;
};
$p4Ours = static fn(array $response): array => array_values(array_filter($response['json']['complaints'] ?? [],
  static fn(array $card): bool => in_array((int)$card['id'], $ctComplaintIds, true)));
foreach ($ctComplaintIds as $ctComplaintId) exec_sql($db, 'UPDATE complaints SET is_public=1 WHERE id=?', 'i', [$ctComplaintId]);
$byType = $p4Public('?type_id=' . $strayId);
$byTypeAll = $p4Public('?type_id=all');
$p4Refused = [$p4Public('?type_id=999999'), $p4Public('?category=Other'), $p4Public('?type_id=1%0A'), $p4Public('?type_id='), $p4Public('?type_id=-1'), $p4Public('?type_id=01')];
check('interface: P4 a) public_list ?type_id= returns only that type; all = no filter',
  $byType['status'] === 200 && count($byType['json']['complaints']) === 2
    && array_unique(array_column($byType['json']['complaints'], 'type_id')) === [$strayId]
    && array_unique(array_column($byType['json']['complaints'], 'type_name')) === ['Stray Animals']
    && !array_key_exists('category', $byType['json']['complaints'][0])
    && $byTypeAll['status'] === 200 && count($p4Ours($byTypeAll)) === 2, $byType['body']);
check('interface: P4 a) unknown id, the old category param, a trailing newline, empty, negative and zero-padded ids are 422',
  $ctAll($p4Refused, 422) && ($p4Refused[0]['json']['message'] ?? '') === 'Invalid type.'
    && ($p4Refused[1]['json']['message'] ?? '') === 'Invalid filter.' && ($p4Refused[2]['json']['message'] ?? '') === 'Invalid type.',
  json_encode($ctStatus($p4Refused)) . $p4Refused[1]['body']);

// b) Rename through the API: old complaints show the new name; category text is unchanged.
$p4Version = static function () use ($ctCall, $staffCookie, $strayId): int {
  foreach (($ctCall('GET', 'list.php', null, $staffCookie)['json']['types'] ?? []) as $listedType) {
    if ((int)$listedType['id'] === $strayId) return (int)$listedType['version'];
  }
  return 0;
};
$renamed = $ctPost('save_type', ['id' => $strayId, 'version' => $p4Version(), 'type_name' => 'Loose Animals'], $staffCookie);
$afterRename = $p4Public('?type_id=' . $strayId);
$p4ListRows = static function (string $cookie) use ($base, $ctComplaintIds): array {
  $listed = json_decode(http($base . '/api/complaints/list.php', 'GET', null, [], $cookie)['body'], true)['complaints'] ?? [];
  return array_values(array_filter($listed, static fn(array $row): bool => in_array((int)$row['id'], $ctComplaintIds, true)));
};
$staffRows = $p4ListRows($staffCookie);
$residentRows = $p4ListRows($residentCookie);
$storedCategories = array_column(q($db, 'SELECT category FROM complaints WHERE id IN (' . implode(',', $ctComplaintIds ?: [0]) . ')'), 'category');
check('interface: P4 b) after a rename, public_list and list.php (staff and resident) show the new type_name for old complaints',
  $renamed['status'] === 200 && $renamed['json']['changed'] === true
    && array_unique(array_column($afterRename['json']['complaints'] ?? [], 'type_name')) === ['Loose Animals'] && count($afterRename['json']['complaints']) === 2
    && count($staffRows) === 2 && array_unique(array_column($staffRows, 'type_name')) === ['Loose Animals']
    && count($residentRows) === 2 && array_unique(array_column($residentRows, 'type_name')) === ['Loose Animals'],
  $renamed['body'] . json_encode(array_column($staffRows, 'type_name')));
check('interface: P4 b) the stored category column keeps the name the complaints were filed with',
  $storedCategories === ['Stray Animals', 'Stray Animals'] && array_unique(array_column($staffRows, 'category')) === ['Stray Animals'],
  json_encode($storedCategories));

// c) Deactivate: complaints stay listed under the type's name, and the filter still works.
$deactivated = $ctPost('toggle_type', ['id' => $strayId, 'version' => $p4Version(), 'is_active' => false], $staffCookie);
$afterOffAll = $p4Public('');
$afterOffFiltered = $p4Public('?type_id=' . $strayId);
check('interface: P4 c) a deactivated type: its public complaints are still listed with its name; filtering by its id still works',
  $deactivated['status'] === 200 && $deactivated['json']['type']['is_active'] === false
    && count($p4Ours($afterOffAll)) === 2 && array_unique(array_column($p4Ours($afterOffAll), 'type_name')) === ['Loose Animals']
    && $afterOffFiltered['status'] === 200 && count($afterOffFiltered['json']['complaints']) === 2, $deactivated['body'] . $afterOffFiltered['body']);

// d) Community page: the type filter is rendered from the database.
$communityHtml = http($base . '/pages/community_complaints.php', 'GET', null, [], $residentCookie)['body'];
$inactiveOption = '<option value="' . $strayId . '">Loose Animals (no longer used)</option>';
$otherOption = '<option value="' . $typeIds['other'] . '">Other</option>';
check('interface: P4 d) community page type select comes from the database: active types, then the inactive test type marked "(no longer used)"',
  str_contains($communityHtml, '<select id="communityCategory">') && str_contains($communityHtml, '<option value="all">All types</option>')
    && str_contains($communityHtml, $otherOption) && str_contains($communityHtml, $inactiveOption)
    && strpos($communityHtml, $otherOption) < strpos($communityHtml, $inactiveOption)
    && !str_contains($communityHtml, 'Stray Animals'), substr($communityHtml, (int)strpos($communityHtml, 'communityCategory'), 600));
$communityJs = (string)file_get_contents(dirname(__DIR__, 3) . '/js/community-complaints.js');
check('interface: P4 d) community script no longer hardcodes type names and sends type_id',
  !str_contains($communityJs, 'COMMUNITY_CATEGORIES') && !str_contains($communityJs, 'Road / Infrastructure')
    && str_contains($communityJs, "params.set('type_id'") && !str_contains($communityJs, "params.set('category'"));

// e) Official console (staff session): the Complaint Types tab is there for staff, not only admins.
$officialHtml = http($base . '/pages/official.php', 'GET', null, [], $staffCookie)['body'];
check('interface: P4 e) official.php for a staff official has the Complaint Types tab, its modals and complaint-types.js',
  str_contains($officialHtml, 'data-tab="complainttypes"') && str_contains($officialHtml, 'id="tab-complainttypes"')
    && str_contains($officialHtml, 'id="ctypeTypeModal"') && str_contains($officialHtml, 'id="ctypeFieldModal"')
    && preg_match('#<script src="\.\./js/complaint-types\.js\?v=\d+"></script>#', $officialHtml) === 1
    && strpos($officialHtml, 'document-templates.js') < strpos($officialHtml, 'complaint-types.js')
    && !str_contains($officialHtml, 'data-tab="officials"'), substr($officialHtml, 0, 200));
check('interface: P4 e) official complaints tab has a type filter rendered from the database',
  str_contains($officialHtml, 'id="compTypeFilter"') && str_contains($officialHtml, $inactiveOption) && str_contains($officialHtml, $otherOption));

// j) Cleanup: the test removes its own rows directly. The endpoints never delete.
foreach ($ctComplaintIds as $ctComplaintId) {
  exec_sql($db, 'DELETE FROM complaint_updates WHERE complaint_id=?', 'i', [$ctComplaintId]);
  exec_sql($db, 'DELETE FROM complaints WHERE id=?', 'i', [$ctComplaintId]);
}
foreach ($ctTypeIds as $ctTypeId) {
  exec_sql($db, 'DELETE FROM complaint_type_fields WHERE complaint_type_id=?', 'i', [$ctTypeId]);
  exec_sql($db, 'DELETE FROM complaint_types WHERE id=?', 'i', [$ctTypeId]);
}
exec_sql($db, 'DELETE FROM audit_log WHERE official_id=?', 'i', [$typeStaff]);
exec_sql($db, 'DELETE FROM barangay_officials WHERE id=?', 'i', [$typeStaff]);
$ctAfter = q($db, "SELECT (SELECT COUNT(*) FROM complaint_types) AS t, (SELECT COUNT(*) FROM complaint_types WHERE is_active=1) AS ta, (SELECT COUNT(*) FROM complaint_type_fields) AS f,
  (SELECT CONCAT(label, ':', max_length, ':', is_required, ':', is_active) FROM complaint_type_fields WHERE field_key='specify') AS s")[0];
$deadStaff = $ctCall('GET', 'list.php', null, $staffCookie);
check('interface: types j) test types, fields, complaints, audit rows and the test official are removed; the seed is intact',
  count($ctComplaintIds) === 2 && count($ctTypeIds) === 2 && (int)$ctAfter['t'] === 5 && (int)$ctAfter['ta'] === 5 && (int)$ctAfter['f'] === 9
    && $ctAfter['s'] === 'Please specify:150:1:1' && $deadStaff['status'] === 401, json_encode($ctAfter) . ' ' . $deadStaff['status']);

// ===========================================================================
// Complaint revision Phase 5: complaint parties, resident respondents, walk-in
// filing, party management, resident search. Scratch database; the section
// removes its own rows at the end.
// ===========================================================================
$p5Call = static function (string $method, string $endpoint, ?array $body, ?string $cookie) use ($base): array {
  $response = http($base . '/api/complaints/' . $endpoint, $method, $body === null ? null : json_encode($body),
    $body === null ? [] : ['Content-Type: application/json'], $cookie);
  $response['json'] = json_decode($response['body'], true);
  return $response;
};
$p5Post = static fn(string $endpoint, array $body, ?string $cookie): array => $p5Call('POST', $endpoint . '.php', $body, $cookie);
$p5Status = static fn(array $responses): array => array_values(array_map(static fn(array $r): int => (int)$r['status'], $responses));
$p5Msg = static fn(array $response): string => (string)($response['json']['message'] ?? '');
$p5ComplaintIds = [];
$p5ResidentIds = [];
$p5SmsMark = (int)q($db, 'SELECT COALESCE(MAX(id), 0) AS n FROM sms_log')[0]['n'];
$p5AuditMark = (int)q($db, 'SELECT COALESCE(MAX(id), 0) AS n FROM audit_log')[0]['n'];
$p5Count = static fn(): int => (int)q($db, 'SELECT COUNT(*) AS n FROM complaints')[0]['n'];
$p5Parties = static fn(int $complaintId): array => q($db, 'SELECT * FROM complaint_parties WHERE complaint_id=? ORDER BY role, sort_order, id', 'i', [$complaintId]);
$p5Party = static fn(int $partyId): array => q($db, 'SELECT * FROM complaint_parties WHERE id=?', 'i', [$partyId])[0] ?? [];
$p5Row = static fn(int $complaintId): array => q($db, 'SELECT * FROM complaints WHERE id=?', 'i', [$complaintId])[0] ?? [];
$p5Find = static fn(array $rows, int $id): array => array_values(array_filter($rows, static fn(array $row): bool => (int)$row['id'] === $id))[0] ?? [];
$p5List = static fn(string $cookie): array => json_decode(http($base . '/api/complaints/list.php', 'GET', null, [], $cookie)['body'], true)['complaints'] ?? [];

// Fixtures: one staff official, a household, an active and an inactive resident, and a "required" type.
$p5Hash = password_hash('Fix24-pass', PASSWORD_DEFAULT);
exec_sql($db, 'INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,?,?,?,?,?,?,?,?)',
  'sssssssss', ['Paz', 'Parties', 'fix-party-staff', 'staff', 'Purok 3', '+639171110019', $p5Hash, 'Clerk', 'active']);
$p5Staff = (int)$db->insert_id;
$sid['partyStaff'] = bin2hex(random_bytes(16));
$craft('partyStaff', ['official_id' => $p5Staff, 'official_role' => 'staff', 'official_name' => 'Paz Parties', 'official_position' => 'Clerk']);
$p5StaffCookie = $cookieFor('partyStaff');
$p5ResidentCookie = $cookieFor('resident');
exec_sql($db, 'INSERT INTO households (household_code,address,purok_zone,household_size) VALUES (?,?,?,?)', 'sssi', ['HH-P5-0001', 'Purok 4', 'Purok 4', 2]);
$p5Household = (int)$db->insert_id;
foreach ([['Pia', 'Party', 'fix-p5-pia', 'Purok 4, Sampaguita', '+639171110021', 'active'], ['Ina', 'Inactive', 'fix-p5-ina', 'Purok 4', '+639171110022', 'inactive']] as $p5Person) {
  exec_sql($db, 'INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id,relationship_to_household_head,purok_zone) VALUES (?,?,?,?,?,?,?,?,?,?)',
    'sssssssiss', [$p5Person[0], $p5Person[1], $p5Person[2], $p5Person[3], $p5Person[4], $p5Hash, $p5Person[5], $p5Household, 'Member', 'Purok 4']);
  $p5ResidentIds[] = (int)$db->insert_id;
}
[$p5Pia, $p5Ina] = $p5ResidentIds;
exec_sql($db, "INSERT INTO complaint_types (type_code,type_name,respondent_mode,sort_order) VALUES ('p5_required','P5 Required Respondent','required',90)");
$p5RequiredType = (int)$db->insert_id;
$noiseType = $typeIds['noise_disturbance'] ?? 0;
$roadType = $typeIds['road_infrastructure'] ?? 0;
$p5Base = ['location_text' => 'Purok 4 corner', 'description' => 'Phase 5 party check', 'severity' => 'low'];

// a) Resident files with two respondents.
$p5A = $p5Post('create', $p5Base + ['complaint_type_id' => $noiseType, 'respondents' => [
  ['full_name' => '  Pedro Santos ', 'address' => 'Purok 2', 'contact_no' => '0917 123 4567'],
  ['full_name' => 'Lito Reyes', 'address' => null, 'contact_no' => null],
]], $p5ResidentCookie);
$p5AId = (int)($p5A['json']['id'] ?? 0);
if ($p5AId) $p5ComplaintIds[] = $p5AId;
$p5AParties = $p5AId ? $p5Parties($p5AId) : [];
check('interface: P5 a) resident files with two respondents: 200, the resident as complainant and two typed respondents stored',
  $p5A['status'] === 200 && count($p5AParties) === 3
    && $p5AParties[0]['role'] === 'complainant' && (int)$p5AParties[0]['resident_id'] === $resident && $p5AParties[0]['full_name'] === 'Rae Resident'
    && $p5AParties[0]['created_by_type'] === 'resident' && $p5AParties[0]['address'] === null
    && $p5AParties[1]['full_name'] === 'Pedro Santos' && $p5AParties[1]['contact_no'] === '+639171234567' && $p5AParties[1]['resident_id'] === null
    && $p5AParties[1]['address'] === 'Purok 2' && $p5AParties[2]['full_name'] === 'Lito Reyes' && $p5AParties[2]['address'] === null
    && ($p5Row($p5AId)['filed_by_type'] ?? '') === 'resident', $p5A['body'] . json_encode($p5AParties));
$p5ResidentListed = $p5Find($p5List($p5ResidentCookie), $p5AId);
$p5ResidentKeys = [];
foreach ($p5ResidentListed['parties'] ?? [] as $p5Item) $p5ResidentKeys = array_values(array_unique(array_merge($p5ResidentKeys, array_keys($p5Item))));
check('interface: P5 a) resident list shows parties as role and name only (no address, contact or resident_id)',
  count($p5ResidentListed['parties'] ?? []) === 3 && $p5ResidentKeys === ['role', 'full_name']
    && array_column($p5ResidentListed['parties'], 'full_name') === ['Rae Resident', 'Pedro Santos', 'Lito Reyes'],
  json_encode($p5ResidentListed['parties'] ?? null));

// b) Resident refusals; none of them leaves a complaint behind.
$p5CountB = $p5Count();
$p5B = [
  'resident_id' => $p5Post('create', $p5Base + ['complaint_type_id' => $noiseType, 'respondents' => [['resident_id' => $p5Pia]]], $p5ResidentCookie),
  'none type' => $p5Post('create', $p5Base + ['complaint_type_id' => $roadType, 'respondents' => [['full_name' => 'Somebody Here']]], $p5ResidentCookie),
  'required empty' => $p5Post('create', $p5Base + ['complaint_type_id' => $p5RequiredType], $p5ResidentCookie),
  'six' => $p5Post('create', $p5Base + ['complaint_type_id' => $noiseType, 'respondents' => array_fill(0, 6, ['full_name' => 'Same Person'])], $p5ResidentCookie),
  'bad contact' => $p5Post('create', $p5Base + ['complaint_type_id' => $noiseType, 'respondents' => [['full_name' => 'Pedro Santos', 'contact_no' => '12345']]], $p5ResidentCookie),
];
check('interface: P5 b) resident_id in a respondent, respondents on a "none" type, none on a "required" type, six respondents, a bad contact: 422, no complaint stored',
  array_unique($p5Status($p5B)) === [422] && $p5Count() === $p5CountB
    && $p5Msg($p5B['resident_id']) === 'Unknown field: resident_id.'
    && $p5Msg($p5B['none type']) === 'Respondents cannot be added for this type of concern.'
    && $p5Msg($p5B['required empty']) === 'Add at least one person this complaint is about.'
    && $p5Msg($p5B['six']) === 'You can add at most 5 people.'
    && $p5Msg($p5B['bad contact']) === 'Invalid contact number.',
  json_encode(array_map($p5Msg, $p5B)) . json_encode($p5Status($p5B)));

// c) A database failure while the parties are inserted rolls everything back.
$p5PartiesBefore = (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_parties')[0]['n'];
$p5UpdatesBefore = (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_updates')[0]['n'];
$p5CountC = $p5Count();
$p5C = ['status' => 0, 'body' => 'not run'];
try {
  if (!$db->query("CREATE TRIGGER p5_force_fail BEFORE INSERT ON complaint_parties FOR EACH ROW
      BEGIN IF NEW.full_name = 'FORCE_FAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END")) {
    throw new RuntimeException('trigger: ' . $db->error);
  }
  $p5C = $p5Post('create', $p5Base + ['complaint_type_id' => $noiseType, 'description' => 'P5 forced rollback',
    'respondents' => [['full_name' => 'FORCE_FAIL']]], $p5ResidentCookie);
} finally {
  $db->query('DROP TRIGGER IF EXISTS p5_force_fail');
}
check('interface: P5 c) a failure inside the filing transaction leaves no complaint, party or timeline row (trigger dropped)',
  $p5C['status'] === 500 && $p5Count() === $p5CountC
    && (int)q($db, "SELECT COUNT(*) AS n FROM complaints WHERE description='P5 forced rollback'")[0]['n'] === 0
    && (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_parties')[0]['n'] === $p5PartiesBefore
    && (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_updates')[0]['n'] === $p5UpdatesBefore
    && (int)q($db, "SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='p5_force_fail'")[0]['n'] === 0,
  $p5C['body']);

// d) Walk-in: unregistered complainant, registered respondent.
$p5SmsBeforeD = (int)q($db, "SELECT COUNT(*) AS n FROM sms_log WHERE endpoint='complaint_filed'")[0]['n'];
$p5D = $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'extra_fields' => ['noise_source' => 'music'],
  'complainants' => [['full_name' => 'Maria Walkin', 'address' => 'Purok 5', 'contact_no' => '09181234567']],
  'respondents' => [['resident_id' => $p5Pia]]], $p5StaffCookie);
$p5DId = (int)($p5D['json']['id'] ?? 0);
if ($p5DId) $p5ComplaintIds[] = $p5DId;
$p5DRow = $p5DId ? $p5Row($p5DId) : [];
$p5DParties = $p5DId ? $p5Parties($p5DId) : [];
$p5DUpdate = $p5DId ? q($db, 'SELECT actor_type, actor_id, note FROM complaint_updates WHERE complaint_id=?', 'i', [$p5DId]) : [];
check('interface: P5 d) walk-in for an unregistered complainant against a registered respondent: resident_id NULL, filed by the official, parties stored',
  $p5D['status'] === 200 && preg_match('/^CSR-\d{8}-[0-9A-F]{6}$/', (string)($p5D['json']['reference_no'] ?? '')) === 1
    && array_key_exists('resident_id', $p5DRow) && $p5DRow['resident_id'] === null && $p5DRow['filed_by_type'] === 'official'
    && (int)$p5DRow['filed_by_official_id'] === $p5Staff
    && $p5DRow['resident_name'] === 'Maria Walkin' && $p5DRow['official_note'] === 'Filed at the barangay hall.'
    && json_decode((string)$p5DRow['extra_fields_json'], true) === ['noise_source' => 'music']
    && count($p5DParties) === 2 && $p5DParties[0]['resident_id'] === null && $p5DParties[0]['contact_no'] === '+639181234567'
    && (int)$p5DParties[1]['resident_id'] === $p5Pia && $p5DParties[1]['full_name'] === 'Pia Party' && $p5DParties[1]['created_by_type'] === 'official'
    && count($p5DUpdate) === 1 && $p5DUpdate[0]['actor_type'] === 'official' && (int)$p5DUpdate[0]['actor_id'] === $p5Staff,
  $p5D['body'] . json_encode([$p5DRow, $p5DParties]));
check('interface: P5 d) no SMS when no complainant is registered (the registered respondent gets none)',
  (int)q($db, "SELECT COUNT(*) AS n FROM sms_log WHERE endpoint='complaint_filed'")[0]['n'] === $p5SmsBeforeD && ($p5D['json']['sms'] ?? null) === []);

// e) Walk-in for a registered complainant: the complaint belongs to that resident.
$p5SmsMarkE = (int)q($db, 'SELECT COALESCE(MAX(id), 0) AS n FROM sms_log')[0]['n'];
$p5E = $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'description' => 'P5 registered walk-in',
  'complainants' => [['resident_id' => $resident], ['full_name' => 'Second Walkin', 'address' => null, 'contact_no' => null]],
  'respondents' => []], $p5StaffCookie);
$p5EId = (int)($p5E['json']['id'] ?? 0);
if ($p5EId) $p5ComplaintIds[] = $p5EId;
$p5EOwn = $p5Find($p5List($p5ResidentCookie), $p5EId);
$p5ESms = q($db, "SELECT resident_id FROM sms_log WHERE id > ? AND endpoint='complaint_filed'", 'i', [$p5SmsMarkE]);
check('interface: P5 e) walk-in for a registered complainant: complaints.resident_id is that resident, who sees it in My Complaints',
  $p5E['status'] === 200 && (int)($p5Row($p5EId)['resident_id'] ?? 0) === $resident && ($p5Row($p5EId)['resident_name'] ?? '') === 'Rae Resident'
    && ($p5EOwn['filed_by_type'] ?? '') === 'official' && count($p5EOwn['parties'] ?? []) === 2, $p5E['body']);
check('interface: P5 e) the filing SMS goes only to the registered complainant, through notify_resident',
  array_map('intval', array_column($p5ESms, 'resident_id')) === [$resident]
    && count($p5E['json']['sms'] ?? []) === 1 && (int)$p5E['json']['sms'][0]['resident_id'] === $resident, json_encode($p5ESms));

// f) Walk-in refusals.
$p5CountF = $p5Count();
$p5F = [
  'both roles' => $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'complainants' => [['resident_id' => $resident]], 'respondents' => [['resident_id' => $resident]]], $p5StaffCookie),
  'same role' => $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'complainants' => [['resident_id' => $p5Pia], ['resident_id' => $p5Pia]]], $p5StaffCookie),
  'inactive' => $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'complainants' => [['resident_id' => $p5Ina]]], $p5StaffCookie),
  'missing' => $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'complainants' => [['resident_id' => 99999999]]], $p5StaffCookie),
  'no complainant' => $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'complainants' => []], $p5StaffCookie),
  'unknown key' => $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'category' => 'Other', 'complainants' => [['full_name' => 'Ab Cd']]], $p5StaffCookie),
  'mixed party' => $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'complainants' => [['resident_id' => $p5Pia, 'full_name' => 'Pia Party']]], $p5StaffCookie),
];
$p5FAsResident = $p5Post('create_official', $p5Base + ['complaint_type_id' => $noiseType, 'complainants' => [['full_name' => 'Ab Cd']]], $p5ResidentCookie);
check('interface: P5 f) same resident as complainant and respondent, twice in one role, inactive or unknown resident, no complainant, unknown keys: 422, nothing stored',
  array_unique($p5Status($p5F)) === [422] && $p5Count() === $p5CountF
    && $p5Msg($p5F['both roles']) === 'The same resident cannot be both a complainant and a respondent.'
    && $p5Msg($p5F['same role']) === 'This resident is already listed.'
    && $p5Msg($p5F['inactive']) === 'Select an active registered resident.' && $p5Msg($p5F['missing']) === 'Select an active registered resident.'
    && $p5Msg($p5F['no complainant']) === 'Add at least one complainant.'
    && $p5Msg($p5F['unknown key']) === 'Unknown field: category.' && $p5Msg($p5F['mixed party']) === 'Unknown field: full_name.',
  json_encode(array_map($p5Msg, $p5F)) . json_encode($p5Status($p5F)));
check('interface: P5 f) a resident session cannot file a walk-in (401)', $p5FAsResident['status'] === 401 && $p5Count() === $p5CountF);

// g) Link the typed respondent "Pedro Santos" (complaint a) to Pia; then the link refusals.
$p5Pedro = $p5AParties[1] ?? ['id' => 0, 'version' => 0];
$p5Lito = $p5AParties[2] ?? ['id' => 0, 'version' => 0];
$p5G = $p5Post('party_link', ['id' => (int)$p5Pedro['id'], 'version' => (int)$p5Pedro['version'], 'resident_id' => $p5Pia], $p5StaffCookie);
$p5PedroAfter = $p5Party((int)$p5Pedro['id']);
$p5GList = $p5Call('GET', 'parties_list.php?complaint_id=' . $p5AId, null, $p5StaffCookie);
$p5GLinked = $p5Find($p5GList['json']['parties'] ?? [], (int)$p5Pedro['id']);
check('interface: P5 g) link: name replaced by the resident\'s, typed address and contact cleared, official view shows the resident\'s live address and contact',
  $p5G['status'] === 200 && (int)($p5PedroAfter['resident_id'] ?? 0) === $p5Pia && ($p5PedroAfter['full_name'] ?? '') === 'Pia Party'
    && $p5PedroAfter['address'] === null && $p5PedroAfter['contact_no'] === null && (int)$p5PedroAfter['version'] === 2
    && ($p5GLinked['is_registered'] ?? null) === true && ($p5GLinked['address'] ?? '') === 'Purok 4, Sampaguita'
    && ($p5GLinked['contact_no'] ?? '') === '+639171110021', $p5G['body'] . $p5GList['body']);
exec_sql($db, "UPDATE residents SET address='Purok 4, New Street' WHERE id=?", 'i', [$p5Pia]);
$p5GLive = $p5Find($p5Call('GET', 'parties_list.php?complaint_id=' . $p5AId, null, $p5StaffCookie)['json']['parties'] ?? [], (int)$p5Pedro['id']);
$p5GResident = $p5Find($p5List($p5ResidentCookie), $p5AId)['parties'] ?? [];
check('interface: P5 g) a registered party\'s address follows the resident profile; the filer now sees the linked resident\'s registered name',
  ($p5GLive['address'] ?? '') === 'Purok 4, New Street' && in_array(['role' => 'respondent', 'full_name' => 'Pia Party'], $p5GResident, true)
    && !in_array('Pedro Santos', array_column($p5GResident, 'full_name'), true), json_encode($p5GResident));
$p5GBoth = $p5Post('party_link', ['id' => (int)$p5Lito['id'], 'version' => (int)$p5Lito['version'], 'resident_id' => $resident], $p5StaffCookie);
$p5GSame = $p5Post('party_link', ['id' => (int)$p5Lito['id'], 'version' => (int)$p5Lito['version'], 'resident_id' => $p5Pia], $p5StaffCookie);
$p5GAgain = $p5Post('party_link', ['id' => (int)$p5Pedro['id'], 'version' => 2, 'resident_id' => $other], $p5StaffCookie);
check('interface: P5 g) link to a resident already in the other role, or already in the same role, is 422; a registered party cannot be linked again',
  $p5GBoth['status'] === 422 && $p5Msg($p5GBoth) === 'The same resident cannot be both a complainant and a respondent.'
    && $p5GSame['status'] === 422 && $p5Msg($p5GSame) === 'This resident is already listed.'
    && $p5GAgain['status'] === 422 && $p5Msg($p5GAgain) === 'This person is already a registered resident.'
    && (int)$p5Party((int)$p5Lito['id'])['version'] === 1 && $p5Party((int)$p5Lito['id'])['resident_id'] === null,
  $p5GBoth['body'] . $p5GSame['body'] . $p5GAgain['body']);

// Add and update through party_save (walk-in d has room for respondents).
$p5Add = $p5Post('party_save', ['complaint_id' => $p5DId, 'role' => 'respondent', 'full_name' => 'Added Person', 'address' => 'Purok 1', 'contact_no' => null], $p5StaffCookie);
$p5AddedId = (int)($p5Add['json']['party']['id'] ?? 0);
$p5Upd = $p5Post('party_save', ['complaint_id' => $p5DId, 'id' => $p5AddedId, 'version' => 1, 'full_name' => 'Added Person Renamed', 'address' => null, 'contact_no' => '+63 918 765 4321'], $p5StaffCookie);
$p5UpdSame = $p5Post('party_save', ['complaint_id' => $p5DId, 'id' => $p5AddedId, 'version' => 2, 'full_name' => 'Added Person Renamed', 'address' => null, 'contact_no' => '09187654321'], $p5StaffCookie);
$p5PiaParty = $p5DParties[1] ?? ['id' => 0, 'version' => 0];
$p5UpdRegistered = $p5Post('party_save', ['complaint_id' => $p5DId, 'id' => (int)$p5PiaParty['id'], 'version' => (int)$p5PiaParty['version'], 'full_name' => 'Other Name'], $p5StaffCookie);
$p5AddBoth = $p5Post('party_save', ['complaint_id' => $p5DId, 'role' => 'complainant', 'resident_id' => $p5Pia], $p5StaffCookie);
$p5AddSame = $p5Post('party_save', ['complaint_id' => $p5DId, 'role' => 'respondent', 'resident_id' => $p5Pia], $p5StaffCookie);
$p5AddedRow = $p5Party($p5AddedId);
check('interface: P5 party_save adds a typed respondent, updates it (version 2, contact normalized), an identical save changes nothing',
  $p5Add['status'] === 200 && $p5AddedId > 0 && $p5Upd['status'] === 200 && ($p5Upd['json']['changed'] ?? null) === true
    && ($p5AddedRow['full_name'] ?? '') === 'Added Person Renamed' && ($p5AddedRow['contact_no'] ?? '') === '+639187654321'
    && $p5AddedRow['address'] === null && (int)$p5AddedRow['version'] === 2
    && $p5UpdSame['status'] === 200 && ($p5UpdSame['json']['changed'] ?? null) === false && (int)$p5Party($p5AddedId)['version'] === 2,
  $p5Add['body'] . $p5Upd['body'] . $p5UpdSame['body']);
check('interface: P5 party_save refuses editing a registered party, and adding a resident already in the other or the same role',
  $p5UpdRegistered['status'] === 422 && $p5Msg($p5UpdRegistered) === 'Registered residents are edited in their profile.'
    && $p5AddBoth['status'] === 422 && $p5Msg($p5AddBoth) === 'The same resident cannot be both a complainant and a respondent.'
    && $p5AddSame['status'] === 422 && $p5Msg($p5AddSame) === 'This resident is already listed.',
  $p5UpdRegistered['body'] . $p5AddBoth['body'] . $p5AddSame['body']);
check('interface: P5 each party change adds a "Parties updated." timeline note with no personal details',
  (int)q($db, "SELECT COUNT(*) AS n FROM complaint_updates WHERE complaint_id=? AND note='Parties updated.'", 'i', [$p5DId])[0]['n'] === 2
    && (int)q($db, "SELECT COUNT(*) AS n FROM complaint_updates WHERE complaint_id=? AND note='Parties updated.'", 'i', [$p5AId])[0]['n'] === 1
    && (int)q($db, "SELECT COUNT(*) AS n FROM complaint_updates WHERE complaint_id IN (?, ?) AND (note LIKE '%Pia%' OR note LIKE '%Pedro%' OR note LIKE '%Added%')", 'ii', [$p5AId, $p5DId])[0]['n'] === 0);

// h) Removal rules.
$p5Maria = $p5DParties[0] ?? ['id' => 0, 'version' => 0];
$p5HLastComplainant = $p5Post('party_remove', ['id' => (int)$p5Maria['id'], 'version' => (int)$p5Maria['version']], $p5StaffCookie);
$p5HFiler = $p5Post('party_remove', ['id' => (int)($p5AParties[0]['id'] ?? 0), 'version' => (int)($p5AParties[0]['version'] ?? 0)], $p5StaffCookie);
$p5HStale = $p5Post('party_remove', ['id' => $p5AddedId, 'version' => 1], $p5StaffCookie);
$p5HOk = $p5Post('party_remove', ['id' => $p5AddedId, 'version' => 2], $p5StaffCookie);
$p5Req = $p5Post('create_official', $p5Base + ['complaint_type_id' => $p5RequiredType, 'complainants' => [['full_name' => 'Req Complainant']],
  'respondents' => [['full_name' => 'Req Respondent']]], $p5StaffCookie);
$p5ReqId = (int)($p5Req['json']['id'] ?? 0);
if ($p5ReqId) $p5ComplaintIds[] = $p5ReqId;
$p5ReqRespondent = $p5ReqId ? ($p5Parties($p5ReqId)[1] ?? ['id' => 0, 'version' => 0]) : ['id' => 0, 'version' => 0];
$p5HRequired = $p5Post('party_remove', ['id' => (int)$p5ReqRespondent['id'], 'version' => (int)$p5ReqRespondent['version']], $p5StaffCookie);
check('interface: P5 h) removing the last complainant, the filing resident, or the last respondent of a "required" type is 422',
  $p5HLastComplainant['status'] === 422 && $p5Msg($p5HLastComplainant) === 'A complaint needs at least one complainant.'
    && $p5HFiler['status'] === 422 && $p5Msg($p5HFiler) === 'The resident who filed this complaint cannot be removed.'
    && $p5Req['status'] === 200 && $p5HRequired['status'] === 422 && $p5Msg($p5HRequired) === 'This type of concern needs at least one respondent.'
    && (int)$p5Party((int)$p5Maria['id'])['is_active'] === 1, $p5HLastComplainant['body'] . $p5HFiler['body'] . $p5HRequired['body']);
$p5DListAfter = $p5Call('GET', 'parties_list.php?complaint_id=' . $p5DId, null, $p5StaffCookie)['json']['parties'] ?? [];
check('interface: P5 h) a stale version is 409; the current version removes (inactive, version 3); removed rows stay in parties_list',
  $p5HStale['status'] === 409 && $p5Msg($p5HStale) === 'This item was changed by someone else. Reload and try again.'
    && $p5HOk['status'] === 200 && (int)$p5Party($p5AddedId)['is_active'] === 0 && (int)$p5Party($p5AddedId)['version'] === 3
    && ($p5Find($p5DListAfter, $p5AddedId)['is_active'] ?? null) === false, $p5HStale['body'] . $p5HOk['body']);
exec_sql($db, "UPDATE complaints SET status='resolved' WHERE id=?", 'i', [$p5EId]);
$p5EParties = $p5Parties($p5EId);
$p5ESecond = $p5EParties[1] ?? ['id' => 0, 'version' => 0];
$p5HResolved = [
  $p5Post('party_remove', ['id' => (int)$p5ESecond['id'], 'version' => (int)$p5ESecond['version']], $p5StaffCookie),
  $p5Post('party_save', ['complaint_id' => $p5EId, 'role' => 'respondent', 'full_name' => 'Late Person'], $p5StaffCookie),
  $p5Post('party_save', ['complaint_id' => $p5EId, 'id' => (int)$p5ESecond['id'], 'version' => (int)$p5ESecond['version'], 'full_name' => 'Changed Name'], $p5StaffCookie),
  $p5Post('party_link', ['id' => (int)$p5ESecond['id'], 'version' => (int)$p5ESecond['version'], 'resident_id' => $p5Pia], $p5StaffCookie),
];
check('interface: P5 h) every party write on a resolved complaint is 409 "Resolved complaints cannot be changed."',
  array_unique($p5Status($p5HResolved)) === [409] && array_values(array_unique(array_map($p5Msg, $p5HResolved))) === ['Resolved complaints cannot be changed.']
    && $p5Parties($p5EId) === $p5EParties, json_encode($p5Status($p5HResolved)));
$p5HAsResident = [
  $p5Post('party_remove', ['id' => $p5AddedId, 'version' => 3], $p5ResidentCookie),
  $p5Post('party_save', ['complaint_id' => $p5AId, 'role' => 'respondent', 'full_name' => 'Sneaky Add'], $p5ResidentCookie),
  $p5Post('party_link', ['id' => (int)$p5Lito['id'], 'version' => 1, 'resident_id' => $p5Pia], $p5ResidentCookie),
  $p5Call('GET', 'parties_list.php?complaint_id=' . $p5AId, null, $p5ResidentCookie),
];
check('interface: P5 party endpoints refuse a resident session (401)', array_unique($p5Status($p5HAsResident)) === [401], json_encode($p5Status($p5HAsResident)));

// i) Resident search.
$p5I = static fn(string $q, ?string $cookie): array => $p5Call('GET', 'resident_search.php?q=' . rawurlencode($q), null, $cookie);
$p5IResident = $p5I('Pia', $p5ResidentCookie);
$p5IShort = $p5I('P', $p5StaffCookie);
$p5ILong = $p5I(str_repeat('a', 51), $p5StaffCookie);
$p5IPercent = $p5I('%%', $p5StaffCookie);
$p5IUnderscore = $p5I('__', $p5StaffCookie);
$p5IPia = $p5I('ia Par', $p5StaffCookie);
$p5IInactive = $p5I('Ina Inactive', $p5StaffCookie);
$p5IKeys = isset($p5IPia['json']['residents'][0]) ? array_keys($p5IPia['json']['residents'][0]) : [];
check('interface: P5 i) resident_search: resident session 401; 1 or 51 characters 422',
  $p5IResident['status'] === 401 && $p5IShort['status'] === 422 && $p5ILong['status'] === 422 && $p5Msg($p5IShort) === 'Enter 2 to 50 characters.',
  json_encode($p5Status([$p5IResident, $p5IShort, $p5ILong])));
check('interface: P5 i) "%" and "_" match literally, not everything',
  $p5IPercent['status'] === 200 && ($p5IPercent['json']['residents'] ?? null) === [] && $p5IUnderscore['status'] === 200 && ($p5IUnderscore['json']['residents'] ?? null) === [],
  $p5IPercent['body'] . $p5IUnderscore['body']);
check('interface: P5 i) finds active residents by full name with id, full_name, purok_zone, address only (no contact); inactive residents are not found',
  $p5IPia['status'] === 200 && count($p5IPia['json']['residents'] ?? []) === 1 && (int)$p5IPia['json']['residents'][0]['id'] === $p5Pia
    && $p5IKeys === ['id', 'full_name', 'purok_zone', 'address'] && !str_contains($p5IPia['body'], '+63917')
    && $p5IInactive['status'] === 200 && ($p5IInactive['json']['residents'] ?? null) === [], $p5IPia['body']);

// j) Community board: no party data.
exec_sql($db, 'UPDATE complaints SET is_public=1 WHERE id IN (?, ?)', 'ii', [$p5AId, $p5DId]);
$p5J = http($base . '/api/complaints/public_list.php', 'GET', null, [], $p5ResidentCookie);
$p5JCards = array_values(array_filter(json_decode($p5J['body'], true)['complaints'] ?? [], static fn(array $card): bool => in_array((int)$card['id'], [$p5AId, $p5DId], true)));
$p5JKeys = [];
foreach ($p5JCards as $p5Card) $p5JKeys = array_values(array_unique(array_merge($p5JKeys, array_keys($p5Card))));
sort($p5JKeys);
check('interface: P5 j) public_list cards keep the seven public keys and carry no party data or names',
  $p5J['status'] === 200 && count($p5JCards) === 2 && $p5JKeys === ['filed_on', 'id', 'published_report_count', 'severity', 'status', 'type_id', 'type_name']
    && !str_contains($p5J['body'], 'Pia') && !str_contains($p5J['body'], 'Lito') && !str_contains($p5J['body'], 'Maria')
    && !str_contains($p5J['body'], 'Rae Resident') && !str_contains($p5J['body'], 'parties'), json_encode($p5JKeys));

// k) Audit rows.
$p5Audit = q($db, 'SELECT action, target_type, target_reference, details FROM audit_log WHERE id > ? AND official_id = ? ORDER BY id', 'ii', [$p5AuditMark, $p5Staff]);
$p5Actions = array_count_values(array_column($p5Audit, 'action'));
$p5WalkinAudit = array_values(array_filter($p5Audit, static fn(array $row): bool => $row['action'] === 'Filed walk-in complaint'))[0] ?? [];
check('interface: P5 k) audit rows for walk-in filing, add, update, link and remove (no contact numbers in details)',
  ($p5Actions['Filed walk-in complaint'] ?? 0) === 3 && ($p5Actions['Added complaint party'] ?? 0) === 1
    && ($p5Actions['Updated complaint party'] ?? 0) === 1 && ($p5Actions['Linked complaint party'] ?? 0) === 1
    && ($p5Actions['Removed complaint party'] ?? 0) === 1
    && ($p5WalkinAudit['target_type'] ?? '') === 'complaint' && ($p5WalkinAudit['target_reference'] ?? '') === ($p5D['json']['reference_no'] ?? '-')
    && json_decode((string)($p5WalkinAudit['details'] ?? ''), true) === ['complainants' => 1, 'registered_complainants' => 0, 'respondents' => 1]
    && !str_contains(json_encode($p5Audit), '0918') && !str_contains(json_encode($p5Audit), '+639'), json_encode($p5Actions));

// Official list carries the official view; existing keys are still there.
$p5OfficialRow = $p5Find($p5List($p5StaffCookie), $p5DId);
check('interface: P5 official list has parties in the official view (active only) next to the existing keys',
  isset($p5OfficialRow['parties'], $p5OfficialRow['reference_no'], $p5OfficialRow['extra_fields_display'], $p5OfficialRow['duplicate_children_count'])
    && count($p5OfficialRow['parties']) === 2 && $p5OfficialRow['filed_by_type'] === 'official'
    && array_keys($p5OfficialRow['parties'][0]) === ['id', 'role', 'resident_id', 'is_registered', 'full_name', 'address', 'contact_no', 'sort_order', 'version', 'is_active']
    && $p5OfficialRow['parties'][1]['address'] === 'Purok 4, New Street', json_encode($p5OfficialRow['parties'] ?? null));

// Pages: data-respondent-mode on the resident form; walk-in button, windows and script on the official page.
$p5ResidentPage = http($base . '/pages/resident.php', 'GET', null, [], $p5ResidentCookie)['body'];
$p5OfficialPage = http($base . '/pages/official.php', 'GET', null, [], $p5StaffCookie)['body'];
check('interface: P5 resident form lists the respondent mode of each type and has the "Person involved" section',
  str_contains($p5ResidentPage, '<span data-type-id="' . $roadType . '" data-respondent-mode="none"></span>')
    && str_contains($p5ResidentPage, '<span data-type-id="' . $noiseType . '" data-respondent-mode="optional"></span>')
    && str_contains($p5ResidentPage, 'id="complaintPersonSection"') && str_contains($p5ResidentPage, 'This concern involves a specific person')
    && str_contains($p5ResidentPage, 'The barangay will verify the person'));
check('interface: P5 official page has the walk-in button, both windows, and complaint-walkin.js after complaint-types.js',
  str_contains($p5OfficialPage, 'id="walkinNewBtn"') && str_contains($p5OfficialPage, 'id="walkinModal"') && str_contains($p5OfficialPage, 'id="partiesModal"')
    && preg_match('#<script src="\.\./js/complaint-walkin\.js\?v=\d+"></script>#', $p5OfficialPage) === 1
    && strpos($p5OfficialPage, 'complaint-types.js') < strpos($p5OfficialPage, 'complaint-walkin.js'));

// Cleanup: complaints (parties and timeline rows cascade), audit rows, SMS rows, fixtures.
foreach ($p5ComplaintIds as $p5Id) exec_sql($db, 'DELETE FROM complaints WHERE id=?', 'i', [$p5Id]);
exec_sql($db, 'DELETE FROM audit_log WHERE official_id=?', 'i', [$p5Staff]);
exec_sql($db, 'DELETE FROM sms_log WHERE id > ?', 'i', [$p5SmsMark]);
exec_sql($db, 'DELETE FROM barangay_officials WHERE id=?', 'i', [$p5Staff]);
foreach ($p5ResidentIds as $p5Rid) exec_sql($db, 'DELETE FROM residents WHERE id=?', 'i', [$p5Rid]);
exec_sql($db, 'DELETE FROM households WHERE id=?', 'i', [$p5Household]);
exec_sql($db, 'DELETE FROM complaint_types WHERE id=?', 'i', [$p5RequiredType]);
$p5In = implode(',', $p5ComplaintIds ?: [0]);
$p5Left = q($db, "SELECT (SELECT COUNT(*) FROM complaint_parties WHERE complaint_id IN ({$p5In})) AS p,
  (SELECT COUNT(*) FROM complaints WHERE id IN ({$p5In})) AS c, (SELECT COUNT(*) FROM complaint_types) AS t,
  (SELECT COUNT(*) FROM residents WHERE username LIKE 'fix-p5-%') AS r")[0];
check('interface: P5 cleanup removed the test complaints, their parties, the fixtures and the test type',
  count($p5ComplaintIds) === 4 && (int)$p5Left['p'] === 0 && (int)$p5Left['c'] === 0 && (int)$p5Left['t'] === 5 && (int)$p5Left['r'] === 0, json_encode($p5Left));

// ===========================================================================
// Complaint revision Phase 6: mediation calendar (api/hearings/). Scratch
// database; times are built from a fixed Manila "now": a day 7 days ahead
// (Monday when that is a Sunday) at 09:00. The section removes its own rows.
// ===========================================================================
require_once FIX_ROOT . '/api/hearings/_hearings.php';
$p6Tz = new DateTimeZone('Asia/Manila');
$p6Now = new DateTimeImmutable('now', $p6Tz);
$p6Day = $p6Now->modify('+7 days')->setTime(0, 0);
if ($p6Day->format('N') === '7') $p6Day = $p6Day->modify('+1 day');
$p6Date = $p6Day->format('Y-m-d');
$p6Year = $p6Now->format('Y');
$p6Sunday = $p6Day->modify('next sunday')->format('Y-m-d');
$p6Call = static function (string $method, string $endpoint, ?array $body, ?string $cookie, string $contentType = 'application/json') use ($base): array {
  $response = http($base . '/api/hearings/' . $endpoint, $method, $body === null ? null : json_encode($body),
    $body === null ? [] : ['Content-Type: ' . $contentType], $cookie);
  $response['json'] = json_decode($response['body'], true);
  return $response;
};
$p6Post = static fn(string $endpoint, array $body, ?string $cookie): array => $p6Call('POST', $endpoint . '.php', $body, $cookie);
$p6Get = static fn(string $endpoint, array $query, ?string $cookie): array => $p6Call('GET', $endpoint . '.php?' . http_build_query($query), null, $cookie);
$p6Msg = static fn(array $response): string => (string)($response['json']['message'] ?? '');
$p6Key = static fn(): string => bin2hex(random_bytes(16));
// Own staff official: earlier runner checks change the shared 'staff' fixture's role.
exec_sql($db, 'INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,?,?,?,?,?,?,?,?)',
  'sssssssss', ['Hana', 'Hearing', 'fix-hearing-staff', 'staff', 'Purok 5', '+639171110039', password_hash('Fix24-pass', PASSWORD_DEFAULT), 'Lupon Clerk', 'active']);
$p6StaffId = (int)$db->insert_id;
$sid['hearingStaff'] = bin2hex(random_bytes(16));
$craft('hearingStaff', ['official_id' => $p6StaffId, 'official_role' => 'staff', 'official_name' => 'Hana Hearing', 'official_position' => 'Lupon Clerk']);
$p6StaffCookie = $cookieFor('hearingStaff');
$p6AdminCookie = $cookieFor('adminB');
$p6ResidentCookie = $cookieFor('resident');
$p6SmsMark = (int)q($db, 'SELECT COALESCE(MAX(id), 0) AS n FROM sms_log')[0]['n'];
$p6AuditMark = (int)q($db, 'SELECT COALESCE(MAX(id), 0) AS n FROM audit_log')[0]['n'];
$p6NoticeMark = (int)q($db, 'SELECT COALESCE(MAX(id), 0) AS n FROM notifications')[0]['n'];
$p6ComplaintIds = [];
$p6ResidentIds = [];
$p6Hearings = static fn(int $complaintId): array => q($db, 'SELECT * FROM complaint_hearings WHERE complaint_id=? ORDER BY session_no', 'i', [$complaintId]);
$p6Row = static fn(int $complaintId): array => q($db, 'SELECT * FROM complaints WHERE id=?', 'i', [$complaintId])[0] ?? [];
$p6HearingCount = static fn(): int => (int)q($db, 'SELECT COUNT(*) AS n FROM complaint_hearings')[0]['n'];
$p6LastNote = static fn(int $complaintId): string => (string)(q($db, 'SELECT note FROM complaint_updates WHERE complaint_id=? ORDER BY id DESC LIMIT 1', 'i', [$complaintId])[0]['note'] ?? '');
$p6Conflict = static fn(array $response, string $reason, int $hearingId): bool => $response['status'] === 409
  && ($response['json']['code'] ?? '') === 'hearing_conflict'
  && count(array_filter($response['json']['conflicts'] ?? [], static fn(array $c): bool => $c['reason'] === $reason && (int)$c['hearing_id'] === $hearingId)) === 1;

// Fixtures: three registered residents and complaints with parties (written directly).
exec_sql($db, 'INSERT INTO households (household_code,address,purok_zone,household_size) VALUES (?,?,?,?)', 'sssi', ['HH-P6-0001', 'Purok 5', 'Purok 5', 3]);
$p6Household = (int)$db->insert_id;
$p6Hash = password_hash('Fix24-pass', PASSWORD_DEFAULT);
foreach ([['Lito', 'Lopez', 'fix-p6-lito', '+639171110031'], ['Mara', 'Santos', 'fix-p6-mara', '+639171110032'], ['Nico', 'Reyes', 'fix-p6-nico', '+639171110033']] as $p6Person) {
  exec_sql($db, 'INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id,relationship_to_household_head,purok_zone) VALUES (?,?,?,?,?,?,?,?,?,?)',
    'sssssssiss', [$p6Person[0], $p6Person[1], $p6Person[2], 'Purok 5', $p6Person[3], $p6Hash, 'active', $p6Household, 'Member', 'Purok 5']);
  $p6ResidentIds[] = (int)$db->insert_id;
}
[$p6Lito, $p6Mara, $p6Nico] = $p6ResidentIds;
$p6Names = ['Rae Resident', 'Lito Lopez', 'Mara Santos', 'Nico Reyes', 'Unreg Person', 'Other Unreg', 'Fifth Party', 'Sixth Party'];
$p6OtherType = $typeIds['other'] ?? 0;
$p6Complaint = static function (string $ref, ?int $filer, string $status, array $complainants, array $respondents) use ($db, $p6OtherType, &$p6ComplaintIds): int {
  exec_sql($db, 'INSERT INTO complaints (resident_id,reference_no,resident_name,category,complaint_type_id,severity,location_text,description,date_filed,status,official_note) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
    'isssissssss', [$filer, $ref, 'P6 Filer', 'Other', $p6OtherType, 'low', 'Purok 5', 'Phase 6 mediation check', 'Nov 18, 2026', $status, '']);
  $id = (int)$db->insert_id;
  $p6ComplaintIds[] = $id;
  foreach (['complainant' => $complainants, 'respondent' => $respondents] as $role => $parties) {
    foreach ($parties as $index => [$residentId, $name, $contact]) {
      exec_sql($db, 'INSERT INTO complaint_parties (complaint_id,role,resident_id,full_name,contact_no,sort_order,created_by_type) VALUES (?,?,?,?,?,?,?)',
        'isissis', [$id, $role, $residentId, $name, $contact, $index, 'system']);
    }
  }
  return $id;
};
$p6A = $p6Complaint('P6-A', $resident, 'open', [[$resident, 'Rae Resident', null]], [[null, 'Unreg Person', '+639171110099'], [$p6Lito, 'Lito Lopez', null]]);
$p6B = $p6Complaint('P6-B', $p6Mara, 'open', [[$p6Mara, 'Mara Santos', null]], [[null, 'Other Unreg', null]]);
$p6C = $p6Complaint('P6-C', $p6Mara, 'open', [[$p6Mara, 'Mara Santos', null]], []);
$p6D = $p6Complaint('P6-D', $p6Mara, 'resolved', [[$p6Mara, 'Mara Santos', null]], [[null, 'Other Unreg', null]]);
$p6E = $p6Complaint('P6-E', $p6Nico, 'open', [[$p6Nico, 'Nico Reyes', null]], [[$p6Lito, 'Lito Lopez', null]]);
$p6F = $p6Complaint('P6-F', null, 'open', [[null, 'Fifth Party', null]], [[null, 'Sixth Party', null]]);
$p6G = $p6Complaint('P6-G', null, 'open', [[null, 'Sixth Party', null]], [[null, 'Fifth Party', null]]);
$p6H = $p6Complaint('P6-H', null, 'open', [[null, 'Fifth Party', null]], [[null, 'Other Unreg', null]]);
$p6Body = static fn(int $complaintId, string $time, int $duration, string $venue, int $official, ?string $date = null, ?string $key = null): array => [
  'complaint_id' => $complaintId, 'date' => $date ?? $p6Date, 'start_time' => $time, 'duration_minutes' => $duration,
  'venue' => $venue, 'presiding_official_id' => $official, 'request_key' => $key ?? bin2hex(random_bytes(16)),
];

// n) The official page: the sidebar button, the tab, both windows, the script order.
$p6Page = http($base . '/pages/official.php', 'GET', null, [], $p6StaffCookie)['body'];
check('interface: P6 official page has the Mediation Calendar button after Complaint Types, the tab, both windows, hearings.js after complaint-walkin.js',
  preg_match('#data-tab="complainttypes".*?</button>\s*<button class="snav-item" data-tab="hearings"><i class="fa-solid fa-calendar-days"></i> <span class="nav-text">Mediation Calendar</span>#s', $p6Page) === 1
    && str_contains($p6Page, 'id="tab-hearings"') && str_contains($p6Page, 'id="hearingSessionModal"') && str_contains($p6Page, 'id="hearingScheduleModal"')
    && preg_match('#<script src="\.\./js/hearings\.js\?v=\d+"></script>#', $p6Page) === 1
    && strpos($p6Page, 'complaint-walkin.js') < strpos($p6Page, 'hearings.js'));

// a) Schedule complaint A (staff session), presided by admin B.
$p6KeyA = $p6Key();
$p6BodyA = $p6Body($p6A, '09:00', 60, 'Barangay Hall', $adminB, null, $p6KeyA);
$p6First = $p6Post('schedule', $p6BodyA, $p6StaffCookie);
$p6HearingA = (int)($p6First['json']['hearing']['id'] ?? 0);
$p6CaseA = (string)($p6First['json']['case_no'] ?? '');
check('interface: P6 a) schedule answers 200 with the first case number BC-YYYY-0001 of this year',
  $p6First['status'] === 200 && $p6HearingA > 0 && $p6CaseA === "BC-{$p6Year}-0001" && $p6Row($p6A)['case_no'] === $p6CaseA, $p6First['body']);
$p6StoredA = $p6Hearings($p6A)[0] ?? [];
check('interface: P6 a) the session is stored in Manila time: date at 09:00-10:00, venue key, presiding official',
  ($p6StoredA['starts_at'] ?? '') === "{$p6Date} 09:00:00" && ($p6StoredA['ends_at'] ?? '') === "{$p6Date} 10:00:00"
    && ($p6StoredA['venue_key'] ?? '') === 'barangay hall' && (int)($p6StoredA['presiding_official_id'] ?? 0) === $adminB, json_encode($p6StoredA));
check('interface: P6 a) the complaint is now investigating, with an in-app notice for the filer',
  $p6Row($p6A)['status'] === 'investigating' && ($p6First['json']['complaint_status'] ?? '') === 'investigating'
    && (int)q($db, "SELECT COUNT(*) AS n FROM notifications WHERE id > ? AND resident_id = ? AND body LIKE '%P6-A is now investigating%'", 'ii', [$p6NoticeMark, $resident])[0]['n'] === 1);
$p6Attendance = q($db, 'SELECT party_id, status FROM complaint_hearing_attendance WHERE hearing_id=? ORDER BY party_id', 'i', [$p6HearingA]);
$p6ActiveA = array_map('intval', array_column(q($db, 'SELECT id FROM complaint_parties WHERE complaint_id=? AND is_active=1 ORDER BY id', 'i', [$p6A]), 'id'));
check('interface: P6 a) one not_recorded attendance row per active party',
  array_map('intval', array_column($p6Attendance, 'party_id')) === $p6ActiveA && count($p6ActiveA) === 3
    && array_values(array_unique(array_column($p6Attendance, 'status'))) === ['not_recorded'], json_encode($p6Attendance));
$p6Timeline = q($db, 'SELECT from_status, to_status, note, actor_type FROM complaint_updates WHERE complaint_id=? ORDER BY id', 'i', [$p6A]);
$p6Note = (string)($p6Timeline[0]['note'] ?? '');
check('interface: P6 a) timeline note open -> investigating, resident-safe (no names)',
  count($p6Timeline) === 1 && $p6Timeline[0]['from_status'] === 'open' && $p6Timeline[0]['to_status'] === 'investigating'
    && str_starts_with($p6Note, 'Mediation session 1 scheduled for ') && str_contains($p6Note, 'at Barangay Hall.')
    && array_filter($p6Names, static fn(string $n): bool => str_contains($p6Note, $n)) === [], json_encode($p6Timeline));

// b) Second schedule, no respondent, resolved.
$p6Count = $p6HearingCount();
$p6Again = $p6Post('schedule', $p6Body($p6A, '14:00', 30, 'Room 9', $p6StaffId), $p6StaffCookie);
$p6NoResp = $p6Post('schedule', $p6Body($p6C, '14:00', 30, 'Room 9', $p6StaffId), $p6StaffCookie);
$p6Resolved = $p6Post('schedule', $p6Body($p6D, '14:00', 30, 'Room 9', $p6StaffId), $p6StaffCookie);
check('interface: P6 b) second schedule 409, no respondent 422, resolved 409, nothing stored',
  $p6Again['status'] === 409 && $p6Msg($p6Again) === 'This complaint already has a scheduled session.'
    && $p6NoResp['status'] === 422 && $p6Msg($p6NoResp) === 'Add at least one respondent before scheduling mediation.'
    && $p6Resolved['status'] === 409 && $p6Msg($p6Resolved) === 'Resolved complaints cannot be scheduled.'
    && $p6HearingCount() === $p6Count && $p6Row($p6C)['case_no'] === null,
  $p6Again['body'] . $p6NoResp['body'] . $p6Resolved['body']);

// c) Time rules: each 422, nothing stored.
$p6Yesterday = $p6Now->modify('-1 day')->format('Y-m-d');
$p6TimeCases = [
  'past time' => [$p6Body($p6B, '09:00', 60, 'Room 1', $p6StaffId, $p6Yesterday), null],
  '06:30' => [$p6Body($p6B, '06:30', 60, 'Room 1', $p6StaffId), 'Sessions start at 7:00 AM or later.'],
  'ends after 19:00' => [$p6Body($p6B, '18:30', 60, 'Room 1', $p6StaffId), 'The session must end by 7:00 PM.'],
  'Sunday' => [$p6Body($p6B, '09:00', 60, 'Room 1', $p6StaffId, $p6Sunday), 'Mediation is held Monday to Saturday.'],
  'duration 20' => [$p6Body($p6B, '09:00', 20, 'Room 1', $p6StaffId), 'Duration must be 15 to 240 minutes, in steps of 15.'],
  'date 2026-02-30' => [$p6Body($p6B, '09:00', 60, 'Room 1', $p6StaffId, '2026-02-30'), 'Enter a valid date.'],
  'start 09:10 (quarter hour)' => [$p6Body($p6B, '09:10', 60, 'Room 1', $p6StaffId), 'Start time must be on the quarter hour.'],
  'date with a trailing newline' => [$p6Body($p6B, '09:00', 60, 'Room 1', $p6StaffId, $p6Date . "\n"), 'Enter a valid date.'],
  'duration as text' => [['duration_minutes' => '60'] + $p6Body($p6B, '09:00', 60, 'Room 1', $p6StaffId), 'Duration must be 15 to 240 minutes, in steps of 15.'],
];
$p6Count = $p6HearingCount();
foreach ($p6TimeCases as $p6Label => [$p6CaseBody, $p6Expected]) {
  $p6Res = $p6Post('schedule', $p6CaseBody, $p6StaffCookie);
  check('interface: P6 c) ' . $p6Label . ' answers 422' . ($p6Expected === null ? '' : ' with its message'),
    $p6Res['status'] === 422 && ($p6Expected === null || $p6Msg($p6Res) === $p6Expected), $p6Res['body']);
}
$p6Soon = $p6Now->modify('+5 minutes');
$p6SoonBody = $p6Body($p6B, $p6Soon->format('H') . ':' . str_pad((string)(intdiv((int)$p6Soon->format('i'), 15) * 15), 2, '0', STR_PAD_LEFT), 15, 'Room 1', $p6StaffId, $p6Soon->format('Y-m-d'));
$p6SoonRes = $p6Post('schedule', $p6SoonBody, $p6StaffCookie);
check('interface: P6 c) a start less than 15 minutes from now is refused (422), nothing stored',
  $p6SoonRes['status'] === 422 && $p6HearingCount() === $p6Count, $p6SoonRes['body']);

// d) Overlaps against A (09:00-10:00, admin B, Barangay Hall, registered Rae + Lito).
$p6Check = $p6Get('check', ['complaint_id' => $p6B, 'date' => $p6Date, 'start_time' => '09:30', 'duration_minutes' => '60', 'venue' => 'Room 2', 'presiding_official_id' => $adminB], $p6StaffCookie);
check('interface: P6 d) check.php warns before submit: ok false with the official conflict, nothing written',
  $p6Check['status'] === 200 && ($p6Check['json']['ok'] ?? null) === false && ($p6Check['json']['problems'] ?? null) === []
    && count($p6Check['json']['conflicts'] ?? []) === 1 && ($p6Check['json']['conflicts'][0]['reason'] ?? '') === 'official'
    && ($p6Check['json']['conflicts'][0]['case_no'] ?? '') === $p6CaseA && $p6HearingCount() === $p6Count, $p6Check['body']);
$p6CheckBad = $p6Get('check', ['complaint_id' => $p6B, 'date' => '2026-02-30', 'start_time' => '06:30', 'duration_minutes' => 'x', 'venue' => '', 'presiding_official_id' => '0'], $p6StaffCookie);
check('interface: P6 d) check.php lists every problem by field',
  $p6CheckBad['status'] === 200 && array_column($p6CheckBad['json']['problems'] ?? [], 'field') === ['date', 'start_time', 'duration_minutes', 'venue', 'presiding_official_id'], $p6CheckBad['body']);
$p6Official = $p6Post('schedule', $p6Body($p6B, '09:30', 60, 'Room 2', $adminB), $p6StaffCookie);
check('interface: P6 d) same presiding official overlapping -> 409, conflict reason "official"', $p6Conflict($p6Official, 'official', $p6HearingA)
  && $p6Msg($p6Official) === 'This time conflicts with another scheduled session.', $p6Official['body']);
$p6Venue = $p6Post('schedule', $p6Body($p6B, '09:30', 60, '  BARANGAY   hall ', $p6StaffId), $p6StaffCookie);
check('interface: P6 d) same venue with other case and spacing -> 409, conflict reason "venue"', $p6Conflict($p6Venue, 'venue', $p6HearingA)
  && count($p6Venue['json']['conflicts'] ?? []) === 1, $p6Venue['body']);
$p6Party = $p6Post('schedule', $p6Body($p6E, '09:45', 60, 'Room 3', $p6StaffId), $p6StaffCookie);
check('interface: P6 d) a shared registered party -> 409, conflict reason "party", no names in the body',
  $p6Conflict($p6Party, 'party', $p6HearingA) && !str_contains($p6Party['body'], 'Lito'), $p6Party['body']);
check('interface: P6 d) refused overlaps stored nothing', $p6HearingCount() === $p6Count && $p6Row($p6B)['case_no'] === null && $p6Row($p6E)['case_no'] === null);
$p6Back = $p6Post('schedule', $p6Body($p6B, '10:00', 60, 'Barangay Hall', $adminB), $p6AdminCookie);
$p6HearingB = (int)($p6Back['json']['hearing']['id'] ?? 0);
check('interface: P6 d) back-to-back (end == next start, same official and venue) -> 200 (admin session)', $p6Back['status'] === 200 && $p6HearingB > 0, $p6Back['body']);
check('interface: P6 j) the second complaint gets the next case number', ($p6Back['json']['case_no'] ?? '') === "BC-{$p6Year}-0002", $p6Back['body']);

// e) The same request_key again.
$p6SmsA = static fn(): int => (int)q($db, "SELECT COUNT(*) AS n FROM sms_log WHERE id > ? AND endpoint = 'complaint_hearing_scheduled' AND resident_id = ?", 'ii', [$p6SmsMark, $resident])[0]['n'];
$p6Repeat = $p6Post('schedule', $p6BodyA, $p6StaffCookie);
check('interface: P6 e) repeating the request_key answers 200 with the same hearing, no second row, one SMS log entry',
  $p6Repeat['status'] === 200 && ($p6Repeat['json']['idempotent'] ?? null) === true && (int)($p6Repeat['json']['hearing']['id'] ?? 0) === $p6HearingA
    && count($p6Hearings($p6A)) === 1 && ($p6Repeat['json']['sms'] ?? null) === [] && $p6SmsA() === 1, $p6Repeat['body']);
$p6KeyOther = $p6Post('schedule', ['request_key' => $p6KeyA] + $p6Body($p6F, '16:00', 30, 'Room 7', $p6StaffId), $p6StaffCookie);
check('interface: P6 e) a request_key already used for another complaint answers 409',
  $p6KeyOther['status'] === 409 && $p6Msg($p6KeyOther) === 'This request was already used for another change.', $p6KeyOther['body']);
$p6BadKey = $p6Post('schedule', ['request_key' => 'ABC'] + $p6Body($p6F, '16:00', 30, 'Room 7', $p6StaffId), $p6StaffCookie);
$p6Unknown = $p6Post('schedule', ['note' => 'x'] + $p6Body($p6F, '16:00', 30, 'Room 7', $p6StaffId), $p6StaffCookie);
check('interface: P6 e) a malformed request_key or an unknown field answers 422',
  $p6BadKey['status'] === 422 && $p6Unknown['status'] === 422 && $p6Msg($p6Unknown) === 'Unknown field: note.', $p6BadKey['body'] . $p6Unknown['body']);

// f) Reschedule A (admin session) to 13:00-13:30, Session Hall, presided by staff.
$p6VersionA = (int)$p6Hearings($p6A)[0]['version'];
$p6Re = ['hearing_id' => $p6HearingA, 'version' => $p6VersionA, 'date' => $p6Date, 'start_time' => '13:00', 'duration_minutes' => 30,
  'venue' => 'Session Hall', 'presiding_official_id' => $p6StaffId, 'reason' => 'Respondent asked for an afternoon slot.', 'request_key' => $p6Key()];
$p6NoReason = $p6Post('reschedule', ['reason' => 'abc'] + $p6Re, $p6AdminCookie);
$p6Stale = $p6Post('reschedule', ['version' => $p6VersionA + 5] + $p6Re, $p6AdminCookie);
check('interface: P6 f) reschedule without a 5..500 reason -> 422, stale version -> 409, nothing changed',
  $p6NoReason['status'] === 422 && $p6Msg($p6NoReason) === 'Enter a reason (5 to 500 characters).'
    && $p6Stale['status'] === 409 && $p6Msg($p6Stale) === 'This item was changed by someone else. Reload and try again.'
    && count($p6Hearings($p6A)) === 1 && $p6Hearings($p6A)[0]['status'] === 'scheduled', $p6NoReason['body'] . $p6Stale['body']);
$p6Moved = $p6Post('reschedule', $p6Re, $p6AdminCookie);
$p6AllA = $p6Hearings($p6A);
$p6HearingA2 = (int)($p6Moved['json']['hearing']['id'] ?? 0);
check('interface: P6 f) reschedule: old session "rescheduled" with its reason, new session 2 points back to it',
  $p6Moved['status'] === 200 && count($p6AllA) === 2 && $p6AllA[0]['status'] === 'rescheduled' && $p6AllA[0]['change_reason'] === $p6Re['reason']
    && (int)$p6AllA[1]['session_no'] === 2 && (int)$p6AllA[1]['rescheduled_from_id'] === $p6HearingA && (int)$p6AllA[1]['id'] === $p6HearingA2
    && $p6AllA[1]['starts_at'] === "{$p6Date} 13:00:00" && ($p6Moved['json']['previous']['status'] ?? '') === 'rescheduled', $p6Moved['body']);
check('interface: P6 j) the case number does not change on reschedule', $p6Row($p6A)['case_no'] === $p6CaseA && ($p6Moved['json']['case_no'] ?? '') === $p6CaseA);
$p6Freed = $p6Get('check', ['complaint_id' => $p6E, 'date' => $p6Date, 'start_time' => '09:00', 'duration_minutes' => '60', 'venue' => 'Room 3', 'presiding_official_id' => $p6StaffId], $p6StaffCookie);
check('interface: P6 f) the old slot is free again (a rescheduled session no longer blocks)', ($p6Freed['json']['conflicts'] ?? null) === [], $p6Freed['body']);
$p6ReTimeline = $p6LastNote($p6A);
check('interface: P6 f) reschedule timeline note has no reason and no names',
  str_starts_with($p6ReTimeline, 'Mediation session 1 rescheduled; new session 2 on ') && !str_contains($p6ReTimeline, 'afternoon'), $p6ReTimeline);

// h) Resolving B (scheduled session) is refused by both endpoints.
$p6ResolveB = $p6Call('POST', '../complaints/update_status.php', ['id' => $p6B, 'status' => 'resolved'], $p6AdminCookie);
$p6NotifyB = $p6Call('POST', '../complaints/notify_resolved.php', ['id' => $p6B, 'official_note' => 'Done'], $p6AdminCookie);
check('interface: P6 h) resolving a complaint with a scheduled session -> 409 (update_status and notify_resolved), status unchanged',
  $p6ResolveB['status'] === 409 && $p6Msg($p6ResolveB) === 'Complete or cancel the scheduled mediation first.'
    && $p6NotifyB['status'] === 409 && $p6Msg($p6NotifyB) === 'Complete or cancel the scheduled mediation first.'
    && $p6Row($p6B)['status'] === 'investigating', $p6ResolveB['body'] . $p6NotifyB['body']);
$p6OtherStatus = $p6Call('POST', '../complaints/update_status.php', ['id' => $p6B, 'status' => 'investigating', 'official_note' => 'Waiting for mediation.'], $p6AdminCookie);
check('interface: P6 h) other saves still work while a session is scheduled', $p6OtherStatus['status'] === 200, $p6OtherStatus['body']);

// g) Record A session 2.
$p6Future = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 1, 'attendance' => [], 'complete' => false], $p6StaffCookie);
check('interface: P6 g) recording a future session -> 422', $p6Future['status'] === 422 && $p6Msg($p6Future) === 'A session can be recorded once it has started.', $p6Future['body']);
// Move the session into the past directly (the API cannot create one): started 2 hours ago, ended 1 hour ago.
exec_sql($db, 'UPDATE complaint_hearings SET starts_at=?, ends_at=? WHERE id=?', 'ssi',
  [$p6Now->modify('-2 hours')->format('Y-m-d H:i:s'), $p6Now->modify('-1 hour')->format('Y-m-d H:i:s'), $p6HearingA2]);
$p6Needs = $p6Get('needs_recording', [], $p6StaffCookie);
check('interface: P6 g) needs_recording lists the ended session with needs_recording true',
  $p6Needs['status'] === 200 && count(array_filter($p6Needs['json']['hearings'] ?? [], static fn(array $h): bool => (int)$h['id'] === $p6HearingA2 && $h['needs_recording'] === true)) === 1, $p6Needs['body']);
[$p6PartyRae, $p6PartyUnreg, $p6PartyLito] = $p6ActiveA;
$p6Summary = 'Both parties agreed to keep the noise down after 10 PM.';
$p6Notes = "Respondent was calm.\nFollow up in a week.";
$p6Partial = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 1, 'attendance' => [(string)$p6PartyRae => 'present', (string)$p6PartyLito => 'absent'],
  'outcome' => 'settled', 'public_summary' => $p6Summary, 'private_notes' => $p6Notes, 'complete' => true], $p6StaffCookie);
check('interface: P6 g) completing with a not_recorded party -> 422, nothing saved',
  $p6Partial['status'] === 422 && $p6Msg($p6Partial) === 'Record attendance for everyone before completing.'
    && (int)q($db, 'SELECT version FROM complaint_hearings WHERE id=?', 'i', [$p6HearingA2])[0]['version'] === 1, $p6Partial['body']);
$p6Stranger = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 1, 'attendance' => ['999999' => 'present'], 'complete' => false], $p6StaffCookie);
$p6BadStatus = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 1, 'attendance' => [(string)$p6PartyRae => 'late'], 'complete' => false], $p6StaffCookie);
$p6ShortSummary = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 1, 'public_summary' => 'Too short', 'complete' => false], $p6StaffCookie);
$p6NoFlag = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 1], $p6StaffCookie);
check('interface: P6 g) party not on the session, unknown attendance value, 9-character summary, missing complete flag -> 422 each',
  $p6Stranger['status'] === 422 && $p6Msg($p6Stranger) === 'Attendance includes someone who is not on this session.'
    && $p6BadStatus['status'] === 422 && $p6ShortSummary['status'] === 422 && $p6NoFlag['status'] === 422,
  $p6Stranger['body'] . $p6BadStatus['body'] . $p6ShortSummary['body'] . $p6NoFlag['body']);
$p6Save = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 1, 'attendance' => [(string)$p6PartyRae => 'present'], 'private_notes' => 'Draft notes', 'complete' => false], $p6StaffCookie);
check('interface: P6 g) save attendance only: stays scheduled, version 2, no timeline note',
  $p6Save['status'] === 200 && ($p6Save['json']['changed'] ?? null) === true && ($p6Save['json']['hearing']['status'] ?? '') === 'scheduled'
    && (int)($p6Save['json']['hearing']['version'] ?? 0) === 2 && $p6LastNote($p6A) === $p6ReTimeline, $p6Save['body']);
$p6Same = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 2, 'attendance' => [(string)$p6PartyRae => 'present'], 'complete' => false], $p6StaffCookie);
check('interface: P6 g) an identical save changes nothing (changed false, version stays 2)',
  $p6Same['status'] === 200 && ($p6Same['json']['changed'] ?? null) === false && (int)($p6Same['json']['hearing']['version'] ?? 0) === 2, $p6Same['body']);
$p6Done = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 2, 'attendance' => [(string)$p6PartyUnreg => 'excused', (string)$p6PartyLito => 'absent'],
  'outcome' => 'settled', 'public_summary' => $p6Summary, 'private_notes' => $p6Notes, 'complete' => true], $p6StaffCookie);
$p6DoneRow = q($db, 'SELECT * FROM complaint_hearings WHERE id=?', 'i', [$p6HearingA2])[0];
check('interface: P6 g) complete: status completed, outcome, summary, notes, completed_at in Manila time, timeline note',
  $p6Done['status'] === 200 && $p6DoneRow['status'] === 'completed' && $p6DoneRow['outcome'] === 'settled' && $p6DoneRow['public_summary'] === $p6Summary
    && $p6DoneRow['private_notes'] === $p6Notes && $p6DoneRow['completed_at'] !== null
    && abs(strtotime((string)$p6DoneRow['completed_at']) - strtotime($p6Now->format('Y-m-d H:i:s'))) < 600
    && $p6LastNote($p6A) === 'Mediation session 2 completed. Outcome: Settled.', $p6Done['body']);
$p6AfterDone = $p6Post('record', ['hearing_id' => $p6HearingA2, 'version' => 3, 'attendance' => [], 'complete' => false], $p6StaffCookie);
check('interface: P6 g) a completed session cannot be recorded again (409)', $p6AfterDone['status'] === 409, $p6AfterDone['body']);
$p6ResolveA = $p6Call('POST', '../complaints/update_status.php', ['id' => $p6A, 'status' => 'resolved'], $p6AdminCookie);
check('interface: P6 g) once the session is completed the complaint can be resolved', $p6ResolveA['status'] === 200 && $p6Row($p6A)['status'] === 'resolved', $p6ResolveA['body']);

// i) Cancel B, then the same slot is free again.
$p6VersionB = (int)$p6Hearings($p6B)[0]['version'];
$p6CancelNoReason = $p6Post('cancel', ['hearing_id' => $p6HearingB, 'version' => $p6VersionB, 'reason' => ''], $p6StaffCookie);
check('interface: P6 i) cancel needs a reason (422)', $p6CancelNoReason['status'] === 422 && $p6Hearings($p6B)[0]['status'] === 'scheduled', $p6CancelNoReason['body']);
$p6Cancel = $p6Post('cancel', ['hearing_id' => $p6HearingB, 'version' => $p6VersionB, 'reason' => 'Complainant is in the hospital.'], $p6StaffCookie);
check('interface: P6 i) cancel -> cancelled with its reason, timeline note without the reason',
  $p6Cancel['status'] === 200 && $p6Hearings($p6B)[0]['status'] === 'cancelled' && $p6Hearings($p6B)[0]['change_reason'] === 'Complainant is in the hospital.'
    && $p6LastNote($p6B) === 'Mediation session 1 cancelled.', $p6Cancel['body']);
$p6CancelAgain = $p6Post('cancel', ['hearing_id' => $p6HearingB, 'version' => $p6VersionB + 1, 'reason' => 'Again please.'], $p6StaffCookie);
check('interface: P6 i) a cancelled session cannot be cancelled again (409)', $p6CancelAgain['status'] === 409 && $p6Msg($p6CancelAgain) === 'Only a scheduled session can be changed.', $p6CancelAgain['body']);
$p6Refill = $p6Post('schedule', $p6Body($p6B, '10:00', 60, 'Barangay Hall', $adminB), $p6StaffCookie);
check('interface: P6 i) the cancelled slot can be scheduled again (200, session 2, same case number)',
  $p6Refill['status'] === 200 && (int)($p6Refill['json']['hearing']['session_no'] ?? 0) === 2 && ($p6Refill['json']['case_no'] ?? '') === "BC-{$p6Year}-0002", $p6Refill['body']);

// k) What the filer sees; the community board; the official view.
$p6ResidentRows = json_decode(http($base . '/api/complaints/list.php', 'GET', null, [], $p6ResidentCookie)['body'], true)['complaints'] ?? [];
$p6ResidentA = array_values(array_filter($p6ResidentRows, static fn(array $r): bool => (int)$r['id'] === $p6A))[0] ?? [];
$p6ResidentKeys = array_values(array_unique(array_map(static fn(array $s): string => implode(',', array_keys($s)), $p6ResidentA['hearings'] ?? [])));
check('interface: P6 k) the filer sees case_no and both sessions with exactly the public keys',
  ($p6ResidentA['case_no'] ?? '') === $p6CaseA && count($p6ResidentA['hearings'] ?? []) === 2
    && $p6ResidentKeys === ['session_no,starts_at,ends_at,venue,status,outcome,public_summary']
    && ($p6ResidentA['hearings'][1]['public_summary'] ?? '') === $p6Summary
    && ($p6ResidentA['hearings'][1]['outcome'] ?? '') === 'settled' && ($p6ResidentA['hearings'][0]['status'] ?? '') === 'rescheduled', json_encode($p6ResidentA['hearings'] ?? null));
$p6ResidentJson = (string)json_encode($p6ResidentA);
check('interface: P6 k) the filer never gets private notes, reasons, attendance or the presiding official',
  !str_contains($p6ResidentJson, 'private_notes') && !str_contains($p6ResidentJson, 'change_reason') && !str_contains($p6ResidentJson, 'attendance')
    && !str_contains($p6ResidentJson, 'presiding') && !str_contains($p6ResidentJson, 'Follow up in a week') && !str_contains($p6ResidentJson, 'afternoon slot'));
$p6OfficialRows = json_decode(http($base . '/api/complaints/list.php', 'GET', null, [], $p6StaffCookie)['body'], true)['complaints'] ?? [];
$p6OfficialA = array_values(array_filter($p6OfficialRows, static fn(array $r): bool => (int)$r['id'] === $p6A))[0] ?? [];
check('interface: P6 k) the official list carries case_no (sessions come from api/hearings/)', ($p6OfficialA['case_no'] ?? '') === $p6CaseA && !array_key_exists('hearings', $p6OfficialA));
exec_sql($db, 'UPDATE complaints SET is_public=1 WHERE id=?', 'i', [$p6A]);
$p6Board = http($base . '/api/complaints/public_list.php', 'GET', null, [], $p6ResidentCookie);
$p6Card = array_values(array_filter(json_decode($p6Board['body'], true)['complaints'] ?? [], static fn(array $r): bool => (int)$r['id'] === $p6A))[0] ?? [];
$p6CardKeys = array_keys($p6Card);
sort($p6CardKeys);
check('interface: P6 k) community board: the same seven keys, no case number or session data',
  $p6CardKeys === ['filed_on', 'id', 'published_report_count', 'severity', 'status', 'type_id', 'type_name']
    && !str_contains($p6Board['body'], $p6CaseA) && !str_contains($p6Board['body'], 'Session Hall') && !str_contains($p6Board['body'], 'hearing'), $p6Board['body']);
exec_sql($db, 'UPDATE complaints SET is_public=0 WHERE id=?', 'i', [$p6A]);
$p6Detail = $p6Get('for_complaint', ['complaint_id' => $p6A], $p6StaffCookie);
$p6DetailS2 = $p6Detail['json']['sessions'][1] ?? [];
check('interface: P6 k) for_complaint (official): attendance with names, private notes, reasons, blocker',
  $p6Detail['status'] === 200 && ($p6Detail['json']['complaint']['case_no'] ?? '') === $p6CaseA
    && ($p6Detail['json']['complaint']['schedule_blocker'] ?? '') === 'Resolved complaints cannot be scheduled.'
    && count($p6DetailS2['attendance'] ?? []) === 3 && in_array('Lito Lopez', array_column($p6DetailS2['attendance'] ?? [], 'full_name'), true)
    && ($p6Detail['json']['sessions'][0]['change_reason'] ?? '') === $p6Re['reason'] && ($p6DetailS2['private_notes'] ?? '') === $p6Notes, $p6Detail['body']);

// Parties added after scheduling are shown, not added retroactively.
exec_sql($db, 'INSERT INTO complaint_parties (complaint_id,role,resident_id,full_name,sort_order,created_by_type) VALUES (?,?,?,?,?,?)',
  'isssis', [$p6B, 'respondent', null, 'Late Comer', 5, 'system']);
$p6Late = $p6Get('for_complaint', ['complaint_id' => $p6B], $p6StaffCookie);
$p6LateSession = array_values(array_filter($p6Late['json']['sessions'] ?? [], static fn(array $s): bool => $s['status'] === 'scheduled'))[0] ?? [];
check('interface: P6 a party added after scheduling is listed as "added after", not on the attendance',
  array_column($p6LateSession['added_after'] ?? [], 'full_name') === ['Late Comer'] && !in_array('Late Comer', array_column($p6LateSession['attendance'] ?? [], 'full_name'), true), $p6Late['body']);

// list.php range rules, officials.php.
$p6Range = $p6Get('list', ['from' => $p6Now->format('Y-m-d'), 'to' => $p6Now->modify('+20 days')->format('Y-m-d')], $p6StaffCookie);
$p6TooWide = $p6Get('list', ['from' => '2026-01-01', 'to' => '2026-03-05'], $p6StaffCookie);
$p6Filtered = $p6Get('list', ['from' => $p6Now->format('Y-m-d'), 'to' => $p6Now->modify('+20 days')->format('Y-m-d'), 'status' => 'cancelled'], $p6StaffCookie);
$p6BadFilter = $p6Get('list', ['from' => $p6Now->format('Y-m-d'), 'to' => $p6Now->format('Y-m-d'), 'status' => 'open'], $p6StaffCookie);
check('interface: P6 list.php: sessions in range; over 62 days 422; status filter; bad filter 422',
  $p6Range['status'] === 200 && in_array($p6HearingB, array_map('intval', array_column($p6Range['json']['hearings'] ?? [], 'id')), true)
    && $p6TooWide['status'] === 422 && array_values(array_unique(array_column($p6Filtered['json']['hearings'] ?? [], 'status'))) === ['cancelled']
    && $p6BadFilter['status'] === 422, $p6Range['body'] . $p6TooWide['body']);
$p6Officials = $p6Get('officials', [], $p6StaffCookie);
check('interface: P6 officials.php lists active officials (id, name, position) only',
  $p6Officials['status'] === 200 && array_keys($p6Officials['json']['officials'][0] ?? []) === ['id', 'name', 'position']
    && in_array($adminB, array_column($p6Officials['json']['officials'] ?? [], 'id'), true), $p6Officials['body']);

// l) SMS: registered parties only, texts without names.
$p6SmsRows = q($db, "SELECT resident_id, endpoint FROM sms_log WHERE id > ? AND endpoint LIKE 'complaint_hearing_%' ORDER BY id", 'i', [$p6SmsMark]);
$p6SmsResidents = array_values(array_unique(array_map('intval', array_column($p6SmsRows, 'resident_id'))));
sort($p6SmsResidents);
$p6Registered = [$resident, $p6Lito, $p6Mara];
sort($p6Registered);
check('interface: P6 l) SMS rows only for registered parties (Rae, Lito, Mara), none without a resident, three events',
  $p6SmsResidents === $p6Registered && !in_array(null, array_column($p6SmsRows, 'resident_id'), true)
    && array_values(array_unique(array_column($p6SmsRows, 'endpoint'))) === ['complaint_hearing_scheduled', 'complaint_hearing_rescheduled', 'complaint_hearing_cancelled'],
  json_encode($p6SmsRows));
check('interface: P6 l) the schedule answer lists SMS for exactly the registered parties',
  array_map(static fn(array $s): int => (int)$s['resident_id'], $p6First['json']['sms'] ?? []) === [$resident, $p6Lito], json_encode($p6First['json']['sms'] ?? null));
$p6Texts = [];
foreach (['scheduled', 'rescheduled', 'cancelled'] as $p6Event) {
  $p6Texts[] = hearing_sms_body($p6Event, $p6CaseA, "{$p6Date} 13:00:00", 'Session Hall');
  $p6Texts[] = hearing_sms_body($p6Event, 'BC-2026-123456', '2026-11-25 10:45:00', str_repeat('Covered Court Purok ', 12));
}
check('interface: P6 l) SMS texts are at most 160 characters and name no party',
  max(array_map(static fn(string $t): int => mb_strlen($t, 'UTF-8'), $p6Texts)) <= 160
    && array_filter($p6Texts, static fn(string $t): bool => (bool)array_filter($p6Names, static fn(string $n): bool => str_contains($t, $n))) === [],
  json_encode($p6Texts));

// m) Concurrency: two schedules for the same official and time, sent together.
$p6Multi = curl_multi_init();
$p6Handles = [];
foreach ([[$p6F, 'Room F'], [$p6G, 'Room G']] as [$p6Cid, $p6Room]) {
  $p6Handle = curl_init($base . '/api/hearings/schedule.php');
  curl_setopt_array($p6Handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: ' . $p6StaffCookie],
    CURLOPT_POSTFIELDS => json_encode($p6Body($p6Cid, '15:00', 60, $p6Room, $p6StaffId))]);
  curl_multi_add_handle($p6Multi, $p6Handle);
  $p6Handles[] = $p6Handle;
}
do { $p6Status = curl_multi_exec($p6Multi, $p6Running); if ($p6Running) curl_multi_select($p6Multi, 1.0); } while ($p6Running && $p6Status === CURLM_OK);
$p6Codes = array_map(static fn($h): int => (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE), $p6Handles);
$p6Bodies = array_map(static fn($h): string => (string)curl_multi_getcontent($h), $p6Handles);
foreach ($p6Handles as $p6Handle) curl_multi_remove_handle($p6Multi, $p6Handle);
curl_multi_close($p6Multi);
$p6Sorted = $p6Codes;
sort($p6Sorted);
check('interface: P6 m) two parallel schedules for the same official and time: exactly one 200 and one 409',
  $p6Sorted === [200, 409] && (int)q($db, "SELECT COUNT(*) AS n FROM complaint_hearings WHERE complaint_id IN (?, ?) AND status = 'scheduled'", 'ii', [$p6F, $p6G])[0]['n'] === 1,
  json_encode([$p6Codes, $p6Bodies]));
// m2) The lock itself: hold the guard row on another connection, send a
// schedule, add a conflicting session while it waits, then release. The
// request must still be waiting, and must then see that session (409).
$p6Lock = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $dbName);
$p6Lock->set_charset('utf8mb4');
$p6Lock->begin_transaction();
$p6Lock->query('SELECT id FROM complaint_hearing_guard WHERE id = 1 FOR UPDATE');
$p6Waiter = curl_init($base . '/api/hearings/schedule.php');
curl_setopt_array($p6Waiter, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: ' . $p6StaffCookie],
  CURLOPT_POSTFIELDS => json_encode($p6Body($p6H, '17:00', 30, 'Room H', $adminB))]);
$p6WaitMulti = curl_multi_init();
curl_multi_add_handle($p6WaitMulti, $p6Waiter);
$p6Until = microtime(true) + 1.5;
do { curl_multi_exec($p6WaitMulti, $p6Running); curl_multi_select($p6WaitMulti, 0.2); } while ($p6Running && microtime(true) < $p6Until);
$p6StillWaiting = $p6Running > 0;
$p6LockStart = "{$p6Date} 17:00:00";
$p6LockEnd = "{$p6Date} 17:30:00";
$p6Stmt = $p6Lock->prepare("INSERT INTO complaint_hearings (complaint_id, session_no, starts_at, ends_at, venue, venue_key, presiding_official_id, status, created_by, updated_by)
  VALUES (?, 1, ?, ?, 'Room Lock', 'room lock', ?, 'scheduled', ?, ?)");
$p6Stmt->bind_param('issiii', $p6C, $p6LockStart, $p6LockEnd, $adminB, $p6StaffId, $p6StaffId);
$p6Stmt->execute();
$p6LockHearing = (int)$p6Stmt->insert_id;
$p6Stmt->close();
$p6Lock->commit();
do { $p6Status = curl_multi_exec($p6WaitMulti, $p6Running); if ($p6Running) curl_multi_select($p6WaitMulti, 1.0); } while ($p6Running && $p6Status === CURLM_OK);
$p6WaitCode = (int)curl_getinfo($p6Waiter, CURLINFO_RESPONSE_CODE);
$p6WaitBody = json_decode((string)curl_multi_getcontent($p6Waiter), true) ?? [];
curl_multi_remove_handle($p6WaitMulti, $p6Waiter);
curl_multi_close($p6WaitMulti);
$p6Lock->close();
check('interface: P6 m2) a schedule waits on the calendar lock, then sees the session committed meanwhile (409 "official")',
  $p6StillWaiting && $p6LockHearing > 0 && $p6WaitCode === 409
    && count(array_filter($p6WaitBody['conflicts'] ?? [], static fn(array $c): bool => $c['reason'] === 'official' && (int)$c['hearing_id'] === $p6LockHearing)) === 1,
  json_encode([$p6StillWaiting, $p6WaitCode, $p6WaitBody]));
// The session inserted under the lock bypassed the rules (complaint C has no respondent): remove it.
exec_sql($db, 'DELETE FROM complaint_hearings WHERE id=?', 'i', [$p6LockHearing]);

// n) Who may call: staff and admin did above; a resident or nobody gets 401 everywhere.
$p6Endpoints = [['GET', 'list.php?from=2026-11-01&to=2026-11-30'], ['GET', 'needs_recording.php'], ['GET', 'for_complaint.php?complaint_id=' . $p6A],
  ['GET', 'check.php?complaint_id=' . $p6A], ['GET', 'officials.php'], ['POST', 'schedule.php'], ['POST', 'reschedule.php'], ['POST', 'cancel.php'], ['POST', 'record.php']];
$p6Denied = [];
foreach ($p6Endpoints as [$p6Method, $p6Path]) {
  foreach ([$p6ResidentCookie, null] as $p6Who) {
    $p6Res = $p6Call($p6Method, $p6Path, $p6Method === 'POST' ? ['hearing_id' => $p6HearingB] : null, $p6Who);
    if ($p6Res['status'] !== 401) $p6Denied[] = $p6Path . ':' . $p6Res['status'];
  }
}
check('interface: P6 n) a resident session or no session gets 401 on every hearings endpoint', $p6Denied === [], implode(',', $p6Denied));
$p6WrongMethod = $p6Call('POST', 'list.php', ['x' => 1], $p6StaffCookie);
$p6WrongGet = $p6Call('GET', 'schedule.php', null, $p6StaffCookie);
$p6NotJson = $p6Call('POST', 'cancel.php', ['hearing_id' => 1], $p6StaffCookie, 'text/plain');
$p6Missing = $p6Get('for_complaint', ['complaint_id' => '99999999'], $p6StaffCookie);
check('interface: P6 n) wrong method 405, not JSON 415, unknown complaint 404',
  $p6WrongMethod['status'] === 405 && $p6WrongGet['status'] === 405 && $p6NotJson['status'] === 415 && $p6Missing['status'] === 404,
  implode(',', [$p6WrongMethod['status'], $p6WrongGet['status'], $p6NotJson['status'], $p6Missing['status']]));

// Audit rows: target type complaint_hearing, case numbers, no reasons, notes or names.
$p6Audit = q($db, "SELECT action, target_reference, details FROM audit_log WHERE id > ? AND target_type = 'complaint_hearing' ORDER BY id", 'i', [$p6AuditMark]);
$p6AuditText = (string)json_encode($p6Audit);
$p6Actions = array_count_values(array_column($p6Audit, 'action'));
check('interface: P6 audit rows for every write, without reasons, notes or names',
  ($p6Actions['Scheduled mediation'] ?? 0) === 4 && ($p6Actions['Rescheduled mediation'] ?? 0) === 1 && ($p6Actions['Cancelled mediation'] ?? 0) === 1
    && ($p6Actions['Recorded mediation'] ?? 0) === 1 && ($p6Actions['Completed mediation'] ?? 0) === 1
    && !str_contains($p6AuditText, 'afternoon') && !str_contains($p6AuditText, 'hospital') && !str_contains($p6AuditText, 'calm')
    && array_filter($p6Names, static fn(string $n): bool => str_contains($p6AuditText, $n)) === [], $p6AuditText);
$p6LiveLines = [];
exec('"' . FIX_PHP . '" "' . FIX_ROOT . '/project-support/tests/complaint-types/run.php" --db=' . $dbName . ' 2>&1', $p6LiveLines);
$p6LiveFail = array_values(array_filter($p6LiveLines, static fn(string $l): bool => str_starts_with($l, 'FAIL P6')));
check('interface: P6 the complaint-types Phase 6 invariants hold on the scratch database after all of this',
  $p6LiveFail === [] && count(array_filter($p6LiveLines, static fn(string $l): bool => str_starts_with($l, 'PASS P6'))) >= 15, implode(' | ', $p6LiveFail));

// Cleanup: sessions (newer first: rescheduled_from_id is RESTRICT), complaints, rows written, fixtures.
$p6In = implode(',', $p6ComplaintIds ?: [0]);
exec_sql($db, "DELETE FROM complaint_hearings WHERE complaint_id IN ({$p6In}) AND rescheduled_from_id IS NOT NULL");
exec_sql($db, "DELETE FROM complaint_hearings WHERE complaint_id IN ({$p6In})");
exec_sql($db, "DELETE FROM complaints WHERE id IN ({$p6In})");
exec_sql($db, 'DELETE FROM complaint_case_counters');
exec_sql($db, 'DELETE FROM audit_log WHERE id > ?', 'i', [$p6AuditMark]);
exec_sql($db, 'DELETE FROM sms_log WHERE id > ?', 'i', [$p6SmsMark]);
exec_sql($db, 'DELETE FROM notifications WHERE id > ?', 'i', [$p6NoticeMark]);
foreach ($p6ResidentIds as $p6Rid) exec_sql($db, 'DELETE FROM residents WHERE id=?', 'i', [$p6Rid]);
exec_sql($db, 'DELETE FROM households WHERE id=?', 'i', [$p6Household]);
exec_sql($db, 'DELETE FROM barangay_officials WHERE id=?', 'i', [$p6StaffId]);
$p6Left = q($db, "SELECT (SELECT COUNT(*) FROM complaint_hearings) AS h, (SELECT COUNT(*) FROM complaint_hearing_attendance) AS a,
  (SELECT COUNT(*) FROM complaints WHERE id IN ({$p6In})) AS c, (SELECT COUNT(*) FROM complaint_parties WHERE complaint_id IN ({$p6In})) AS p,
  (SELECT COUNT(*) FROM complaint_case_counters) AS k, (SELECT COUNT(*) FROM residents WHERE username LIKE 'fix-p6-%') AS r, (SELECT COUNT(*) FROM barangay_officials WHERE username = 'fix-hearing-staff') AS o")[0];
check('interface: P6 cleanup removed sessions, attendance, complaints, parties, counters and fixtures',
  count($p6ComplaintIds) === 8 && array_sum(array_map('intval', $p6Left)) === 0, json_encode($p6Left));

// ===========================================================================
// Complaint revision Phase 8: complaints report + AI payload (api/stats/_complaint_report.php).
// Scratch database. Fixtures are written directly (fixed past dates the API
// would refuse); the section removes its own rows at the end.
// ===========================================================================
require_once FIX_ROOT . '/api/stats/_complaint_report.php';
$p8Report = static function (?string $cookie) use ($base): array {
  $response = http($base . '/api/stats/complaints_report.php', 'GET', null, [], $cookie);
  $response['json'] = json_decode($response['body'], true);
  return $response;
};
$p8Ai = static fn(?string $cookie): array => http($base . '/api/ai/analyze_complaints.php', 'POST', '{}', ['Content-Type: application/json'], $cookie);
$p8Local = static function () use ($db): array {
  complaint_types_cache_reset();
  return complaint_report_data($db);
};
$p8Keys = static function (array $row): array {
  $keys = array_keys($row);
  sort($keys);
  return $keys;
};
$p8RowsOk = static function (array $rows, array $expected) use ($p8Keys): bool {
  sort($expected);
  foreach ($rows as $row) if (!is_array($row) || $p8Keys($row) !== $expected) return false;
  return true;
};
$p8OfType = static fn(array $rows, int $typeId): array => array_values(array_filter($rows, static fn(array $r): bool => ($r['type_id'] ?? null) === $typeId));

// g) Officials only, on both endpoints.
$p8Denied = [];
foreach ([null, $cookieFor('resident'), $cookieFor('other')] as $p8Cookie) {
  $p8Denied[] = $p8Report($p8Cookie)['status'];
  $p8Denied[] = $p8Ai($p8Cookie)['status'];
}
check('interface: P8 g) anonymous and resident sessions get 401/403 from the report and the AI endpoint',
  array_filter($p8Denied, static fn(int $s): bool => !in_array($s, [401, 403], true)) === [], json_encode($p8Denied));

$p8Before = $p8Report($cookieFor('adminB'))['json'] ?? [];
$p8BaseMed = $p8Before['mediation'] ?? [];
check('interface: P8 baseline: no mediation sessions left on the scratch database (P6 cleaned up)',
  ($p8BaseMed['sessions'] ?? null) === ['scheduled' => 0, 'completed' => 0, 'cancelled' => 0, 'rescheduled' => 0]
    && array_key_exists('settlement_rate', $p8BaseMed) && $p8BaseMed['settlement_rate'] === null
    && array_key_exists('avg_days_to_first_session', $p8BaseMed) && $p8BaseMed['avg_days_to_first_session'] === null, json_encode($p8BaseMed));

// Fixtures: two test types (one inactive), five complaints, parties, sessions, summons rows.
$p8Secrets = ['P8-SECRET', '+63917000', 'BC-2026-88', 'FIX-P8-'];
exec_sql($db, "INSERT INTO complaint_types (type_code,type_name,respondent_mode,is_active,sort_order) VALUES ('p8_alpha','P8 Alpha','optional',1,90)");
$p8TypeA = (int)$db->insert_id;
exec_sql($db, "INSERT INTO complaint_types (type_code,type_name,respondent_mode,is_active,sort_order) VALUES ('p8_beta','P8 Beta','optional',0,91)");
$p8TypeB = (int)$db->insert_id;
$p8ComplaintIds = [];
$p8Complaint = static function (int $n, int $typeId, string $category, string $status, string $dateFiled, bool $walkIn, ?string $caseNo) use ($db, $resident, $adminB, &$p8ComplaintIds): int {
  exec_sql($db, 'INSERT INTO complaints (resident_id,reference_no,case_no,resident_name,filed_by_type,filed_by_official_id,category,complaint_type_id,severity,location_text,description,date_filed,status,official_note)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'issssisissssss',
    [$walkIn ? null : $resident, 'FIX-P8-' . $n, $caseNo, 'P8-SECRET-FILER', $walkIn ? 'official' : 'resident', $walkIn ? $adminB : null,
     $category, $typeId, 'medium', 'P8-SECRET-LOCATION', 'P8-SECRET-DESCRIPTION', $dateFiled, $status, 'P8-SECRET-NOTE']);
  $id = (int)$db->insert_id;
  $p8ComplaintIds[] = $id;
  return $id;
};
$p8Party = static function (int $complaintId, string $role, ?int $residentId, string $name, bool $active = true) use ($db): int {
  exec_sql($db, 'INSERT INTO complaint_parties (complaint_id,role,resident_id,full_name,address,contact_no,is_active,created_by_type) VALUES (?,?,?,?,?,?,?,?)',
    'isisssis', [$complaintId, $role, $residentId, $name, 'P8-SECRET-ADDRESS', '+639170008888', $active ? 1 : 0, 'system']);
  return (int)$db->insert_id;
};
$p8C1 = $p8Complaint(1, $p8TypeA, 'P8 Alpha', 'open', 'Oct 01, 2026', false, 'BC-2026-8801');
$p8C2 = $p8Complaint(2, $p8TypeA, 'P8 Alpha', 'investigating', 'Oct 05, 2026', true, 'BC-2026-8802');
$p8Party($p8C1, 'complainant', null, 'P8-SECRET-COMPLAINANT');
$p8Party($p8C1, 'respondent', $resident, 'P8-SECRET-RESP-REG');
$p8Party($p8C1, 'respondent', null, 'P8-SECRET-RESP-UNREG');
$p8Party($p8C2, 'complainant', null, 'P8-SECRET-COMPLAINANT-2');
$p8RespC2 = $p8Party($p8C2, 'respondent', null, 'P8-SECRET-RESP-2');
// b) Rename after C1/C2; C4 is filed under the new name.
exec_sql($db, "UPDATE complaint_types SET type_name='P8 Alpha Renamed' WHERE id=?", 'i', [$p8TypeA]);
$p8C4 = $p8Complaint(4, $p8TypeA, 'P8 Alpha Renamed', 'resolved', 'Nov 03, 2026', false, null);
$p8C5 = $p8Complaint(5, $p8TypeB, 'P8 Beta', 'resolved', 'Nov 02, 2026', false, null);
$p8C6 = $p8Complaint(6, $p8TypeB, 'P8 Beta', 'open', 'not-a-date', false, null);
$p8Party($p8C5, 'respondent', $resident, 'P8-SECRET-REMOVED', false);   // inactive: not counted

$p8Hearing = static function (int $complaintId, int $no, string $start, string $status, ?string $outcome, ?int $from) use ($db, $adminB): int {
  $end = (new DateTimeImmutable($start))->modify('+1 hour')->format('Y-m-d H:i:s');
  exec_sql($db, 'INSERT INTO complaint_hearings (complaint_id,session_no,starts_at,ends_at,venue,venue_key,presiding_official_id,status,outcome,public_summary,private_notes,rescheduled_from_id,change_reason,created_by,updated_by,completed_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iissssissssisiis',
    [$complaintId, $no, $start, $end, 'P8-SECRET-VENUE', 'p8-secret-venue', $adminB, $status, $outcome, 'P8-SECRET-SUMMARY public text',
     'P8-SECRET-PRIVATE-NOTES', $from, $status === 'cancelled' || $status === 'rescheduled' ? 'P8-SECRET-REASON' : null, $adminB, $adminB,
     $status === 'completed' ? $end : null]);
  return (int)$db->insert_id;
};
// C1: s1 completed settled (10 days after filing), s2 completed no agreement.
$p8Hearing($p8C1, 1, '2026-10-11 09:00:00', 'completed', 'settled', null);
$p8Hearing($p8C1, 2, '2026-10-20 09:00:00', 'completed', 'no_agreement', null);
// C2: s1 cancelled (20 days after filing), s2 rescheduled -> s3 scheduled, already ended, with a draft outcome.
$p8Hearing($p8C2, 1, '2026-10-25 09:00:00', 'cancelled', null, null);
$p8S2 = $p8Hearing($p8C2, 2, '2026-10-28 09:00:00', 'rescheduled', null, null);
$p8S3 = $p8Hearing($p8C2, 3, '2026-11-02 09:00:00', 'scheduled', 'settled', $p8S2);
$p8Summons = static function (string $status) use ($db, $p8C2, $p8S3, $p8RespC2, $adminB): void {
  exec_sql($db, 'INSERT INTO complaint_documents (complaint_id,hearing_id,party_id,kind,file_uuid,pdf_sha256,docx_sha256,status,generated_by,generated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?)', 'iiisssssis',
    [$p8C2, $p8S3, $p8RespC2, 'summons', sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffff)),
     str_repeat('a', 64), str_repeat('b', 64), $status, $adminB, '2026-10-29 10:00:00']);
};
$p8Summons('superseded');
$p8Summons('current');

$p8Res = $p8Report($cookieFor('adminB'));
$p8 = $p8Res['json'] ?? [];
check('interface: P8 report answers 200 for an official', $p8Res['status'] === 200 && ($p8['success'] ?? false) === true, substr($p8Res['body'], 0, 300));

// a) Old keys keep their structure; new keys are present.
$p8Top = $p8Keys($p8);
check('interface: P8 a) top-level keys: the old five, monthly, the five new ones, and billing (Phase 10)',
  $p8Top === ['billing', 'by_category', 'by_severity', 'by_type_status', 'cross', 'filing', 'mediation', 'mediation_by_type', 'monthly', 'monthly_rank', 'parties', 'success', 'totals'],
  implode(',', $p8Top));
check('interface: P8 a) row key sets (totals, by_category, by_severity, cross, monthly_rank, monthly, by_type_status, mediation_by_type)',
  $p8Keys($p8['totals']) === ['investigating', 'open', 'resolved', 'total']
    && $p8RowsOk($p8['by_category'], ['type_id', 'category', 'is_active', 'count'])
    && $p8RowsOk($p8['by_severity'], ['severity', 'count'])
    && $p8RowsOk($p8['cross'], ['type_id', 'category', 'is_active', 'severity', 'count'])
    && $p8RowsOk($p8['monthly_rank'], ['ym', 'rn', 'type_id', 'category', 'is_active', 'count'])
    && $p8RowsOk($p8['monthly'], ['ym', 'total', 'critical_count', 'high_count'])
    && $p8RowsOk($p8['by_type_status'], ['type_id', 'category', 'is_active', 'open', 'investigating', 'resolved', 'total'])
    && $p8RowsOk($p8['mediation_by_type'], ['type_id', 'category', 'is_active', 'sessions_completed', 'settled', 'avg_days_to_first_session'])
    && $p8Keys($p8['filing']) === ['online', 'walk_in']
    && $p8Keys($p8['parties']) === ['registered_respondents', 'unregistered_respondents', 'with_respondent', 'without_respondent']
    && $p8Keys($p8['mediation']) === ['avg_days_to_first_session', 'complaints_with_case_no', 'needs_recording', 'outcomes', 'sessions', 'settlement_rate', 'summons_issued', 'summons_superseded']
    && $p8Keys($p8['mediation']['outcomes']) === ['for_follow_up', 'no_agreement', 'referred', 'settled'],
  json_encode(array_map(static fn($v) => is_array($v) && isset($v[0]) && is_array($v[0]) ? array_keys($v[0]) : (is_array($v) ? array_keys($v) : $v), $p8)));
check('interface: P8 a) totals grew by the five fixture complaints',
  (int)$p8['totals']['total'] === (int)$p8Before['totals']['total'] + 5
    && (int)$p8['totals']['open'] === (int)$p8Before['totals']['open'] + 2
    && (int)$p8['totals']['investigating'] === (int)$p8Before['totals']['investigating'] + 1
    && (int)$p8['totals']['resolved'] === (int)$p8Before['totals']['resolved'] + 2, json_encode([$p8Before['totals'], $p8['totals']]));

// b) Renamed type: one row, current name, old and new complaints together.
$p8A = $p8OfType($p8['by_category'], $p8TypeA);
$p8ACross = $p8OfType($p8['cross'], $p8TypeA);
$p8ARank = $p8OfType($p8['monthly_rank'], $p8TypeA);
check('interface: P8 b) after a rename by_category has ONE row for the type, with the new name, counting old and new complaints',
  count($p8A) === 1 && $p8A[0]['category'] === 'P8 Alpha Renamed' && $p8A[0]['count'] === 3 && $p8A[0]['is_active'] === true
    && !in_array('P8 Alpha', array_column($p8['by_category'], 'category'), true)
    && count($p8ACross) === 1 && $p8ACross[0]['count'] === 3 && $p8ACross[0]['category'] === 'P8 Alpha Renamed'
    && array_unique(array_column($p8ARank, 'category')) === ['P8 Alpha Renamed']
    && array_sum(array_column($p8ARank, 'count')) === 3, json_encode([$p8A, $p8ACross, $p8ARank]));

// c) Inactive type: name kept, is_active false everywhere; the unparseable date is left out of the monthly figures only.
$p8B = $p8OfType($p8['by_category'], $p8TypeB);
$p8BRank = $p8OfType($p8['monthly_rank'], $p8TypeB);
$p8BStatus = $p8OfType($p8['by_type_status'], $p8TypeB);
$p8BCross = $p8OfType($p8['cross'], $p8TypeB);
check('interface: P8 c) inactive type rows carry is_active false with the type name (by_category, cross, monthly_rank, by_type_status)',
  count($p8B) === 1 && $p8B[0]['is_active'] === false && $p8B[0]['category'] === 'P8 Beta' && $p8B[0]['count'] === 2
    && count($p8BCross) === 1 && $p8BCross[0]['is_active'] === false
    && count($p8BRank) === 1 && $p8BRank[0]['is_active'] === false && $p8BRank[0]['ym'] === '2026-11' && $p8BRank[0]['count'] === 1
    && count($p8BStatus) === 1 && $p8BStatus[0]['is_active'] === false
    && [$p8BStatus[0]['open'], $p8BStatus[0]['investigating'], $p8BStatus[0]['resolved'], $p8BStatus[0]['total']] === [1, 0, 1, 2],
  json_encode([$p8B, $p8BCross, $p8BRank, $p8BStatus]));
$p8AStatus = $p8OfType($p8['by_type_status'], $p8TypeA);
check('interface: P8 by_type_status for the renamed type: open 1, investigating 1, resolved 1, total 3',
  count($p8AStatus) === 1 && [$p8AStatus[0]['open'], $p8AStatus[0]['investigating'], $p8AStatus[0]['resolved'], $p8AStatus[0]['total']] === [1, 1, 1, 3],
  json_encode($p8AStatus));

// d) Mediation numbers (fixed dates).
$p8Med = $p8['mediation'];
check('interface: P8 d) sessions by status 1/2/1/1, outcomes of completed sessions only (draft outcome on the scheduled one ignored)',
  $p8Med['sessions'] === ['scheduled' => 1, 'completed' => 2, 'cancelled' => 1, 'rescheduled' => 1]
    && $p8Med['outcomes'] === ['settled' => 1, 'for_follow_up' => 0, 'referred' => 0, 'no_agreement' => 1], json_encode($p8Med));
check('interface: P8 d) settlement_rate 50.0, avg_days_to_first_session 15.0 (10 and 20 days), needs_recording 1',
  (float)$p8Med['settlement_rate'] === 50.0 && (float)$p8Med['avg_days_to_first_session'] === 15.0 && $p8Med['needs_recording'] === 1, json_encode($p8Med));
check('interface: P8 d) case numbers +2, summons current +1 and superseded +1',
  $p8Med['complaints_with_case_no'] === $p8BaseMed['complaints_with_case_no'] + 2
    && $p8Med['summons_issued'] === $p8BaseMed['summons_issued'] + 1 && $p8Med['summons_superseded'] === $p8BaseMed['summons_superseded'] + 1, json_encode($p8Med));
$p8AMed = $p8OfType($p8['mediation_by_type'], $p8TypeA);
check('interface: P8 d) mediation_by_type: the renamed type, 2 completed, 1 settled, 15.0 days; no row for types without sessions',
  count($p8['mediation_by_type']) === 1 && count($p8AMed) === 1 && $p8AMed[0]['category'] === 'P8 Alpha Renamed'
    && $p8AMed[0]['sessions_completed'] === 2 && $p8AMed[0]['settled'] === 1 && (float)$p8AMed[0]['avg_days_to_first_session'] === 15.0,
  json_encode($p8['mediation_by_type']));
$p8Needs = (int)(q($db, 'SELECT COUNT(*) AS n FROM complaint_hearings WHERE status=? AND ends_at < ?', 'ss', ['scheduled', hearing_fmt(hearing_now())])[0]['n']);
check('interface: P8 d) needs_recording equals the hearing module rule (hearing_now, Manila)', $p8Needs === $p8Med['needs_recording'], (string)$p8Needs);

// e) Filing and parties against a manual count of the fixtures (deltas over the baseline).
$p8Delta = static fn(string $group, string $key): int => (int)$p8[$group][$key] - (int)$p8Before[$group][$key];
check('interface: P8 e) filing: +4 online, +1 walk-in',
  $p8Delta('filing', 'online') === 4 && $p8Delta('filing', 'walk_in') === 1, json_encode([$p8Before['filing'], $p8['filing']]));
check('interface: P8 e) parties (active only): +2 with respondent, +3 without, +1 registered, +2 not registered',
  $p8Delta('parties', 'with_respondent') === 2 && $p8Delta('parties', 'without_respondent') === 3
    && $p8Delta('parties', 'registered_respondents') === 1 && $p8Delta('parties', 'unregistered_respondents') === 2,
  json_encode([$p8Before['parties'], $p8['parties']]));
$p8Manual = q($db, "SELECT SUM(filed_by_type='resident') AS online, SUM(filed_by_type='official') AS walk_in,
    SUM(EXISTS (SELECT 1 FROM complaint_parties p WHERE p.complaint_id=c.id AND p.role='respondent' AND p.is_active=1)) AS with_r
  FROM complaints c")[0];
check('interface: P8 e) filing and with_respondent equal a direct count over the whole scratch database',
  (int)$p8Manual['online'] === $p8['filing']['online'] && (int)$p8Manual['walk_in'] === $p8['filing']['walk_in']
    && (int)$p8Manual['with_r'] === $p8['parties']['with_respondent'], json_encode($p8Manual));

// f) AI payload: allowlisted keys only; no personal text anywhere in the report or the payload.
$p8Payload = complaint_ai_payload($p8Local());
$p8PayloadKeys = $p8Keys($p8Payload);
check('interface: P8 f) AI payload has only the allowlisted keys and row fields',
  $p8PayloadKeys === ['billing', 'by_category', 'by_severity', 'cross', 'filing', 'mediation', 'mediation_by_type', 'monthly', 'parties', 'totals']
    && $p8Keys($p8Payload['billing']) === ['bills_issued', 'paid_count', 'paid_total', 'unpaid_count', 'unpaid_total', 'waived_count']
    && $p8RowsOk($p8Payload['by_category'], ['category', 'is_active', 'count'])
    && $p8RowsOk($p8Payload['cross'], ['category', 'severity', 'count'])
    && $p8RowsOk($p8Payload['mediation_by_type'], ['category', 'sessions_completed', 'settled', 'avg_days_to_first_session'])
    && $p8Keys($p8Payload['mediation']) === ['avg_days_to_first_session', 'complaints_with_case_no', 'needs_recording', 'outcomes', 'sessions', 'settlement_rate', 'summons_issued', 'summons_superseded'],
  implode(',', $p8PayloadKeys));
$p8Smuggled = complaint_ai_payload(['totals' => ['total' => 1, 'resident_name' => 'P8-SECRET-X'], 'future_names' => ['P8-SECRET-Y'],
  'by_category' => [['category' => 'X', 'count' => 1, 'full_name' => 'P8-SECRET-Z']],
  'billing' => ['bills_issued' => 1, 'receipt_no' => 'P8-SECRET-R', 'waive_reason' => 'P8-SECRET-W', 'reference_no' => 'P8-SECRET-REF']]);
check('interface: P8 f) a key or row field added to the report later does not reach the payload (billing: six aggregate keys only)',
  !str_contains(json_encode($p8Smuggled), 'P8-SECRET') && !array_key_exists('future_names', $p8Smuggled)
    && array_keys($p8Smuggled['billing'] ?? []) === ['bills_issued', 'unpaid_count', 'unpaid_total', 'paid_count', 'paid_total', 'waived_count']
    && !array_key_exists('billing', complaint_ai_payload(['totals' => ['total' => 1]])), json_encode($p8Smuggled));
$p8Leaks = [];
$p8Scan = static function (mixed $value, string $path) use (&$p8Scan, &$p8Leaks, $p8Secrets): void {
  if (is_array($value)) {
    foreach ($value as $k => $v) {
      foreach ($p8Secrets as $secret) if (str_contains((string)$k, $secret)) $p8Leaks[] = $path . '/' . $k;
      $p8Scan($v, $path . '/' . $k);
    }
    return;
  }
  foreach ($p8Secrets as $secret) if (str_contains((string)$value, $secret)) $p8Leaks[] = $path . '=' . $value;
};
$p8Scan($p8, 'report');
$p8Scan($p8Payload, 'payload');
$p8Scan(json_decode(json_encode($p8Payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true), 'message');
check('interface: P8 f) no party names, addresses, contacts, notes, summaries, reasons, venues, reference or case numbers in the report or the payload',
  $p8Leaks === [] && !str_contains($p8Res['body'], 'P8-SECRET') && !str_contains($p8Res['body'], '8888'), implode(' | ', $p8Leaks));

// h) Same numbers: the HTTP report and the in-process data build the same AI payload; the AI endpoint gets past the data and stops at the blank key.
check('interface: P8 h) HTTP report and the AI payload builder use the same numbers',
  complaint_ai_payload($p8) == $p8Payload && complaint_ai_payload($p8)['mediation']['sessions'] === $p8Payload['mediation']['sessions'],
  json_encode([complaint_ai_payload($p8)['mediation'], $p8Payload['mediation']]));
$p8AiRes = $p8Ai($cookieFor('adminB'));
check('interface: P8 h) AI endpoint (official) with no key: 503 "AI analysis is not configured." before the throttle, nothing sent, no budget spent',
  $p8AiRes['status'] === 503 && (json_decode($p8AiRes['body'], true)['message'] ?? '') === 'AI analysis is not configured.'
  && !str_contains($p8AiRes['body'], 'P8-SECRET')
  && (int)$db->query("SELECT COUNT(*) FROM ai_request_log WHERE endpoint = 'complaint_report'")->fetch_row()[0] === 0, $p8AiRes['body']);

// UI wiring (server-rendered page and script).
$p8Page = http($base . '/pages/official.php', 'GET', null, [], $cookieFor('adminB'))['body'];
$p8Js = (string)file_get_contents(FIX_ROOT . '/js/official.js');
check('interface: P8 page has the Mediation, Filing and People containers; the script renders them and opens the calendar',
  str_contains($p8Page, 'id="complaintMediation"') && str_contains($p8Page, 'id="complaintFiling"') && str_contains($p8Page, 'id="complaintParties"')
    && str_contains($p8Js, "No mediation sessions yet.") && str_contains($p8Js, "(no longer used)") && str_contains($p8Js, "switchTab('hearings')"));

// Cleanup: summons rows, sessions (newer first), complaints (parties cascade), types.
$p8In = implode(',', $p8ComplaintIds ?: [0]);
exec_sql($db, "DELETE FROM complaint_documents WHERE complaint_id IN ({$p8In})");
exec_sql($db, "DELETE FROM complaint_hearings WHERE complaint_id IN ({$p8In}) AND rescheduled_from_id IS NOT NULL");
exec_sql($db, "DELETE FROM complaint_hearings WHERE complaint_id IN ({$p8In})");
exec_sql($db, "DELETE FROM complaint_updates WHERE complaint_id IN ({$p8In})");
exec_sql($db, "DELETE FROM complaints WHERE id IN ({$p8In})");
exec_sql($db, 'DELETE FROM complaint_types WHERE id IN (?,?)', 'ii', [$p8TypeA, $p8TypeB]);
complaint_types_cache_reset();
$p8Left = q($db, "SELECT (SELECT COUNT(*) FROM complaints WHERE id IN ({$p8In})) AS c, (SELECT COUNT(*) FROM complaint_parties WHERE complaint_id IN ({$p8In})) AS p,
  (SELECT COUNT(*) FROM complaint_hearings WHERE complaint_id IN ({$p8In})) AS h, (SELECT COUNT(*) FROM complaint_documents WHERE complaint_id IN ({$p8In})) AS d,
  (SELECT COUNT(*) FROM complaint_types WHERE type_code IN ('p8_alpha','p8_beta')) AS t")[0];
$p8After = $p8Report($cookieFor('adminB'))['json'] ?? [];
unset($p8After['success'], $p8Before['success']);
check('interface: P8 cleanup removed every fixture; the report is back to its baseline',
  count($p8ComplaintIds) === 5 && array_sum(array_map('intval', $p8Left)) === 0 && $p8After == $p8Before,
  json_encode($p8Left));
