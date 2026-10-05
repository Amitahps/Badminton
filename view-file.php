<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();

$f = basename((string)($_GET['f'] ?? ''));
if ($f === '' || str_contains($f, '..')) {
    http_response_code(404);
    exit('Not found');
}
$path = BM_UPLOAD_DIR . '/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$types = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
