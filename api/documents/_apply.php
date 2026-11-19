<?php
/**
 * BarangayLink - Apply confirmed AI placeholder suggestions (Phase 4A).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * The source template file is only ever read. Applying suggestions always
 * produces a NEW draft version with its own server-generated file. The client
 * sends suggestion indexes (and optionally a replacement key), never span text
 * or offsets: those come from the stored analysis.
 */

require_once __DIR__ . '/_analysis.php';

const DOCUMENT_APPLY_MAX_SELECTIONS = 40;
const DOCUMENT_APPLY_W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const DOCUMENT_APPLY_XML_NS = 'http://www.w3.org/XML/1998/namespace';
const DOCUMENT_APPLY_STALE_MESSAGE = 'The analysis is out of date. Analyze the document again.';
const DOCUMENT_APPLY_NONE_MESSAGE = 'None of the selected suggestions could be applied.';
const DOCUMENT_APPLY_NOT_DRAFT_MESSAGE = 'Suggestions can only be applied to a draft version.';

/**
 * Pure. Turn client selections into edits using only the stored suggestions.
 *
 * @param array $suggestions decoded suggestions JSON of the analysis
 * @param mixed $selections  client list of {index, key?}
 * @param array $fieldKeys   registry field keys of the document type
 * @return list<array{index:int, paragraph:int, start:int, text:string, key:string}>
 * @throws RequestProblem 422 on any invalid selection.
 */
function document_apply_plan(array $suggestions, mixed $selections, array $fieldKeys): array {
    if (!is_array($selections) || !array_is_list($selections) || $selections === []) {
        throw new RequestProblem('Select at least one suggestion.', 422);
    }
    if (count($selections) > DOCUMENT_APPLY_MAX_SELECTIONS) {
        throw new RequestProblem('Select at most ' . DOCUMENT_APPLY_MAX_SELECTIONS . ' suggestions.', 422);
    }
    $suggestions = array_values($suggestions);
    $keySet = [];
    foreach ($fieldKeys as $fieldKey) $keySet[(string)$fieldKey] = true;

    $seen = []; $edits = [];
    foreach ($selections as $selection) {
        // Only {index, key?} is accepted; span text or offsets from the client are refused.
        if (!is_array($selection) || array_is_list($selection) || array_diff(array_keys($selection), ['index', 'key']) !== []) {
            throw new RequestProblem('Invalid suggestion selection.', 422);
        }
        $index = $selection['index'] ?? null;
        if (!is_int($index) || $index < 0 || $index >= count($suggestions)) {
            throw new RequestProblem('A selected suggestion does not exist.', 422);
        }
        if (isset($seen[$index])) throw new RequestProblem('A suggestion was selected more than once.', 422);
        $seen[$index] = true;

        $stored = $suggestions[$index];
        if (!is_array($stored) || !is_int($stored['paragraph'] ?? null) || !is_int($stored['start'] ?? null)
            || $stored['start'] < 0 || !is_string($stored['text'] ?? null) || $stored['text'] === '') {
            throw new RequestProblem('A selected suggestion does not exist.', 422);
        }
        $key = array_key_exists('key', $selection) ? $selection['key'] : ($stored['key'] ?? null);
        if (!is_string($key) || !isset($keySet[$key])) {
            throw new RequestProblem('A selected placeholder is not registered for this document type.', 422);
        }
        $edits[] = ['index' => $index, 'paragraph' => $stored['paragraph'], 'start' => $stored['start'],
            'text' => $stored['text'], 'key' => $key];
    }
    return $edits;
}

/** Parse word/document.xml without network access or entity expansion. */
function document_apply_load(string $xml): array {
    if (preg_match('/<!DOCTYPE/i', $xml)) throw new RuntimeException('Document XML must not declare a DOCTYPE.');
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $previous = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$ok || !$dom->documentElement) throw new RuntimeException('Document XML cannot be parsed.');
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', DOCUMENT_APPLY_W_NS);
    return [$dom, $xpath];
}

