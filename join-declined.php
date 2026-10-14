<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$code = trim((string)($_GET['p'] ?? $_POST['p'] ?? ''));
$tournament = bm_tournament_by_public_token($pdo, $token);
if (!$tournament) {
    http_response_code(404);
    $pageTitle = 'Declined entries · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">This link is not valid.</p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($code === '') {
    $code = bm_own_parent_code($token);
}
if ($code !== '') {
    bm_remember_parent_code($token, $code);
}

$rows = $code !== '' ? bm_parent_declined_for_code($pdo, (int)$tournament['id'], $code) : [];
$grouped = [];
foreach ($rows as $row) {
    $catName = (string)(($row['category']['name'] ?? '') ?: 'Age');
    $grouped[$catName][] = $row;
}

$pageTitle = 'Declined entries · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<?php bm_parent_top_bar($token, $code); ?>
<p class="hint" style="margin-top:-0.4rem;"><a href="join.php?t=<?= bm_h(urlencode($token)) ?>">Back to entry form</a></p>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?></p>
    <h1>Declined entries</h1>
    <p class="lede">Only your declined players. Click a player to correct the mismatched field and submit again.</p>
  </div>
</section>
<?php if ($code === ''): ?>
<section class="panel"><p class="empty">Open this page from the tournament entry link on the same phone after you have saved an entry.</p></section>
<?php elseif (!$rows): ?>
<section class="panel"><p class="empty">No declined entries for you.</p></section>
<?php else: ?>
  <?php foreach ($grouped as $catName => $list): ?>
  <section class="panel">
    <div class="panel-head"><h2><?= bm_h($catName) ?></h2></div>
    <table class="table">
      <thead><tr><th>Player</th><th>Mismatch</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $row): ?>
        <?php $pl = $row['primary'] ?: []; ?>
        <tr>
          <td><strong><?= bm_h((string)($pl['full_name'] ?? '')) ?></strong></td>
          <td><?= bm_h(bm_decline_reason_label((string)($row['decline_reason'] ?? ''))) ?></td>
          <td class="right">
            <a class="btn btn-sm btn-primary" href="join-edit.php?t=<?= bm_h(urlencode($token)) ?>&amp;p=<?= bm_h(urlencode($code)) ?>&amp;r=<?= bm_h(urlencode((string)$row['receipt_code'])) ?>">Correct this entry</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
