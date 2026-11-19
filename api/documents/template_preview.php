<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_registry.php';
session_start();
require_official_session();
$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) { http_response_code(404); exit; }
$path = document_storage_dir('previews') . DIRECTORY_SEPARATOR . $token . '.pdf';
if (!is_file($path)) { http_response_code(404); exit; }
header_remove('Content-Type'); header('Content-Type: application/pdf'); header('Content-Disposition: inline'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); header('Content-Length: ' . filesize($path));
readfile($path);
