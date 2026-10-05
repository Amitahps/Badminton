<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tournamentId = (int)($_GET['tournament_id'] ?? 0);
$catId = (int)($_GET['category_id'] ?? 0);

$tstmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$tstmt->execute([$tournamentId]);
$tournament = $tstmt->fetch();
$cstmt = $pdo->prepare('SELECT * FROM age_categories WHERE id = ?');
$cstmt->execute([$catId]);
$category = $cstmt->fetch();
if (!$tournament || !$category) {
    bm_flash('error', 'Tournament or category not found.');
    bm_redirect('tournaments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = array_map('intval', $_POST['player_ids'] ?? []);
    $selectedMap = array_fill_keys($selected, true);
    $players = $pdo->prepare('SELECT id FROM players WHERE age_category_id = ?');
    $players->execute([$catId]);
    foreach ($players->fetchAll() as $p) {
        $pid = (int)$p['id'];
        $isSelected = isset($selectedMap[$pid]);
        $ex = $pdo->prepare('SELECT id FROM tournament_entries WHERE tournament_id = ? AND player_id = ?');
        $ex->execute([$tournamentId, $pid]);
        $existing = $ex->fetch();
        if ($isSelected) {
            if ($existing) {
                $pdo->prepare('UPDATE tournament_entries SET selected = 1, age_category_id = ? WHERE id = ?')
                    ->execute([$catId, (int)$existing['id']]);
            } else {
                $pdo->prepare('INSERT INTO tournament_entries (tournament_id, player_id, age_category_id, selected) VALUES (?,?,?,1)')
                    ->execute([$tournamentId, $pid, $catId]);
            }
        } elseif ($existing) {
            $pdo->prepare('DELETE FROM tournament_entries WHERE id = ?')->execute([(int)$existing['id']]);
        }
    }
    bm_flash('success', 'Saved participants for ' . $category['name'] . '.');
    bm_redirect('tournament.php?id=' . $tournamentId);
}

$pstmt = $pdo->prepare("
    SELECT p.*,
      CASE WHEN e.selected = 1 THEN 1 ELSE 0 END AS is_selected
    FROM players p
    LEFT JOIN tournament_entries e ON e.player_id = p.id AND e.tournament_id = ?
    WHERE p.age_category_id = ?
    ORDER BY p.full_name
");
$pstmt->execute([$tournamentId, $catId]);
$players = $pstmt->fetchAll();
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);

$pageTitle = $category['name'] . ' · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?> · Held at <?= bm_h($tournament['held_at']) ?> · <?= bm_h($dateText) ?></p>
    <h1>Tick players — <?= bm_h($category['name']) ?></h1>
    <p class="lede">Select who will participate in this category, then save. You can open other categories next.</p>
  </div>
  <div class="page-actions"><a class="btn" href="tournament.php?id=<?= $tournamentId ?>">Back to tournament</a></div>
</section>
<section class="panel">
  <?php if ($players): ?>
  <form method="post">
    <div class="tick-toolbar">
      <button type="button" class="btn btn-sm" id="select-all">Select all</button>
      <button type="button" class="btn btn-sm" id="clear-all">Clear all</button>
      <button type="submit" class="btn btn-primary">Save selected list</button>
    </div>
    <table class="table">
      <thead><tr><th class="check-col">Tick</th><th>Name</th><th>Type</th><th>BAI ID</th><th>PBI ID</th><th>Aadhaar</th></tr></thead>
      <tbody>
      <?php foreach ($players as $p): ?>
        <tr>
          <td class="check-col"><input type="checkbox" class="player-tick" name="player_ids[]" value="<?= (int)$p['id'] ?>" <?= (int)$p['is_selected'] ? 'checked' : '' ?>></td>
          <td>
            <strong><?= bm_h($p['full_name']) ?></strong>
            <?php if ($p['play_type'] === 'double' && $p['partner_name']): ?>
              <div class="muted">with <?= bm_h($p['partner_name']) ?></div>
            <?php endif; ?>
          </td>
          <td class="cap"><?= bm_h($p['play_type']) ?></td>
          <td><?= bm_h($p['bai_id'] ?: '—') ?></td>
          <td><?= bm_h($p['pbi_id'] ?: '—') ?></td>
          <td><?php if ($p['aadhaar_no']): ?>****<?= bm_h(substr($p['aadhaar_no'], -4)) ?><?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save selected list</button>
      <a class="btn" href="tournament.php?id=<?= $tournamentId ?>">Cancel</a>
    </div>
  </form>
  <script>
    document.getElementById('select-all')?.addEventListener('click', function(){ document.querySelectorAll('.player-tick').forEach(function(el){ el.checked=true; }); });
    document.getElementById('clear-all')?.addEventListener('click', function(){ document.querySelectorAll('.player-tick').forEach(function(el){ el.checked=false; }); });
  </script>
  <?php else: ?>
  <p class="empty">No players in this category yet. <a href="player-form.php">Add players</a> first.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
