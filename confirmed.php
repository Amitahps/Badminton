<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tid = (int)($_GET['id'] ?? $_GET['tournament_id'] ?? 0);
if ($tid <= 0) {
    $tid = bm_current_tournament_id();
}
$q = trim((string)($_GET['q'] ?? ''));
$view = trim((string)($_GET['view'] ?? 'all'));
if (!in_array($view, ['all', 'category'], true)) {
    $view = 'category';
}
$wrapClass = 'wrap-wide';

if ($tid <= 0) {
    $rows = $pdo->query('SELECT id, name, held_at, date_from, date_to FROM tournaments ORDER BY date_from DESC, name')->fetchAll();
    $pageTitle = 'Confirmed entries · Badminton';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="page-head">
      <div>
        <p class="eyebrow">Admin</p>
        <h1>Confirmed entries</h1>
        <p class="lede">Open a tournament to see every player the organiser has accepted, including fee pending.</p>
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
            <td class="right"><a class="btn btn-sm btn-primary" href="confirmed.php?id=<?= (int)$r['id'] ?>">Open list</a></td>
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
    bm_redirect('confirmed.php');
}

bm_remember_tournament_id($tid);
$token = bm_tournament_ensure_public_token($pdo, $tournament);
$fmt = strtolower(trim((string)($_GET['format'] ?? '')));
$catId = (int)($_GET['category_id'] ?? 0);
$rows = bm_parent_list_for_browse($pdo, $tid, ['paid', 'pending', 'na'], $q);
if ($fmt === 'csv' || $fmt === 'excel') {
    $export = bm_parent_filter_by_category_id($rows, $catId);
    $label = 'all';
    if ($catId > 0 && $export) {
        $label = (string)($export[0]['category_name'] ?? 'category');
    }
    bm_parent_output_confirmed_csv($tournament, $export, $label);
}
$grouped = bm_parent_group_by_category($rows);
$browseMode = 'confirmed';
$filterAction = 'confirmed.php';
$filterHidden = ['id' => (string)$tid, 'view' => $view];
$receiptsUrl = 'receipts.php?id=' . $tid;

$pageTitle = $tournament['name'] . ' · Confirmed entries';
require __DIR__ . '/includes/header.php';
?>
<p class="hint no-print" style="margin-top:0;"><a href="tournament.php?id=<?= $tid ?>">Back to tournament</a></p>
<?php
require __DIR__ . '/includes/browse-entries.php';
require __DIR__ . '/includes/footer.php';
