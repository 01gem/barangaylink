<?php
/**
 * BarangayLink - Scratch-database document template endpoint tests.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

mysqli_report(MYSQLI_REPORT_OFF);
$root = dirname(__DIR__, 3);
require_once $root . '/db.php';
// Template and preview storage is a temp folder; the real folders are only read.
require_once $root . '/project-support/tests/_storage.php';
require_once $root . '/api/documents/_registry.php';
$storage = new TestStorage($root);
$storage->assertActive();
$prefix = 'capstone_templates_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$server = null; $admin = null; $scratchDb = null; $officialIds = []; $storedFiles = []; $previewFiles = [];
$literalTemplate = null;
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'template-sessions-' . getmypid();
$router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'template-router-' . getmypid() . '.php';
$prepend = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'template-prepend-' . getmypid() . '.php';
$passed = 0; $failed = 0; $cleanupDone = false;

function template_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n"; }
}
function sql_admin(): mysqli {
    $db = new mysqli('localhost', 'root', '');
    if ($db->connect_error) throw new RuntimeException($db->connect_error);
    $db->set_charset('utf8mb4'); return $db;
}
function database_names(mysqli $db, string $prefix): array {
    $stmt = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE CONCAT(?, "%")');
    $stmt->bind_param('s', $prefix); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return array_map(static fn(array $row): string => (string)$row['SCHEMA_NAME'], $rows);
}
function drop_database(mysqli $db, string $name): void {
    if (preg_match('/^[A-Za-z0-9_]+$/', $name)) $db->query("DROP DATABASE IF EXISTS `$name`");
}
function import_sql(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
}
function row_count(mysqli $db, string $database, string $table): int {
    $result = $db->query("SELECT COUNT(*) AS c FROM `{$database}`.`{$table}`");
    if (!$result) throw new RuntimeException($db->error);
    return (int)$result->fetch_assoc()['c'];
}
function snapshot_templates(string $root): array {
    $rows = [];
    foreach (glob($root . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT) ?: [] as $path) {
        if (is_file($path)) $rows[basename($path)] = (int)filesize($path);
    }
    ksort($rows); return $rows;
}
function fixture_docx(string $path, string $xml, array $extra = []): void {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('fixture open failed');
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', $xml);
    foreach ($extra as $name => $body) $zip->addFromString($name, $body);
    $zip->close();
}
function http_request(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
    $headersRaw = substr((string)$raw, 0, $size); $cookies = '';
    if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headersRaw, $match)) $cookies = $match[1];
    return ['status' => $status, 'headers' => $headersRaw, 'body' => substr((string)$raw, $size), 'cookie' => $cookies, 'type' => $type];
}
function json_body(array $response): array { $json = json_decode($response['body'], true); return is_array($json) ? $json : []; }
function concurrent_requests(array $requests): array {
    $multi = curl_multi_init(); $handles = [];
    foreach ($requests as $request) {
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: ' . $request['cookie']], CURLOPT_POSTFIELDS => $request['body']]);
        curl_multi_add_handle($multi, $ch); $handles[] = $ch;
    }
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 1.0); } while ($running);
    $responses = [];
    foreach ($handles as $ch) { $responses[] = ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string)curl_multi_getcontent($ch)]; curl_multi_remove_handle($multi, $ch); curl_close($ch); }
    curl_multi_close($multi); return $responses;
}
function start_server(string $root, string $sessionPath, string $prepend, string $router, string $database): array {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 45000); $pipes = [];
        $environment = [
            'BARANGAY_TEMPLATE_DB' => $database,
            'BARANGAY_STORAGE_ROOT' => (string)getenv('BARANGAY_STORAGE_ROOT'),
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            'PATH' => getenv('PATH') ?: '',
            'TEMP' => sys_get_temp_dir(),
            'TMP' => sys_get_temp_dir(),
        ];
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', $root],
            [0 => ['pipe','r'], 1 => ['file', $router . '.out','a'], 2 => ['file', $router . '.err','a']], $pipes, $root, $environment);
        if (!is_resource($process)) continue;
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) { fclose($socket); return [$process, 'http://127.0.0.1:' . $port]; }
            usleep(25000);
        }
        proc_terminate($process); proc_close($process);
    }
    throw new RuntimeException('Endpoint server did not start.');
}
function stop_server($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process); if ($state['running']) proc_terminate($process); proc_close($process);
}

$realCounts = [];
$realDatabase = $DB_NAME;
$templateRoot = $storage->templates;
// Every test render leaves one preview PDF; record its exact name for cleanup.
$trackPreview = static function (array $response) use (&$previewFiles): void {
    $token = (string)(json_decode($response['body'] ?? '', true)['preview_token'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $token)) $previewFiles[] = $token . '.pdf';
};
$templateSnapshot = snapshot_templates($templateRoot);
register_shutdown_function(static function () use (&$server, &$admin, &$sessionPath, &$router, &$prepend, $prefix): void {
    if (is_resource($server)) stop_server($server);
    if ($admin instanceof mysqli) {
        foreach (database_names($admin, $prefix) as $name) drop_database($admin, $name);
    }
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath); @unlink($router); @unlink($router . '.out'); @unlink($router . '.err'); @unlink($prepend);
});

try {
    $admin = sql_admin();
    template_check('scratch database differs from real database', $scratch !== $realDatabase);
    foreach (['barangay_officials', 'document_template_versions', 'audit_log'] as $table) $realCounts[$table] = row_count($admin, $realDatabase, $table);
    foreach (database_names($admin, $prefix) as $old) drop_database($admin, $old);
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true)) throw new RuntimeException('session directory failed');
    $schema = file_get_contents($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sql');
    if ($schema === false) throw new RuntimeException('schema unavailable');
    $admin->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $scratchDb = new mysqli('localhost', 'root', '', $scratch); $scratchDb->set_charset('utf8mb4');
    if ($scratchDb->select_db($scratch) === false || $scratchDb->query('SELECT DATABASE()')->fetch_row()[0] !== $scratch) throw new RuntimeException('scratch database assertion failed');
    template_check('scratch database prefix asserted before writes', str_starts_with((string)$scratchDb->query('SELECT DATABASE()')->fetch_row()[0], $prefix));
    import_sql($scratchDb, $schema);
    // Test-only: blank the AI provider key before db.php's load_env() can read
    // it from .env, so the child server can never reach the provider.
    file_put_contents($prepend, "<?php foreach (['GEMINI_API_KEY', 'GEMINI_MODEL'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; } require_once " . var_export($root . '/db.php', true) . "; \$DB_NAME = getenv('BARANGAY_TEMPLATE_DB') ?: \$DB_NAME;");
    file_put_contents($router, "<?php return false;");
    [$server, $base] = start_server($root, $sessionPath, $prepend, $router, $scratch);
    require_once $root . '/api/documents/_templates.php';
    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $uploadError) {
        try {
            document_template_validate_upload(['error' => $uploadError]);
            template_check('server upload limit maps error ' . $uploadError, false);
        } catch (RequestProblem $e) {
            template_check('server upload limit maps error ' . $uploadError, $e->status === 413 && $e->getMessage() === 'The file is larger than the server allows (limit 2 MB).');
        }
    }

    $password = 'Template-test-2026!'; $hash = password_hash($password, PASSWORD_DEFAULT);
    foreach ([['Scratch','Admin','template-admin','admin'], ['Scratch','Staff','template-staff','staff']] as $official) {
        $stmt = $scratchDb->prepare('INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,?,?,?,?,?,?,?,?)');
        $address = 'Purok 1'; $contact = '+639171110099'; $position = 'Tester'; $status = 'active';
        $stmt->bind_param('sssssssss', $official[0], $official[1], $official[2], $official[3], $address, $contact, $hash, $position, $status); $stmt->execute(); $officialIds[] = $stmt->insert_id; $stmt->close();
    }
    $adminLogin = http_request($base . '/pages/login.php', 'POST', ['action'=>'official-login','username'=>'template-admin','password'=>$password]);
    $staffLogin = http_request($base . '/pages/login.php', 'POST', ['action'=>'official-login','username'=>'template-staff','password'=>$password]);
    template_check('admin login uses scratch database', $adminLogin['status'] === 302 && $adminLogin['cookie'] !== '');
    template_check('staff login uses scratch database', $staffLogin['status'] === 302 && $staffLogin['cookie'] !== '');
    $adminCookie = $adminLogin['cookie']; $staffCookie = $staffLogin['cookie'];
    $scratchDb->query("INSERT INTO households (household_code,address,household_size) VALUES ('TPL-" . getmypid() . "','Purok 1',1)");
    $householdId = (int)$scratchDb->insert_id;
    $residentPassword = 'Resident-test-2026!';
    $residentHash = password_hash($residentPassword, PASSWORD_DEFAULT);
    $residentStmt = $scratchDb->prepare('INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id) VALUES (?,?,?,?,?,?,?,?)');
    $residentFname = 'Scratch'; $residentLname = 'Resident'; $residentUsername = 'template-resident'; $residentAddress = 'Purok 1'; $residentContact = '+639171110098'; $residentStatus = 'active';
    $residentStmt->bind_param('sssssssi', $residentFname, $residentLname, $residentUsername, $residentAddress, $residentContact, $residentHash, $residentStatus, $householdId);
    $residentStmt->execute(); $residentStmt->close();
    $residentId = (int)$scratchDb->insert_id;
    $scratchDb->query("UPDATE households SET head_resident_id={$residentId} WHERE id={$householdId}");
    $residentLogin = http_request($base . '/pages/login.php', 'POST', ['action'=>'resident-login','username'=>$residentUsername,'password'=>$residentPassword]);
    $residentCookie = $residentLogin['cookie'];
    $list = http_request($base . '/api/documents/types_list.php', 'GET', null, $adminCookie); $types = json_body($list)['types'] ?? []; $type = $types[0] ?? [];
    template_check('types list returns safe fields and decoded check errors', $list['status'] === 200 && isset($type['fields'], $type['versions'][0]['check_errors']) && array_key_exists('activated_by_name', $type['versions'][0]) && !array_key_exists('file_name', $type['versions'][0]) && !array_key_exists('sha256', $type['versions'][0]) && !array_key_exists('tested_sha256', $type['versions'][0]));
    $pageAdmin = http_request($base . '/pages/official.php', 'GET', null, $adminCookie);
    $pageStaff = http_request($base . '/pages/official.php', 'GET', null, $staffCookie);
    template_check('admin official page contains document templates tab', $pageAdmin['status'] === 200 && str_contains($pageAdmin['body'], 'data-tab="documents"') && str_contains($pageAdmin['body'], 'id="tab-documents"'));
    template_check('staff official page contains document templates tab', $pageStaff['status'] === 200 && str_contains($pageStaff['body'], 'data-tab="documents"') && str_contains($pageStaff['body'], 'id="tab-documents"'));
    $typeId = (int)$type['id']; $activeId = (int)$type['active_version_id']; $source = $templateRoot . DIRECTORY_SEPARATOR . 'barangay_clearance.docx'; $sourceHash = hash_file('sha256', $source);
    require_once $root . '/api/documents/_registry.php'; $oldContext = document_registry_context($scratchDb, 'Barangay Clearance');
    require_once $root . '/api/requests/_render.php';
    $literalTemplate = 'literal_test_' . getmypid() . '.docx';
    copy($source, $templateRoot . DIRECTORY_SEPARATOR . $literalTemplate);
    $literalTemplateZip = new ZipArchive(); $literalTemplateZip->open($templateRoot . DIRECTORY_SEPARATOR . $literalTemplate);
    $literalTemplateXml = (string)$literalTemplateZip->getFromName('word/document.xml');
    $literalTemplateXml = str_replace('</w:body>', '<w:p><w:r><w:t>{{purpose}} {{business_name}}</w:t></w:r></w:p></w:body>', $literalTemplateXml);
    $literalTemplateZip->addFromString('word/document.xml', $literalTemplateXml); $literalTemplateZip->close();
    $literalDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'template-literal-' . getmypid();
    mkdir($literalDir, 0700, true);
    $literalFields = $oldContext['fields'];
    $literalFields[] = ['field_key' => 'business_name'];
    $literalFiles = request_render_document(
        ['document_type' => 'Barangay Clearance', 'reference_no' => 'LITERAL-TEST', 'resident_name' => 'Sample Resident', 'address' => 'Sample Address', 'purpose' => '{{official_name}} {{unknown}}'],
        ['business_name' => '{{official_name}} {{unknown}}'],
        $literalDir,
        'LITERAL-TEST',
        ['type' => ['name' => 'Barangay Clearance', 'template_file' => $literalTemplate], 'fields' => $literalFields, 'captain_name' => 'Scratch Captain']
    );
    $literalZip = new ZipArchive(); $literalZip->open($literalFiles['docx']); $literalXml = (string)$literalZip->getFromName('word/document.xml'); $literalZip->close();
    template_check('literal placeholder-shaped values render unchanged',
        substr_count($literalXml, '{{official_name}}') === 3
        && substr_count($literalXml, '{{unknown}}') === 3
        && str_contains($literalXml, 'Hon. Scratch Captain')
        && str_contains($literalXml, 'Barangay Captain'),
        'official=' . substr_count($literalXml, '{{official_name}}') . ', unknown=' . substr_count($literalXml, '{{unknown}}'));
    remove_dir_tree($literalDir);
    $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'template-endpoint-' . getmypid() . '.docx';
    $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{full_name}} {{purpose}} {{date_issued}} {{reference_no}} {{official_name}} {{official_title}}</w:t></w:r></w:p></w:body></w:document>';
    fixture_docx($fixture, $xml); $filesToUpload = [];
    $upload = static function(string $name, string $path, string $cookie) use ($base, $typeId): array {
        return http_request($base . '/api/documents/template_upload.php', 'POST', ['document_type_id'=>(string)$typeId,'notes'=>$name,'file'=>new CURLFile($path,'application/vnd.openxmlformats-officedocument.wordprocessingml.document',$name)], $cookie);
    };
    $over = tempnam(sys_get_temp_dir(), 'over-'); file_put_contents($over, str_repeat('x', 2097153));
    $overRes = $upload('over.docx', $over, $adminCookie); template_check('upload rejects over 2 MB', $overRes['status'] === 422); @unlink($over);
    $badExt = $upload('bad.pdf', $fixture, $adminCookie); template_check('upload rejects wrong extension', $badExt['status'] === 422);
    $unsafe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unsafe-' . getmypid() . '.docx'; fixture_docx($unsafe, $xml, ['../evil.txt'=>'x']);
    template_check('upload rejects unsafe entry', $upload('../evil.docx', $unsafe, $adminCookie)['status'] === 422);
    $external = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'external-' . getmypid() . '.docx'; fixture_docx($external, $xml, ['word/_rels/document.xml.rels'=>'<Relationships><Relationship Type="http://x/oleObject" Target="https://example.test" TargetMode="External"/></Relationships>']);
    template_check('upload rejects external relationship', $upload('external.docx', $external, $adminCookie)['status'] === 422);
    $bomb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bomb-' . getmypid() . '.docx'; fixture_docx($bomb, $xml, ['word/bomb.txt'=>str_repeat('A', 1048577)]);
    template_check('upload rejects zip-bomb ratio', $upload('bomb.docx', $bomb, $adminCookie)['status'] === 422);
    foreach ([$fixture,$unsafe,$external,$bomb] as $path) @unlink($path);

    $valid = $upload('valid.docx', $source, $adminCookie); $validJson = json_body($valid); $versionId = (int)($validJson['version_id'] ?? 0); $uploadedFile = '';
    if ($versionId > 0) { $row = $scratchDb->query("SELECT file_name FROM document_template_versions WHERE id={$versionId}")->fetch_assoc(); $uploadedFile = (string)$row['file_name']; $storedFiles[] = $uploadedFile; }
    template_check('valid upload and check results', $valid['status'] === 200 && $versionId > 0 && !empty($validJson['check_ok']));

    // Phase 3 analysis endpoints. $versionId is a draft here, $activeId is active.
    $analyzeUrl = $base . '/api/documents/template_analyze.php'; $analysisGetUrl = $base . '/api/documents/template_analysis_get.php';
    $analyzeBody = static fn(int $id, mixed $ack = true): string => (string)json_encode(['version_id' => $id, 'acknowledge_external_processing' => $ack]);
    $analyzeNoSession = http_request($analyzeUrl, 'POST', $analyzeBody($versionId));
    template_check('analyze returns 401 without session', $analyzeNoSession['status'] === 401);
    // First authenticated analyze call: proves the child server has no AI key,
    // so no later call in this suite can reach the provider.
    $analyzeOff = http_request($analyzeUrl, 'POST', $analyzeBody($versionId), $adminCookie);
    template_check('analyze returns 503 when AI is not configured (child server key is empty)', $analyzeOff['status'] === 503 && (json_body($analyzeOff)['message'] ?? '') === 'AI analysis is not configured. Mark placeholders manually in Word.', $analyzeOff['status'] . ' ' . $analyzeOff['body']);
    template_check('analyze is open to staff (503, not 403)', http_request($analyzeUrl, 'POST', $analyzeBody($versionId), $staffCookie)['status'] === 503);
    $ackStatuses = [];
    foreach ([json_encode(['version_id' => $versionId]), $analyzeBody($versionId, false), $analyzeBody($versionId, 'true'), $analyzeBody($versionId, 1)] as $noAckBody) {
        $noAck = http_request($analyzeUrl, 'POST', $noAckBody, $adminCookie);
        $ackStatuses[] = $noAck['status'] . ':' . (json_body($noAck)['message'] ?? '');
    }
    template_check('analyze returns 422 without strict acknowledgement', array_unique($ackStatuses) === ['422:Confirm that the document text may be sent to the AI provider.'], json_encode($ackStatuses));
    template_check('analyze returns 409 for a non-draft version', http_request($analyzeUrl, 'POST', $analyzeBody($activeId), $adminCookie)['status'] === 409);
    template_check('analyze returns 404 for an unknown version', http_request($analyzeUrl, 'POST', $analyzeBody(999999), $adminCookie)['status'] === 404);
    template_check('analyze is POST only', http_request($analyzeUrl, 'GET', null, $adminCookie)['status'] === 405);
    template_check('analysis_get returns 401 without session', http_request($analysisGetUrl . '?version_id=' . $versionId)['status'] === 401);
    $noAnalysis = http_request($analysisGetUrl . '?version_id=' . $versionId, 'GET', null, $adminCookie);
    template_check('analysis_get returns 404 with code no_analysis when none exists', $noAnalysis['status'] === 404
        && json_body($noAnalysis) === ['success' => false, 'message' => 'No analysis exists for this version.', 'code' => 'no_analysis'], $noAnalysis['body']);
    $unknownVersion = http_request($analysisGetUrl . '?version_id=999999', 'GET', null, $adminCookie);
    template_check('analysis_get returns 404 without a code for an unknown version', $unknownVersion['status'] === 404 && !array_key_exists('code', json_body($unknownVersion)), $unknownVersion['body']);
    template_check('refused analyze calls store nothing and consume no AI budget',
        row_count($admin, $scratch, 'document_template_analyses') === 0
        && (int)$scratchDb->query("SELECT COUNT(*) c FROM ai_request_log WHERE endpoint='template_analyze'")->fetch_assoc()['c'] === 0
        && (int)$scratchDb->query("SELECT COUNT(*) c FROM audit_log WHERE action='Analyzed template with AI'")->fetch_assoc()['c'] === 0);

    // Phase 4A apply endpoint. Analysis rows are inserted directly (no AI call).
    $applyUrl = $base . '/api/documents/template_apply_suggestions.php';
    $insertAnalysis = static function (int $id, string $fileHash) use ($scratchDb): int {
        $suggestions = json_encode([['paragraph' => 4, 'start' => 0, 'text' => 'OFFICE', 'occurrence' => 1, 'key' => 'full_name', 'reason' => 'test', 'confidence' => 0.9]]);
        $stmt = $scratchDb->prepare("INSERT INTO document_template_analyses (version_id, file_sha256, status, model, paragraph_count, suggestions, dropped_count) VALUES (?, ?, 'completed', 'fake-model', 12, ?, 0)");
        $stmt->bind_param('iss', $id, $fileHash, $suggestions); $stmt->execute(); $newId = (int)$stmt->insert_id; $stmt->close();
        return $newId;
    };
    $draftAnalysis = $insertAnalysis($versionId, (string)hash_file('sha256', $templateRoot . DIRECTORY_SEPARATOR . $uploadedFile));
    $activeAnalysis = $insertAnalysis($activeId, (string)$sourceHash);
    $applyBody = static fn(int $version, int $analysis, array $selections = [['index' => 0]]): string => (string)json_encode(['version_id' => $version, 'analysis_id' => $analysis, 'selections' => $selections]);
    $versionsBefore = row_count($admin, $scratch, 'document_template_versions'); $applyDirBefore = snapshot_templates($templateRoot);
    template_check('apply returns 401 without session', http_request($applyUrl, 'POST', $applyBody($versionId, $draftAnalysis))['status'] === 401);
    template_check('apply is POST only (405)', http_request($applyUrl, 'GET', null, $adminCookie)['status'] === 405);
    $malformed = [];
    foreach (['{}', 'not json', json_encode(['version_id' => (string)$versionId, 'analysis_id' => $draftAnalysis, 'selections' => [['index' => 0]]]), json_encode(['version_id' => $versionId, 'analysis_id' => $draftAnalysis]), json_encode(['version_id' => $versionId, 'analysis_id' => 0, 'selections' => []])] as $badBody) {
        $malformed[] = http_request($applyUrl, 'POST', (string)$badBody, $adminCookie)['status'];
    }
    template_check('apply returns 422 for a malformed body', array_unique($malformed) === [422], json_encode($malformed));
    template_check('apply returns 404 for an unknown analysis', http_request($applyUrl, 'POST', $applyBody($versionId, 999999), $adminCookie)['status'] === 404);
    template_check('apply returns 404 for an unknown version', http_request($applyUrl, 'POST', $applyBody(999999, $draftAnalysis), $adminCookie)['status'] === 404);
    template_check('apply returns 404 for an analysis of another version', http_request($applyUrl, 'POST', $applyBody($versionId, $activeAnalysis), $adminCookie)['status'] === 404);
    template_check('apply returns 409 for a non-draft version', http_request($applyUrl, 'POST', $applyBody($activeId, $activeAnalysis), $adminCookie)['status'] === 409);
    $badKey = http_request($applyUrl, 'POST', $applyBody($versionId, $draftAnalysis, [['index' => 0, 'key' => 'admin_password']]), $adminCookie);
    template_check('apply returns 422 for a key not in the registry', $badKey['status'] === 422 && (json_body($badKey)['message'] ?? '') === 'A selected placeholder is not registered for this document type.', $badKey['body']);
    template_check('refused apply calls create no version and no file', row_count($admin, $scratch, 'document_template_versions') === $versionsBefore && snapshot_templates($templateRoot) === $applyDirBefore);
    $staffApply = http_request($applyUrl, 'POST', $applyBody($versionId, $draftAnalysis), $staffCookie); $staffApplyJson = json_body($staffApply);
    $appliedId = (int)($staffApplyJson['new_version_id'] ?? 0);
    if ($appliedId > 0) $storedFiles[] = (string)$scratchDb->query("SELECT file_name FROM document_template_versions WHERE id={$appliedId}")->fetch_assoc()['file_name'];
    template_check('staff may apply suggestions into a new draft', $staffApply['status'] === 200 && $appliedId > 0 && ($staffApplyJson['applied'] ?? 0) === 1
        && array_keys($staffApplyJson) === ['success', 'new_version_id', 'version_no', 'applied', 'skipped', 'check_ok', 'check_errors']
        && row_count($admin, $scratch, 'document_template_versions') === $versionsBefore + 1, $staffApply['body']);
    template_check('apply response holds no file name or hash', !preg_match('/tpl_|\.docx|[a-f0-9]{64}/', $staffApply['body']));
    template_check('apply leaves the source draft file unchanged', hash_file('sha256', $templateRoot . DIRECTORY_SEPARATOR . $uploadedFile) === hash('sha256', (string)file_get_contents($source)));

    $requiredFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'required-' . getmypid() . '.docx';
    fixture_docx($requiredFixture, '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{full_name}}</w:t></w:r></w:p></w:body></w:document>');
    $requiredRes = $upload('required.docx', $requiredFixture, $adminCookie);
    $requiredId = (int)(json_body($requiredRes)['version_id'] ?? 0);
    if ($requiredId > 0) $storedFiles[] = (string)$scratchDb->query("SELECT file_name FROM document_template_versions WHERE id={$requiredId}")->fetch_assoc()['file_name'];
    template_check('missing required key blocks activation', $requiredRes['status'] === 200 && empty(json_body($requiredRes)['check_ok']));
    $optionalFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'optional-' . getmypid() . '.docx';
    fixture_docx($optionalFixture, '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{full_name}} {{address}} {{purpose}} {{date_issued}} {{reference_no}} {{official_name}} {{official_title}}</w:t></w:r></w:p></w:body></w:document>');
    $optionalRes = $upload('optional.docx', $optionalFixture, $adminCookie);
    $optionalId = (int)(json_body($optionalRes)['version_id'] ?? 0);
    if ($optionalId > 0) $storedFiles[] = (string)$scratchDb->query("SELECT file_name FROM document_template_versions WHERE id={$optionalId}")->fetch_assoc()['file_name'];
    template_check('missing optional key remains valid', $optionalRes['status'] === 200 && !empty(json_body($optionalRes)['check_ok']));
    @unlink($requiredFixture); @unlink($optionalFixture);
    $testBefore = http_request($base . '/api/documents/template_activate.php', 'POST', json_encode(['version_id'=>$versionId,'expected_active_version_id'=>$activeId]), $adminCookie);
    template_check('activation refused before test render', $testBefore['status'] === 422);
    $render = http_request($base . '/api/documents/template_test_render.php', 'POST', json_encode(['version_id'=>$versionId]), $adminCookie); $renderJson = json_body($render); $token = (string)($renderJson['preview_token'] ?? '');
    if ($token !== '') $previewFiles[] = $token . '.pdf';
    template_check('one real LibreOffice test render', $render['status'] === 200 && preg_match('/^[a-f0-9]{32}$/', $token) === 1);
    $download = http_request($base . '/api/documents/template_download.php?version_id=' . $versionId, 'GET', null, $adminCookie);
    template_check('download exact stored bytes and audit', $download['status'] === 200 && hash('sha256', $download['body']) === hash_file('sha256', $templateRoot . DIRECTORY_SEPARATOR . $storedFiles[0]));
    template_check('preview valid token only', http_request($base . '/api/documents/template_preview.php?token=' . $token, 'GET', null, $adminCookie)['status'] === 200 && http_request($base . '/api/documents/template_preview.php?token=bad', 'GET', null, $adminCookie)['status'] === 404);
    $beforeAlter = $scratchDb->query("SELECT sha256 FROM document_template_versions WHERE id={$versionId}")->fetch_assoc()['sha256']; $scratchDb->query("UPDATE document_template_versions SET sha256='bad' WHERE id={$versionId}");
    $altered = http_request($base . '/api/documents/template_activate.php', 'POST', json_encode(['version_id'=>$versionId,'expected_active_version_id'=>$activeId]), $adminCookie); template_check('altered checksum refused', $altered['status'] === 422);
    $scratchDb->query("UPDATE document_template_versions SET sha256='" . $scratchDb->real_escape_string($beforeAlter) . "' WHERE id={$versionId}");
    $activate = http_request($base . '/api/documents/template_activate.php', 'POST', json_encode(['version_id'=>$versionId,'expected_active_version_id'=>$activeId]), $adminCookie); template_check('activation after render succeeds', $activate['status'] === 200);
    template_check('resolved old context and seeded file remain stable', ($oldContext['type']['template_file'] ?? '') !== $uploadedFile && is_file($templateRoot . DIRECTORY_SEPARATOR . $oldContext['type']['template_file']) && hash_file('sha256', $source) === $sourceHash);
    $activeCount = (int)$scratchDb->query("SELECT COUNT(*) c FROM document_template_versions WHERE status='active' AND document_type_id={$typeId}")->fetch_assoc()['c']; template_check('one active version enforced', $activeCount === 1);
    $seededBefore = $scratchDb->query("SELECT test_ok, tested_sha256 FROM document_template_versions WHERE id={$activeId}")->fetch_assoc();
    $rollbackTest = http_request($base . '/api/documents/template_test_render.php', 'POST', json_encode(['version_id'=>$activeId]), $adminCookie);
    $trackPreview($rollbackTest);
    $seededAfter = $scratchDb->query("SELECT test_ok, tested_sha256 FROM document_template_versions WHERE id={$activeId}")->fetch_assoc();
    $rollback = http_request($base . '/api/documents/template_activate.php', 'POST', json_encode(['version_id'=>$activeId,'expected_active_version_id'=>$versionId]), $adminCookie);
    template_check('seeded version 1 activates after exactly one test render', $seededBefore['tested_sha256'] === null && $rollbackTest['status'] === 200 && (int)$seededAfter['test_ok'] === 1 && $seededAfter['tested_sha256'] !== null && $rollback['status'] === 200, json_encode(['before' => $seededBefore, 'render' => $rollbackTest['status'], 'after' => $seededAfter, 'activate' => $rollback['status']]));
    $staffUpload = $upload('staff.docx', $source, $staffCookie); $staffVersion = (int)(json_body($staffUpload)['version_id'] ?? 0); if ($staffVersion) { $row=$scratchDb->query("SELECT file_name FROM document_template_versions WHERE id={$staffVersion}")->fetch_assoc(); $storedFiles[]=$row['file_name']; }
    template_check('staff upload allowed', $staffUpload['status'] === 200);
    $staffTest = http_request($base . '/api/documents/template_test_render.php', 'POST', json_encode(['version_id'=>$staffVersion]), $staffCookie); $trackPreview($staffTest); template_check('staff test allowed', $staffTest['status'] === 200);
    $freshActiveId = (int)$scratchDb->query("SELECT id FROM document_template_versions WHERE document_type_id={$typeId} AND status='active'")->fetch_assoc()['id'];
    $staffActivate = http_request($base . '/api/documents/template_activate.php', 'POST', json_encode(['version_id'=>$staffVersion,'expected_active_version_id'=>$freshActiveId]), $staffCookie); template_check('staff activate allowed', $staffActivate['status'] === 200);
    $raceOne = $upload('race-one.docx', $source, $adminCookie); $raceTwo = $upload('race-two.docx', $source, $adminCookie);
    $raceOneId = (int)(json_body($raceOne)['version_id'] ?? 0); $raceTwoId = (int)(json_body($raceTwo)['version_id'] ?? 0);
    foreach ([$raceOneId, $raceTwoId] as $raceId) {
        if ($raceId > 0) { $row = $scratchDb->query("SELECT file_name FROM document_template_versions WHERE id={$raceId}")->fetch_assoc(); $storedFiles[] = $row['file_name']; }
        $trackPreview(http_request($base . '/api/documents/template_test_render.php', 'POST', json_encode(['version_id'=>$raceId]), $adminCookie));
    }
    $raceExpected = (int)$scratchDb->query("SELECT id FROM document_template_versions WHERE document_type_id={$typeId} AND status='active'")->fetch_assoc()['id'];
    $race = concurrent_requests([
        ['url'=>$base . '/api/documents/template_activate.php', 'cookie'=>$adminCookie, 'body'=>json_encode(['version_id'=>$raceOneId,'expected_active_version_id'=>$raceExpected])],
        ['url'=>$base . '/api/documents/template_activate.php', 'cookie'=>$adminCookie, 'body'=>json_encode(['version_id'=>$raceTwoId,'expected_active_version_id'=>$raceExpected])],
    ]);
    $raceStatuses = array_map(static fn(array $response): int => $response['status'], $race);
    sort($raceStatuses); template_check('concurrent activations have one winner and one conflict', $raceStatuses === [200, 409]);
    $discardUpload = $upload('discard.docx', $source, $adminCookie); $discardId = (int)(json_body($discardUpload)['version_id'] ?? 0);
    if ($discardId > 0) { $row = $scratchDb->query("SELECT file_name FROM document_template_versions WHERE id={$discardId}")->fetch_assoc(); $storedFiles[] = $row['file_name']; }
    $discard = http_request($base . '/api/documents/template_discard.php', 'POST', json_encode(['version_id'=>$discardId]), $adminCookie);
    $nonDraftDiscard = http_request($base . '/api/documents/template_discard.php', 'POST', json_encode(['version_id'=>$freshActiveId]), $adminCookie);
    template_check('draft discard succeeds', $discard['status'] === 200);
    template_check('non-draft discard returns conflict', $nonDraftDiscard['status'] === 409);
    foreach (['/api/documents/types_list.php','/api/documents/template_test_render.php','/api/documents/template_download.php','/api/documents/template_activate.php','/api/documents/template_discard.php','/api/documents/type_archive.php','/api/documents/template_analyze.php','/api/documents/template_analysis_get.php','/api/documents/template_apply_suggestions.php'] as $path) {
        $res = http_request($base . $path, 'POST', '{}'); template_check('no session ' . basename($path), $res['status'] === 401);
    }
    template_check('anonymous create remains 401', http_request($base . '/api/requests/create.php', 'POST', json_encode(['document_type'=>'Barangay Clearance','purpose'=>'test']))['status'] === 401);
    $pendingPurpose = 'Existing pending request';
    $pendingCreate = http_request($base . '/api/requests/create.php', 'POST', json_encode(['document_type'=>'Barangay Clearance','purpose'=>$pendingPurpose]), $residentCookie);
    $pendingRef = (string)(json_body($pendingCreate)['reference_no'] ?? '');
    $pendingRow = $pendingRef === '' ? [] : ($scratchDb->query("SELECT * FROM document_requests WHERE reference_no='" . $scratchDb->real_escape_string($pendingRef) . "'")->fetch_assoc() ?: []);
    $archive = http_request($base . '/api/documents/type_archive.php', 'POST', json_encode(['document_type_id'=>$typeId,'archived'=>true]), $adminCookie); $staffArchive = http_request($base . '/api/documents/type_archive.php', 'POST', json_encode(['document_type_id'=>$typeId,'archived'=>true]), $staffCookie); $repeatArchive = http_request($base . '/api/documents/type_archive.php', 'POST', json_encode(['document_type_id'=>$typeId,'archived'=>true]), $adminCookie);
    template_check('archive role and repeat guard', $archive['status']===200 && $staffArchive['status']===403 && $repeatArchive['status']===409);
    $pendingDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'template-pending-' . getmypid(); mkdir($pendingDir, 0700, true);
    $archivedContext = null; $archivedContextError = '';
    try { $archivedContext = document_registry_context($scratchDb, 'Barangay Clearance'); }
    catch (Throwable $e) { $archivedContextError = $e->getMessage(); }
    $pendingFiles = $archivedContext === null ? [] : request_render_document($pendingRow, [], $pendingDir, 'PENDING-TEST', $archivedContext);
    template_check('archived type context remains available', $archivedContext !== null && ($archivedContext['type']['status'] ?? '') === 'archived', $archivedContextError);
    template_check('archived type existing pending request still generates', $archive['status'] === 200 && $pendingCreate['status'] === 200 && $pendingRow['status'] === 'pending' && is_file($pendingFiles['docx'] ?? '') && is_file($pendingFiles['pdf'] ?? ''));
    remove_dir_tree($pendingDir);
    template_check('archived create rejected', $residentLogin['status'] === 302 && http_request($base . '/api/requests/create.php','POST',json_encode(['document_type'=>'Barangay Clearance','purpose'=>'test']),$residentCookie)['status']===422);
    $legacy = $scratchDb->query("SELECT template_file FROM document_types WHERE id={$typeId}")->fetch_assoc()['template_file'];
    // The analyses table references versions; it must go first or the drop below is refused.
    // Phase 7: complaint_documents (fk_cdoc_version) references versions too; a pre-Phase-2 install has neither.
    $scratchDb->query('DROP TABLE document_template_analyses'); $scratchDb->query('DROP TABLE complaint_documents'); $scratchDb->query('DROP TABLE document_template_versions');
    template_check('versions table is really gone before the fallback check', (int)$scratchDb->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'document_template_versions'")->fetch_assoc()['c'] === 0);
    require_once $root . '/api/documents/_registry.php'; $ctx = document_registry_context($scratchDb, 'Barangay Clearance'); template_check('generation fallback without versions table', $ctx['type']['template_file'] === $legacy);
} finally {
    if (is_resource($server)) stop_server($server);
    foreach ($previewFiles as $file) @unlink($storage->previews . DIRECTORY_SEPARATOR . $file);
    foreach ($storedFiles as $file) @unlink($templateRoot . DIRECTORY_SEPARATOR . $file);
    foreach ($officialIds as $id) { if ($scratchDb) { $stmt=$scratchDb->prepare('DELETE FROM audit_log WHERE official_id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); $stmt=$scratchDb->prepare('DELETE FROM barangay_officials WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); } }
    if ($admin) { foreach (database_names($admin, $prefix) as $name) drop_database($admin, $name); }
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath); @unlink($router); @unlink($router . '.out'); @unlink($router . '.err'); @unlink($prepend); @unlink($fixture ?? ''); @unlink($templateRoot . DIRECTORY_SEPARATOR . $literalTemplate);
    $afterFiles = snapshot_templates($templateRoot); template_check('template directory unchanged', $afterFiles === $templateSnapshot);
    if ($admin) foreach ($realCounts as $table => $count) template_check('real row count unchanged ' . $table, row_count($admin, $realDatabase, $table) === $count);
}
echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
