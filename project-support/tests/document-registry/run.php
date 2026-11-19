<?php
/**
 * BarangayLink - Document registry integration tests.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__, 3) . '/api/requests/_render.php';
require dirname(__DIR__, 3) . '/api/documents/_template_check.php';
require_once dirname(__DIR__, 3) . '/api/documents/_registry.php';
require_once dirname(__DIR__, 3) . '/project-support/tests/_storage.php';

mysqli_report(MYSQLI_REPORT_OFF);
$root = dirname(__DIR__, 3);
// Template storage is a temp folder; the real document_templates/ is only read.
$storage = new TestStorage($root);
$storage->assertActive();
$prefix = 'capstone_registry_';
$freshName = $prefix . getmypid() . '_fresh';
$upgradeName = $prefix . getmypid() . '_upgrade';
$admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
$realApp = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$passed = 0; $failed = 0; $server = null; $serverPipes = null;
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'registry-sessions-' . getmypid();
$router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'registry-router-' . getmypid() . '.php';
$prepend = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'registry-prepend-' . getmypid() . '.php';

function check_registry(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n"; }
}
function import_registry_sql(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do {
        if ($result = $db->store_result()) $result->free();
        if ($db->errno) throw new RuntimeException($db->error);
    } while ($db->more_results() && $db->next_result());
}
function registry_docx(string $path, string $xml): void {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create fixture.');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
}
function registry_rows(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return $result->fetch_all(MYSQLI_ASSOC);
}
function registry_database_exists(mysqli $db, string $name): bool {
    $stmt = $db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $name); $stmt->execute();
    $result = $stmt->get_result(); $exists = (bool)$result->fetch_row(); $stmt->close();
    return $exists;
}
function registry_drop(mysqli $db, string $name): void {
    if (preg_match('/^[A-Za-z0-9_]+$/', $name)) $db->query("DROP DATABASE IF EXISTS `$name`");
}
function registry_cleanup_prefix(mysqli $db, string $prefix): void {
    $stmt = $db->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE CONCAT(?, '%')");
    $stmt->bind_param('s', $prefix); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    foreach ($rows as $row) registry_drop($db, (string)$row['SCHEMA_NAME']);
}
function registry_schema_without_registry(string $schema): array {
    $pattern = '/-- ----------------------------------------------------------------------------\r?\n-- document registry\r?\n-- ----------------------------------------------------------------------------\r?\n.*?(?=-- ----------------------------------------------------------------------------\r?\n-- complaints)/s';
    $stripped = preg_replace($pattern, '', $schema, 1, $count);
    if ($count !== 1 || $stripped === null) throw new RuntimeException('Registry section was not removed.');
    return [$stripped, substr_count($schema, '-- document registry')];
}
function registry_http(string $url, string $method = 'GET', ?string $body = null, array $headers = [], ?string $cookie = null): array {
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $status = 0; $cookies = [];
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) $status = (int)$match[1];
        if (stripos($header, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^;]+)/i', $header, $match)) $cookies[] = $match[1];
    }
    return ['status' => $status, 'body' => (string)$response, 'cookie' => implode('; ', $cookies)];
}
function registry_start_server(string $root, string $dbName, string $sessionPath, string $prepend, string $router): array {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = random_int(20000, 45000);
        $cmd = [PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', $root];
        $pipes = [];
        $environment = [
            'BARANGAY_REGISTRY_DB' => $dbName,
            'BARANGAY_STORAGE_ROOT' => (string)getenv('BARANGAY_STORAGE_ROOT'),
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            'PATH' => getenv('PATH') ?: '',
            'TEMP' => sys_get_temp_dir(),
            'TMP' => sys_get_temp_dir(),
        ];
        $process = proc_open($cmd, [0 => ['pipe','r'], 1 => ['file', $router . '.out','a'], 2 => ['file', $router . '.err','a']], $pipes, $root, $environment);
        if (!is_resource($process)) continue;
        for ($i = 0; $i < 40; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($probe)) { fclose($probe); return [$process, $port]; }
            usleep(25000);
        }
        registry_stop_server($process);
    }
    $error = @file_get_contents($router . '.err');
    throw new RuntimeException('Endpoint server did not start.' . ($error ? ' ' . trim($error) : ''));
}
function registry_stop_server($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process);
    if ($state['running']) proc_terminate($process);
    proc_close($process);
}

try {
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true)) throw new RuntimeException('Cannot create session directory.');
    file_put_contents($prepend, "<?php require_once " . var_export($root . "/db.php", true) . "; \$DB_NAME = getenv('BARANGAY_REGISTRY_DB') ?: \$DB_NAME;");
    file_put_contents($router, "<?php return false;");
    $schema = file_get_contents($root . '/database/database.sql');
    $migrations = [];
    foreach (glob($root . '/project-support/migrations/202611*.sql') ?: [] as $migrationPath) {
        $migration = file_get_contents($migrationPath);
        if ($migration === false) throw new RuntimeException('Migration unavailable: ' . basename($migrationPath));
        $migrations[basename($migrationPath)] = $migration;
    }
    ksort($migrations, SORT_STRING);
    $requiredMigrations = [
        '20261108_document_registry.sql',
        '20261109_document_template_versions.sql',
        '20261110_official_title_field.sql',
    ];
    if ($schema === false || array_diff($requiredMigrations, array_keys($migrations))) {
        throw new RuntimeException('Schema or required migrations unavailable.');
    }
    registry_cleanup_prefix($admin, $prefix);

    [$schemaWithoutRegistry, $registryMarkerCount] = registry_schema_without_registry($schema);
    check_registry('registry strip removed exactly one section', $registryMarkerCount === 1 && substr_count($schemaWithoutRegistry, '-- document registry') === 0);
    $admin->query("CREATE DATABASE `$freshName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $fresh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $freshName); $fresh->set_charset('utf8mb4');
    // database.sql never selects a database; refuse to import one that does.
    if (preg_match('/^\s*(USE\s|CREATE\s+DATABASE)/mi', $schema) === 1) throw new RuntimeException('database.sql selects or creates a database.');
    import_registry_sql($fresh, $schema);
    $admin->query("CREATE DATABASE `$upgradeName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $upgrade = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $upgradeName); $upgrade->set_charset('utf8mb4');
    import_registry_sql($upgrade, $schemaWithoutRegistry);
    $initialTables = [
        registry_database_exists($upgrade, 'document_types'),
        registry_database_exists($upgrade, 'document_type_fields'),
        registry_database_exists($upgrade, 'document_settings'),
    ];
    check_registry('upgrade database initially has no registry tables',
        !$initialTables[0] && !$initialTables[1] && !$initialTables[2],
        implode(',', array_map(static fn(bool $exists): string => $exists ? 'present' : 'absent', $initialTables)));
    foreach ($migrations as $migration) import_registry_sql($upgrade, $migration);
    check_registry('first migration creates all registry tables',
        registry_database_exists($upgrade, 'document_types') && registry_database_exists($upgrade, 'document_type_fields') && registry_database_exists($upgrade, 'document_settings'));
    foreach ($migrations as $migration) import_registry_sql($upgrade, $migration);
    $freshTypes = registry_rows($fresh, 'SELECT name,template_file,status,sort_order FROM document_types ORDER BY sort_order,id');
    $upgradeTypes = registry_rows($upgrade, 'SELECT name,template_file,status,sort_order FROM document_types ORDER BY sort_order,id');
    $tupleSql = 'SELECT dt.name, f.field_key, f.source, f.data_type, f.min_value, f.max_value, f.max_length, f.sort_order FROM document_types dt JOIN document_type_fields f ON f.document_type_id=dt.id ORDER BY dt.sort_order,dt.id,f.sort_order,f.id';
    $freshTuples = registry_rows($fresh, $tupleSql); $upgradeTuples = registry_rows($upgrade, $tupleSql);
    $freshSettings = registry_rows($fresh, 'SELECT setting_key,setting_value FROM document_settings ORDER BY setting_key');
    $upgradeSettings = registry_rows($upgrade, 'SELECT setting_key,setting_value FROM document_settings ORDER BY setting_key');
    // Phase 7: the 40 fields of the five resident types; the internal Summons is checked on its own below.
    $residentFieldSql = "SELECT COUNT(*) c FROM document_type_fields f JOIN document_types dt ON dt.id=f.document_type_id WHERE dt.audience='resident'";
    $freshFieldCount = (int)registry_rows($fresh, $residentFieldSql)[0]['c'];
    $upgradeFieldCount = (int)registry_rows($upgrade, $residentFieldSql)[0]['c'];
    check_registry('both installs contain 40 registry fields', $freshFieldCount === 40 && $upgradeFieldCount === 40);
    $summonsFieldSql = "SELECT COUNT(*) c FROM document_type_fields f JOIN document_types dt ON dt.id=f.document_type_id WHERE dt.code='summons' AND dt.audience='internal' AND f.source='system' AND f.is_required=1";
    check_registry('both installs: internal Summons type with 13 system fields (Phase 7)',
        (int)registry_rows($fresh, $summonsFieldSql)[0]['c'] === 13 && (int)registry_rows($upgrade, $summonsFieldSql)[0]['c'] === 13);
    check_registry('upgrade tuples equal fresh-install tuples', $freshTypes === $upgradeTypes && $freshTuples === $upgradeTuples && $freshSettings === $upgradeSettings);
    $beforeSecond = [$upgradeTypes, $upgradeTuples, $upgradeSettings];
    foreach ($migrations as $migration) import_registry_sql($upgrade, $migration);
    check_registry('second migration changes nothing',
        $beforeSecond === [
            registry_rows($upgrade, 'SELECT name,template_file,status,sort_order FROM document_types ORDER BY sort_order,id'),
            registry_rows($upgrade, $tupleSql),
            registry_rows($upgrade, 'SELECT setting_key,setting_value FROM document_settings ORDER BY setting_key'),
        ]);

    $hash = password_hash('Registry-pass-2026', PASSWORD_DEFAULT);
    foreach ([['Registry','Admin','registry-admin','admin','Captain'], ['Registry','Staff','registry-staff','staff','Clerk']] as $i => $official) {
        $stmt = $upgrade->prepare('INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,?,?,?,?,?,?,?,?)');
        // Contact numbers are unique per table (uq_officials_contact_key): one number each.
        $address = 'Purok 1'; $contact = '+63917111000' . ($i + 1); $status = 'active';
        $stmt->bind_param('sssssssss', $official[0], $official[1], $official[2], $official[3], $address, $contact, $hash, $official[4], $status);
        $stmt->execute(); $stmt->close();
    }
    $auditBefore = (int)registry_rows($realApp, 'SELECT COUNT(*) c FROM audit_log')[0]['c'];
    $serverParts = registry_start_server($root, $upgradeName, $sessionPath, $prepend, $router);
    $server = $serverParts[0]; $base = 'http://127.0.0.1:' . $serverParts[1];
    $login = static function (string $username) use ($base): array {
        return registry_http($base . '/pages/login.php', 'POST',
            http_build_query(['action' => 'official-login', 'username' => $username, 'password' => 'Registry-pass-2026']),
            ['Content-Type: application/x-www-form-urlencoded']);
    };
    $adminLogin = $login('registry-admin'); $staffLogin = $login('registry-staff');
    $adminUpdate = registry_http($base . '/api/settings/signatory_update.php', 'POST', json_encode(['captain_name' => 'Registry Captain']), ['Content-Type: application/json'], $adminLogin['cookie']);
    $staffUpdate = registry_http($base . '/api/settings/signatory_update.php', 'POST', json_encode(['captain_name' => 'Staff Captain']), ['Content-Type: application/json'], $staffLogin['cookie']);
    $noSession = registry_http($base . '/api/settings/signatory_update.php', 'POST', json_encode(['captain_name' => 'No Session']), ['Content-Type: application/json']);
    $staffGet = registry_http($base . '/api/settings/signatory_get.php', 'GET', null, [], $staffLogin['cookie']);
    $title = registry_http($base . '/api/settings/signatory_update.php', 'POST', json_encode(['captain_name' => 'Hon. Registry Captain']), ['Content-Type: application/json'], $adminLogin['cookie']);
    $auditAfter = (int)registry_rows($realApp, 'SELECT COUNT(*) c FROM audit_log')[0]['c'];
    check_registry('admin signatory update returns 200 and audit row', $adminLogin['status'] === 302 && $adminUpdate['status'] === 200 && (int)registry_rows($upgrade, "SELECT COUNT(*) c FROM audit_log WHERE target_reference='captain_name'")[0]['c'] === 1);
    check_registry('staff signatory update returns 403', $staffUpdate['status'] === 403);
    check_registry('no-session signatory update returns 401', $noSession['status'] === 401);
    check_registry('staff signatory GET returns 200', $staffGet['status'] === 200);
    check_registry('signatory title returns 422', $title['status'] === 422);
    check_registry('real app audit log count is unchanged', $auditBefore === $auditAfter);

    check_registry('registry preserves exact case-sensitive type matching', document_registry_type($fresh, 'Barangay Clearance') !== null && document_registry_type($fresh, 'barangay clearance') === null);
    foreach (LEGACY_DOCUMENT_MAP as $type => $legacy) {
        $row = document_registry_type($fresh, $type);
        $keys = array_values(array_filter($legacy['placeholders'], static fn(string $key): bool => $key !== 'official_position'));
        $fields = document_registry_fields($fresh, (int)$row['id']);
        $expected = [];
        foreach ($keys as $key) {
            $rules = LEGACY_RESIDENT_FIELDS[$type][$key] ?? ['data_type' => 'text'];
            $source = isset(LEGACY_RESIDENT_FIELDS[$type][$key]) ? 'resident_supplied' : (in_array($key, ['full_name','address','owner_name','business_address'], true) ? 'resident_record' : (in_array($key, ['reference_no','official_name','official_title'], true) ? 'system' : 'staff_entered'));
            $expected[] = ['field_key' => $key, 'source' => $source, 'data_type' => $rules['data_type'], 'min_value' => $rules['min_value'] ?? null, 'max_value' => $rules['max_value'] ?? null, 'max_length' => $rules['max_length'] ?? null];
        }
        $actual = array_map(static fn(array $field): array => ['field_key' => $field['field_key'], 'source' => $field['source'], 'data_type' => $field['data_type'], 'min_value' => $field['min_value'] === null ? null : (int)$field['min_value'], 'max_value' => $field['max_value'] === null ? null : (int)$field['max_value'], 'max_length' => $field['max_length'] === null ? null : (int)$field['max_length']], $fields);
        $parityDetail = '';
        if ($expected !== $actual) {
            $limit = max(count($expected), count($actual));
            for ($index = 0; $index < $limit; $index++) {
                $expectedField = $expected[$index] ?? null;
                $actualField = $actual[$index] ?? null;
                if ($expectedField !== $actualField) {
                    $parityDetail = 'first_diff_index=' . $index
                        . ' expected=' . json_encode($expectedField, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        . ' actual=' . json_encode($actualField, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    break;
                }
            }
        }
        check_registry("parity {$type}", $expected === $actual, $parityDetail);
    }
    check_registry('ISO date is formatted', request_render_date('2026-11-07') === 'November 07, 2026');
    check_registry('invalid date is preserved', request_render_date('2026-02-30') === '2026-02-30');
    check_registry('address suffix is removed once', request_render_address('Purok 1, Brgy. Sampaguita') === 'Purok 1');
    check_registry('address suffix without comma is removed', request_render_address('Purok 1 Barangay Sampaguita.') === 'Purok 1');
    check_registry('plain address is preserved', request_render_address('Purok 1') === 'Purok 1');
    $signatoryDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'registry-signatory-' . bin2hex(random_bytes(5));
    mkdir($signatoryDir, 0700, true);
    try {
        $rendered = request_render_document(
            ['document_type' => 'Barangay Clearance', 'resident_name' => 'Registry Resident', 'address' => 'Purok 1', 'reference_no' => 'SIGNATORY-TEST'],
            ['full_name' => 'Registry Resident', 'age' => '42', 'civil_status' => 'Married', 'address' => 'Purok 1', 'purpose' => 'Testing', 'date_issued' => 'November 07, 2026', 'reference_no' => 'SIGNATORY-TEST'],
            $signatoryDir,
            'SIGNATORY-TEST',
            ['type' => ['name' => 'Barangay Clearance', 'template_file' => 'barangay_clearance.docx'], 'fields' => array_map(static fn(string $key): array => ['field_key' => $key], LEGACY_DOCUMENT_MAP['Barangay Clearance']['placeholders']), 'captain_name' => 'Registry Captain']
        );
        $zip = new ZipArchive();
        $zip->open($rendered['docx']);
        $xml = (string)$zip->getFromName('word/document.xml');
        $zip->close();
        check_registry('printed signatory has honorific and title', str_contains($xml, DOCUMENT_SIGNATORY_HONORIFIC . ' Registry Captain') && str_contains($xml, DOCUMENT_SIGNATORY_TITLE));
    } finally {
        foreach (glob($signatoryDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
        @rmdir($signatoryDir);
    }

    $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'registry-template-' . bin2hex(random_bytes(5)) . '.docx';
    try {
        registry_docx($fixture, '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{full_name}}</w:t></w:r><w:r><w:t>{{full_</w:t></w:r><w:r><w:t>name}}</w:t></w:r></w:p></w:body></w:document>');
        $errors = document_template_check($fixture, ['full_name']);
        check_registry('template checker detects intact-plus-split duplicate', in_array('split placeholder {{full_name}} in word/document.xml', $errors, true));
    } finally { @unlink($fixture); }

    $template = $storage->track($storage->template('phase1_test.docx'));
    check_registry('fail-closed fixture path is absent before test', !is_file($template));
    try {
        if (is_file($template)) throw new RuntimeException('Fixture already exists.');
        registry_docx($template, '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{unknown}}</w:t></w:r></w:p></w:body></w:document>');
        $message = '';
        try {
            request_render_document(['document_type' => 'Test', 'reference_no' => 'TEST'], [], sys_get_temp_dir(), 'TEST', [
                'type' => ['name' => 'Test', 'template_file' => 'phase1_test.docx'],
                'fields' => [['field_key' => 'known']],
                'captain_name' => 'Erwin Astronomo',
            ]);
        } catch (Throwable $e) { $message = $e->getMessage(); }
        check_registry('render fails closed with exact message', $message === 'Document template is invalid.');
    } finally { @unlink($template); }
} finally {
    registry_stop_server($server);
    if (is_resource($server)) $server = null;
    foreach ([$freshName, $upgradeName] as $database) registry_drop($admin, $database);
    registry_cleanup_prefix($admin, $prefix);
    if ($admin instanceof mysqli) $admin->close();
    if ($realApp instanceof mysqli) $realApp->close();
    @unlink($router); @unlink($router . '.out'); @unlink($router . '.err'); @unlink($prepend);
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath);
}
echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
