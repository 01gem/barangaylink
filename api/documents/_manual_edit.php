<?php
/**
 * BarangayLink - Manual template text edits (Phase 4C).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Officials turn selected text into a registered {{key}}, change the wording
 * of a span, or turn an existing {{key}} back into plain text. No AI and no
 * analysis are involved. The source template file is only ever read; every
 * save produces a NEW draft version through document_apply_store_version().
 *
 * The client sends (paragraph, start, text) spans in Unicode code points,
 * using the paragraph indexes of document_template_paragraph_text(). The
 * server re-reads the document and uses the client text for comparison only.
 */

require_once __DIR__ . '/_apply.php';

const DOCUMENT_MANUAL_MAX_EDITS = 40;
const DOCUMENT_MANUAL_MAX_PLACEHOLDER_SPAN = 200;
const DOCUMENT_MANUAL_MAX_TEXT_SPAN = 500;
const DOCUMENT_MANUAL_MAX_NEW_TEXT = 500;
const DOCUMENT_MANUAL_OPS = [
    'placeholder' => ['op', 'paragraph', 'start', 'text', 'key'],
    'replace_text' => ['op', 'paragraph', 'start', 'text', 'new_text'],
    'remove_placeholder' => ['op', 'paragraph', 'start', 'key', 'new_text'],
];

const DOCUMENT_MANUAL_MSG_SHAPE = 'A version, the file hash and a list of edits are required.';
const DOCUMENT_MANUAL_MSG_NOT_DRAFT = 'Manual edits can only be saved to a draft version.';
const DOCUMENT_MANUAL_MSG_CHANGED = 'The template changed. Reload and try again.';
const DOCUMENT_MANUAL_MSG_EMPTY = 'Add at least one edit.';
const DOCUMENT_MANUAL_MSG_TOO_MANY = 'Save at most 40 edits at a time.';
const DOCUMENT_MANUAL_MSG_INVALID = 'An edit is not valid.';
const DOCUMENT_MANUAL_MSG_PARAGRAPH = 'An edit refers to a paragraph that cannot be edited.';
const DOCUMENT_MANUAL_MSG_MISMATCH = 'An edit no longer matches the document text. Reload and try again.';
const DOCUMENT_MANUAL_MSG_PLACEHOLDER_SPAN = 'Selected text must be 1 to 200 characters.';
const DOCUMENT_MANUAL_MSG_TEXT_SPAN = 'Selected text must be 1 to 500 characters.';
const DOCUMENT_MANUAL_MSG_KEY = 'A selected placeholder is not registered for this document type.';
const DOCUMENT_MANUAL_MSG_NEW_TEXT_LONG = 'New text must be at most 500 characters.';
const DOCUMENT_MANUAL_MSG_NEW_TEXT_CONTROL = 'New text cannot contain tabs, line breaks or control characters.';
const DOCUMENT_MANUAL_MSG_NEW_TEXT_BRACES = 'New text cannot contain { or }.';
const DOCUMENT_MANUAL_MSG_OVERLAP = 'Edits must not overlap.';
const DOCUMENT_MANUAL_MSG_TOUCHES_PLACEHOLDER = 'An edit cannot change part of an existing placeholder.';
const DOCUMENT_MANUAL_MSG_WHOLE_PLACEHOLDER = 'Only a whole placeholder such as {{key}} can be turned into text.';
const DOCUMENT_MANUAL_MSG_SAME = 'The new text is the same as the old text.';
const DOCUMENT_MANUAL_MSG_NONE = 'None of the edits could be applied.';

/**
 * Pure. Paragraphs of word/document.xml with the same indexes as
 * document_template_paragraph_text() (blank paragraphs omitted, index kept),
 * an editable flag, and every {{key}} placeholder with its code-point start.
 *
 * @return array{paragraphs: list<array>, placeholders: list<array>}
 */
function document_manual_paragraphs(string $xml): array {
    [, $xpath] = document_apply_load($xml);
    $inField = document_apply_field_texts($xpath);
    $paragraphs = []; $placeholders = [];
    foreach ($xpath->query('//w:p') as $index => $paragraph) {
        $text = ''; $reason = null;
        foreach ($xpath->query('.//w:t', $paragraph) as $node) {
            $text .= $node->textContent;
            if ($inField->contains($node) || $xpath->query('ancestor::w:fldSimple', $node)->length > 0) $reason ??= 'field code';
        }
        if (trim($text) === '') continue;
        if ($xpath->query('ancestor::w:txbxContent | .//w:txbxContent', $paragraph)->length > 0) $reason = 'text box';
        $row = ['index' => $index, 'text' => $text, 'editable' => $reason === null];
        if ($reason !== null) $row['reason'] = $reason;
        $paragraphs[] = $row;
        if (preg_match_all('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $placeholders[] = [
                    'paragraph' => $index,
                    'start' => mb_strlen(substr($text, 0, $match[0][1]), 'UTF-8'),
                    'key' => $match[1][0],
                    'text' => $match[0][0],
                    // Only the exact form {{key}} can be turned into text.
                    'removable' => $match[0][0] === '{{' . $match[1][0] . '}}',
                ];
            }
        }
    }
    return ['paragraphs' => $paragraphs, 'placeholders' => $placeholders];
}

