<?php
declare(strict_types=1);
/** Redirect old URL to new teams page if params present, else tournament list. */
require_once __DIR__ . '/helpers.php';
bm_require_login();
$tid = (int)($_GET['tournament_id'] ?? 0);
$cid = (int)($_GET['category_id'] ?? 0);
if ($tid > 0 && $cid > 0) {
    bm_redirect('tournament.php?id=' . $tid);
}
bm_redirect('tournaments.php');
