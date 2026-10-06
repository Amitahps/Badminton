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

$def = $events[$event];
$teamSize = (int)$def['team_size'];

// Delete team
if (isset($_POST['delete_team_id'])) {
    $tid = (int)$_POST['delete_team_id'];
    $chk = $pdo->prepare('SELECT id FROM tournament_teams WHERE id=? AND tournament_id=?');
    $chk->execute([$tid, $tournamentId]);
    if ($chk->fetch()) {
        $pdo->prepare('DELETE FROM tournament_teams WHERE id=?')->execute([$tid]);
        bm_flash('success', 'Team removed. You can form a new team.');
    }
    bm_redirect('tournament-teams.php?tournament_id=' . $tournamentId . '&category_id=' . $catId . '&event=' . urlencode($event));
}

// Create / replace team
if (isset($_POST['save_team'])) {
    $playerIds = array_values(array_unique(array_map('intval', $_POST['player_ids'] ?? [])));
    $playerIds = array_values(array_filter($playerIds, static fn($x) => $x > 0));

    try {
        if (count($playerIds) !== $teamSize) {
            throw new RuntimeException('Select exactly ' . $teamSize . ' player(s) for ' . $def['label'] . '.');
        }

        // Load players and validate
        $in = implode(',', array_fill(0, count($playerIds), '?'));
        $pst = $pdo->prepare("SELECT * FROM players WHERE id IN ($in)");
        $pst->execute($playerIds);
        $picked = $pst->fetchAll();
        if (count($picked) !== $teamSize) {
            throw new RuntimeException('One or more selected players not found.');
        }

        foreach ($picked as $p) {
            if ((int)$p['age_category_id'] !== $catId) {
                throw new RuntimeException($p['full_name'] . ' is not in this age category.');
            }
            if (!bm_player_has_event($pdo, (int)$p['id'], $event)) {
                throw new RuntimeException($p['full_name'] . ' is not assigned to event ' . $def['label'] . '.');
            }
        }

        $genders = array_column($picked, 'gender');
        if ($event === 'single') {
            // ok any gender
        } elseif ($event === 'double_men') {
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

        // A player can only be in one team for this tournament+category+event
        foreach ($playerIds as $pid) {
            $busy = $pdo->prepare("
                SELECT t.id FROM tournament_teams t
                JOIN tournament_team_members m ON m.team_id = t.id
                WHERE t.tournament_id=? AND t.age_category_id=? AND t.event_code=? AND m.player_id=?
            ");
            $busy->execute([$tournamentId, $catId, $event, $pid]);
            if ($busy->fetch()) {
                $name = '';
                foreach ($picked as $p) {
                    if ((int)$p['id'] === $pid) {
                        $name = $p['full_name'];
                        break;
                    }
                }
                throw new RuntimeException(($name ?: 'Player') . ' is already in another team for this event. Remove that team first to change.');
            }
        }

        $names = [];
        foreach ($picked as $p) {
            $names[] = $p['full_name'];
        }
        $label = implode(' / ', $names);

        $pdo->prepare('INSERT INTO tournament_teams (tournament_id, age_category_id, event_code, team_label) VALUES (?,?,?,?)')
            ->execute([$tournamentId, $catId, $event, $label]);
        $teamId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO tournament_team_members (team_id, player_id) VALUES (?,?)');
        foreach ($playerIds as $pid) {
            $ins->execute([$teamId, $pid]);
        }
        bm_flash('success', 'Team saved: ' . $label);
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
    }
    bm_redirect('tournament-teams.php?tournament_id=' . $tournamentId . '&category_id=' . $catId . '&event=' . urlencode($event));
}

// Eligible players for this category+event (player may also play other events)
$elig = $pdo->prepare("
    SELECT p.* FROM players p
    JOIN player_events pe ON pe.player_id = p.id AND pe.event_code = ?
    WHERE p.age_category_id = ?
    ORDER BY p.full_name
");
$elig->execute([$event, $catId]);
$players = $elig->fetchAll();

// Already used in THIS event only (same player may still join other events)
$usedStmt = $pdo->prepare("
    SELECT m.player_id FROM tournament_team_members m
    JOIN tournament_teams t ON t.id = m.team_id
    WHERE t.tournament_id=? AND t.age_category_id=? AND t.event_code=?
");
$usedStmt->execute([$tournamentId, $catId, $event]);
$usedIds = array_map('intval', array_column($usedStmt->fetchAll(), 'player_id'));
$usedMap = array_fill_keys($usedIds, true);

// Existing teams
$teamsStmt = $pdo->prepare("
    SELECT t.* FROM tournament_teams t
    WHERE t.tournament_id=? AND t.age_category_id=? AND t.event_code=?
    ORDER BY t.id
");
$teamsStmt->execute([$tournamentId, $catId, $event]);
$teams = $teamsStmt->fetchAll();
foreach ($teams as &$team) {
    $ms = $pdo->prepare("
        SELECT p.* FROM players p
        JOIN tournament_team_members m ON m.player_id = p.id
        WHERE m.team_id = ?
        ORDER BY p.full_name
    ");
    $ms->execute([(int)$team['id']]);
    $team['members'] = $ms->fetchAll();
}
unset($team);

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$pageTitle = $def['label'] . ' · ' . $category['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?> · <?= bm_h($tournament['held_at']) ?> · <?= bm_h($dateText) ?></p>
    <h1><?= bm_h($category['name']) ?> — <?= bm_h($def['label']) ?></h1>
    <p class="lede">
      <?php if ($event === 'single'): ?>
        Select <strong>1 player</strong> per team.
      <?php elseif ($event === 'double_men'): ?>
        Select <strong>2 boys</strong> to form a team.
      <?php elseif ($event === 'double_girls'): ?>
        Select <strong>2 girls</strong> to form a team.
      <?php else: ?>
        Select <strong>1 boy + 1 girl</strong> to form a mix double team.
      <?php endif; ?>
      Same player can also play in <strong>other events</strong> in this tournament. To change a team here, remove it and form again.
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
          <form method="post" class="inline" onsubmit="return confirm('Remove this team so players can join another team?');">
            <input type="hidden" name="delete_team_id" value="<?= (int)$team['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Remove / change</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No teams yet for this category and event.</p>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Form a new team</h2></div>
  <?php
    $available = array_values(array_filter($players, static function ($p) use ($usedMap) {
        return empty($usedMap[(int)$p['id']]);
    }));
  ?>
  <?php if ($available): ?>
  <form method="post" id="team-form">
    <p class="hint">Tick <?= (int)$teamSize ?> player<?= $teamSize > 1 ? 's' : '' ?>, then save.</p>
    <table class="table">
      <thead>
        <tr><th class="check-col">Select</th><th>Name</th><th>Gender</th><th>BAI</th><th>PBI</th></tr>
      </thead>
      <tbody>
      <?php foreach ($available as $p): ?>
        <tr>
          <td class="check-col">
            <input type="checkbox" class="team-pick" name="player_ids[]" value="<?= (int)$p['id'] ?>">
          </td>
          <td><strong><?= bm_h($p['full_name']) ?></strong></td>
          <td><?= bm_h(bm_gender_label($p['gender'])) ?></td>
          <td><?= bm_h($p['bai_id'] ?: '—') ?></td>
          <td><?= bm_h($p['pbi_id'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="form-actions">
      <button type="submit" name="save_team" value="1" class="btn btn-primary">Save team</button>
    </div>
  </form>
  <script>
  (function(){
    var max = <?= (int)$teamSize ?>;
    var boxes = document.querySelectorAll('.team-pick');
    boxes.forEach(function(box){
      box.addEventListener('change', function(){
        var n = document.querySelectorAll('.team-pick:checked').length;
        if (n > max) {
          box.checked = false;
          alert('Select only ' + max + ' player(s) for this event.');
        }
      });
    });
  })();
  </script>
  <?php elseif ($players): ?>
  <p class="empty">All assigned players for this event are already in teams. Remove a team to change partners.</p>
  <?php else: ?>
  <p class="empty">No players assigned to this age category + event yet. <a href="players.php">Assign players</a> first.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
