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
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$receiptUrl = bm_join_url($token);
$receiptUrl = preg_replace('/join\.php\?t=/', 'join-receipt.php?t=', $receiptUrl) ?: $receiptUrl;
$receiptUrl .= '&r=' . rawurlencode($receipt);

$pageTitle = 'Entry copy · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';

$blockPlayer = static function (string $title, array $pl): void {
    echo '<section class="letter-category">';
    echo '<h2>' . bm_h($title) . '</h2>';
    echo '<table class="letter-table"><tbody>';
    echo '<tr><th>Name</th><td>' . bm_h((string)($pl['full_name'] ?? '')) . '</td></tr>';
    echo '<tr><th>Gender</th><td>' . bm_h(bm_gender_label((string)($pl['gender'] ?? ''))) . '</td></tr>';
    echo '<tr><th>BAI ID</th><td>' . bm_h(trim((string)($pl['bai_id'] ?? '')) !== '' ? (string)$pl['bai_id'] : '—') . '</td></tr>';
    echo '<tr><th>PBI ID</th><td>' . bm_h(trim((string)($pl['pbi_id'] ?? '')) !== '' ? (string)$pl['pbi_id'] : '—') . '</td></tr>';
    echo '<tr><th>Father’s name</th><td>' . bm_h(trim((string)($pl['father_name'] ?? '')) !== '' ? (string)$pl['father_name'] : '—') . '</td></tr>';
    echo '<tr><th>Contact</th><td>' . bm_h(trim((string)($pl['mobile'] ?? '')) !== '' ? (string)$pl['mobile'] : '—') . '</td></tr>';
    $addr = bm_player_address($pl);
    echo '<tr><th>District</th><td>' . bm_h($addr !== '' ? $addr : '—') . '</td></tr>';
    echo '</tbody></table></section>';
};
?>
<style>
@media print {
  .topbar, .no-print, .flash { display: none !important; }
  body { background: #fff; }
  .wrap { max-width: none; padding: 0; margin: 0; width: auto; }
  .letter-sheet { box-shadow: none; border: none; margin: 0; padding: 12mm 14mm; }
}
</style>
<section class="page-head no-print">
  <div>
    <p class="eyebrow">Your copy only</p>
    <h1>Share / print PDF</h1>
    <p class="lede">Save this as PDF and share it with the player’s parents to cross-check against the tournament list. Other parents cannot see this page.</p>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    <button type="button" class="btn" id="copy-receipt">Copy this page link</button>
    <a class="btn" href="join.php?t=<?= bm_h(urlencode($token)) ?>">Enter another age category</a>
  </div>
</section>

<article class="letter-sheet" id="receipt-sheet">
  <p class="letter-date">Date: <?= bm_h(date('d-m-Y')) ?></p>
  <h1 style="font-size:1.35rem;margin:0 0 0.6rem;"><?= bm_h($tournament['name']) ?></h1>
  <p>Held at <strong><?= bm_h($tournament['held_at']) ?></strong><br>
     Dates: <strong><?= bm_h($dateText) ?></strong></p>
  <p>This is the entry submitted for this tournament. Please cross-check name, age category, events, BAI ID, PBI ID, father, contact and district with the tournament list.</p>
  <p><strong>Age category:</strong> <?= bm_h((string)($cat['name'] ?? '')) ?><br>
     <strong>Events:</strong> <?= bm_h(implode(', ', $eventLabels)) ?></p>

  <?php $blockPlayer('Player', $p); ?>
  <?php if ($row['double_partner']) {
      $blockPlayer('Doubles partner', $row['double_partner']);
  } ?>
  <?php if ($row['mix_partner']) {
      $blockPlayer('Mix Double partner', $row['mix_partner']);
  } ?>
  <p class="muted">Private copy — not visible to other parents.</p>
</article>
<script>
(function(){
  var btn = document.getElementById('copy-receipt');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var t = <?= json_encode($receiptUrl) ?>;
    function ok(){ var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = o; }, 1600); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(ok).catch(function(){ window.prompt('Copy this link:', t); });
    } else { window.prompt('Copy this link:', t); }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
