<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tournamentId = (int)($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? 0);
$editPlayerId = (int)($_GET['player_id'] ?? 0);

$tstmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$tstmt->execute([$tournamentId]);
$tournament = $tstmt->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('tournaments.php');
}

$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();
$events = bm_event_defs();
$allPlayers = $pdo->query('SELECT * FROM players ORDER BY full_name')->fetchAll();

// Remove one player's entries from this tournament
if (isset($_POST['clear_player_id'])) {
    $pid = (int)$_POST['clear_player_id'];
    bm_clear_tournament_player_entries($pdo, $tournamentId, $pid);
    bm_flash('success', 'Player removed from this tournament’s category/event list.');
    bm_redirect('tournament-entries.php?tournament_id=' . $tournamentId);
}

// Save: one player → many categories × many events (this tournament only)
if (isset($_POST['save_entry'])) {
    $pid = (int)($_POST['player_id'] ?? 0);
    $catIds = $_POST['category_ids'] ?? [];
    $evCodes = $_POST['event_codes'] ?? [];
    if (!is_array($catIds)) {
        $catIds = [];
    }
    if (!is_array($evCodes)) {
        $evCodes = [];
    }
    $catIds = array_values(array_unique(array_map('intval', $catIds)));
    $evCodes = array_values(array_unique(array_map('strval', $evCodes)));

    $pst = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $pst->execute([$pid]);
    $player = $pst->fetch();

    if (!$player) {
        bm_flash('error', 'Select a player.');
    } elseif (!$categories) {
        bm_flash('error', 'Create age categories first.');
    } elseif (!$catIds) {
        bm_flash('error', 'Select at least one age category for this tournament.');
    } elseif (!$evCodes) {
        bm_flash('error', 'Select at least one event for this tournament.');
    } else {
        $validCat = [];
        foreach ($categories as $c) {
            $validCat[(int)$c['id']] = true;
        }
        foreach ($catIds as $cid) {
            if (empty($validCat[$cid])) {
                bm_flash('error', 'Invalid age category.');
                bm_redirect('tournament-entries.php?tournament_id=' . $tournamentId);
            }
        }
        $g = $player['gender'];
        $ok = true;
        foreach ($evCodes as $code) {
            if (!isset($events[$code])) {
                bm_flash('error', 'Invalid event.');
                $ok = false;
                break;
            }
            if ($code === 'double_men' && $g !== 'boy') {
                bm_flash('error', 'Double Men is for boys only.');
                $ok = false;
                break;
            }
            if ($code === 'double_girls' && $g !== 'girl') {
                bm_flash('error', 'Double Girls is for girls only.');
                $ok = false;
                break;
            }
        }
        if ($ok) {
            bm_set_tournament_player_entries($pdo, $tournamentId, $pid, $catIds, $evCodes);
            bm_flash('success', $player['full_name'] . ' saved for this tournament (' . count($catIds) . ' categor' . (count($catIds) === 1 ? 'y' : 'ies') . ', ' . count($evCodes) . ' event' . (count($evCodes) === 1 ? '' : 's') . ').');
            bm_redirect('tournament-entries.php?tournament_id=' . $tournamentId);
        }
    }
    $editPlayerId = $pid;
}

