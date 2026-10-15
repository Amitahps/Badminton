<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$stats = [
    'categories' => (int)$pdo->query('SELECT COUNT(*) FROM age_categories')->fetchColumn(),
    'players' => (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn(),
    'tournaments' => (int)$pdo->query('SELECT COUNT(*) FROM tournaments')->fetchColumn(),
];
$recent = $pdo->query("
    SELECT t.*,
      (SELECT COUNT(*) FROM tournament_teams e WHERE e.tournament_id = t.id) AS entry_count
    FROM tournaments t
    ORDER BY t.date_from DESC, t.id DESC
    LIMIT 5
")->fetchAll();

$pageTitle = 'Home · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Admin desk</p>
    <h1>Tournament entry</h1>
    <p class="lede">Keep a one-time player name list. For each tournament, choose which players join which age categories and events, form teams, then export the participating list.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="tournament-form.php">New tournament</a>
    <a class="btn" href="player-form.php">Add player</a>
  </div>
</section>

<section class="stat-row">
  <div class="stat"><span><?= $stats['categories'] ?></span><small>Age categories</small></div>
  <div class="stat"><span><?= $stats['players'] ?></span><small>Players (name list)</small></div>
  <div class="stat"><span><?= $stats['tournaments'] ?></span><small>Tournaments</small></div>
</section>

<section class="panel">
  <div class="panel-head">
    <h2>Recent tournaments</h2>
    <a href="tournaments.php">View all</a>
  </div>
  <?php if ($recent): ?>
  <table class="table">
    <thead>
      <tr><th>Tournament</th><th>Held at</th><th>Dates</th><th>Teams</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($recent as $t): ?>
      <tr>
        <td><strong><?= bm_h($t['name']) ?></strong></td>
        <td><?= bm_h($t['held_at']) ?></td>
        <td><?= bm_h($t['date_from']) ?><?= $t['date_to'] !== $t['date_from'] ? ' → ' . bm_h($t['date_to']) : '' ?></td>
        <td><?= (int)$t['entry_count'] ?></td>
        <td class="right"><a class="btn btn-sm" href="tournament.php?id=<?= (int)$t['id'] ?>">Open</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No tournament yet. Create one, assign players to categories/events for that tournament, then form teams.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
