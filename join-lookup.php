<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = bm_db();
$token = trim((string)($_GET['t'] ?? ''));
if (!bm_tournament_by_public_token($pdo, $token)) {
    http_response_code(404);
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode(
    bm_parent_lookup_details($pdo, (string)($_GET['bai'] ?? ''), (string)($_GET['pbi'] ?? '')),
    JSON_UNESCAPED_UNICODE
);