// Load existing entries grouped by player
$entryRows = $pdo->prepare("
    SELECT e.player_id, e.age_category_id, e.event_code,
           p.full_name, p.gender, c.name AS category_name
    FROM tournament_entries e
    JOIN players p ON p.id = e.player_id
    JOIN age_categories c ON c.id = e.age_category_id
    WHERE e.tournament_id = ?
    ORDER BY p.full_name, c.sort_order, c.name, e.event_code
");
$entryRows->execute([$tournamentId]);
$byPlayer = [];
foreach ($entryRows->fetchAll() as $r) {
    $pid = (int)$r['player_id'];
    if (!isset($byPlayer[$pid])) {
        $byPlayer[$pid] = [
            'player_id' => $pid,
            'full_name' => $r['full_name'],
            'gender' => $r['gender'],
            'categories' => [],
            'events' => [],
            'pairs' => [],
        ];
    }
    $byPlayer[$pid]['categories'][(int)$r['age_category_id']] = $r['category_name'];
    $byPlayer[$pid]['events'][$r['event_code']] = bm_event_label($r['event_code']);
    $byPlayer[$pid]['pairs'][] = $r['category_name'] . ' · ' . bm_event_label($r['event_code']);
}

$selectedCats = [];
$selectedEvents = [];
$formPlayerId = $editPlayerId;
$formGender = 'boy';
if ($editPlayerId > 0) {
    $selectedCats = bm_tournament_entry_categories($pdo, $tournamentId, $editPlayerId);
    $selectedEvents = bm_tournament_entry_events($pdo, $tournamentId, $editPlayerId);
    foreach ($allPlayers as $p) {
        if ((int)$p['id'] === $editPlayerId) {
            $formGender = $p['gender'];
            break;
        }
    }
}

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$pageTitle = 'Tournament entries · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?> · <?= bm_h($tournament['held_at']) ?> · <?= bm_h($dateText) ?></p>
    <h1>Assign players for this tournament</h1>
    <p class="lede">Pick a player from the one-time list, then tick <strong>one or more age categories</strong> and <strong>one or more events</strong> for <em>this tournament only</em>. Ask again for every new tournament.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="tournament.php?id=<?= $tournamentId ?>">Form teams next</a>
    <a class="btn" href="tournament.php?id=<?= $tournamentId ?>">Back</a>
  </div>
</section>

<?php if (!$categories): ?>
<section class="panel">
  <p class="empty">Create age categories first. <a href="category-form.php">Create category</a></p>
</section>
<?php elseif (!$allPlayers): ?>
<section class="panel">
  <p class="empty">Add players first (name list is one-time). <a href="player-form.php">Add player</a></p>
</section>
<?php else: ?>
<section class="panel narrow">
  <form method="post" class="form" id="entry-form">
    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
    <label>Player
      <select name="player_id" id="player_id" required>
        <option value="">Select player</option>
        <?php foreach ($allPlayers as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-gender="<?= bm_h($p['gender']) ?>"
            <?= $formPlayerId === (int)$p['id'] ? 'selected' : '' ?>>
            <?= bm_h($p['full_name']) ?> (<?= bm_h(bm_gender_label($p['gender'])) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <fieldset style="border:1px solid var(--line);border-radius:10px;padding:0.75rem 1rem;margin:0.75rem 0;">
      <legend style="font-weight:600;padding:0 0.35rem;">Age categories (select multiple)</legend>
      <?php foreach ($categories as $c): ?>
        <?php $sc = bm_category_gender_scope($c); ?>
        <label class="check-inline" style="display:flex;margin:0.4rem 0;font-weight:500;">
          <input type="checkbox" class="cat-pick" name="category_ids[]" value="<?= (int)$c['id'] ?>"
            data-scope="<?= bm_h($sc) ?>"
            <?= in_array((int)$c['id'], $selectedCats, true) ? 'checked' : '' ?>>
          <?= bm_h($c['name']) ?>
          <span class="muted"> (<?= bm_h(bm_gender_scope_label($sc)) ?>)</span>
        </label>
      <?php endforeach; ?>
    </fieldset>

    <fieldset style="border:1px solid var(--line);border-radius:10px;padding:0.75rem 1rem;margin:0.75rem 0;">
      <legend style="font-weight:600;padding:0 0.35rem;">Events (select multiple — filtered by category)</legend>
      <?php foreach ($events as $code => $def): ?>
        <label class="check-inline event-opt" data-code="<?= bm_h($code) ?>" style="display:flex;margin:0.4rem 0;font-weight:500;">
          <input type="checkbox" name="event_codes[]" value="<?= bm_h($code) ?>"
            <?= in_array($code, $selectedEvents, true) ? 'checked' : '' ?>>
          <span><?= bm_h($def['label']) ?></span>
        </label>
      <?php endforeach; ?>
    </fieldset>

    <div class="form-actions">
      <button type="submit" name="save_entry" value="1" class="btn btn-primary">Save for this tournament</button>
      <a class="btn" href="tournament-entries.php?tournament_id=<?= $tournamentId ?>">Clear form</a>
    </div>
  </form>
</section>
<script>
(function(){
  var sel = document.getElementById('player_id');
  var scopeEvents = {
    boys: {single:1, double_men:1, mix_double:1},
    girls: {single:1, double_girls:1, mix_double:1},
    open: {single:1, double_men:1, double_girls:1, mix_double:1}
  };
  function syncEvents(){
    var opt = sel.options[sel.selectedIndex];
    var g = opt ? (opt.getAttribute('data-gender') || '') : '';
    var cats = document.querySelectorAll('.cat-pick:checked');
    var allowed = {};
    if (!cats.length) {
      allowed = {single:1, double_men:1, double_girls:1, mix_double:1};
    } else {
      cats.forEach(function(c){
        var map = scopeEvents[c.getAttribute('data-scope')] || scopeEvents.open;
        Object.keys(map).forEach(function(k){ allowed[k] = 1; });
      });
    }
    document.querySelectorAll('.event-opt').forEach(function(lab){
      var code = lab.getAttribute('data-code');
      var input = lab.querySelector('input');
      var byCat = !allowed[code];
      var byGender = (code === 'double_men' && g === 'girl') || (code === 'double_girls' && g === 'boy');
      var disabled = byCat || byGender;
      input.disabled = disabled;
      if (disabled) input.checked = false;
      lab.style.display = byCat ? 'none' : 'flex';
      lab.style.opacity = byGender ? '0.45' : '1';
    });
  }
  document.querySelectorAll('.cat-pick').forEach(function(c){
    c.addEventListener('change', syncEvents);
  });
  sel.addEventListener('change', function(){
    var id = sel.value;
    if (id) {
      location.href = 'tournament-entries.php?tournament_id=<?= (int)$tournamentId ?>&player_id=' + encodeURIComponent(id);
      return;
    }
    syncEvents();
  });
  syncEvents();
})();
</script>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2>Saved for this tournament (<?= count($byPlayer) ?> players)</h2></div>
  <?php if ($byPlayer): ?>
  <table class="table">
    <thead>
      <tr><th>Player</th><th>Gender</th><th>Age categories</th><th>Events</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($byPlayer as $row): ?>
      <tr>
        <td><strong><?= bm_h($row['full_name']) ?></strong></td>
        <td><?= bm_h(bm_gender_label($row['gender'])) ?></td>
        <td><?= bm_h(implode(', ', array_values($row['categories']))) ?></td>
        <td><?= bm_h(implode(', ', array_values($row['events']))) ?></td>
        <td class="right actions">
          <a class="btn btn-sm" href="tournament-entries.php?tournament_id=<?= $tournamentId ?>&player_id=<?= (int)$row['player_id'] ?>">Change</a>
          <form method="post" class="inline" onsubmit="return confirm('Remove this player from this tournament only?');">
            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
            <input type="hidden" name="clear_player_id" value="<?= (int)$row['player_id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No players assigned to categories/events in this tournament yet.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