/** Code-point ranges [start, end) of every {{...}} and stray "{{" / "}}" in a paragraph. */
function document_manual_brace_ranges(string $text): array {
    $ranges = [];
    foreach (document_analysis_brace_ranges($text) as [$byteStart, $byteEnd]) {
        $start = mb_strlen(substr($text, 0, $byteStart), 'UTF-8');
        $ranges[] = [$start, $start + mb_strlen(substr($text, $byteStart, $byteEnd - $byteStart), 'UTF-8')];
    }
    return $ranges;
}

/** @throws RequestProblem 422 when new_text is not allowed. */
function document_manual_check_new_text(string $newText): void {
    if (mb_strlen($newText, 'UTF-8') > DOCUMENT_MANUAL_MAX_NEW_TEXT) throw new RequestProblem(DOCUMENT_MANUAL_MSG_NEW_TEXT_LONG, 422);
    // C0/C1 controls (tab, CR, LF, ...), line/paragraph separators, bidi overrides.
    if (preg_match('/[\p{Cc}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $newText)) {
        throw new RequestProblem(DOCUMENT_MANUAL_MSG_NEW_TEXT_CONTROL, 422);
    }
    // Any single brace is refused, so no edit can form or break a placeholder.
    if (str_contains($newText, '{') || str_contains($newText, '}')) throw new RequestProblem(DOCUMENT_MANUAL_MSG_NEW_TEXT_BRACES, 422);
}

/**
 * Pure. Validate client edits against the server's own paragraph text and
 * turn them into document_apply_xml() edits. The first problem found is
 * thrown; nothing is partially accepted at this stage.
 *
 * @param mixed $edits      client list
 * @param array $document   result of document_manual_paragraphs()
 * @param array $fieldKeys  registry field keys of the document type
 * @return list<array{index:int, op:string, paragraph:int, start:int, text:string, key:string, replacement:string}>
 * @throws RequestProblem 422
 */
function document_manual_plan(mixed $edits, array $document, array $fieldKeys): array {
    if (!is_array($edits) || ($edits !== [] && !array_is_list($edits))) throw new RequestProblem(DOCUMENT_MANUAL_MSG_SHAPE, 422);
    if ($edits === []) throw new RequestProblem(DOCUMENT_MANUAL_MSG_EMPTY, 422);
    if (count($edits) > DOCUMENT_MANUAL_MAX_EDITS) throw new RequestProblem(DOCUMENT_MANUAL_MSG_TOO_MANY, 422);

    $byIndex = [];
    foreach ($document['paragraphs'] as $row) $byIndex[(int)$row['index']] = $row;
    $keySet = array_fill_keys(array_map('strval', $fieldKeys), true);
    $placeholdersAt = [];
    foreach ($document['placeholders'] as $row) $placeholdersAt[$row['paragraph'] . ':' . $row['start']] = $row;

    $planned = [];
    foreach ($edits as $index => $edit) {
        if (!is_array($edit) || array_is_list($edit) || !is_string($edit['op'] ?? null) || !isset(DOCUMENT_MANUAL_OPS[$edit['op']])) {
            throw new RequestProblem(DOCUMENT_MANUAL_MSG_INVALID, 422);
        }
        $op = $edit['op'];
        $fields = DOCUMENT_MANUAL_OPS[$op];
        $names = array_keys($edit); sort($names); $wanted = $fields; sort($wanted);
        if ($names !== $wanted) throw new RequestProblem(DOCUMENT_MANUAL_MSG_INVALID, 422);
        $paragraph = $edit['paragraph']; $start = $edit['start'];
        if (!is_int($paragraph) || $paragraph < 0 || !is_int($start) || $start < 0) throw new RequestProblem(DOCUMENT_MANUAL_MSG_INVALID, 422);
        foreach (['text', 'key', 'new_text'] as $name) {
            if (in_array($name, $fields, true) && (!is_string($edit[$name]) || !mb_check_encoding($edit[$name], 'UTF-8'))) {
                throw new RequestProblem(DOCUMENT_MANUAL_MSG_INVALID, 422);
            }
        }
        if ($op === 'remove_placeholder' && !preg_match('/^[a-z_][a-z0-9_]*$/', $edit['key'])) throw new RequestProblem(DOCUMENT_MANUAL_MSG_INVALID, 422);

        $row = $byIndex[$paragraph] ?? null;
        if ($row === null || empty($row['editable'])) throw new RequestProblem(DOCUMENT_MANUAL_MSG_PARAGRAPH, 422);
        $full = (string)$row['text'];

        if ($op === 'remove_placeholder') {
            $text = '{{' . $edit['key'] . '}}';
            if (mb_substr($full, $start, mb_strlen($text, 'UTF-8'), 'UTF-8') !== $text) {
                // A placeholder written differently (for example {{ key }}) is not removable.
                $found = $placeholdersAt[$paragraph . ':' . $start] ?? null;
                throw new RequestProblem($found !== null && $found['key'] === $edit['key']
                    ? DOCUMENT_MANUAL_MSG_WHOLE_PLACEHOLDER : DOCUMENT_MANUAL_MSG_MISMATCH, 422);
            }
            $found = $placeholdersAt[$paragraph . ':' . $start] ?? null;
            if ($found === null || $found['text'] !== $text) throw new RequestProblem(DOCUMENT_MANUAL_MSG_WHOLE_PLACEHOLDER, 422);
            document_manual_check_new_text($edit['new_text']);
            $replacement = $edit['new_text'];
            $key = $edit['key'];
        } else {
            $text = $edit['text'];
            $length = mb_strlen($text, 'UTF-8');
            if ($op === 'placeholder') {
                if ($length < 1 || $length > DOCUMENT_MANUAL_MAX_PLACEHOLDER_SPAN) throw new RequestProblem(DOCUMENT_MANUAL_MSG_PLACEHOLDER_SPAN, 422);
                if (!isset($keySet[$edit['key']])) throw new RequestProblem(DOCUMENT_MANUAL_MSG_KEY, 422);
                $key = $edit['key'];
                $replacement = '{{' . $key . '}}';
            } else {
                if ($length < 1 || $length > DOCUMENT_MANUAL_MAX_TEXT_SPAN) throw new RequestProblem(DOCUMENT_MANUAL_MSG_TEXT_SPAN, 422);
                document_manual_check_new_text($edit['new_text']);
                if ($edit['new_text'] === $text) throw new RequestProblem(DOCUMENT_MANUAL_MSG_SAME, 422);
                $key = '';
                $replacement = $edit['new_text'];
            }
            if (mb_substr($full, $start, $length, 'UTF-8') !== $text) throw new RequestProblem(DOCUMENT_MANUAL_MSG_MISMATCH, 422);
            // Sharing even one character with a placeholder (or a stray brace
            // pair) is refused; only remove_placeholder may remove one.
            foreach (document_manual_brace_ranges($full) as [$rangeStart, $rangeEnd]) {
                if ($start < $rangeEnd && $rangeStart < $start + $length) throw new RequestProblem(DOCUMENT_MANUAL_MSG_TOUCHES_PLACEHOLDER, 422);
            }
        }
        $planned[] = ['index' => $index, 'op' => $op, 'paragraph' => $paragraph, 'start' => $start,
            'text' => $text, 'key' => $key, 'replacement' => $replacement];
    }

    foreach ($planned as $i => $a) {
        foreach (array_slice($planned, $i + 1) as $b) {
            if ($a['paragraph'] !== $b['paragraph']) continue;
            $aEnd = $a['start'] + mb_strlen($a['text'], 'UTF-8');
            $bEnd = $b['start'] + mb_strlen($b['text'], 'UTF-8');
            if ($a['start'] < $bEnd && $b['start'] < $aEnd) throw new RequestProblem(DOCUMENT_MANUAL_MSG_OVERLAP, 422);
        }
    }
    return $planned;
}

/**
 * Pure. The placeholder keys of the new document must be exactly the old
 * ones, minus the removed ones, plus the added ones (as multisets).
 */
function document_manual_verify_keys(string $oldXml, string $newXml, array $applied): void {
    $count = static function (string $xml): array {
        $keys = [];
        foreach (document_template_paragraph_text($xml) as $text) {
            foreach (document_template_keys($text) as $key) $keys[$key] = ($keys[$key] ?? 0) + 1;
        }
        return $keys;
    };
    $expected = $count($oldXml);
    foreach ($applied as $edit) {
        if ($edit['op'] === 'placeholder') $expected[$edit['key']] = ($expected[$edit['key']] ?? 0) + 1;
        if ($edit['op'] === 'remove_placeholder') $expected[$edit['key']] = ($expected[$edit['key']] ?? 0) - 1;
    }
    $expected = array_filter($expected, static fn(int $n): bool => $n !== 0);
    $actual = $count($newXml);
    ksort($expected); ksort($actual);
    if ($expected !== $actual) throw new RuntimeException('Verify failed: placeholder keys changed unexpectedly.');
}

function document_manual_notes(int $sourceVersionNo, int $applied): string {
    return mb_substr('Manual edits from v' . $sourceVersionNo . ': ' . $applied . ' edit(s) applied', 0, 255, 'UTF-8');
}

/** Read word/document.xml of a stored version. */
function document_manual_read_xml(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot open template archive.');
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) throw new RuntimeException('Template is missing word/document.xml.');
    return $xml;
}

