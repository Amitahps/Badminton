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

$q = trim((string)($_GET['q'] ?? ''));
$genderFilter = trim((string)($_GET['gender'] ?? ''));

$sql = 'SELECT * FROM players WHERE 1=1';
$params = [];
if ($genderFilter === 'boy' || $genderFilter === 'girl') {
    $sql .= ' AND gender = ?';
    $params[] = $genderFilter;
}
if ($q !== '') {
    $sql .= " AND (full_name LIKE ? OR IFNULL(bai_id,'') LIKE ? OR IFNULL(pbi_id,'') LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY full_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Players · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Registry</p>
    <h1>Players</h1>
    <p class="lede">One-time player list (name, gender, documents). Age categories and events are chosen later inside each tournament.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="player-form.php">Add player</a>
  </div>
</section>
<section class="panel">
  <form method="get" class="filters">
    <input type="search" name="q" value="<?= bm_h($q) ?>" placeholder="Search name / BAI / PBI">
    <select name="gender">
      <option value="">All genders</option>
      <option value="boy" <?= $genderFilter === 'boy' ? 'selected' : '' ?>>Boy</option>
      <option value="girl" <?= $genderFilter === 'girl' ? 'selected' : '' ?>>Girl</option>
    </select>
    <button type="submit" class="btn">Filter</button>
  </form>

  <?php if ($rows): ?>
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Gender</th>
        <th>BAI / PBI</th>
        <th>Mobile</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= bm_h($r['full_name']) ?></strong></td>
        <td><?= bm_h(bm_gender_label($r['gender'] ?? '')) ?></td>
        <td><?= bm_h(($r['bai_id'] ?: '—') . ' / ' . ($r['pbi_id'] ?: '—')) ?></td>
        <td><?= bm_h($r['mobile'] ?: '—') ?></td>
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
  <p class="empty">No players yet. Add player names once; assign categories and events inside each tournament.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
