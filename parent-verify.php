<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tid = (int)($_GET['tournament_id'] ?? 0);
if ($tid <= 0) {
    $tid = bm_current_tournament_id();
}
$cid = (int)($_GET['category_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$fmt = strtolower(trim((string)($_GET['format'] ?? '')));
$view = trim((string)($_GET['view'] ?? 'all'));
if (!in_array($view, ['all', 'category'], true)) {
    $view = 'all';
}
$wrapClass = 'wrap-wide';

$tournaments = $pdo->query('SELECT id, name FROM tournaments ORDER BY date_from DESC, name')->fetchAll();
$categories = $tid > 0
    ? bm_tournament_categories($pdo, $tid)
    : [];
$tournamentName = '';
if ($tid > 0) {
    foreach ($tournaments as $t) {
        if ((int)$t['id'] === $tid) {
            $tournamentName = (string)$t['name'];
            break;
        }
    }
}

$sql = "
    SELECT s.*, c.name AS category_name, c.age_group,
           tn.name AS tournament_name,
           p.full_name, p.gender, p.bai_id, p.pbi_id, p.address AS district,
           p.father_name AS player_father, p.mobile AS player_mobile,
           dp.full_name AS d_name, dp.father_name AS d_father, dp.mobile AS d_mobile, dp.address AS d_district, dp.bai_id AS d_bai, dp.pbi_id AS d_pbi,
           mp.full_name AS m_name, mp.father_name AS m_father, mp.mobile AS m_mobile, mp.address AS m_district, mp.bai_id AS m_bai, mp.pbi_id AS m_pbi
    FROM parent_submissions s
    JOIN tournaments tn ON tn.id = s.tournament_id
    JOIN age_categories c ON c.id = s.age_category_id
    JOIN players p ON p.id = s.primary_player_id
    LEFT JOIN players dp ON dp.id = s.double_partner_id
    LEFT JOIN players mp ON mp.id = s.mix_partner_id
    WHERE 1=1
";
$params = [];
if ($tid <= 0) {
    $pageTitle = 'Verify parent entries · Badminton';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="page-head">
      <div>
        <p class="eyebrow">Check</p>
        <h1>Verify parent entries</h1>
        <p class="lede">Open a tournament first. Verify then shows only that tournament.</p>
      </div>
    </section>
    <section class="panel">
      <?php if (!$tournaments): ?>
      <p class="empty">No tournament yet.</p>
      <?php else: ?>
      <table class="table">
        <thead><tr><th>Tournament</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tournaments as $t): ?>
          <tr>
            <td><strong><?= bm_h((string)$t['name']) ?></strong></td>
            <td class="right"><a class="btn btn-sm btn-primary" href="parent-verify.php?tournament_id=<?= (int)$t['id'] ?>">Open</a></td>
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
bm_remember_tournament_id($tid);
$sql .= ' AND s.tournament_id = ?';
$params[] = $tid;
if ($cid > 0) {
    $sql .= ' AND s.age_category_id = ?';
    $params[] = $cid;
}
if (in_array($status, ['review', 'declined', 'pending', 'paid', 'na'], true)) {
    $sql .= ' AND s.payment_status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= ' AND (
        p.full_name LIKE ? OR p.bai_id LIKE ? OR p.pbi_id LIKE ? OR p.mobile LIKE ? OR IFNULL(p.father_name,\'\') LIKE ?
        OR s.father_name LIKE ? OR s.contact_mobile LIKE ? OR s.payment_utr LIKE ?
        OR p.address LIKE ? OR IFNULL(dp.full_name,\'\') LIKE ? OR IFNULL(mp.full_name,\'\') LIKE ?
        OR IFNULL(dp.address,\'\') LIKE ? OR IFNULL(mp.address,\'\') LIKE ?
    )';
    $like = '%' . $q . '%';
    for ($i = 0; $i < 13; $i++) {
        $params[] = $like;
    }
}
$sql .= ' ORDER BY s.id DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$head = ['Tournament', 'Category', 'Player', 'Gender', 'BAI ID', 'PBI ID', 'Father', 'Contact', 'District', 'Events', 'Amount', 'Payment', 'UTR', 'Doubles partner', 'Partner father', 'Partner contact', 'Partner district', 'Mix partner', 'Mix father', 'Mix contact', 'Mix district'];

if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parent_entries.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head);
    $expanded = [];
    foreach ($rows as $r) {
        $codes = bm_parent_row_event_codes($r);
        foreach ($codes as $code) {
            $copy = $r;
            $copy['_excel_event'] = $code;
            $copy['_excel_group'] = trim((string)($r['category_name'] ?? 'Age') . '|' . $code);
            $expanded[] = $copy;
        }
    }
    usort($expanded, static function (array $a, array $b): int {
        $c = strcasecmp((string)($a['category_name'] ?? ''), (string)($b['category_name'] ?? ''));
        if ($c !== 0) {
            return $c;
        }
        $e = bm_event_sort_index((string)($a['_excel_event'] ?? '')) <=> bm_event_sort_index((string)($b['_excel_event'] ?? ''));
        if ($e !== 0) {
            return $e;
        }
        return strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
    });
    bm_csv_write_with_group_gaps($out, $expanded, static function (array $r): array {
        $eventCode = (string)($r['_excel_event'] ?? '');
        $eventLabel = $eventCode !== '' ? bm_event_label($eventCode) : '';
        $isDouble = in_array($eventCode, ['double_men', 'double_girls', ''], true);
        $isMix = in_array($eventCode, ['mix_double', ''], true);
        return [
            $r['tournament_name'],
            $r['category_name'],
            $r['full_name'],
            bm_gender_label((string)$r['gender']),
            $r['bai_id'],
            $r['pbi_id'],
            $r['player_father'] ?: $r['father_name'],
            $r['player_mobile'] ?: $r['contact_mobile'],
            $r['district'],
            $eventLabel,
            $r['payment_amount'],
            $r['payment_status'],
            $r['payment_utr'],
            $isDouble ? $r['d_name'] : '',
            $isDouble ? $r['d_father'] : '',
            $isDouble ? $r['d_mobile'] : '',
            $isDouble ? $r['d_district'] : '',
            $isMix ? $r['m_name'] : '',
            $isMix ? $r['m_father'] : '',
            $isMix ? $r['m_mobile'] : '',
            $isMix ? $r['m_district'] : '',
        ];
    });
    fclose($out);
    exit;
}

$groupedVerify = [];
foreach ($rows as $r) {
    $name = trim((string)($r['category_name'] ?? '')) !== '' ? (string)$r['category_name'] : 'Age';
    $groupedVerify[$name][] = $r;
}

$qs = http_build_query(array_filter([
    'tournament_id' => $tid ?: null,
    'category_id' => $cid ?: null,
    'status' => $status !== '' ? $status : null,
    'q' => $q !== '' ? $q : null,
    'view' => $view !== '' ? $view : null,
], static fn($v) => $v !== null && $v !== ''));
$viewAllUrl = 'parent-verify.php?' . http_build_query(array_filter([
    'tournament_id' => $tid, 'category_id' => $cid ?: null, 'status' => $status !== '' ? $status : null, 'q' => $q !== '' ? $q : null, 'view' => 'all',
], static fn($v) => $v !== null && $v !== ''));
$viewCatUrl = 'parent-verify.php?' . http_build_query(array_filter([
    'tournament_id' => $tid, 'category_id' => $cid ?: null, 'status' => $status !== '' ? $status : null, 'q' => $q !== '' ? $q : null, 'view' => 'category',
], static fn($v) => $v !== null && $v !== ''));

$pageTitle = ($tournamentName !== '' ? $tournamentName . ' · ' : '') . 'Verify parent entries';
require __DIR__ . '/includes/header.php';

$renderVerifyRow = static function (array $r, bool $showCat): void {
    $labs = [];
    foreach (explode(',', (string)$r['event_codes']) as $code) {
        $code = trim($code);
        if ($code !== '') {
            $labs[] = bm_event_label($code);
        }
    }
    echo '<tr data-cat-id="' . (int)($r['age_category_id'] ?? 0) . '">';
    echo '<td><strong>' . bm_h((string)$r['full_name']) . '</strong>';
    echo '<div class="muted">' . bm_h(bm_gender_label((string)$r['gender']));
    if (trim((string)$r['bai_id']) !== '') {
        echo ' · BAI ' . bm_h((string)$r['bai_id']);
    }
    if (trim((string)$r['pbi_id']) !== '') {
        echo ' · PBA ' . bm_h((string)$r['pbi_id']);
    }
    echo '</div>';
    if (!empty($r['d_name'])) {
        echo '<div class="muted">Doubles: ' . bm_h((string)$r['d_name']) . '</div>';
    }
    if (!empty($r['m_name'])) {
        echo '<div class="muted">Mix: ' . bm_h((string)$r['m_name']) . '</div>';
    }
    echo '</td>';
    if ($showCat) {
        echo '<td>' . bm_h((string)$r['category_name']) . '</td>';
    }
    echo '<td>' . bm_h((string)($r['player_father'] ?: $r['father_name'])) . '</td>';
    echo '<td>' . bm_h((string)($r['player_mobile'] ?: $r['contact_mobile'])) . '</td>';
    echo '<td>' . bm_h((string)$r['district']) . '</td>';
    echo '<td>' . bm_h(implode(', ', $labs)) . '</td>';
    echo '<td>' . bm_h(bm_parent_payment_label((string)$r['payment_status'], (int)$r['payment_amount'])) . '</td>';
    echo '<td>' . bm_h((string)($r['payment_utr'] ?? ''));
    if (trim((string)($r['payment_sender'] ?? '')) !== '') {
        echo '<div class="muted">' . bm_h((string)$r['payment_sender']) . '</div>';
    }
    if (trim((string)($r['payment_date'] ?? '')) !== '') {
        echo '<div class="muted">' . bm_h(bm_fmt_date((string)$r['payment_date'])) . '</div>';
    }
    echo '</td>';
    echo '<td class="right no-print"><a class="btn btn-sm" href="parent-edit.php?id=' . (int)$r['id'] . '">Open</a></td>';
    echo '</tr>';
};
?>
<?php bm_admin_tournament_nav($tid, 'verify'); ?>
<section class="page-head no-print">
  <div>
    <p class="eyebrow"><?= bm_h($tournamentName !== '' ? $tournamentName : 'Check') ?></p>
    <h1>Verify parent entries</h1>
    <p class="lede">This tournament only. Print / PDF or Excel for all entries or one category.</p>
  </div>
  <div class="page-actions">
    <a class="btn <?= $view === 'all' ? 'btn-primary' : '' ?>" href="<?= bm_h($viewAllUrl) ?>">All entries</a>
    <a class="btn <?= $view === 'category' ? 'btn-primary' : '' ?>" href="<?= bm_h($viewCatUrl) ?>">Category wise</a>
    <button type="button" class="btn" data-print-all>Print / PDF</button>
    <a class="btn" href="parent-verify.php?<?= bm_h($qs !== '' ? $qs . '&' : '') ?>format=csv">Excel</a>
    <a class="btn" href="tournament.php?id=<?= $tid ?>">Back</a>
  </div>
</section>
<p class="print-only print-title"><?= bm_h($tournamentName) ?><br>Verify entries</p>
<section class="panel no-print">
  <form method="get" class="filters">
    <input type="hidden" name="tournament_id" value="<?= $tid ?>">
    <input type="hidden" name="view" value="<?= bm_h($view) ?>">
    <select name="category_id">
      <option value="0">All age categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cid === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h((string)$c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">All payments</option>
      <option value="review" <?= $status === 'review' ? 'selected' : '' ?>>Awaiting check</option>
      <option value="declined" <?= $status === 'declined' ? 'selected' : '' ?>>Declined</option>
      <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
      <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
      <option value="na" <?= $status === 'na' ? 'selected' : '' ?>>Free / no fee</option>
    </select>
    <input type="search" name="q" value="<?= bm_h($q) ?>" placeholder="Name, UTR, mobile, district">
    <button type="submit" class="btn btn-primary">Filter</button>
  </form>
  <?php if ($groupedVerify && $cid === 0): ?>
  <form class="filters" onsubmit="return false;">
    <select id="cat-export-id">
      <option value="">One category…</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= bm_h((string)$c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn" data-print-cat-select>Print / PDF this category</button>
    <a class="btn" id="excel-cat-link" href="#">Excel this category</a>
  </form>
  <?php endif; ?>
</section>
<?php if (!$rows): ?>
<section class="panel"><p class="empty">No matching parent entries.</p></section>
<?php elseif ($view === 'category'): ?>
  <?php foreach ($groupedVerify as $catName => $list): ?>
  <?php $thisCid = (int)($list[0]['age_category_id'] ?? 0); ?>
  <details class="cat-drop" data-cat-id="<?= $thisCid ?>" open>
    <summary>
      <?= bm_h($catName) ?> <span class="muted">(<?= count($list) ?>)</span>
      <span class="cat-export no-print">
        <button type="button" class="btn btn-sm" data-print-cat="<?= $thisCid ?>">Print / PDF</button>
        <a class="btn btn-sm" href="parent-verify.php?<?= bm_h(http_build_query(['tournament_id' => $tid, 'category_id' => $thisCid, 'status' => $status, 'q' => $q, 'view' => 'category', 'format' => 'csv'])) ?>">Excel</a>
      </span>
    </summary>
    <table class="table">
      <thead>
        <tr>
          <th>Player</th><th>Father</th><th>Contact</th><th>District</th>
          <th>Events</th><th>Payment</th><th>UTR</th><th class="no-print"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($list as $r) { $renderVerifyRow($r, false); } ?>
      </tbody>
    </table>
  </details>
  <?php endforeach; ?>
<?php else: ?>
<section class="panel">
  <table class="table">
    <thead>
      <tr>
        <th>Player</th><th>Category</th><th>Father</th><th>Contact</th><th>District</th>
        <th>Events</th><th>Payment</th><th>UTR</th><th class="no-print"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r) { $renderVerifyRow($r, true); } ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<script>
(function(){
  var excelBase = <?= json_encode('parent-verify.php?' . $qs, JSON_UNESCAPED_UNICODE) ?>;
  function cats(){ return Array.prototype.slice.call(document.querySelectorAll('.cat-drop[data-cat-id]')); }
  function rows(){ return Array.prototype.slice.call(document.querySelectorAll('tr[data-cat-id]')); }
  function resetPrint(){
    cats().forEach(function(d){ d.classList.remove('print-hide'); });
    rows().forEach(function(r){ r.classList.remove('print-hide'); });
  }
  function printAll(){ resetPrint(); cats().forEach(function(d){ d.open = true; }); window.print(); }
  function printCat(id){
    resetPrint();
    cats().forEach(function(d){
      var match = String(d.getAttribute('data-cat-id')) === String(id);
      d.open = match;
      d.classList.toggle('print-hide', !match);
    });
    rows().forEach(function(r){ r.classList.toggle('print-hide', String(r.getAttribute('data-cat-id')) !== String(id)); });
    window.print();
  }
  document.addEventListener('click', function(e){
    var t = e.target;
    if (t.closest && t.closest('.cat-export')) { e.stopPropagation(); }
    if (t.closest && t.closest('[data-print-all]')) { e.preventDefault(); printAll(); return; }
    var one = t.closest && t.closest('[data-print-cat]');
    if (one) { e.preventDefault(); printCat(one.getAttribute('data-print-cat')); return; }
    if (t.closest && t.closest('[data-print-cat-select]')) {
      e.preventDefault();
      var id = (document.getElementById('cat-export-id') || {}).value;
      if (!id) { window.alert('Choose a category first.'); return; }
      printCat(id);
    }
  }, true);
  var excelLink = document.getElementById('excel-cat-link');
  if (excelLink) {
    excelLink.addEventListener('click', function(e){
      e.preventDefault();
      var id2 = (document.getElementById('cat-export-id') || {}).value;
      if (!id2) { window.alert('Choose a category first.'); return; }
      window.location.href = excelBase + (excelBase.indexOf('?') >= 0 ? '&' : '?') + 'format=csv&category_id=' + encodeURIComponent(id2);
    });
  }
  window.addEventListener('afterprint', resetPrint);
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
