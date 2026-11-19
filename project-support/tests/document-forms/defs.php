<?php
/**
 * BarangayLink - Helper for the Phase 5B frontend test (CLI only, no database).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 *   php defs.php            prints {messages, catalogue, types} from the PHP constants
 *   php defs.php rules      reads [[field, value], ...] as JSON on stdin and prints
 *                           what document_field_rule_error() answers for each
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}
$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/documents/_type_create.php';

if (($argv[1] ?? '') === 'rules') {
    $cases = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($cases)) { fwrite(STDERR, "invalid cases\n"); exit(2); }
    $out = [];
    foreach ($cases as [$field, $value]) $out[] = document_field_rule_error($field, (string)$value);
    echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
// The five seeded types as the registry seeds them: placeholder order from the
// legacy map, definitions from the catalogue (the 5A suite asserts catalogue = seed).
$types = [];
foreach (LEGACY_DOCUMENT_MAP as $name => $config) {
    $fields = [];
    foreach ($config['placeholders'] as $key) $fields[] = array_merge(['field_key' => $key], DOCUMENT_FIELD_CATALOGUE[$key]);
    $types[$name] = $fields;
}
echo json_encode(['messages' => DOCUMENT_FIELD_MESSAGES, 'catalogue' => DOCUMENT_FIELD_CATALOGUE, 'types' => $types], JSON_UNESCAPED_UNICODE), "\n";
