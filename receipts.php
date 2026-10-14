<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tid = (int)($_GET['id'] ?? $_GET['tournament_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$wrapClass = 'wrap-wide';

if ($tid <= 0) {
    $rows = $pdo->query('SELECT id, name, held_at, date_from, date_to FROM tournaments ORDER BY date_from DESC, name')->fetchAll();
    $pageTitle = 'Download receipts · Badminton';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="page-head">
      <div>
        <p class="eyebrow">Admin</p>
        <h1>Download receipts</h1>
        <p class="lede">Open a tournament to download receipts for players whose online payment is confirmed. Fee-pending entries are not listed.</p>
      </div>
    </section>
    <section class="panel">
      <?php if (!$rows): ?>
      <p class="empty">No tournament yet.</p>
      <?php else: ?>
      <table class="table">
        <thead><tr><th>Tournament</th><th>Held at</th><th>Dates</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><strong><?= bm_h((string)$r['name']) ?></strong></td>
            <td><?= bm_h((string)$r['held_at']) ?></td>
            <td><?= bm_h(bm_date_range((string)$r['date_from'], (string)$r['date_to'])) ?></td>
            <td class="right"><a class="btn btn-sm btn-primary" href="receipts.php?id=<?= (int)$r['id'] ?>">Open receipts</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$st = $pdo->prepare('SELECT * FROM tournaments WHERE id=?');
$st->execute([$tid]);
$tournament = $st->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('receipts.php');
}

$token = bm_tournament_ensure_public_token($pdo, $tournament);
$rows = bm_parent_list_for_browse($pdo, $tid, ['paid'], $q);
$grouped = bm_parent_group_by_category($rows);
$browseMode = 'receipts';
$filterAction = 'receipts.php';
$filterHidden = ['id' => (string)$tid];

$pageTitle = $tournament['name'] . ' · Download receipts';
require __DIR__ . '/includes/header.php';
?>
<p class="hint no-print" style="margin-top:0;"><a href="receipts.php">All tournaments</a> · <a href="tournament.php?id=<?= $tid ?>">Back to tournament</a> · <a href="confirmed.php?id=<?= $tid ?>">Confirmed entries</a></p>
<?php
require __DIR__ . '/includes/browse-entries.php';
require __DIR__ . '/includes/footer.php';
