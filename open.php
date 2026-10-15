<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();

$byYear = bm_public_tournaments_by_year($pdo);
$years = array_keys($byYear);
$wantYear = trim((string)($_GET['year'] ?? ''));
if ($wantYear !== '' && isset($byYear[$wantYear])) {
    $byYear = [$wantYear => $byYear[$wantYear]];
} elseif ($wantYear !== '') {
    foreach ($byYear as $yk => $list) {
        if ((string)$yk === $wantYear) {
            $byYear = [$yk => $list];
            break;
        }
    }
}

$publicHeader = true;
$pageTitle = 'Tournaments · ' . BM_ORG_LINE;
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h(BM_ORG_LINE) ?></p>
    <h1>Tournaments</h1>
    <p class="lede">Choose a tournament. You will only see New Entry, Confirmed Entries and Declined Entries.</p>
  </div>
</section>
<?php if ($years): ?>
<nav class="page-switch" style="margin-top:-0.3rem;">
  <a class="btn <?= $wantYear === '' ? 'btn-primary' : '' ?>" href="open.php">All years</a>
  <?php foreach ($years as $y): ?>
    <a class="btn <?= (string)$wantYear === (string)$y ? 'btn-primary' : '' ?>" href="open.php?year=<?= bm_h(urlencode((string)$y)) ?>"><?= bm_h((string)$y) ?></a>
  <?php endforeach; ?>
</nav>
<?php foreach ($byYear as $year => $list): ?>
<section class="year-block">
  <h2><?= bm_h((string)$year) ?></h2>
  <div class="tour-grid">
    <?php foreach ($list as $t): ?>
      <?php
        $tok = bm_tournament_ensure_public_token($pdo, $t);
        $href = 'join.php?t=' . rawurlencode($tok);
      ?>
      <a class="choice-card" href="<?= bm_h($href) ?>">
        <strong><?= bm_h((string)$t['name']) ?></strong>
        <span><?= bm_h((string)$t['held_at']) ?><br><?= bm_h(bm_date_range((string)$t['date_from'], (string)$t['date_to'])) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<?php else: ?>
<section class="panel">
  <p class="empty">No tournament is open on the website yet. Ask the organiser, or use the link they sent you.</p>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
