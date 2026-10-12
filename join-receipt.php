<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();

$token = trim((string)($_GET['t'] ?? ''));
$receipt = trim((string)($_GET['r'] ?? ''));
$tournament = bm_tournament_by_public_token($pdo, $token);
if (!$tournament) {
    http_response_code(404);
    $pageTitle = 'Entry · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">This link is not valid.</p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$row = bm_parent_load_receipt($pdo, $receipt, (int)$tournament['id']);
if (!$row) {
    http_response_code(404);
    $pageTitle = 'Your entry · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">No entry found for this link. Use the copy you received after Save — other parents’ details are not shown.</p>';
    echo '<p><a class="btn" href="join.php?t=' . bm_h(urlencode($token)) . '">Back to entry form</a></p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$p = $row['primary'] ?: [];
$cat = $row['category'] ?: [];
$eventLabels = [];
foreach ($row['events'] as $code) {
    $eventLabels[] = bm_event_label($code);
}

$pageTitle = 'Your entry · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';

$showPlayer = static function (array $pl): void {
    if (!$pl) {
        echo '<p class="empty">—</p>';
        return;
    }
    echo '<div class="player-meta">';
    echo '<div><dt>Name</dt><dd>' . bm_h((string)($pl['full_name'] ?? '')) . '</dd></div>';
    echo '<div><dt>Gender</dt><dd>' . bm_h(bm_gender_label((string)($pl['gender'] ?? ''))) . '</dd></div>';
    echo '<div><dt>BAI ID</dt><dd>' . bm_h(trim((string)($pl['bai_id'] ?? '')) !== '' ? (string)$pl['bai_id'] : '—') . '</dd></div>';
    echo '<div><dt>PBA ID</dt><dd>' . bm_h(trim((string)($pl['pbi_id'] ?? '')) !== '' ? (string)$pl['pbi_id'] : '—') . '</dd></div>';
    echo '</div>';
    $addr = bm_player_address($pl);
    echo '<p><strong>Address:</strong> ' . bm_h($addr !== '' ? $addr : '—') . '</p>';
};
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Your copy only</p>
    <h1>Details sent</h1>
    <p class="lede">This page shows the entry you saved for <strong><?= bm_h($tournament['name']) ?></strong>. Other parents cannot see this. Keep this page or this link for your records.</p>
  </div>
  <div class="page-actions no-print">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <a class="btn" href="join.php?t=<?= bm_h(urlencode($token)) ?>">Enter another age category</a>
  </div>
</section>

<section class="panel">
  <p><strong>Age category:</strong> <?= bm_h((string)($cat['name'] ?? '')) ?></p>
  <p><strong>Events:</strong> <?= bm_h(implode(', ', $eventLabels)) ?></p>
  <h2>Player</h2>
  <?php $showPlayer($p); ?>

  <?php if ($row['double_partner']): ?>
    <h2>Doubles partner</h2>
    <?php $showPlayer($row['double_partner']); ?>
  <?php endif; ?>

  <?php if ($row['mix_partner']): ?>
    <h2>Mix Double partner</h2>
    <?php $showPlayer($row['mix_partner']); ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
