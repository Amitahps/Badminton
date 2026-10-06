<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

if (isset($_POST['delete_id'])) {
    $deleteId = (int)$_POST['delete_id'];
    $password = (string)($_POST['confirm_password'] ?? '');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['bm_user_id']]);
    $hash = (string)$stmt->fetchColumn();
    if ($password === '' || !$hash || !password_verify($password, $hash)) {
        bm_flash('error', 'Tournament not removed. Enter your admin password to confirm delete.');
    } else {
        $pdo->prepare('DELETE FROM tournaments WHERE id = ?')->execute([$deleteId]);
        bm_flash('success', 'Tournament removed.');
    }
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
        <td><?= bm_h(bm_fmt_date($r['date_from'])) ?></td>
        <td><?= bm_h(bm_fmt_date($r['date_to'])) ?></td>
        <td><?= (int)$r['entry_count'] ?></td>
        <td class="right actions">
          <a class="btn btn-sm btn-primary" href="tournament.php?id=<?= (int)$r['id'] ?>">Open</a>
          <a class="btn btn-sm" href="letter-select.php?tournament_id=<?= (int)$r['id'] ?>">Letter</a>
          <a class="btn btn-sm" href="tournament-form.php?id=<?= (int)$r['id'] ?>">Edit</a>
          <form method="post" class="inline delete-tournament-form" onsubmit="return confirmTournamentDelete(this);">
            <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="confirm_password" value="">
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
<script>
function confirmTournamentDelete(form){
  if (!confirm('Remove this tournament and all its teams? This cannot be undone.')) {
    return false;
  }
  var pw = window.prompt('Enter your admin password to remove this tournament:');
  if (pw === null || String(pw).trim() === '') {
    alert('Password required. Tournament was not removed.');
    return false;
  }
  form.querySelector('input[name="confirm_password"]').value = pw;
  return true;
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
