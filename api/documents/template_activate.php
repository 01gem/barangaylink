<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_templates.php';
session_start();
require_official_session();
require_post();
$input = read_json_input(); $versionId = (int)($input['version_id'] ?? 0); $expected = (int)($input['expected_active_version_id'] ?? 0);
if ($versionId <= 0 || $expected < 0) json_error('Version and expected active version are required.', 422);
$db = get_db();
try {
    $db->begin_transaction();
    $version = document_template_version($db, $versionId, true);
    if (!$version || !in_array($version['status'], ['draft','retired'], true)) throw new RequestProblem('Only draft or retired versions can be activated.', 409);
    $stmt = $db->prepare("SELECT * FROM document_template_versions WHERE document_type_id=? AND status='active' FOR UPDATE"); $stmt->bind_param('i', $version['document_type_id']); $stmt->execute(); $active = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ((int)($active['id'] ?? 0) !== $expected) throw new RequestProblem('The active version changed. Reload and try again.', 409);
    $path = document_template_version_path((string)$version['file_name']); $sha = hash_file('sha256', $path);
    if (!$sha || !(int)$version['check_ok'] || !(int)$version['test_ok'] || $version['tested_sha256'] !== $sha) throw new RequestProblem('Run a successful test render before activating this version.', 422);
    if ($version['sha256'] === null || !hash_equals((string)$version['sha256'], $sha)) {
        throw new RequestProblem('Template checksum changed. Run a fresh test render before activating.', 422);
    }
    // Complaint revision Phase 9: a Summons version must have its final wording.
    $stmt = $db->prepare('SELECT code FROM document_types WHERE id=?'); $stmt->bind_param('i', $version['document_type_id']); $stmt->execute();
    $typeCode = (string)($stmt->get_result()->fetch_assoc()['code'] ?? ''); $stmt->close();
    if ($typeCode === DOCUMENT_SUMMONS_CODE) {
        $problems = document_summons_template_problems($path, DOCUMENT_SUMMONS_FIELDS);
        if ($problems !== []) throw new RequestProblem('This Summons template is not ready: ' . implode('; ', $problems) . '.', 422);
    }
    $stmt = $db->prepare("UPDATE document_template_versions SET status='retired' WHERE document_type_id=? AND status='active'"); $stmt->bind_param('i', $version['document_type_id']); $stmt->execute(); $stmt->close();
    $stmt = $db->prepare("UPDATE document_template_versions SET status='active', activated_by=?, activated_at=NOW() WHERE id=?"); $officialId = (int)$_SESSION['official_id']; $stmt->bind_param('ii', $officialId, $versionId); $stmt->execute(); $stmt->close();
    $stmt = $db->prepare('UPDATE document_types SET template_file=? WHERE id=?'); $stmt->bind_param('si', $version['file_name'], $version['document_type_id']); $stmt->execute(); $stmt->close();
    $db->commit(); document_template_log($db, 'Activated document template version', (string)$versionId); json_success(['message' => 'Template version activated.']);
} catch (RequestProblem $e) { @$db->rollback(); json_error($e->getMessage(), $e->status); }
catch (Throwable $e) { @$db->rollback(); error_log('Template activation failed: ' . $e->getMessage()); json_error('Template activation failed.', 500); }
