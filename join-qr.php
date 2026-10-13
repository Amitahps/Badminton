<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();
$token = trim((string)($_GET['t'] ?? ''));
$tournament = bm_tournament_by_public_token($pdo, $token);
$f = $tournament ? basename((string)($tournament['qr_file'] ?? '')) : '';
if (!$tournament || $f === '' || strpos($f, '..') !== false) {
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
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