/**
 * Save manual edits of one draft version into a NEW draft version.
 *
 * Checks in order: 404 version, 409 not draft, 422 hash format, 409 hash
 * differs, 422 edits, 422 none applied. The hash is checked again inside the
 * transaction, after the source version row is locked. $afterWrite is a
 * TEST-ONLY seam (see document_apply_store_version).
 *
 * @throws RequestProblem for client-visible refusals; RuntimeException otherwise.
 */
function document_manual_edit_run(mysqli $db, int $versionId, mixed $expectedSha, mixed $edits, int $officialId, ?callable $afterWrite = null): array {
    $version = $versionId > 0 ? document_template_version($db, $versionId) : null;
    if (!$version) throw new RequestProblem('Template version is unavailable.', 404);
    if ($version['status'] !== 'draft') throw new RequestProblem(DOCUMENT_MANUAL_MSG_NOT_DRAFT, 409);
    if (!is_string($expectedSha) || !preg_match('/^[a-f0-9]{64}$/', $expectedSha)) throw new RequestProblem(DOCUMENT_MANUAL_MSG_SHAPE, 422);

    $sourcePath = document_template_version_path((string)$version['file_name']);
    $sha = hash_file('sha256', $sourcePath);
    if (!is_string($sha)) throw new RuntimeException('Cannot hash template.');
    if (!hash_equals($sha, $expectedSha)) throw new RequestProblem(DOCUMENT_MANUAL_MSG_CHANGED, 409);
    $oldXml = document_manual_read_xml($sourcePath);

    $typeId = (int)$version['document_type_id'];
    $fields = document_registry_fields($db, $typeId);
    $allowed = array_map(static fn(array $f): string => (string)$f['field_key'], $fields);
    $required = array_values(array_map(static fn(array $f): string => (string)$f['field_key'],
        array_filter($fields, static fn(array $f): bool => (int)$f['is_required'] === 1)));
    $plan = document_manual_plan($edits, document_manual_paragraphs($oldXml), $allowed);

    $result = document_apply_xml($oldXml, $plan);
    if ($result['applied'] === []) throw new RequestProblem(DOCUMENT_MANUAL_MSG_NONE, 422, 'validation', ['skipped' => $result['skipped']]);
    document_apply_verify($oldXml, $result['xml'], $result['applied']);
    document_manual_verify_keys($oldXml, $result['xml'], $result['applied']);

    $sourceVersionNo = (int)$version['version_no'];
    $stored = document_apply_store_version($db, $version, $sourcePath, $result['xml'], $allowed, $required,
        'manual-edit-from-v' . $sourceVersionNo . '.docx', document_manual_notes($sourceVersionNo, count($result['applied'])),
        DOCUMENT_MANUAL_MSG_NOT_DRAFT, $officialId, $afterWrite,
        static function (array $locked) use ($sourcePath, $expectedSha): void {
            // Inside the transaction, after the source row is locked.
            $current = hash_file('sha256', $sourcePath);
            if (!is_string($current) || !hash_equals($current, $expectedSha)) throw new RequestProblem(DOCUMENT_MANUAL_MSG_CHANGED, 409);
        });
    $newId = $stored['new_version_id'];

    // Operation counts only. Document text, file names and hashes never go to the audit log.
    $ops = array_count_values(array_column($result['applied'], 'op'));
    log_audit($db, $officialId > 0 ? $officialId : null, document_analysis_official_name($db, $officialId),
        'Edited template text manually', 'document_template_version', (string)$newId,
        'placeholder=' . ($ops['placeholder'] ?? 0) . '; replace_text=' . ($ops['replace_text'] ?? 0)
        . '; remove_placeholder=' . ($ops['remove_placeholder'] ?? 0) . '; skipped=' . count($result['skipped'])
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
