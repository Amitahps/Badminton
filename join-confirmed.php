<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();

$token = trim((string)($_GET['t'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$tournament = bm_tournament_by_public_token($pdo, $token);
if (!$tournament) {
    http_response_code(404);
    $pageTitle = 'Confirmed entries · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">This link is not valid.</p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$view = trim((string)($_GET['view'] ?? 'category'));
if (!in_array($view, ['all', 'category'], true)) {
    $view = 'category';
}
$rows = bm_parent_rows_on_saved_teams(
    $pdo,
    (int)$tournament['id'],
    bm_parent_list_for_browse($pdo, (int)$tournament['id'], ['paid', 'pending', 'na'], $q)
);
$allowExport = false;
$browseLede = bm_parent_confirmed_note($tournament);
$grouped = bm_parent_group_by_category($rows);
$browseMode = 'confirmed';
$filterAction = 'join-confirmed.php';
$filterHidden = ['t' => $token, 'view' => $view];
$receiptsUrl = 'join-receipts.php?t=' . rawurlencode($token);
$parentCode = bm_own_parent_code($token);
$parentOwnCode = $parentCode;

$wrapClass = 'wrap-wide';
$pageTitle = $tournament['name'] . ' · Confirmed entries';
$browseHideTitle = true;
$parentPhoneList = true;
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <h1><?= bm_h((string)$tournament['name']) ?></h1>
  </div>
</section>
<?php
bm_parent_top_bar($token, $parentCode, 'confirmed', bm_parent_mistake_pdf_html($token, $parentCode));
require __DIR__ . '/includes/browse-entries.php';
require __DIR__ . '/includes/footer.php';
