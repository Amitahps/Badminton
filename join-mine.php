<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$code = trim((string)($_GET['p'] ?? $_POST['p'] ?? ''));
$tournament = bm_tournament_by_public_token($pdo, $token);
if (!$tournament) {
    http_response_code(404);
    $pageTitle = 'Your entries · Badminton';
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

$rows = $code !== '' ? bm_parent_submissions_for_code($pdo, (int)$tournament['id'], $code) : [];
if (!$rows) {
    http_response_code(404);
    $pageTitle = 'Your entries · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">No entries found for this link. Use the page you received after Save — other parents’ details are not shown.</p>';
    echo '<p><a class="btn" href="join.php?t=' . bm_h(urlencode($token)) . '">Back to entry form</a></p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$first = $rows[0];
$pageTitle = 'Your entries · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';

$blockPlayer = static function (string $title, array $pl): void {
    echo '<section class="letter-category">';
    echo '<h2>' . bm_h($title) . '</h2>';
    echo '<table class="letter-table"><tbody>';
    echo '<tr><th>Name</th><td>' . bm_h((string)($pl['full_name'] ?? '')) . '</td></tr>';
    echo '<tr><th>Gender</th><td>' . bm_h(bm_gender_label((string)($pl['gender'] ?? ''))) . '</td></tr>';
    echo '<tr><th>BAI ID</th><td>' . bm_h(trim((string)($pl['bai_id'] ?? '')) !== '' ? (string)$pl['bai_id'] : '—') . '</td></tr>';
    echo '<tr><th>PBA ID</th><td>' . bm_h(trim((string)($pl['pbi_id'] ?? '')) !== '' ? (string)$pl['pbi_id'] : '—') . '</td></tr>';
    $addr = bm_player_address($pl);
    echo '<tr><th>Address</th><td>' . bm_h($addr !== '' ? $addr : '—') . '</td></tr>';
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
    <p class="eyebrow">Your entries only</p>
    <h1>Players you sent</h1>
    <p class="lede"><?= count($rows) ?> player/age <?= count($rows) === 1 ? 'entry' : 'entries' ?> for this tournament. Print / Save as PDF. Use Edit if you made a mistake.</p>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    <a class="btn" href="join.php?t=<?= bm_h(urlencode($token)) ?>">Add another player</a>
  </div>
</section>

<article class="letter-sheet" id="mine-sheet">
  <p class="letter-date">Date: <?= bm_h(date('d-m-Y')) ?></p>
  <h1 style="font-size:1.35rem;margin:0 0 0.6rem;"><?= bm_h($tournament['name']) ?></h1>
  <p>Held at <strong><?= bm_h($tournament['held_at']) ?></strong><br>
     Dates: <strong><?= bm_h($dateText) ?></strong></p>
  <p><strong>Father’s name:</strong> <?= bm_h((string)($first['father_name'] ?? '')) ?><br>
     <strong>Contact:</strong> <?= bm_h((string)($first['contact_mobile'] ?? '')) ?></p>

  <?php foreach ($rows as $i => $row): ?>
    <?php
      $eventLabels = [];
      foreach ($row['events'] as $ec) {
          $eventLabels[] = bm_event_label($ec);
      }
      $cat = $row['category'] ?: [];
    ?>
    <section class="letter-category">
      <h2>Entry <?= $i + 1 ?> — <?= bm_h((string)($cat['name'] ?? 'Age')) ?></h2>
      <p><strong>Events:</strong> <?= bm_h(implode(', ', $eventLabels)) ?><br>
         <strong>Payment:</strong> <?= bm_h(bm_parent_payment_label((string)($row['payment_status'] ?? ''), (int)($row['payment_amount'] ?? 0))) ?>
         <?php if (trim((string)($row['payment_utr'] ?? '')) !== ''): ?>
           · UTR <?= bm_h((string)$row['payment_utr']) ?>
         <?php endif; ?>
      </p>
      <p class="no-print"><a class="btn btn-sm" href="join-edit.php?t=<?= bm_h(urlencode($token)) ?>&amp;p=<?= bm_h(urlencode($code)) ?>&amp;r=<?= bm_h(urlencode((string)$row['receipt_code'])) ?>">Edit this entry</a></p>
    </section>
    <?php $blockPlayer('Player', $row['primary'] ?: []); ?>
    <?php if ($row['double_partner']) {
        $blockPlayer('Doubles partner', $row['double_partner']);
    } ?>
    <?php if ($row['mix_partner']) {
        $blockPlayer('Mix Double partner', $row['mix_partner']);
    } ?>
  <?php endforeach; ?>
  <p class="muted">Private copy — not visible to other parents.</p>
</article>
<?php require __DIR__ . '/includes/footer.php'; ?>
