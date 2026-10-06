<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

if (isset($_POST['delete_id'])) {
    $pdo->prepare('DELETE FROM tournaments WHERE id = ?')->execute([(int)$_POST['delete_id']]);
    bm_flash('success', 'Tournament removed.');
    bm_redirect('tournaments.php');
}

$rows = $pdo->query("
    SELECT t.*,
      (SELECT COUNT(*) FROM tournament_teams e WHERE e.tournament_id = t.id) AS entry_count
    FROM tournaments t
    ORDER BY t.date_from DESC, t.id DESC
")->fetchAll();

$pageTitle = 'Tournaments · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Events</p>
    <h1>Tournaments</h1>
    <p class="lede">Create tournament name, held at, and dates. Then assign players to age categories and events for that tournament, and form teams.</p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="tournament-form.php">Create tournament</a></div>
</section>
<section class="panel">
  <?php if ($rows): ?>
  <table class="table">
    <thead><tr><th>Name</th><th>Held at</th><th>From</th><th>To</th><th>Teams</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= bm_h($r['name']) ?></strong></td>
        <td><?= bm_h($r['held_at']) ?></td>
        <td><?= bm_h($r['date_from']) ?></td>
        <td><?= bm_h($r['date_to']) ?></td>
        <td><?= (int)$r['entry_count'] ?></td>
        <td class="right actions">
          <a class="btn btn-sm btn-primary" href="tournament.php?id=<?= (int)$r['id'] ?>">Open</a>
          <a class="btn btn-sm" href="letter-select.php?tournament_id=<?= (int)$r['id'] ?>">Letter</a>
          <a class="btn btn-sm" href="tournament-form.php?id=<?= (int)$r['id'] ?>">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Remove this tournament and its selected lists?');">
            <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No tournament yet.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