function document_apply_is_w(DOMNode $node, string ...$names): bool {
    return $node instanceof DOMElement && $node->namespaceURI === DOCUMENT_APPLY_W_NS && in_array($node->localName, $names, true);
}

/** Every w:t that lies in the result or code of a complex field (fldChar begin..end). */
function document_apply_field_texts(DOMXPath $xpath): SplObjectStorage {
    $inField = new SplObjectStorage();
    $depth = 0;
    foreach ($xpath->query('//w:fldChar | //w:t') as $node) {
        if ($node->localName === 'fldChar') {
            $type = $node->getAttributeNS(DOCUMENT_APPLY_W_NS, 'fldCharType');
            if ($type === 'begin') $depth++;
            elseif ($type === 'end' && $depth > 0) $depth--;
        } elseif ($depth > 0) {
            $inField->attach($node);
        }
    }
    return $inField;
}

/** [node, startChar, endChar] for every w:t of a paragraph, in document order. */
function document_apply_text_nodes(DOMXPath $xpath, DOMElement $paragraph): array {
    $nodes = []; $offset = 0;
    foreach ($xpath->query('.//w:t', $paragraph) as $node) {
        $length = mb_strlen($node->textContent, 'UTF-8');
        $nodes[] = [$node, $offset, $offset + $length];
        $offset += $length;
    }
    return $nodes;
}

/** Reason for a run child that sits between the first and last touched w:t, or null when harmless. */
function document_apply_run_child_reason(DOMElement $child): ?string {
    if (document_apply_is_w($child, 'rPr', 't', 'lastRenderedPageBreak')) return null;
    if (document_apply_is_w($child, 'tab', 'br', 'cr')) return 'crosses a tab or break';
    if (document_apply_is_w($child, 'fldChar', 'instrText', 'delInstrText')) return 'crosses a field code';
    return 'crosses non-text content';
}

/** Reason for a non-run sibling between the first and last touched run, or null when harmless. */
function document_apply_sibling_reason(DOMElement $sibling): ?string {
    if (document_apply_is_w($sibling, 'proofErr', 'bookmarkStart', 'bookmarkEnd', 'commentRangeStart', 'commentRangeEnd', 'permStart', 'permEnd')) return null;
    if (document_apply_is_w($sibling, 'fldSimple')) return 'crosses a field code';
    if (document_apply_is_w($sibling, 'hyperlink')) return 'crosses a hyperlink boundary';
    return 'crosses a content boundary';
}

/**
 * Why the touched w:t nodes may not be merged, or null when the span is safe.
 * Touched nodes are in document order.
 */
function document_apply_crossing(array $touched, DOMElement $paragraph, SplObjectStorage $inField): ?string {
    foreach ($touched as $node) {
        if ($inField->contains($node)) return 'crosses a field code';
        for ($up = $node->parentNode; $up !== null && $up !== $paragraph; $up = $up->parentNode) {
            if (document_apply_is_w($up, 'fldSimple')) return 'crosses a field code';
        }
        if (!document_apply_is_w($node->parentNode, 'r')) return 'crosses non-text content';
    }
    $runs = array_map(static fn(DOMElement $node): DOMElement => $node->parentNode, $touched);
    $container = $runs[0]->parentNode;
    foreach ($runs as $run) {
        if ($run->parentNode !== $container) {
            foreach ($runs as $candidate) {
                for ($up = $candidate->parentNode; $up !== null && $up !== $paragraph; $up = $up->parentNode) {
                    if (document_apply_is_w($up, 'hyperlink')) return 'crosses a hyperlink boundary';
                }
            }
            return 'crosses a content boundary';
        }
    }

    // Walk everything strictly between the first and last touched w:t.
    $first = $touched[0]; $last = $touched[count($touched) - 1];
    if ($first === $last) return null;
    $firstRun = $runs[0]; $lastRun = $runs[count($runs) - 1];
    $passedFirst = false;
    for ($run = $firstRun; $run !== null; $run = $run->nextSibling) {
        if (!$run instanceof DOMElement) continue;
        if ($run !== $firstRun && !document_apply_is_w($run, 'r')) {
            $reason = document_apply_sibling_reason($run);
            if ($reason !== null) return $reason;
            continue;
        }
        foreach ($run->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if ($child === $first) { $passedFirst = true; continue; }
            if ($child === $last) return null;
            if (!$passedFirst) continue;
            $reason = document_apply_run_child_reason($child);
            if ($reason !== null) return $reason;
        }
        if ($run === $lastRun) break;
    }
    return 'crosses a content boundary';
}

