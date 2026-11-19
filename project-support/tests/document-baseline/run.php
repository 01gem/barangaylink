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

require dirname(__DIR__, 3) . '/api/requests/_render.php';

$root = dirname(__DIR__, 3);
$goldenDir = __DIR__ . DIRECTORY_SEPARATOR . 'golden';
$update = in_array('--update', array_slice($argv, 1), true);
$templateMap = [];
foreach (LEGACY_DOCUMENT_MAP as $type => $config) {
    $templateMap[$type] = [
        'file' => $config['file'],
        'keys' => array_values(array_filter($config['placeholders'], static fn(string $key): bool => $key !== 'official_position')),
        'slug' => preg_replace('/\.docx$/i', '', $config['file']),
    ];
}
$fixtures = [
    'full_name' => 'Baseline Resident',
    'age' => '42',
    'civil_status' => 'Married',
    'address' => 'Purok 1',
    'purpose' => 'Baseline verification',
    'date_issued' => 'November 07, 2026',
    'reference_no' => 'BASELINE-REF-0001',
    'official_name' => 'Baseline Official',
    'years_of_residency' => '12',
    'owner_name' => 'Baseline Resident',
    'business_name' => 'Baseline Store',
    'business_type' => 'Sari-sari store',
    'business_address' => 'Purok 1',
];

function baseline_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($full) ? baseline_remove_tree($full) : @unlink($full);
    }
    @rmdir($path);
}

function baseline_document_text(string $docx): string
{
    $zip = new ZipArchive();
    if ($zip->open($docx) !== true) {
        throw new RuntimeException('Unable to open generated DOCX.');
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('Generated DOCX is missing word/document.xml.');
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET)) {
        throw new RuntimeException('Generated document XML is invalid.');
    }
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $lines = [];
    foreach ($xpath->query('//w:body/w:p') as $paragraph) {
        $text = '';
        foreach ($xpath->query('.//w:t', $paragraph) as $node) {
            $text .= $node->textContent;
        }
        $lines[] = trim($text);
    }
    return implode("\n", $lines) . "\n";
}

function baseline_pdf_pages(string $pdf): int
{
    $contents = file_get_contents($pdf);
    if ($contents === false) {
        throw new RuntimeException('Unable to read generated PDF.');
    }
    // Approximate: this counts page objects by their /Type /Page marker.
    preg_match_all('/\/Type\s*\/Page\b/', $contents, $matches);
    return count($matches[0]);
}

if (!is_dir($goldenDir) && !mkdir($goldenDir, 0777, true)) {
    throw new RuntimeException('Unable to create golden directory.');
}

$workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barangay-baseline-' . bin2hex(random_bytes(8));
if (!mkdir($workspace, 0700)) {
    throw new RuntimeException('Unable to create baseline workspace.');
}
$pages = [];
$failures = 0;
try {
    foreach ($templateMap as $type => $config) {
        $request = ['document_type' => $type, 'resident_name' => 'Baseline Resident', 'address' => $fixtures['address'], 'reference_no' => 'BASELINE-REF-0001'];
        $fields = [];
        foreach ($config['keys'] as $key) {
            $fields[$key] = $fixtures[$key] ?? 'Baseline value';
        }
        $result = request_render_document($request, $fields, $workspace, 'BASELINE-REF-0001', [
            'type' => ['name' => $type, 'template_file' => $config['file']],
            'fields' => array_map(static fn(string $key): array => ['field_key' => $key], $config['keys']),
            'captain_name' => 'Erwin Astronomo',
        ]);
        $text = baseline_document_text($result['docx']);
        $pages[$config['slug']] = baseline_pdf_pages($result['pdf']);
        if (preg_match('/\{\{[^}]+\}\}/', $text)) {
            echo "WARNING {$type}: unresolved {{...}} remains in baseline output\n";
        }
        $goldenPath = $goldenDir . DIRECTORY_SEPARATOR . $config['slug'] . '.txt';
        if ($update) {
            file_put_contents($goldenPath, $text);
            echo "UPDATED {$type}: {$goldenPath}\n";
            continue;
        }
        $expected = is_file($goldenPath) ? file_get_contents($goldenPath) : null;
        if ($expected === $text) {
            echo "PASS {$type}\n";
        } else {
            echo "DIFF {$type}\n";
            $failures++;
            $expectedLines = $expected === null ? [] : explode("\n", rtrim($expected, "\n"));
            $actualLines = explode("\n", rtrim($text, "\n"));
            $max = max(count($expectedLines), count($actualLines));
            for ($i = 0; $i < $max; $i++) {
                $before = $expectedLines[$i] ?? '';
                $after = $actualLines[$i] ?? '';
                if ($before !== $after) {
                    echo sprintf("  line %d: -%s +%s\n", $i + 1, $before, $after);
                }
            }
        }
    }
    $extraRequest = [
        'document_type' => 'Barangay Clearance',
        'resident_name' => 'Baseline Resident',
        'address' => 'Purok 1, Brgy. Sampaguita',
        'reference_no' => 'BASELINE-ISO-0001',
    ];
    $extra = request_render_document($extraRequest, [
        'full_name' => 'Baseline Resident',
        'age' => '42',
        'civil_status' => 'Married',
        'address' => 'Purok 1, Brgy. Sampaguita',
        'purpose' => 'Baseline verification',
        'date_issued' => '2026-11-07',
        'reference_no' => 'BASELINE-ISO-0001',
        'official_name' => 'Erwin Astronomo',
    ], $workspace, 'BASELINE-ISO-0001', [
        'type' => ['name' => 'Barangay Clearance', 'template_file' => 'barangay_clearance.docx'],
        'fields' => array_map(static fn(string $key): array => ['field_key' => $key], $templateMap['Barangay Clearance']['keys']),
        'captain_name' => 'Erwin Astronomo',
    ]);
    $extraText = baseline_document_text($extra['docx']);
    $extraOk = str_contains($extraText, 'Purok 1, Barangay Sampaguita.')
        && str_contains($extraText, 'Issued this November 07, 2026')
        && !str_contains($extraText, 'Purok 1, Brgy. Sampaguita, Barangay Sampaguita');
    echo $extraOk ? "PASS Barangay Clearance ISO/address normalization\n" : "FAIL Barangay Clearance ISO/address normalization\n";
    if (!$extraOk) $failures++;
    $pagesPath = $goldenDir . DIRECTORY_SEPARATOR . 'pages.json';
    if ($update) {
        file_put_contents($pagesPath, json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } elseif (file_get_contents($pagesPath) !== json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL) {
        echo "DIFF pages.json\n";
        $failures++;
    }
} finally {
    baseline_remove_tree($workspace);
}

if ($update) {
    echo "UPDATED pages.json\n";
    exit(0);
}
echo $failures === 0 ? "Baseline comparison passed.\n" : "Baseline comparison failed: {$failures} type(s).\n";
exit($failures === 0 ? 0 : 1);
