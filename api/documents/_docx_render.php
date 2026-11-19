<?php
/**
 * BarangayLink - Shared DOCX fill + LibreOffice PDF conversion.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Used by document requests (api/requests/_render.php) and summons
 * (api/hearings/_summons.php). Moved out of request_render_document() without
 * changing messages, statuses or exception classes:
 *  - document_fill_docx() throws RequestProblem(500, 'conversion_failed') for
 *    template problems (same texts as before);
 *  - document_convert_pdf() throws RuntimeException when LibreOffice is missing,
 *    times out, fails, or produces no valid PDF.
 * Only word/document.xml is filled; headers and footers are not processed.
 */

require_once __DIR__ . '/../requests/_generation.php';

/**
 * Copy the template to $workingDocx and replace every {{key}} of
 * $placeholderKeys with the XML-escaped value ('' when missing). Refuses a
 * template that still has an unresolved / unknown placeholder.
 */
function document_fill_docx(string $templatePath, array $placeholderKeys, array $values, string $workingDocx): void {
    $fail = static function (string $message): never { throw new RequestProblem($message, 500, 'conversion_failed'); };
    if (!copy($templatePath, $workingDocx)) {
        $fail('Failed to create working document from template.');
    }

    if (!class_exists('ZipArchive')) {
        $fail('PHP ZipArchive extension is not enabled.');
    }

    $zip = new ZipArchive();
    if ($zip->open($workingDocx) !== true) {
        $fail('Failed to open template document archive.');
    }

    $documentXml = $zip->getFromName('word/document.xml');
    if ($documentXml === false) {
        $zip->close();
        $fail('Template is missing word/document.xml.');
    }

    $templateXml = $documentXml;
    foreach ($placeholderKeys as $key) {
        $templateXml = str_replace('{{' . $key . '}}', '', $templateXml);
    }
    if (str_contains($templateXml, '{{') || str_contains($templateXml, '}}')) {
        preg_match_all('/\{\{[^}]*\}\}/', $templateXml, $unresolved);
        $keys = array_values(array_unique($unresolved[0] ?? []));
        error_log('Document template has unresolved placeholders: ' . implode(', ', $keys));
        $zip->close();
        $fail('Document template is invalid.');
    }
    $replacements = [];
    foreach ($placeholderKeys as $key) {
        $replacements['{{' . $key . '}}'] = htmlspecialchars((string)($values[$key] ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
    $documentXml = strtr($documentXml, $replacements);

    if (!$zip->addFromString('word/document.xml', $documentXml)) {
        $zip->close();
        $fail('Failed to write updated document XML.');
    }
    $zip->close();
}

/**
 * Convert $workingDocx (named <operation>.docx inside $tempDir) to
 * $tempDir/<operation>.pdf with LibreOffice (LIBREOFFICE_PATH, else the default
 * install path) and a private profile inside $tempDir. Returns the PDF path.
 */
function document_convert_pdf(string $workingDocx, string $tempDir, string $operation): string {
    $soffice = trim((string)(getenv('LIBREOFFICE_PATH') ?: ''));
    if ($soffice === '') $soffice = 'C:/Program Files/LibreOffice/program/soffice.exe';
    if (!is_file($soffice)) throw new RuntimeException('LibreOffice executable is unavailable. Set LIBREOFFICE_PATH in .env.');
    $profilePath = str_replace('\\', '/', $tempDir . '/profile');
    $profile = 'file:///' . implode('/', array_map('rawurlencode', explode('/', $profilePath)));
    // Preserve the drive colon in a Windows file URI.
    $profile = str_replace('%3A', ':', $profile);
    request_convert([$soffice, '-env:UserInstallation=' . $profile, '--headless', '--convert-to', 'pdf', '--outdir', $tempDir, $workingDocx], $tempDir);
    $pdf = $tempDir . '/' . $operation . '.pdf';
    if (!request_valid_pdf($pdf)) throw new RuntimeException('This operation did not produce a valid PDF.');
    return $pdf;
}
