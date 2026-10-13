<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tid = (int)($_GET['tournament_id'] ?? 0);
$cid = (int)($_GET['category_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$fmt = strtolower(trim((string)($_GET['format'] ?? '')));
$wrapClass = 'wrap-wide';

$tournaments = $pdo->query('SELECT id, name FROM tournaments ORDER BY date_from DESC, name')->fetchAll();
$categories = $tid > 0
    ? bm_tournament_categories($pdo, $tid)
    : $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();

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
if ($tid > 0) {
    $sql .= ' AND s.tournament_id = ?';
    $params[] = $tid;
}
if ($cid > 0) {
    $sql .= ' AND s.age_category_id = ?';
    $params[] = $cid;
}
if (in_array($status, ['pending', 'paid', 'na'], true)) {
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
    foreach ($rows as $r) {
        $labs = [];
        foreach (explode(',', (string)$r['event_codes']) as $code) {
            $code = trim($code);
            if ($code !== '') {
                $labs[] = bm_event_label($code);
            }
        }
        fputcsv($out, [
            $r['tournament_name'],
            $r['category_name'],
            $r['full_name'],
            bm_gender_label((string)$r['gender']),
            $r['bai_id'],
            $r['pbi_id'],
            $r['player_father'] ?: $r['father_name'],
            $r['player_mobile'] ?: $r['contact_mobile'],
            $r['district'],
            implode(', ', $labs),
            $r['payment_amount'],
            $r['payment_status'],
            $r['payment_utr'],
            $r['d_name'],
            $r['d_father'],
            $r['d_mobile'],
            $r['d_district'],
            $r['m_name'],
            $r['m_father'],
            $r['m_mobile'],
            $r['m_district'],
        ]);
    }
    fclose($out);
    exit;
}

$qs = http_build_query(array_filter([
    'tournament_id' => $tid ?: null,
    'category_id' => $cid ?: null,
    'status' => $status !== '' ? $status : null,
    'q' => $q !== '' ? $q : null,
], static fn($v) => $v !== null && $v !== ''));

$pageTitle = 'Verify parent entries · Badminton';
require __DIR__ . '/includes/header.php';
?>
<style>
@media print {
  .topbar, .no-print, .flash { display: none !important; }
  body { background: #fff; }
  .wrap { max-width: none; width: auto; margin: 0; padding: 0; }
  .table { font-size: 0.78rem; }
}
</style>
<section class="page-head no-print">
  <div>
    <p class="eyebrow">Check</p>
    <h1>Verify parent entries</h1>
    <p class="lede">Filter, print, or download Excel/CSV to check father name, contact, district, UTR and screenshots.</p>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
    <a class="btn" href="parent-verify.php?<?= bm_h($qs !== '' ? $qs . '&' : '') ?>format=csv">Download Excel / CSV</a>
  </div>
</section>
<section class="panel no-print">
  <form method="get" class="filters">
    <select name="tournament_id">
      <option value="0">All tournaments</option>
      <?php foreach ($tournaments as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $tid === (int)$t['id'] ? 'selected' : '' ?>><?= bm_h((string)$t['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="category_id">
      <option value="0">All age categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cid === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h((string)$c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">All payments</option>
      <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
      <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
      <option value="na" <?= $status === 'na' ? 'selected' : '' ?>>Free / no fee</option>
    </select>
    <input type="search" name="q" value="<?= bm_h($q) ?>" placeholder="Name, UTR, mobile, district">
    <button type="submit" class="btn btn-primary">Filter</button>
  </form>
</section>
<section class="panel">
  <p class="print-only"><strong><?= count($rows) ?></strong> parent <?= count($rows) === 1 ? 'entry' : 'entries' ?></p>
  <?php if (!$rows): ?>
  <p class="empty">No matching parent entries.</p>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th>Player</th><th>Category</th><th>Father</th><th>Contact</th><th>District</th>
        <th>Events</th><th>Payment</th><th>UTR</th><th class="no-print"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <strong><?= bm_h((string)$r['full_name']) ?></strong>
          <div class="muted"><?= bm_h(bm_gender_label((string)$r['gender'])) ?>
            <?php if (trim((string)$r['bai_id']) !== ''): ?> · BAI <?= bm_h((string)$r['bai_id']) ?><?php endif; ?>
            <?php if (trim((string)$r['pbi_id']) !== ''): ?> · PBA <?= bm_h((string)$r['pbi_id']) ?><?php endif; ?>
          </div>
          <?php if (!empty($r['d_name'])): ?>
            <div class="muted">Doubles: <?= bm_h((string)$r['d_name']) ?> · <?= bm_h((string)($r['d_father'] ?? '')) ?> · <?= bm_h((string)($r['d_mobile'] ?? '')) ?> · <?= bm_h((string)($r['d_district'] ?? '')) ?></div>
          <?php endif; ?>
          <?php if (!empty($r['m_name'])): ?>
            <div class="muted">Mix: <?= bm_h((string)$r['m_name']) ?> · <?= bm_h((string)($r['m_father'] ?? '')) ?> · <?= bm_h((string)($r['m_mobile'] ?? '')) ?> · <?= bm_h((string)($r['m_district'] ?? '')) ?></div>
          <?php endif; ?>
        </td>
        <td><?= bm_h((string)$r['category_name']) ?><div class="muted"><?= bm_h((string)$r['tournament_name']) ?></div></td>
        <td><?= bm_h((string)($r['player_father'] ?: $r['father_name'])) ?></td>
        <td><?= bm_h((string)($r['player_mobile'] ?: $r['contact_mobile'])) ?></td>
        <td><?= bm_h((string)$r['district']) ?></td>
        <td><?php
          $labs = [];
          foreach (explode(',', (string)$r['event_codes']) as $code) {
              $code = trim($code);
              if ($code !== '') {
                  $labs[] = bm_event_label($code);
              }
          }
          echo bm_h(implode(', ', $labs));
        ?></td>
        <td><?= bm_h(bm_parent_payment_label((string)$r['payment_status'], (int)$r['payment_amount'])) ?></td>
        <td>
          <?= bm_h((string)($r['payment_utr'] ?? '')) ?>
          <?php if (!empty($r['payment_screenshot'])): ?>
            <div class="no-print"><a href="view-file.php?f=<?= bm_h(urlencode((string)$r['payment_screenshot'])) ?>" target="_blank">Screenshot</a></div>
          <?php endif; ?>
        </td>
        <td class="right no-print"><a class="btn btn-sm" href="parent-edit.php?id=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
