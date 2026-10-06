<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tournamentId = (int)($_GET['tournament_id'] ?? 0);
$catId = (int)($_GET['category_id'] ?? 0);
$event = trim((string)($_GET['event'] ?? ''));
$events = bm_event_defs();

$tstmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$tstmt->execute([$tournamentId]);
$tournament = $tstmt->fetch();
$cstmt = $pdo->prepare('SELECT * FROM age_categories WHERE id = ?');
$cstmt->execute([$catId]);
$category = $cstmt->fetch();

if (!$tournament || !$category || !isset($events[$event])) {
    bm_flash('error', 'Tournament / category / event not found.');
    bm_redirect('tournaments.php');
}

$scope = bm_category_gender_scope($category);
$allowed = bm_events_for_gender_scope($scope);
if (!isset($allowed[$event])) {
    bm_flash('error', 'This event is not available for ' . $category['name'] . '.');
    bm_redirect('tournament.php?id=' . $tournamentId);
}

$def = $events[$event];
$teamSize = (int)$def['team_size'];
$pairedIds = bm_paired_category_ids($pdo, $category);
$isMix = ($event === 'mix_double');

$redir = 'tournament-teams.php?tournament_id=' . $tournamentId . '&category_id=' . $catId . '&event=' . urlencode($event);

// Delete team
if (isset($_POST['delete_team_id'])) {
    $tid = (int)$_POST['delete_team_id'];
    $chk = $pdo->prepare('SELECT id FROM tournament_teams WHERE id=? AND tournament_id=?');
    $chk->execute([$tid, $tournamentId]);
    if ($chk->fetch()) {
        $pdo->prepare('DELETE FROM tournament_teams WHERE id=?')->execute([$tid]);
        bm_flash('success', 'Team removed.');
    }
    bm_redirect($redir);
}

/**
 * @param list<int> $playerIds
 * @param list<int> $busyCategoryIds
 */