/**
 * What an edit's span becomes: its explicit 'replacement' (plain text or a
 * placeholder, Phase 4C) or, by default, '{{key}}' (Phase 4A).
 */
function document_apply_replacement(array $edit): string {
    return array_key_exists('replacement', $edit) ? (string)$edit['replacement'] : '{{' . $edit['key'] . '}}';
}

function document_apply_set_text(DOMElement $node, string $text): void {
    while ($node->firstChild) $node->removeChild($node->firstChild);
    $node->appendChild($node->ownerDocument->createTextNode($text));
    if ($text !== '' && preg_match('/^\s|\s$/u', $text)) {
        $node->setAttributeNS(DOCUMENT_APPLY_XML_NS, 'xml:space', 'preserve');
    }
}

/** Remove an emptied w:t; remove its run too when it held nothing but that w:t (and run properties). */
function document_apply_remove_text(DOMElement $node): void {
    $run = $node->parentNode;
    $onlyThis = true;
    foreach ($run->childNodes as $child) {
        if ($child instanceof DOMElement && $child !== $node && !document_apply_is_w($child, 'rPr')) { $onlyThis = false; break; }
    }
    if ($onlyThis && $run->parentNode) $run->parentNode->removeChild($run);
    else $run->removeChild($node);
}

/**
 * Pure on XML strings. Apply edits from plan() to word/document.xml.
 *
 * @return array{xml:string, applied:list<array>, skipped:list<array{index:int, reason:string}>}
 */
