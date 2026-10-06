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
$eventFilter = trim((string)($_GET['event'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$showPending = isset($_GET['pending']) && $_GET['pending'] === '1';

$sql = "SELECT p.*, c.name AS category_name
        FROM players p
        LEFT JOIN age_categories c ON c.id = p.age_category_id
        WHERE 1=1";
$params = [];
if ($showPending) {
    $sql .= ' AND (p.age_category_id IS NULL OR p.event_code IS NULL OR p.event_code = \'\')';
} else {
    // Default list: only assigned players
    $sql .= ' AND p.age_category_id IS NOT NULL AND p.event_code IS NOT NULL AND p.event_code != \'\'';
}
if ($catFilter > 0) {
    $sql .= ' AND p.age_category_id = ?';
    $params[] = $catFilter;
}
if ($eventFilter !== '' && isset(bm_event_defs()[$eventFilter])) {
    $sql .= ' AND p.event_code = ?';
    $params[] = $eventFilter;
}
if ($q !== '') {
    $sql .= " AND (p.full_name LIKE ? OR IFNULL(p.bai_id,'') LIKE ? OR IFNULL(p.pbi_id,'') LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY c.sort_order, c.name, p.event_code, p.full_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE age_category_id IS NULL OR event_code IS NULL OR event_code=''")->fetchColumn();

$pageTitle = 'Players · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Registry</p>
    <h1>Players</h1>
    <p class="lede">Create player name first, then select age category and event. Assigned players appear in this list for tournaments.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="player-form.php">Add player</a>
    <?php if ($pendingCount > 0): ?>
      <a class="btn" href="players.php?pending=1">Pending assign (<?= $pendingCount ?>)</a>
    <?php endif; ?>
    <?php if ($showPending): ?>
      <a class="btn" href="players.php">Show assigned list</a>
    <?php endif; ?>
  </div>
</section>
<section class="panel">
  <form method="get" class="filters">
    <?php if ($showPending): ?><input type="hidden" name="pending" value="1"><?php endif; ?>
    <input type="search" name="q" value="<?= bm_h($q) ?>" placeholder="Search name / BAI / PBI">
    <select name="category">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $catFilter === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="event">
      <option value="">All events</option>
      <?php foreach (bm_event_defs() as $code => $def): ?>
        <option value="<?= bm_h($code) ?>" <?= $eventFilter === $code ? 'selected' : '' ?>><?= bm_h($def['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Filter</button>
  </form>

  <?php if ($rows): ?>
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Gender</th>
        <th>Age category</th>
        <th>Event</th>
        <th>BAI / PBI</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= bm_h($r['full_name']) ?></strong></td>
        <td><?= bm_h(bm_gender_label($r['gender'] ?? '')) ?></td>
        <td><?= bm_h($r['category_name'] ?: '— not set —') ?></td>
        <td><?= bm_h($r['event_code'] ? bm_event_label($r['event_code']) : '— not set —') ?></td>
        <td><?= bm_h(($r['bai_id'] ?: '—') . ' / ' . ($r['pbi_id'] ?: '—')) ?></td>
        <td class="right actions">
          <a class="btn btn-sm" href="player-assign.php?id=<?= (int)$r['id'] ?>">Category / Event</a>
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
  <p class="empty"><?= $showPending ? 'No pending players.' : 'No assigned players yet. Add a player, then select age category and event.' ?></p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
