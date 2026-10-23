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

if ((string)($row['payment_status'] ?? '') !== 'paid') {
    http_response_code(403);
    $pageTitle = 'Receipt · ' . $tournament['name'];
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">A receipt is available only after the organiser confirms the online payment. Entries with fee still pending do not appear in the receipt list.</p>';
    echo '<p><a class="btn" href="join-receipts.php?t=' . bm_h(urlencode($token)) . '">Back to receipts</a> ';
    echo '<a class="btn" href="join-confirmed.php?t=' . bm_h(urlencode($token)) . '">Confirmed entries</a></p></section>';
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

$pageTitle = 'Receipt · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
bm_parent_top_bar($token, bm_own_parent_code($token), 'confirmed');

$blockPlayer = static function (string $title, array $pl): void {
    echo '<section class="letter-category">';
    echo '<h2>' . bm_h($title) . '</h2>';
    echo '<table class="letter-table"><tbody>';
    echo '<tr><th>Name</th><td>' . bm_h((string)($pl['full_name'] ?? '')) . '</td></tr>';
    echo '<tr><th>Gender</th><td>' . bm_h(bm_gender_label((string)($pl['gender'] ?? ''))) . '</td></tr>';
    echo '<tr><th>BAI ID</th><td>' . bm_h(trim((string)($pl['bai_id'] ?? '')) !== '' ? (string)$pl['bai_id'] : '—') . '</td></tr>';
    echo '<tr><th>PBA ID</th><td>' . bm_h(trim((string)($pl['pbi_id'] ?? '')) !== '' ? (string)$pl['pbi_id'] : '—') . '</td></tr>';
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
    <p class="eyebrow"><?= bm_h($tournament['name']) ?></p>
    <h1>Download receipt</h1>
    <p class="lede">Print or save this receipt as PDF. Only entries with confirmed online payment can be downloaded.</p>
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
  <p>Payment receipt — online payment confirmed by the organiser.</p>
  <p><strong>Age category:</strong> <?= bm_h((string)($cat['name'] ?? '')) ?><br>
     <strong>Events:</strong> <?= bm_h(implode(', ', $eventLabels)) ?><br>
     <strong>Payment:</strong> <?= bm_h(bm_parent_payment_label((string)($row['payment_status'] ?? ''), (int)($row['payment_amount'] ?? 0))) ?>
     <?php if (trim((string)($row['payment_date'] ?? '')) !== ''): ?> · Date <?= bm_h(bm_fmt_date((string)$row['payment_date'])) ?><?php endif; ?>
     <?php if (trim((string)($row['payment_utr'] ?? '')) !== ''): ?> · UTR <?= bm_h((string)$row['payment_utr']) ?><?php endif; ?>
     <?php if (trim((string)($row['payment_sender'] ?? '')) !== ''): ?> · Sender <?= bm_h((string)$row['payment_sender']) ?><?php endif; ?>
  </p>

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