function document_apply_xml(string $xml, array $edits): array {
    [$dom, $xpath] = document_apply_load($xml);
    $paragraphs = [];
    foreach ($xpath->query('//w:p') as $paragraph) $paragraphs[] = $paragraph;
    $inField = document_apply_field_texts($xpath);

    $byParagraph = [];
    foreach ($edits as $edit) $byParagraph[$edit['paragraph']][] = $edit;
    ksort($byParagraph);

    $applied = []; $skipped = [];
    foreach ($byParagraph as $paragraphIndex => $group) {
        // Last position first, so earlier offsets stay valid.
        usort($group, static fn(array $a, array $b): int => ($b['start'] <=> $a['start']) ?: ($a['index'] <=> $b['index']));
        $paragraph = $paragraphs[$paragraphIndex] ?? null;
        if (!$paragraph instanceof DOMElement) {
            foreach ($group as $edit) $skipped[] = ['index' => $edit['index'], 'reason' => 'text mismatch'];
            continue;
        }
        if ($xpath->query('ancestor::w:txbxContent | .//w:txbxContent', $paragraph)->length > 0) {
            foreach ($group as $edit) $skipped[] = ['index' => $edit['index'], 'reason' => 'text box'];
            continue;
        }
        $floor = PHP_INT_MAX;
        foreach ($group as $edit) {
            $start = $edit['start']; $length = mb_strlen($edit['text'], 'UTF-8'); $end = $start + $length;
            if ($end > $floor) { $skipped[] = ['index' => $edit['index'], 'reason' => 'overlaps another selection']; continue; }

            $nodes = document_apply_text_nodes($xpath, $paragraph);
            $full = implode('', array_map(static fn(array $row): string => $row[0]->textContent, $nodes));
            if ($start < 0 || $length < 1 || mb_substr($full, $start, $length, 'UTF-8') !== $edit['text']) {
                $skipped[] = ['index' => $edit['index'], 'reason' => 'text mismatch']; continue;
            }
            $touched = array_values(array_filter($nodes, static fn(array $row): bool => $row[1] < $end && $row[2] > $start));
            if ($touched === []) { $skipped[] = ['index' => $edit['index'], 'reason' => 'text mismatch']; continue; }
            $reason = document_apply_crossing(array_column($touched, 0), $paragraph, $inField);
            if ($reason !== null) { $skipped[] = ['index' => $edit['index'], 'reason' => $reason]; continue; }

            foreach ($touched as $position => [$node, $nodeStart, $nodeEnd]) {
                $text = $node->textContent;
                $localStart = max(0, $start - $nodeStart);
                $localEnd = min($nodeEnd - $nodeStart, $end - $nodeStart);
                $before = mb_substr($text, 0, $localStart, 'UTF-8');
                $after = mb_substr($text, $localEnd, null, 'UTF-8');
                $new = $position === 0 ? $before . document_apply_replacement($edit) . $after : $before . $after;
                if ($new === '') document_apply_remove_text($node);
                else document_apply_set_text($node, $new);
            }
            $floor = $start;
            $applied[] = $edit;
        }
    }
    usort($applied, static fn(array $a, array $b): int => ($a['paragraph'] <=> $b['paragraph']) ?: ($a['start'] <=> $b['start']));
    usort($skipped, static fn(array $a, array $b): int => $a['index'] <=> $b['index']);
    $out = $dom->saveXML();
    if ($out === false) throw new RuntimeException('Document XML cannot be serialized.');
    return ['xml' => $out, 'applied' => $applied, 'skipped' => $skipped];
}

/**
 * Pure. The new XML must parse, keep the same paragraphs, and differ from the
 * original only by the applied spans becoming {{key}}. Throws on any difference.
 */
function document_apply_verify(string $oldXml, string $newXml, array $applied): void {
    [, $oldPath] = document_apply_load($oldXml);
    [, $newPath] = document_apply_load($newXml);
    if ($oldPath->query('//w:p')->length !== $newPath->query('//w:p')->length) {
        throw new RuntimeException('Verify failed: paragraph count changed.');
    }
    $old = document_template_paragraph_text($oldXml);
    $new = document_template_paragraph_text($newXml);
    if (count($old) !== count($new)) throw new RuntimeException('Verify failed: paragraph count changed.');

    $expected = $old;
    $byParagraph = [];
    foreach ($applied as $edit) $byParagraph[$edit['paragraph']][] = $edit;
    foreach ($byParagraph as $index => $group) {
        if (!array_key_exists($index, $expected)) throw new RuntimeException('Verify failed: unknown paragraph.');
        usort($group, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        $text = $expected[$index];
        foreach ($group as $edit) {
            $length = mb_strlen($edit['text'], 'UTF-8');
            if (mb_substr($text, $edit['start'], $length, 'UTF-8') !== $edit['text']) {
                throw new RuntimeException('Verify failed: applied span does not match the original.');
            }
            $text = mb_substr($text, 0, $edit['start'], 'UTF-8') . document_apply_replacement($edit) . mb_substr($text, $edit['start'] + $length, null, 'UTF-8');
        }
        $expected[$index] = $text;
    }
    foreach ($expected as $index => $text) {
        if ($new[$index] !== $text) throw new RuntimeException('Verify failed: paragraph ' . $index . ' differs.');
    }
}

/**
 * Pure on archives. Same entry names in the same order; every entry except
 * word/document.xml has identical uncompressed bytes; word/document.xml equals
 * the verified XML. Throws on any difference.
 */
function document_apply_verify_archive(string $sourcePath, string $newPath, string $newXml): void {
    $source = new ZipArchive(); $result = new ZipArchive();
    if ($source->open($sourcePath, ZipArchive::RDONLY) !== true) throw new RuntimeException('Verify failed: source archive cannot be opened.');
    if ($result->open($newPath, ZipArchive::RDONLY) !== true) { $source->close(); throw new RuntimeException('Verify failed: new archive cannot be opened.'); }
    try {
        if ($source->numFiles !== $result->numFiles) throw new RuntimeException('Verify failed: archive entry count changed.');
        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);
            if ($name === false || $result->getNameIndex($i) !== $name) throw new RuntimeException('Verify failed: archive entry names or order changed.');
            $bytes = $result->getFromIndex($i);
            if ($bytes === false) throw new RuntimeException('Verify failed: archive entry cannot be read.');
            if ($name === 'word/document.xml') {
                if ($bytes !== $newXml) throw new RuntimeException('Verify failed: document.xml was not stored as verified.');
            } elseif ($bytes !== $source->getFromIndex($i)) {
                throw new RuntimeException('Verify failed: archive entry ' . $i . ' changed.');
            }
        }
    } finally {
        $source->close(); $result->close();
    }
}

