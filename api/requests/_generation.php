<?php
require_once __DIR__ . '/_workflow.php';
require_once __DIR__ . '/../documents/_registry.php';

/** Conversion/publication coordinator; optional local dependencies support isolated CLI tests. */
function request_generation_run(mysqli $db, array $actor, array $payload, callable $renderer, string $artifactRoot): array {
    $workspace = null; $published = [];
    try {
        $begun = request_generation_begin($db, $actor, $payload);
        if (isset($begun['result'])) return $begun['result'];
        $renderContext = document_registry_context($db, (string)$begun['request']['document_type']);
        $workspace = sys_get_temp_dir() . '/barangay-doc-' . bin2hex(random_bytes(16));
        if (!mkdir($workspace, 0700)) throw new RuntimeException('Cannot create conversion workspace.');
        $files = $renderer($begun['request'], $payload['fields'], $workspace, $payload['operation_id'], $renderContext);
        if (!request_valid_pdf($files['pdf'])) throw new RuntimeException('This operation did not produce a valid PDF.');
        $base = 'generated_documents/' . $payload['operation_id'];
        foreach (['docx','pdf'] as $extension) {
            $destination = $artifactRoot . '/' . $base . '.' . $extension;
            request_copy_exclusive($files[$extension], $destination);
            $published[] = $destination;
        }
        return request_generation_publish($db, $actor, $payload, ['stored_path' => $base . '.pdf', 'docx_path' => $base . '.docx',
            // The version whose file the renderer filled (null on a registry-less legacy install).
            'template_version_id' => isset($renderContext['version']['id']) ? (int)$renderContext['version']['id'] : null]);
    } catch (Throwable $e) {
        if (isset($begun['request'])) {
            try { $result = request_generation_abort($db, $payload, $e); }
            catch (Throwable $recoveryError) {
                error_log('Generation outcome requires recovery: ' . $recoveryError->getMessage());
                $result = [503, ['success'=>false, 'code'=>'outcome_uncertain', 'message'=>'Generation outcome is uncertain. Retry the same operation ID.']];
                $published = []; // Database unavailable: retain potentially referenced files.
            }
        } else $result = request_problem_result($e);
        if (empty($result[1]['success'])) foreach ($published as $path) @unlink($path);
        return $result;
    } finally {
        if ($workspace !== null) remove_dir_tree($workspace);
    }
}

/**
 * Phase 5B: registry rules for the staff_entered values, checked before the
 * operation is claimed (a refused payload never takes the lease). Skipped when
 * there is no registry (legacy), or when the request / type is unknown: the
 * run itself reports those.
 * @throws RequestProblem 422
 */
function request_generation_validate_fields(mysqli $db, int $requestId, array $fields): void {
    if (!document_registry_table_exists($db)) return;
    $rows = request_rows($db, 'SELECT document_type FROM document_requests WHERE id=?', [$requestId]);
    if (!$rows) return;
    $type = document_registry_type($db, (string)$rows[0]['document_type']);
    if ($type === null) return;
    document_validate_staff_fields(document_registry_fields($db, (int)$type['id']), $fields);
}

function request_generation_begin(mysqli $db, array $actor, array $p): array {
    $replay = request_claim($db, $actor, 'generation', (string)$p['id'], $p);
    if ($replay !== null) return ['result' => $replay];
    try {
        return request_transaction($db, function () use ($db, $p) {
            $r = request_lock($db, (string)$p['id'], true);
            $op = request_rows($db, 'SELECT * FROM request_operations WHERE operation_id=? FOR UPDATE', [$p['operation_id']])[0];
            if ($op['state'] === 'done') return ['result' => [(int)$op['http_status'], json_decode($op['outcome_json'], true)]];
            request_version($r, $p['expected_version']);
            if ($r['status'] !== 'pending') throw new RequestProblem('Only Pending requests can be generated.', 409, 'invalid_transition');
            if ($r['generation_token'] !== null) {
                // Expired leases must be recovered explicitly; never steal an active workspace.
                throw new RequestProblem('Generation is active or awaiting recovery.', 409, 'generation_active');
            }
            request_exec($db, 'UPDATE document_requests SET generation_token=?,generation_expires_at=NOW()+INTERVAL 180 SECOND WHERE id=?', [$p['operation_id'], $p['id']]);
            $resident = request_rows($db, 'SELECT fname,lname,address,birthdate,civil_status FROM residents WHERE id=?', [(int)$r['resident_id']]);
            return ['request' => array_merge($r, $resident[0] ?? [])];
        });
    } catch (Throwable $e) {
        // A lease transaction with an uncertain commit must not leave a terminal
        // failed operation holding the request token indefinitely.
        if (request_rows($db, 'SELECT id FROM document_requests WHERE id=?', [$p['id']])) {
            return ['result' => request_generation_abort($db, $p, $e)];
        }
        $result = request_problem_result($e); request_finish($db, $p['operation_id'], $result); return ['result' => $result];
    }
}

