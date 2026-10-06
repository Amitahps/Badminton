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

$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();
$events = bm_event_defs();

// Team counts per category+event
$countStmt = $pdo->prepare("
    SELECT age_category_id, event_code, COUNT(*) AS team_count
    FROM tournament_teams
    WHERE tournament_id = ?
    GROUP BY age_category_id, event_code
");
$countStmt->execute([$id]);
$teamCounts = [];
foreach ($countStmt->fetchAll() as $r) {
    $teamCounts[(int)$r['age_category_id'] . '|' . $r['event_code']] = (int)$r['team_count'];
}

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$pageTitle = $tournament['name'] . ' · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Tournament</p>
    <h1><?= bm_h($tournament['name']) ?></h1>
    <p class="lede">Held at <strong><?= bm_h($tournament['held_at']) ?></strong> on <strong><?= bm_h($dateText) ?></strong>. Open a category + event, form teams (Single = 1 player, Doubles = 2 players). Teams can be changed anytime.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="letter.php?id=<?= $id ?>">Export participating list</a>
    <a class="btn" href="tournament-form.php?id=<?= $id ?>">Edit details</a>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Form teams by age category &amp; event</h2></div>
  <?php if ($categories): ?>
  <table class="table">
    <thead>
      <tr>
        <th>Age category</th>
        <th>Event</th>
        <th>Teams saved</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
      <?php foreach ($events as $code => $def): ?>
        <?php $key = (int)$c['id'] . '|' . $code; ?>
        <tr>
          <td><strong><?= bm_h($c['name']) ?></strong></td>
          <td><?= bm_h($def['label']) ?></td>
          <td><?= (int)($teamCounts[$key] ?? 0) ?></td>
          <td class="right">
            <a class="btn btn-sm btn-primary" href="tournament-teams.php?tournament_id=<?= $id ?>&category_id=<?= (int)$c['id'] ?>&event=<?= urlencode($code) ?>">Open &amp; form teams</a>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Create age categories and assign players to category + event first.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
