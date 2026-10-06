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
    $sql .= " AND (p.age_category_id IS NULL OR NOT EXISTS (SELECT 1 FROM player_events pe WHERE pe.player_id = p.id))";
} else {
    $sql .= " AND p.age_category_id IS NOT NULL AND EXISTS (SELECT 1 FROM player_events pe WHERE pe.player_id = p.id)";
}
if ($catFilter > 0) {
    $sql .= ' AND p.age_category_id = ?';
    $params[] = $catFilter;
}
if ($eventFilter !== '' && isset(bm_event_defs()[$eventFilter])) {
    $sql .= ' AND EXISTS (SELECT 1 FROM player_events pe WHERE pe.player_id = p.id AND pe.event_code = ?)';
    $params[] = $eventFilter;
}
if ($q !== '') {
    $sql .= " AND (p.full_name LIKE ? OR IFNULL(p.bai_id,'') LIKE ? OR IFNULL(p.pbi_id,'') LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY c.sort_order, c.name, p.full_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $codes = bm_player_event_codes($pdo, (int)$r['id']);
    $r['event_labels'] = array_map('bm_event_label', $codes);
}
unset($r);
$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM players p WHERE p.age_category_id IS NULL OR NOT EXISTS (SELECT 1 FROM player_events pe WHERE pe.player_id = p.id)")->fetchColumn();

$pageTitle = 'Players · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Registry</p>
    <h1>Players</h1>
    <p class="lede">Create player name first, then assign age category and one or more events. The same player can play in multiple events in a tournament.</p>
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
        <td><?= $r['event_labels'] ? bm_h(implode(', ', $r['event_labels'])) : '— not set —' ?></td>
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
