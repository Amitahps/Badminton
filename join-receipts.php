<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();

$token = trim((string)($_GET['t'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$tournament = bm_tournament_by_public_token($pdo, $token);
if (!$tournament) {
    http_response_code(404);
    $pageTitle = 'Download receipt · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">This link is not valid.</p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$rows = bm_parent_list_for_browse($pdo, (int)$tournament['id'], ['paid'], $q);
$grouped = bm_parent_group_by_category($rows);
$browseMode = 'receipts';
$filterAction = 'join-receipts.php';
$filterHidden = ['t' => $token];
$parentCode = bm_own_parent_code($token);

$pageTitle = $tournament['name'] . ' · Download receipt';
require __DIR__ . '/includes/header.php';
bm_parent_top_bar($token, $parentCode);
require __DIR__ . '/includes/browse-entries.php';
require __DIR__ . '/includes/footer.php';
