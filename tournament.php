<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$stmt->execute([$id]);
$tournament = $stmt->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('tournaments.php');
}

$cstmt = $pdo->prepare("
    SELECT c.*,
      (SELECT COUNT(*) FROM players p WHERE p.age_category_id = c.id) AS player_count,
      (SELECT COUNT(*) FROM tournament_entries e
        WHERE e.tournament_id = ? AND e.age_category_id = c.id AND e.selected = 1) AS selected_count
    FROM age_categories c
    ORDER BY c.sort_order, c.name
");
$cstmt->execute([$id]);
$categories = $cstmt->fetchAll();
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);

$pageTitle = $tournament['name'] . ' · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Tournament</p>
    <h1><?= bm_h($tournament['name']) ?></h1>
    <p class="lede">Held at <strong><?= bm_h($tournament['held_at']) ?></strong> on <strong><?= bm_h($dateText) ?></strong>. Open a category, tick players who will participate, and save. Then open the next category.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="letter.php?id=<?= $id ?>">Prepare letter</a>
    <a class="btn" href="tournament-form.php?id=<?= $id ?>">Edit details</a>
  </div>
</section>
<section class="panel">
  <div class="panel-head"><h2>Age categories — tick participants</h2></div>
  <?php if ($categories): ?>
  <table class="table">
    <thead><tr><th>Category</th><th>Players in registry</th><th>Selected for this tournament</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
      <tr>
        <td><strong><?= bm_h($c['name']) ?></strong></td>
        <td><?= (int)$c['player_count'] ?></td>
        <td><?= (int)$c['selected_count'] ?></td>
        <td class="right"><a class="btn btn-sm btn-primary" href="tournament-category.php?tournament_id=<?= $id ?>&category_id=<?= (int)$c['id'] ?>">Open &amp; tick</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Create age categories and players first, then return here to tick participants.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
