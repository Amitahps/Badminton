<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = (int)($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? 0);
$tstmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$tstmt->execute([$id]);
$tournament = $tstmt->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('tournaments.php');
}

if (isset($_POST['save_selection']) || isset($_POST['save_and_open'])) {
    $selected = $_POST['team_ids'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }
    $selected = array_fill_keys(array_map('intval', $selected), true);

    $all = $pdo->prepare('SELECT id FROM tournament_teams WHERE tournament_id = ?');
    $all->execute([$id]);
    $upd = $pdo->prepare('UPDATE tournament_teams SET include_in_letter = ?, updated_at = datetime(\'now\',\'localtime\') WHERE id = ? AND tournament_id = ?');
    $on = 0;
    foreach ($all->fetchAll() as $row) {
        $tid = (int)$row['id'];
        $include = isset($selected[$tid]) ? 1 : 0;
        $upd->execute([$include, $tid, $id]);
        if ($include) {
            $on++;
        }
    }
    bm_flash('success', $on . ' team(s) selected for the letter.');
    if (isset($_POST['save_and_open'])) {
        bm_redirect('letter.php?id=' . $id);
    }
    bm_redirect('letter-select.php?tournament_id=' . $id);
}

// Load teams with members, grouped for display
$rows = $pdo->prepare("
    SELECT t.*,
           c.name AS category_name,
           c.gender_scope AS category_scope,
           c.age_group AS category_age_group,
           CASE t.event_code
             WHEN 'single' THEN 1
             WHEN 'double_men' THEN 2
             WHEN 'double_girls' THEN 3
             WHEN 'mix_double' THEN 4
             ELSE 9
           END AS event_sort
    FROM tournament_teams t
    JOIN age_categories c ON c.id = t.age_category_id
    WHERE t.tournament_id = ?
    ORDER BY c.sort_order, c.name, event_sort, t.id
");
$rows->execute([$id]);
$teams = $rows->fetchAll();
foreach ($teams as &$team) {
    $ms = $pdo->prepare("
        SELECT p.full_name, p.gender FROM players p
        JOIN tournament_team_members m ON m.player_id = p.id
        WHERE m.team_id = ?
        ORDER BY CASE p.gender WHEN 'boy' THEN 0 ELSE 1 END, p.full_name
    ");
    $ms->execute([(int)$team['id']]);
    $team['members'] = $ms->fetchAll();
    $cat = [
        'id' => (int)$team['age_category_id'],
        'name' => $team['category_name'],
        'gender_scope' => $team['category_scope'] ?: 'open',
        'age_group' => $team['category_age_group'],
    ];
    $team['heading'] = bm_letter_heading($cat, (string)$team['event_code']);
    if (!isset($team['include_in_letter'])) {
        $team['include_in_letter'] = 1;
    }
}
unset($team);

$byHeading = [];
foreach ($teams as $team) {
    $h = $team['heading'];
    if (!isset($byHeading[$h])) {
        $byHeading[$h] = [];
    }
    $byHeading[$h][] = $team;
}

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$pageTitle = 'Select for letter · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?> · <?= bm_h($tournament['held_at']) ?> · <?= bm_h($dateText) ?></p>
    <h1>Select teams for letter</h1>
    <p class="lede">Tick <strong>all</strong> or only a <strong>few</strong> teams. Only selected teams will appear in the letter / CSV export.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="tournament.php?id=<?= $id ?>">Back to tournament</a>
  </div>
</section>

<?php if (!$teams): ?>
<section class="panel">
  <p class="empty">No teams formed yet. Form teams first, then select for the letter.</p>
</section>
<?php else: ?>
<section class="panel">
  <form method="post" id="select-form">
    <input type="hidden" name="tournament_id" value="<?= $id ?>">
    <p class="hint" style="margin-bottom:0.75rem;">
      <button type="button" class="btn btn-sm" id="select-all">Select all</button>
      <button type="button" class="btn btn-sm" id="clear-all">Clear all</button>
    </p>
    <?php foreach ($byHeading as $heading => $list): ?>
      <h3 style="margin:1rem 0 0.4rem;font-size:1.05rem;"><?= bm_h($heading) ?></h3>
      <table class="table">
        <thead>
          <tr><th class="check-col">In letter</th><th>Team</th><th>Players</th></tr>
        </thead>
        <tbody>
        <?php foreach ($list as $team): ?>
          <tr>
            <td class="check-col">
              <input type="checkbox" class="letter-pick" name="team_ids[]" value="<?= (int)$team['id'] ?>"
                <?= ((int)($team['include_in_letter'] ?? 1) === 1) ? 'checked' : '' ?>>
            </td>
            <td><strong><?= bm_h($team['team_label'] ?: ('Team #' . (int)$team['id'])) ?></strong></td>
            <td>
              <?php foreach ($team['members'] as $m): ?>
                <div><?= bm_h($m['full_name']) ?> <span class="muted">(<?= bm_h(bm_gender_label($m['gender'])) ?>)</span></div>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
    <div class="form-actions">
      <button type="submit" name="save_selection" value="1" class="btn">Save selection</button>
      <button type="submit" name="save_and_open" value="1" class="btn btn-primary">Save &amp; open letter</button>
    </div>
  </form>
</section>
<script>
(function(){
  var all = document.getElementById('select-all');
  var clear = document.getElementById('clear-all');
  if (all) all.addEventListener('click', function(){
    document.querySelectorAll('.letter-pick').forEach(function(b){ b.checked = true; });
  });
  if (clear) clear.addEventListener('click', function(){
    document.querySelectorAll('.letter-pick').forEach(function(b){ b.checked = false; });
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
