<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

if (isset($_POST['delete_id'])) {
    $pid = (int)$_POST['delete_id'];
    $stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $stmt->execute([$pid]);
    $row = $stmt->fetch();
    if ($row) {
        bm_delete_upload($row['aadhaar_file'] ?? null);
        bm_delete_upload($row['dob_certificate_file'] ?? null);
        $pdo->prepare('DELETE FROM players WHERE id = ?')->execute([$pid]);
        bm_flash('success', 'Player removed.');
    }
    bm_redirect('players.php');
}

$catFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$q = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT p.*, c.name AS category_name
        FROM players p
        JOIN age_categories c ON c.id = p.age_category_id
        WHERE 1=1";
$params = [];
if ($catFilter > 0) {
    $sql .= ' AND p.age_category_id = ?';
    $params[] = $catFilter;
}
if ($q !== '') {
    $sql .= " AND (p.full_name LIKE ? OR IFNULL(p.bai_id,'') LIKE ? OR IFNULL(p.pbi_id,'') LIKE ? OR IFNULL(p.partner_name,'') LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY c.sort_order, c.name, p.full_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();

$pageTitle = 'Players · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Registry</p>
    <h1>Players</h1>
    <p class="lede">Store name, single/double, BAI ID, PBI ID, Aadhaar, and date of birth certificate. Edit or remove anytime.</p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="player-form.php">Add player</a></div>
</section>
<section class="panel">
  <form method="get" class="filters">
    <input type="search" name="q" value="<?= bm_h($q) ?>" placeholder="Search name / BAI / PBI">
    <select name="category">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $catFilter === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Filter</button>
  </form>
  <?php if ($rows): ?>
  <table class="table">
    <thead>
      <tr><th>Name</th><th>Type</th><th>Category</th><th>BAI ID</th><th>PBI ID</th><th>Aadhaar</th><th>DOB cert</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <strong><?= bm_h($r['full_name']) ?></strong>
          <?php if ($r['play_type'] === 'double' && $r['partner_name']): ?>
            <div class="muted">with <?= bm_h($r['partner_name']) ?></div>
          <?php endif; ?>
        </td>
        <td class="cap"><?= bm_h($r['play_type']) ?></td>
        <td><?= bm_h($r['category_name']) ?></td>
        <td><?= bm_h($r['bai_id'] ?: '—') ?></td>
        <td><?= bm_h($r['pbi_id'] ?: '—') ?></td>
        <td>
          <?php if ($r['aadhaar_no']): ?>****<?= bm_h(substr($r['aadhaar_no'], -4)) ?><?php else: ?>—<?php endif; ?>
          <?php if ($r['aadhaar_file']): ?><div><a href="view-file.php?f=<?= urlencode($r['aadhaar_file']) ?>" target="_blank">File</a></div><?php endif; ?>
        </td>
        <td>
          <?php if ($r['dob_certificate_file']): ?>
            <a href="view-file.php?f=<?= urlencode($r['dob_certificate_file']) ?>" target="_blank">View</a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="right actions">
          <a class="btn btn-sm" href="player-form.php?id=<?= (int)$r['id'] ?>">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Remove this player permanently?');">
            <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No players found. Add players after creating age categories.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
