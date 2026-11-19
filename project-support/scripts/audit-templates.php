<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$templateDir = $root . DIRECTORY_SEPARATOR . 'document_templates';
require_once $root . DIRECTORY_SEPARATOR . 'db.php';
require_once $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . '_registry_legacy.php';
require_once $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . '_template_check.php';
function audit_xml_text(string $xml): string
{
    $dom = new DOMDocument();
    if (@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return trim(preg_replace('/\s+/u', ' ', $dom->textContent ?? '') ?? '');
    }
    return trim(preg_replace('/<[^>]+>/', ' ', html_entity_decode($xml, ENT_XML1, 'UTF-8')) ?? '');
}

function audit_paragraphs(string $xml): array
{
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return [];
    }
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $paragraphs = [];
    foreach ($xpath->query('//w:p') as $paragraph) {
        $text = '';
        foreach ($xpath->query('.//w:t', $paragraph) as $textNode) {
            $text .= $textNode->textContent;
        }
        $paragraphs[] = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
    return array_values(array_filter($paragraphs, static fn(string $text): bool => $text !== ''));
}

function audit_keys(string $text): array
{
    preg_match_all('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/', $text, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

// Active version file name => document type. Stays null when the versions table
// (or the database) is unavailable, and then every DOCX is audited as before.
$activeFiles = null;
$map = [];
foreach (LEGACY_DOCUMENT_MAP as $documentType => $config) {
    $map[$documentType] = ['file' => $config['file'], 'placeholders' => $config['placeholders']];
}
try {
    $db = db_connection();
    $table = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'document_types' LIMIT 1");
    if ($table && $table->fetch_row()) {
        $result = $db->query('SELECT id, name, template_file FROM document_types');
        $registryMap = [];
        while ($row = $result->fetch_assoc()) {
            $fields = $db->prepare('SELECT field_key FROM document_type_fields WHERE document_type_id=? ORDER BY sort_order,id');
            $fields->bind_param('i', $row['id']); $fields->execute();
            $keys = array_column($fields->get_result()->fetch_all(MYSQLI_ASSOC), 'field_key'); $fields->close();
            $registryMap[$row['name']] = ['file' => $row['template_file'], 'placeholders' => $keys];
        }
        if ($registryMap) $map = $registryMap;
        $versionsTable = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'document_template_versions' LIMIT 1");
        if ($versionsTable && $versionsTable->fetch_row()) {
            $active = $db->query("SELECT dt.name, v.file_name FROM document_template_versions v JOIN document_types dt ON dt.id = v.document_type_id WHERE v.status = 'active' ORDER BY dt.sort_order, dt.id");
            if ($active) {
                $activeRows = [];
                while ($row = $active->fetch_assoc()) {
                    $activeRows[(string)$row['file_name']] = (string)$row['name'];
                }
                foreach ($activeRows as $fileName => $typeName) {
                    if (isset($map[$typeName])) $map[$typeName]['file'] = $fileName;
                }
                $activeFiles = $activeRows;
            }
        }
    }
} catch (Throwable $e) {
    // The audit remains useful before the database is installed.
}
$strict = in_array('--strict', array_slice($argv, 1), true);
$files = glob($templateDir . DIRECTORY_SEPARATOR . '*.docx') ?: [];
sort($files);
$problems = 0;
$audited = 0;
$skipped = 0;

echo "Document template audit\n";
echo "=======================\n";
echo "Template directory: {$templateDir}\n";
echo "Mode: " . ($strict ? 'strict' : 'report') . "\n\n";

foreach ($files as $path) {
    $name = basename($path);
    echo "TEMPLATE: {$name}\n";
    if ($activeFiles !== null && !isset($activeFiles[$name])) {
        echo "  skipped (not an active version)\n\n";
        $skipped++;
        continue;
    }
    $audited++;
    $templateProblems = [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        echo "  ERROR: unable to open DOCX archive\n\n";
        $problems++;
        continue;
    }

    $parts = [];
    $allText = '';
    $paragraphContexts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (!is_string($entry) || !preg_match('#^word/[^/]+\.xml$#', $entry)) {
            continue;
        }
        $xml = $zip->getFromIndex($i);
        if ($xml === false) {
            continue;
        }
        $rawKeys = audit_keys($xml);
        $paragraphKeys = [];
        foreach (audit_paragraphs($xml) as $paragraph) {
            $paragraphKeys = array_merge($paragraphKeys, audit_keys($paragraph));
        }
        $paragraphKeys = array_values(array_unique($paragraphKeys));
        $splitKeys = [];
        foreach ($paragraphKeys as $key) {
            if (!in_array($key, $rawKeys, true)) {
                $splitKeys[] = $key;
            }
        }
        $parts[$entry] = [
            'keys' => $rawKeys,
            'paragraph_keys' => $paragraphKeys,
            'split_keys' => $splitKeys,
            'text' => audit_xml_text($xml),
            'paragraphs' => audit_paragraphs($xml),
            'raw_xml' => $xml,
        ];
        $allText .= "\n" . $parts[$entry]['text'];
        foreach ($parts[$entry]['paragraphs'] as $paragraph) {
            $paragraphContexts[] = [$entry, $paragraph];
        }
    }

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (is_string($entry)) {
            $names[] = $entry;
        }
    }
    $zip->close();

    echo "  Parts:\n";
    foreach ($parts as $part => $data) {
        echo "    {$part}: " . ($data['keys'] ? implode(', ', $data['keys']) : '(none)') . "\n";
    }

    $mapped = [];
    foreach ($map as $documentType => $config) {
        if ($config['file'] === $name) {
            $mapped = $config['placeholders'];
            break;
        }
    }
    $found = [];
    foreach ($parts as $part) {
        $found = array_merge($found, $part['keys']);
    }
    $found = array_values(array_unique($found));
    $missing = array_values(array_diff($mapped, ['official_position'], $found));
    $templateProblems = array_merge($templateProblems, document_template_check($path, $mapped));
    if ($missing) {
        $templateProblems[] = 'mapped keys missing from template: ' . implode(', ', $missing);
    }

    foreach ($paragraphContexts as [$part, $paragraph]) {
        if ($paragraph !== 'OFFICE OF THE BARANGAY CAPTAIN' && preg_match('/\b(?:Hon\.|Honorable|Barangay Captain)\b/i', $paragraph)) {
            $templateProblems[] = "typed static signatory text in {$part}: {$paragraph}";
        }
    }
    $hasHeaderFooter = (bool)preg_grep('#^word/(header|footer)[^/]*\.xml$#', $names);
    if ($hasHeaderFooter) {
        $templateProblems[] = 'headers or footers are present';
    }
    $xmlParts = [];
    $zip = new ZipArchive();
    if ($zip->open($path) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (is_string($entry) && str_ends_with($entry, '.xml')) {
                $xml = $zip->getFromIndex($i);
                if ($xml !== false) {
                    $xmlParts[$entry] = $xml;
                }
            }
        }
        $zip->close();
    }
    if (array_filter($xmlParts, static fn(string $xml): bool => str_contains($xml, 'w:txbxContent'))) {
        $templateProblems[] = 'text boxes (w:txbxContent) are present';
    }
    if (array_filter($xmlParts, static fn(string $xml): bool => str_contains($xml, '<w:ins') || str_contains($xml, '<w:del'))) {
        $templateProblems[] = 'tracked changes are present';
    }
    if (array_filter($xmlParts, static fn(string $xml): bool => str_contains($xml, 'w:commentRangeStart') || str_contains($xml, '<w:comment'))) {
        $templateProblems[] = 'comments are present';
    }
    if (array_filter($names, static fn(string $entry): bool => str_starts_with($entry, 'word/embeddings/') || str_contains($entry, 'oleObject'))) {
        $templateProblems[] = 'embedded objects are present';
    }
    if (in_array('vbaProject.bin', array_map('basename', $names), true)) {
        $templateProblems[] = 'vbaProject.bin is present';
    }

    if ($templateProblems) {
        foreach (array_values(array_unique($templateProblems)) as $problem) {
            echo "  PROBLEM: {$problem}\n";
        }
        $problems++;
    } else {
        echo "  OK: no problems found\n";
    }
    echo "\n";
}

if ($activeFiles !== null) {
    $present = array_map('basename', $files);
    foreach ($activeFiles as $fileName => $typeName) {
        if (!in_array($fileName, $present, true)) {
            echo "TEMPLATE: {$fileName}\n";
            echo "  PROBLEM: active template file is missing ({$typeName})\n\n";
            $problems++;
        }
    }
    echo "Summary: {$audited} template(s) audited, {$skipped} skipped, {$problems} template(s) with problems.\n";
} else {
    echo "Summary: " . count($files) . " template(s), {$problems} template(s) with problems.\n";
}
exit($strict && $problems > 0 ? 1 : 0);
