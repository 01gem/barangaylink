<?php
/**
 * BarangayLink - DOCX template validation helpers.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

function document_template_keys(string $text): array {
    preg_match_all('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/', $text, $matches);
    return $matches[1] ?? [];
}

function document_template_paragraph_text(string $xml): array {
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return [];
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $paragraphs = [];
    foreach ($xpath->query('//w:p') as $paragraph) {
        $text = '';
        foreach ($xpath->query('.//w:t', $paragraph) as $node) $text .= $node->textContent;
        $paragraphs[] = $text;
    }
    return $paragraphs;
}

function document_template_check(string $path, array $allowedKeys, array $requiredKeys = []): array {
    $allowedKeys = array_values(array_unique($allowedKeys));
    $errors = [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return ['unable to open DOCX archive'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $part = $zip->getNameIndex($i);
        if (!is_string($part) || !preg_match('#^word/[^/]+\.xml$#', $part)) continue;
        $xml = $zip->getFromIndex($i);
        if ($xml === false) continue;
        $rawKeys = document_template_keys($xml);
        $unknown = array_values(array_diff(array_unique($rawKeys), $allowedKeys));
        if ($unknown) $errors[] = 'unknown placeholders: ' . implode(', ', $unknown);
        if (in_array('official_position', $rawKeys, true)) {
            $errors[] = 'retired placeholder official_position is present';
        }
        $knownPattern = $allowedKeys
            ? '/\{\{\s*(?:' . implode('|', array_map('preg_quote', $allowedKeys)) . ')\s*\}\}/'
            : '/\{\{[^}]*\}\}/';
        $withoutKnown = preg_replace($knownPattern, '', $xml);
        if ($withoutKnown !== null && (str_contains($withoutKnown, '{{') || str_contains($withoutKnown, '}}'))) {
            $errors[] = 'malformed placeholder remains in ' . $part;
        }
        $paragraphKeys = [];
        foreach (document_template_paragraph_text($xml) as $paragraph) {
            $paragraphKeys = array_merge($paragraphKeys, document_template_keys($paragraph));
        }
        $rawCounts = array_count_values($rawKeys);
        $paragraphCounts = array_count_values($paragraphKeys);
        foreach (array_keys($paragraphCounts) as $key) {
            if (($rawCounts[$key] ?? 0) < $paragraphCounts[$key]) {
                $errors[] = 'split placeholder {{' . $key . '}} in ' . $part;
            }
        }
    }
    $zip->close();
    $found = [];
    $zip = new ZipArchive();
    if ($zip->open($path) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $xml = $zip->getFromIndex($i);
            if ($xml !== false) $found = array_merge($found, document_template_keys($xml));
        }
        $zip->close();
    }
    foreach (array_diff($requiredKeys, array_unique($found)) as $missing) {
        $errors[] = 'required placeholder missing: ' . $missing;
    }
    return array_values(array_unique($errors));
}

/**
 * Complaint revision Phase 9: wording rules for a Summons template, checked
 * before activation (template_activate.php, Summons type only). Pure: takes
 * the paragraph texts of word/document.xml. Returns problems ([] = ready):
 *  - the 13 Summons keys exactly once each, and no other key;
 *  - no "morning/afternoon" left anywhere (hearing_time already says it);
 *  - no blank run "____" left anywhere;
 *  - the summons sentence ("You are hereby summoned ...") exists and has no
 *    two consecutive spaces.
 * @param list<string> $paragraphs
 * @param list<string> $requiredKeys DOCUMENT_SUMMONS_FIELDS
 */
function document_summons_wording_problems(array $paragraphs, array $requiredKeys): array {
    $problems = [];
    $keys = [];
    foreach ($paragraphs as $text) $keys = array_merge($keys, document_template_keys((string)$text));
    $counts = array_count_values($keys);
    $missing = $twice = [];
    foreach ($requiredKeys as $key) {
        $n = $counts[$key] ?? 0;
        if ($n === 0) $missing[] = $key;
        elseif ($n > 1) $twice[] = $key;
    }
    $unknown = array_values(array_diff(array_keys($counts), $requiredKeys));
    if ($missing) $problems[] = 'missing placeholders: ' . implode(', ', $missing);
    if ($twice) $problems[] = 'placeholders used more than once: ' . implode(', ', $twice);
    if ($unknown) $problems[] = 'unknown placeholders: ' . implode(', ', $unknown);
    $all = implode("\n", array_map('strval', $paragraphs));
    if (stripos($all, 'morning/afternoon') !== false) $problems[] = 'the text still contains "morning/afternoon"';
    if (str_contains($all, '____')) $problems[] = 'a blank line "____" is left in the text';
    $sentence = null;
    foreach ($paragraphs as $text) if (str_contains((string)$text, 'You are hereby summoned')) { $sentence = (string)$text; break; }
    if ($sentence === null) $problems[] = 'the summons sentence ("You are hereby summoned ...") is missing';
    elseif (preg_match('/ {2,}/u', $sentence)) $problems[] = 'the summons sentence has two spaces in a row';
    return $problems;
}

/** document_summons_wording_problems() for a DOCX file. */
function document_summons_template_problems(string $path, array $requiredKeys): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return ['unable to open DOCX archive'];
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return ['word/document.xml is missing'];
    return document_summons_wording_problems(document_template_paragraph_text($xml), $requiredKeys);
}
