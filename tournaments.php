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
if (isset($_POST['toggle_show_id'])) {
    $tid = (int)$_POST['toggle_show_id'];
    $show = ((string)($_POST['show'] ?? '0') === '1') ? 1 : 0;
    $pdo->prepare('UPDATE tournaments SET show_on_website=?, updated_at=datetime(\'now\',\'localtime\') WHERE id=?')
        ->execute([$show, $tid]);
    bm_flash('success', $show ? 'Tournament is now on the website list.' : 'Tournament hidden from the website list.');
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
    <p class="lede">Create a tournament, then choose whether it appears on the public website list (year-wise). Hidden events stay off the site; you can still send that tournament’s own link.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="tournament-form.php">Create tournament</a>
    <a class="btn" href="open.php" target="_blank" rel="noopener">Open website list</a>
  </div>
</section>
<section class="panel">
  <p class="hint" style="margin-top:0;">Website list link (always the same) — attach this on the PBA Tournament page</p>
  <div class="share-link">
    <input id="website-list-link" type="text" readonly value="<?= bm_h(bm_public_list_url()) ?>">
    <button type="button" class="btn btn-primary" id="copy-website-list">Copy list link</button>
  </div>
</section>
<?php
$byYear = [];
foreach ($rows as $r) {
    $byYear[bm_tournament_year($r)][] = $r;
}
?>
<?php if ($rows): ?>
  <?php foreach ($byYear as $year => $list): ?>
  <section class="panel year-block">
    <div class="panel-head"><h2><?= bm_h((string)$year) ?></h2></div>
    <table class="table">
      <thead><tr><th>Name</th><th>Held at</th><th>From</th><th>To</th><th>Website</th><th>Teams</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $r): ?>
        <?php $onSite = bm_tournament_show_on_website($r); ?>
        <tr>
          <td><strong><?= bm_h($r['name']) ?></strong></td>
          <td><?= bm_h($r['held_at']) ?></td>
          <td><?= bm_h(bm_fmt_date($r['date_from'])) ?></td>
          <td><?= bm_h(bm_fmt_date($r['date_to'])) ?></td>
          <td>
            <form method="post" class="inline">
              <input type="hidden" name="toggle_show_id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="show" value="<?= $onSite ? '0' : '1' ?>">
              <button type="submit" class="btn btn-sm <?= $onSite ? 'btn-primary' : '' ?>"><?= $onSite ? 'Showing' : 'Hidden' ?></button>
            </form>
          </td>
          <td><?= (int)$r['entry_count'] ?></td>
          <td class="right actions">
            <a class="btn btn-sm btn-primary" href="tournament.php?id=<?= (int)$r['id'] ?>">Open</a>
            <a class="btn btn-sm" href="confirmed.php?id=<?= (int)$r['id'] ?>">Confirmed</a>
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
  </section>
  <?php endforeach; ?>
<?php else: ?>
<section class="panel">
  <p class="empty">No tournament yet.</p>
</section>
<?php endif; ?>
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
(function(){
  var btn = document.getElementById('copy-website-list');
  var inp = document.getElementById('website-list-link');
  if (!btn || !inp) return;
  btn.addEventListener('click', function(){
    var t = inp.value;
    function ok(){ var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = o; }, 1600); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(ok).catch(function(){ inp.select(); document.execCommand('copy'); ok(); });
    } else { inp.select(); document.execCommand('copy'); ok(); }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