function bm_validate_and_create_team(PDO $pdo, int $tournamentId, int $catId, string $event, array $def, array $playerIds, array $busyCategoryIds, array $pairedIds): string
{
    $teamSize = (int)$def['team_size'];
    $playerIds = array_values(array_unique(array_map('intval', $playerIds)));
    $playerIds = array_values(array_filter($playerIds, static fn($x) => $x > 0));
    if (count($playerIds) !== $teamSize) {
        throw new RuntimeException('Each ' . $def['label'] . ' team needs exactly ' . $teamSize . ' player(s).');
    }

    $in = implode(',', array_fill(0, count($playerIds), '?'));
    $pst = $pdo->prepare("SELECT * FROM players WHERE id IN ($in)");
    $pst->execute($playerIds);
    $picked = $pst->fetchAll();
    if (count($picked) !== $teamSize) {
        throw new RuntimeException('One or more selected players not found.');
    }
    $byId = [];
    foreach ($picked as $p) {
        $byId[(int)$p['id']] = $p;
    }
    // Preserve order
    $ordered = [];
    foreach ($playerIds as $pid) {
        $ordered[] = $byId[$pid];
    }

    foreach ($ordered as $p) {
        $ok = false;
        if ($event === 'mix_double') {
            foreach ($pairedIds as $pcid) {
                if (bm_tournament_has_entry($pdo, $tournamentId, (int)$p['id'], (int)$pcid, $event)) {
                    $ok = true;
                    break;
                }
            }
        } else {
            $ok = bm_tournament_has_entry($pdo, $tournamentId, (int)$p['id'], $catId, $event);
        }
        if (!$ok) {
            throw new RuntimeException($p['full_name'] . ' is not entered for this event in this tournament.');
        }
    }

    $genders = array_column($ordered, 'gender');
    if ($event === 'double_men') {
        foreach ($genders as $g) {
            if ($g !== 'boy') {
                throw new RuntimeException('Double Men team must be 2 boys.');
            }
        }
    } elseif ($event === 'double_girls') {
        foreach ($genders as $g) {
            if ($g !== 'girl') {
                throw new RuntimeException('Double Girls team must be 2 girls.');
            }
        }
    } elseif ($event === 'mix_double') {
        sort($genders);
        if ($genders !== ['boy', 'girl']) {
            throw new RuntimeException('Mix Double team must be 1 boy and 1 girl.');
        }
    }

    $catIn = implode(',', array_fill(0, count($busyCategoryIds), '?'));
    foreach ($playerIds as $pid) {
        $busy = $pdo->prepare("
            SELECT t.id FROM tournament_teams t
            JOIN tournament_team_members m ON m.team_id = t.id
            WHERE t.tournament_id=? AND t.event_code=? AND t.age_category_id IN ($catIn) AND m.player_id=?
        ");
        $busy->execute(array_merge([$tournamentId, $event], $busyCategoryIds, [$pid]));
        if ($busy->fetch()) {
            throw new RuntimeException(($byId[$pid]['full_name'] ?? 'Player') . ' is already in another team for this event.');
        }
    }

    $names = array_column($ordered, 'full_name');
    $label = implode(' / ', $names);
    $pdo->prepare('INSERT INTO tournament_teams (tournament_id, age_category_id, event_code, team_label) VALUES (?,?,?,?)')
        ->execute([$tournamentId, $catId, $event, $label]);
    $teamId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO tournament_team_members (team_id, player_id) VALUES (?,?)');
    foreach ($playerIds as $pid) {
        $ins->execute([$teamId, $pid]);
    }
    return $label;
}

// Batch save all teams once
if (isset($_POST['save_teams'])) {
    $busyCats = $isMix ? $pairedIds : [$catId];
    $created = 0;
    try {
        $pdo->beginTransaction();
        if ($teamSize === 1) {
            $playerIds = array_values(array_unique(array_map('intval', $_POST['player_ids'] ?? [])));
            $playerIds = array_values(array_filter($playerIds, static fn($x) => $x > 0));
            if (!$playerIds) {
                throw new RuntimeException('Select at least one player, then Save teams.');
            }
            foreach ($playerIds as $pid) {
                bm_validate_and_create_team($pdo, $tournamentId, $catId, $event, $def, [$pid], $busyCats, $pairedIds);
                $created++;
            }
        } else {
            $rawTeams = $_POST['teams'] ?? [];
            if (!is_array($rawTeams) || !$rawTeams) {
                throw new RuntimeException('Lock at least one pair (select 2 players), then Save teams.');
            }
            $seen = [];
            foreach ($rawTeams as $raw) {
                $parts = array_values(array_filter(array_map('intval', preg_split('/[|,]/', (string)$raw) ?: [])));
                if (count($parts) !== 2) {
                    throw new RuntimeException('Each doubles team must have exactly 2 players.');
                }
                foreach ($parts as $pid) {
                    if (isset($seen[$pid])) {
                        throw new RuntimeException('A player appears in more than one locked team.');
                    }
                    $seen[$pid] = true;
                }
                bm_validate_and_create_team($pdo, $tournamentId, $catId, $event, $def, $parts, $busyCats, $pairedIds);
                $created++;
            }
        }
        $pdo->commit();
        bm_flash('success', $created . ' team(s) saved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        bm_flash('error', $e->getMessage());
    }
    bm_redirect($redir);
}

// Eligible players
if ($isMix) {
    $in = implode(',', array_fill(0, count($pairedIds), '?'));
    $elig = $pdo->prepare("
        SELECT DISTINCT p.* FROM players p
        JOIN tournament_entries e ON e.player_id = p.id
          AND e.tournament_id = ? AND e.event_code = ? AND e.age_category_id IN ($in)
        ORDER BY CASE p.gender WHEN 'boy' THEN 0 ELSE 1 END, p.full_name
    ");
    $elig->execute(array_merge([$tournamentId, $event], $pairedIds));
} else {
    $elig = $pdo->prepare("
        SELECT p.* FROM players p
        JOIN tournament_entries e ON e.player_id = p.id
          AND e.tournament_id = ? AND e.age_category_id = ? AND e.event_code = ?
        ORDER BY p.full_name
    ");
    $elig->execute([$tournamentId, $catId, $event]);
}
$players = $elig->fetchAll();

// Already used
$busyCats = $isMix ? $pairedIds : [$catId];
$catIn = implode(',', array_fill(0, count($busyCats), '?'));
$usedStmt = $pdo->prepare("
    SELECT m.player_id FROM tournament_team_members m
    JOIN tournament_teams t ON t.id = m.team_id
    WHERE t.tournament_id=? AND t.event_code=? AND t.age_category_id IN ($catIn)
");
$usedStmt->execute(array_merge([$tournamentId, $event], $busyCats));
$usedIds = array_map('intval', array_column($usedStmt->fetchAll(), 'player_id'));
$usedMap = array_fill_keys($usedIds, true);

// Existing teams for this category page (mix also shows teams saved under paired cats)
$teamsStmt = $pdo->prepare("
    SELECT t.* FROM tournament_teams t
    WHERE t.tournament_id=? AND t.event_code=? AND t.age_category_id IN ($catIn)
    ORDER BY t.id
");
$teamsStmt->execute(array_merge([$tournamentId, $event], $busyCats));
$teams = $teamsStmt->fetchAll();
foreach ($teams as &$team) {
    $ms = $pdo->prepare("
        SELECT p.* FROM players p
        JOIN tournament_team_members m ON m.player_id = p.id
        WHERE m.team_id = ?
        ORDER BY CASE p.gender WHEN 'boy' THEN 0 ELSE 1 END, p.full_name
    ");
    $ms->execute([(int)$team['id']]);
    $team['members'] = $ms->fetchAll();
}
unset($team);

$available = array_values(array_filter($players, static function ($p) use ($usedMap) {
    return empty($usedMap[(int)$p['id']]);
}));

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$mixGroupLabel = trim((string)($category['age_group'] ?? ''));
$pageTitle = $def['label'] . ' · ' . ($isMix && $mixGroupLabel !== '' ? $mixGroupLabel : $category['name']);
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?> · <?= bm_h($tournament['held_at']) ?> · <?= bm_h($dateText) ?></p>
    <h1><?= bm_h($isMix && $mixGroupLabel !== '' ? $mixGroupLabel : $category['name']) ?> — <?= bm_h($def['label']) ?></h1>
    <p class="lede">
      <?php if ($event === 'single'): ?>
        Tick <strong>one, several, or all</strong> players, then click <strong>Save teams</strong> once at the bottom — each selected player becomes a Single entry.
      <?php elseif ($isMix): ?>
        Select <strong>1 boy + 1 girl</strong>, click <strong>Lock team</strong>, repeat for more pairs, then <strong>Save teams</strong> once at the bottom. Boys and girls who opted for Mix Double in this age group are listed together.
      <?php else: ?>
        Select <strong>any 2 players</strong>, click <strong>Lock team</strong>, form more pairs the same way, then <strong>Save teams</strong> once at the bottom.
      <?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn" href="tournament.php?id=<?= $tournamentId ?>">Back to tournament</a>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Saved teams (<?= count($teams) ?>)</h2></div>
  <?php if ($teams): ?>
  <table class="table">
    <thead><tr><th>#</th><th>Team</th><th>Players</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($teams as $i => $team): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><strong><?= bm_h($team['team_label'] ?: 'Team') ?></strong></td>
        <td>
          <?php foreach ($team['members'] as $m): ?>
            <div><?= bm_h($m['full_name']) ?> <span class="muted">(<?= bm_h(bm_gender_label($m['gender'])) ?>)</span></div>
          <?php endforeach; ?>
        </td>
        <td class="right">
          <form method="post" class="inline" onsubmit="return confirm('Remove this team?');">
            <input type="hidden" name="delete_team_id" value="<?= (int)$team['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Remove / change</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No teams saved yet for this category and event.</p>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Form teams</h2></div>
  <?php if ($available): ?>
  <form method="post" id="team-form">
    <?php if ($teamSize === 1): ?>
      <p class="hint">
        <button type="button" class="btn btn-sm" id="select-all">Select all</button>
        <button type="button" class="btn btn-sm" id="clear-all">Clear</button>
      </p>
      <table class="table">
        <thead><tr><th class="check-col">Select</th><th>Name</th><th>Gender</th><th>BAI</th><th>PBI</th></tr></thead>
        <tbody>
        <?php foreach ($available as $p): ?>
          <tr>
            <td class="check-col"><input type="checkbox" class="team-pick" name="player_ids[]" value="<?= (int)$p['id'] ?>"></td>
            <td><strong><?= bm_h($p['full_name']) ?></strong></td>
            <td><?= bm_h(bm_gender_label($p['gender'])) ?></td>
            <td><?= bm_h($p['bai_id'] ?: '—') ?></td>
            <td><?= bm_h($p['pbi_id'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div id="locked-wrap" style="margin-bottom:1rem;">
        <h3 style="margin:0 0 0.5rem;font-size:1rem;">Locked teams (not saved yet)</h3>
        <ul id="locked-list" style="list-style:none;padding:0;margin:0;"></ul>
        <p id="locked-empty" class="muted">No pairs locked yet.</p>
      </div>
      <p class="hint">Tick exactly 2 players, then Lock team. Repeat. Save once below.</p>
      <table class="table">
        <thead><tr><th class="check-col">Select</th><th>Name</th><th>Gender</th><th>BAI</th><th>PBI</th></tr></thead>
        <tbody>
        <?php foreach ($available as $p): ?>
          <tr data-player-row="<?= (int)$p['id'] ?>">
            <td class="check-col">
              <input type="checkbox" class="team-pick" value="<?= (int)$p['id'] ?>"
                data-name="<?= bm_h($p['full_name']) ?>" data-gender="<?= bm_h($p['gender']) ?>">
            </td>
            <td><strong><?= bm_h($p['full_name']) ?></strong></td>
            <td><?= bm_h(bm_gender_label($p['gender'])) ?></td>
            <td><?= bm_h($p['bai_id'] ?: '—') ?></td>
            <td><?= bm_h($p['pbi_id'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="form-actions" style="margin-top:0.75rem;">
        <button type="button" class="btn" id="lock-team">Lock team</button>
      </div>
      <div id="teams-hidden"></div>
    <?php endif; ?>
    <div class="form-actions">
      <button type="submit" name="save_teams" value="1" class="btn btn-primary">Save teams</button>
    </div>
  </form>
  <script>
  (function(){
    var teamSize = <?= (int)$teamSize ?>;
    var isMix = <?= $isMix ? 'true' : 'false' ?>;
    if (teamSize === 1) {
      var allBtn = document.getElementById('select-all');
      var clearBtn = document.getElementById('clear-all');
      if (allBtn) allBtn.addEventListener('click', function(){
        document.querySelectorAll('.team-pick').forEach(function(b){ b.checked = true; });
      });
      if (clearBtn) clearBtn.addEventListener('click', function(){
        document.querySelectorAll('.team-pick').forEach(function(b){ b.checked = false; });
      });
      return;
    }
    var locked = [];
    var list = document.getElementById('locked-list');
    var empty = document.getElementById('locked-empty');
    var hidden = document.getElementById('teams-hidden');
    function render(){
      list.innerHTML = '';
      hidden.innerHTML = '';
      empty.style.display = locked.length ? 'none' : 'block';
      locked.forEach(function(t, idx){
        var li = document.createElement('li');
        li.style.margin = '0.35rem 0';
        li.innerHTML = '<strong>Team ' + (idx+1) + ':</strong> ' + t.labels.join(' / ') +
          ' <button type="button" class="btn btn-sm btn-danger" data-i="'+idx+'">Unlock</button>';
        list.appendChild(li);
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'teams[]';
        inp.value = t.ids.join('|');
        hidden.appendChild(inp);
      });
      list.querySelectorAll('button[data-i]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var i = parseInt(btn.getAttribute('data-i'), 10);
          var t = locked.splice(i, 1)[0];
          t.ids.forEach(function(id){
            var row = document.querySelector('[data-player-row="'+id+'"]');
            if (row) row.style.display = '';
            var box = row ? row.querySelector('.team-pick') : null;
            if (box) { box.checked = false; box.disabled = false; }
          });
          render();
        });
      });
    }
    document.getElementById('lock-team').addEventListener('click', function(){
      var checked = Array.prototype.slice.call(document.querySelectorAll('.team-pick:checked:not(:disabled)'));
      if (checked.length !== 2) {
        alert('Select exactly 2 players, then Lock team.');
        return;
      }
      if (isMix) {
        var g = checked.map(function(b){ return b.getAttribute('data-gender'); }).sort();
        if (g[0] !== 'boy' || g[1] !== 'girl') {
          alert('Mix Double needs 1 boy and 1 girl.');
          return;
        }
      }
      var ids = checked.map(function(b){ return parseInt(b.value, 10); });
      var labels = checked.map(function(b){ return b.getAttribute('data-name'); });
      locked.push({ ids: ids, labels: labels });
      checked.forEach(function(b){
        b.checked = false;
        b.disabled = true;
        var row = b.closest('[data-player-row]');
        if (row) row.style.display = 'none';
      });
      render();
    });
    document.querySelectorAll('.team-pick').forEach(function(box){
      box.addEventListener('change', function(){
        var n = document.querySelectorAll('.team-pick:checked:not(:disabled)').length;
        if (n > 2) {
          box.checked = false;
          alert('Select only 2 players at a time, then Lock team.');
        }
      });
    });
    render();
  })();
  </script>
  <?php elseif ($players): ?>
  <p class="empty">All entered players for this event are already in teams. Remove a team to change.</p>
  <?php else: ?>
  <p class="empty">No players entered for this event yet. <a href="tournament-entries.php?tournament_id=<?= $tournamentId ?>">Assign players</a> first.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