/** Copy the source archive entry by entry, replacing only word/document.xml. */
function document_apply_build_docx(string $sourcePath, string $newXml, string $destination): void {
    $source = new ZipArchive();
    if ($source->open($sourcePath, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot open template archive.');
    $out = new ZipArchive();
    if ($out->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { $source->close(); throw new RuntimeException('Cannot create the new archive.'); }
    try {
        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);
            if ($name === false) throw new RuntimeException('Cannot read template archive.');
            if (str_ends_with($name, '/')) { $out->addEmptyDir(rtrim($name, '/')); continue; }
            $bytes = $name === 'word/document.xml' ? $newXml : $source->getFromIndex($i);
            if ($bytes === false || !$out->addFromString($name, $bytes)) throw new RuntimeException('Cannot copy template archive entry.');
        }
    } finally {
        $source->close();
    }
    if (!$out->close()) throw new RuntimeException('Cannot write the new archive.');
}

function document_apply_notes(int $sourceVersionNo, int $applied): string {
    return mb_substr('AI-assisted from v' . $sourceVersionNo . ': ' . $applied . ' placeholder(s) applied', 0, 255, 'UTF-8');
}

function document_apply_analysis(mysqli $db, int $analysisId): ?array {
    $stmt = $db->prepare('SELECT id, version_id, file_sha256, status, suggestions FROM document_template_analyses WHERE id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('i', $analysisId);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

/**
 * Apply selected suggestions of one analysis into a NEW draft version.
 *
 * Checks in order: 404 version, 404 analysis (missing or of another version),
 * 409 not draft, 409 stale or not completed, 422 selections, 422 none applied.
 * $afterWrite is a TEST-ONLY seam called after the new file is written and the
 * row inserted, before commit. Production callers never pass it.
 *
 * @throws RequestProblem for client-visible refusals; RuntimeException otherwise.
 */
function document_apply_run(mysqli $db, int $versionId, int $analysisId, mixed $selections, int $officialId, ?callable $afterWrite = null): array {
    $version = $versionId > 0 ? document_template_version($db, $versionId) : null;
    if (!$version) throw new RequestProblem('Template version is unavailable.', 404);
    $analysis = $analysisId > 0 ? document_apply_analysis($db, $analysisId) : null;
    if (!$analysis || (int)$analysis['version_id'] !== $versionId) throw new RequestProblem('The analysis is unavailable.', 404);
    if ($version['status'] !== 'draft') throw new RequestProblem(DOCUMENT_APPLY_NOT_DRAFT_MESSAGE, 409);
    if ($analysis['status'] !== 'completed' || document_analysis_is_stale($analysis, $version)) {
        throw new RequestProblem(DOCUMENT_APPLY_STALE_MESSAGE, 409);
    }

    $typeId = (int)$version['document_type_id'];
    $fields = document_registry_fields($db, $typeId);
    $allowed = array_map(static fn(array $f): string => (string)$f['field_key'], $fields);
    $required = array_values(array_map(static fn(array $f): string => (string)$f['field_key'],
        array_filter($fields, static fn(array $f): bool => (int)$f['is_required'] === 1)));
    $suggestions = json_decode((string)$analysis['suggestions'], true);
    $edits = document_apply_plan(is_array($suggestions) ? $suggestions : [], $selections, $allowed);

    $sourcePath = document_template_version_path((string)$version['file_name']);
    $zip = new ZipArchive();
    if ($zip->open($sourcePath, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot open template archive.');
    $oldXml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($oldXml === false) throw new RuntimeException('Template is missing word/document.xml.');

    $result = document_apply_xml($oldXml, $edits);
    if ($result['applied'] === []) throw new RequestProblem(DOCUMENT_APPLY_NONE_MESSAGE, 422, 'validation', ['skipped' => $result['skipped']]);
    document_apply_verify($oldXml, $result['xml'], $result['applied']);

    $sourceVersionNo = (int)$version['version_no'];
    $stored = document_apply_store_version($db, $version, $sourcePath, $result['xml'], $allowed, $required,
        'ai-assisted-from-v' . $sourceVersionNo . '.docx', document_apply_notes($sourceVersionNo, count($result['applied'])),
        DOCUMENT_APPLY_NOT_DRAFT_MESSAGE, $officialId, $afterWrite, null);
    $newId = $stored['new_version_id'];
    $createdBy = $officialId > 0 ? $officialId : null;

    // Counts only. Document text, file names and hashes never go to the audit log.
    log_audit($db, $createdBy, document_analysis_official_name($db, $officialId),
        'Applied AI placeholder suggestions', 'document_template_version', (string)$newId,
        'applied=' . count($result['applied']) . '; skipped=' . count($result['skipped'])
        . '; source_version=' . $versionId . '; new_version=' . $newId);

    return [
        'new_version_id' => $newId,
        'version_no' => $stored['version_no'],
        'applied' => count($result['applied']),
        'skipped' => $result['skipped'],
        'check_ok' => $stored['check_ok'],
        'check_errors' => $stored['check_errors'],
    ];
}

/**
 * Store verified document XML as a NEW draft version of $version's type.
 * Shared by Phase 4A (apply) and Phase 4C (manual edits).
 *
 * The source archive is only read. The new archive is built and verified in
 * a private workspace, validated like an upload, then copied to a new
 * server-generated name created exclusively. Lock order: document type row,
 * then source version row (FOR UPDATE), then the next version number. A
 * source version that is no longer a draft is refused with $notDraftMessage
 * (409). $whileLocked, when given, is called with the locked source version
 * row right after that check, inside the transaction, and may throw to
 * refuse (Phase 4C re-checks the source file hash there). $afterWrite is a
 * TEST-ONLY seam called after the file is written and the row inserted,
 * before commit. The new file is removed on any
 * failure, including a fatal error (shutdown function).
 *
 * @return array{new_version_id:int, version_no:int, check_ok:bool, check_errors:array}
 */
function document_apply_store_version(mysqli $db, array $version, string $sourcePath, string $newXml,
    array $allowed, array $required, string $originalName, string $notes, string $notDraftMessage,
    int $officialId, ?callable $afterWrite = null, ?callable $whileLocked = null): array {
    $versionId = (int)$version['id'];
    $typeId = (int)$version['document_type_id'];
    $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barangay-template-apply-' . bin2hex(random_bytes(8));
    $destination = null; $committed = false;
    // A fatal error skips finally; this removes the new file in that case too.
    // It is disarmed by $committed once the row is committed.
    register_shutdown_function(static function () use (&$destination, &$committed, $workspace): void {
        if (!$committed && is_string($destination) && is_file($destination)) @unlink($destination);
        if (is_dir($workspace)) remove_dir_tree($workspace);
    });

    try {
        if (!mkdir($workspace, 0700, true)) throw new RuntimeException('Cannot create apply workspace.');
        $built = $workspace . DIRECTORY_SEPARATOR . 'result.docx';
        document_apply_build_docx($sourcePath, $newXml, $built);
        document_apply_verify_archive($sourcePath, $built, $newXml);
        $size = filesize($built);
        if ($size === false) throw new RuntimeException('Cannot size the new archive.');
        // Same checks a user upload gets (size, archive safety, macros, required parts).
        document_template_validate_upload(['error' => UPLOAD_ERR_OK, 'size' => $size, 'name' => 'result.docx', 'tmp_name' => $built]);

        if (!$db->begin_transaction()) throw new RuntimeException($db->error);
        $stmt = $db->prepare('SELECT id FROM document_types WHERE id = ? FOR UPDATE');
        if (!$stmt) throw new RuntimeException($db->error);
        $stmt->bind_param('i', $typeId);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
        $type = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$type) throw new RuntimeException('Document type is unavailable.');
        $locked = document_template_version($db, $versionId, true);
        if (!$locked || $locked['status'] !== 'draft') throw new RequestProblem($notDraftMessage, 409);
        if ($whileLocked !== null) $whileLocked($locked);

        $stmt = $db->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version FROM document_template_versions WHERE document_type_id = ?');
        if (!$stmt) throw new RuntimeException($db->error);
        $stmt->bind_param('i', $typeId);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
        $versionNo = (int)$stmt->get_result()->fetch_assoc()['next_version']; $stmt->close();

        $fileName = document_template_new_file_name($typeId, $versionNo);
        $target = document_template_root() . DIRECTORY_SEPARATOR . $fileName;
        $out = @fopen($target, 'xb');
        if ($out === false) throw new RuntimeException('Cannot create the new template file.');
        $destination = $target; // only a file this call created is ever removed
        $in = fopen($built, 'rb');
        $copied = $in ? stream_copy_to_stream($in, $out) : false;
        if (is_resource($in)) fclose($in);
        fclose($out);
        if ($copied !== $size) throw new RuntimeException('Cannot store the new template file.');

        $sha = hash_file('sha256', $destination);
        if (!is_string($sha)) throw new RuntimeException('Cannot hash the new template file.');
        $errors = document_template_check($destination, $allowed, $required);
        $report = json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE);
        $checkOk = $errors ? 0 : 1;
        $original = $originalName;
        $status = 'draft'; $testOk = 0;
        $createdBy = $officialId > 0 ? $officialId : null;
        $stmt = $db->prepare('INSERT INTO document_template_versions
            (document_type_id, version_no, file_name, original_name, sha256, size_bytes, status, notes, check_report, check_ok, test_ok, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException($db->error);
        $stmt->bind_param('iisssisssiii', $typeId, $versionNo, $fileName, $original, $sha, $size, $status, $notes, $report, $checkOk, $testOk, $createdBy);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
        $newId = (int)$stmt->insert_id; $stmt->close();

        if ($afterWrite !== null) $afterWrite($destination, $newId);

        if (!$db->commit()) throw new RuntimeException('Cannot commit the new version.');
        $committed = true;
    } catch (Throwable $e) {
        if (!$committed) @$db->rollback();
        throw $e;
    } finally {
        if (!$committed && is_string($destination) && is_file($destination)) @unlink($destination);
        remove_dir_tree($workspace);
    }

    return ['new_version_id' => $newId, 'version_no' => $versionNo, 'check_ok' => (bool)$checkOk, 'check_errors' => $errors];
}