function request_generation_publish(mysqli $db, array $actor, array $p, array $artifacts): array {
    return request_transaction($db, function () use ($db, $actor, $p, $artifacts) {
        $r = request_lock($db, (string)$p['id'], true);
        request_version($r, $p['expected_version']);
        $lease = request_rows($db, 'SELECT generation_expires_at>NOW() AS valid FROM document_requests WHERE id=?', [$p['id']])[0];
        $op = request_rows($db, 'SELECT state FROM request_operations WHERE operation_id=? FOR UPDATE', [$p['operation_id']])[0];
        if ($r['status'] !== 'pending' || $r['generation_token'] !== $p['operation_id'] || !(int)$lease['valid'] || $op['state'] !== 'running') {
            throw new RequestProblem('Generation lease expired or request changed. Review before generating again.', 409, 'generation_expired');
        }
        $versionColumn = request_template_version_column_exists($db);
        request_exec($db, "UPDATE document_requests SET generated_document_path=?,status='processing',row_version=row_version+1,generation_token=NULL,generation_expires_at=NULL"
            . ($versionColumn ? ',template_version_id=?' : '') . ' WHERE id=?',
            $versionColumn ? [$artifacts['stored_path'], $artifacts['template_version_id'] ?? null, $p['id']] : [$artifacts['stored_path'], $p['id']]);
        request_audit($db, $actor, $r, 'Generated document and started Processing', "Operation: {$p['operation_id']}; PDF: {$artifacts['stored_path']}");
        request_resident_notice($db, $r, "Your request {$r['reference_no']} is now processing.");
        $r['row_version']++;
        $result = request_success($r, true, [
            'reference_no' => $r['reference_no'], 'generated_document_path' => $artifacts['stored_path'],
            'pdf_path' => '../' . $artifacts['stored_path'], 'docx_path' => '../' . $artifacts['docx_path'],
            'message' => 'Document generated. Request is Processing.'
        ]);
        request_finish($db, $p['operation_id'], $result);
        return $result;
    });
}
function request_generation_abort(mysqli $db, array $p, Throwable $e): array {
    // Request -> operation lock order, matching publication and recovery.
    return request_transaction($db, function () use ($db, $p, $e) {
        request_lock($db, (string)$p['id'], true);
        $op = request_rows($db, 'SELECT * FROM request_operations WHERE operation_id=? FOR UPDATE', [$p['operation_id']])[0];
        if ($op['state'] === 'done') return [(int)$op['http_status'], json_decode($op['outcome_json'], true)];
        request_exec($db, 'UPDATE document_requests SET generation_token=NULL,generation_expires_at=NULL WHERE id=? AND generation_token=?', [$p['id'], $p['operation_id']]);
        $result = request_problem_result($e); $result[1]['operation_finished'] = true;
        request_finish($db, $p['operation_id'], $result); return $result;
    });
}

function request_valid_pdf(string $path): bool {
    if (!is_file($path) || filesize($path) < 16) return false;
    $file = fopen($path, 'rb'); if (!$file) return false;
    $header = fread($file, 5); fclose($file); return $header === '%PDF-';
}

/** Migration 20261122 applied? Generation keeps working on an install that has not run it yet. */
function request_template_version_column_exists(mysqli $db): bool {
    return (bool)request_rows($db, "SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='document_requests' AND column_name='template_version_id' LIMIT 1");
}

/**
 * Absolute path of a request's generated PDF, or null when the stored value is not
 * a name the generator writes or the file is gone. view_document.php serves by this
 * and list.php reports file_available by it, so the two can never disagree.
 */
function request_generated_pdf_path(?string $stored): ?string {
    if (!preg_match('#^generated_documents/([a-f0-9-]{36})\.pdf$#', (string)$stored, $match)) return null;
    // The storage override is test-only (see document_storage_override); production uses the project root.
    $root = realpath((document_storage_override() ?? dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR . 'generated_documents');
    if ($root === false) return null;
    $path = realpath($root . DIRECTORY_SEPARATOR . $match[1] . '.pdf');
    return ($path !== false && str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR))) ? $path : null;
}
function request_convert(array $command, string $workspace, int $timeout = 60): void {
    $spec = [0 => ['pipe','r'], 1 => ['file', $workspace . '/conversion.log','w'], 2 => ['file', $workspace . '/conversion-error.log','w']];
    $process = proc_open($command, $spec, $pipes, $workspace, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start LibreOffice.');
    fclose($pipes[0]); $deadline = microtime(true) + $timeout;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) break;
        if (microtime(true) >= $deadline) {
            if (PHP_OS_FAMILY === 'Windows') {
                // soffice.exe may launch soffice.bin; terminate only this owned process tree.
                $killer = proc_open(['taskkill.exe', '/PID', (string)$state['pid'], '/T', '/F'],
                    [0=>['pipe','r'],1=>['file',$workspace.'/timeout.log','w'],2=>['file',$workspace.'/timeout-error.log','w']], $killPipes, null, null, ['bypass_shell'=>true]);
                if (is_resource($killer)) { fclose($killPipes[0]); proc_close($killer); }
            }
            proc_terminate($process); proc_close($process);
            throw new RuntimeException('Document conversion timed out.');
        }
        usleep(20000);
    } while (true);
    $code = proc_close($process);
    if (($state['exitcode'] ?? $code) !== 0) throw new RuntimeException('Document conversion failed.');
}
function request_copy_exclusive(string $source, string $destination): void {
    $out = @fopen($destination, 'xb');
    if (!$out) throw new RuntimeException('Cannot publish a unique artifact.');
    $in = fopen($source, 'rb');
    try {
        if (!$in || stream_copy_to_stream($in, $out) !== filesize($source) || !fflush($out)) throw new RuntimeException('Artifact publication failed.');
    } catch (Throwable $e) {
        if (is_resource($in)) fclose($in);
        fclose($out); @unlink($destination); throw $e;
    }
    if (is_resource($in)) fclose($in);
    fclose($out);
}
