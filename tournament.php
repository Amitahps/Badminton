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

$ecStmt = $pdo->prepare('SELECT COUNT(DISTINCT player_id) FROM tournament_entries WHERE tournament_id = ?');
$ecStmt->execute([$id]);
$entryPlayerCount = (int)$ecStmt->fetchColumn();

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

/** Players assigned to an event who are not yet on a saved team. */
$unplacedStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT e.player_id)
    FROM tournament_entries e
    WHERE e.tournament_id = ?
      AND e.age_category_id = ?
      AND e.event_code = ?
      AND e.player_id NOT IN (
        SELECT m.player_id
        FROM tournament_team_members m
        JOIN tournament_teams t ON t.id = m.team_id
        WHERE t.tournament_id = ?
          AND t.age_category_id = ?
          AND t.event_code = ?
      )
");

// Build display rows: gender events per category; Mix Double once per age group
$tableRows = [];
$mixShown = [];
foreach ($categories as $c) {
    $scope = bm_category_gender_scope($c);
    $events = bm_events_for_gender_scope($scope);
    foreach ($events as $code => $def) {
        if ($code === 'mix_double') {
            continue; // handled once below
        }
        $key = (int)$c['id'] . '|' . $code;
        $unplacedStmt->execute([$id, (int)$c['id'], $code, $id, (int)$c['id'], $code]);
        $tableRows[] = [
            'label' => $c['name'],
            'for' => bm_gender_scope_label($scope),
            'event_label' => $def['label'],
            'event_code' => $code,
            'category_id' => (int)$c['id'],
            'teams' => (int)($teamCounts[$key] ?? 0),
            'not_in_team' => (int)$unplacedStmt->fetchColumn(),
        ];
    }
}
foreach ($categories as $c) {
    $scope = bm_category_gender_scope($c);
    $events = bm_events_for_gender_scope($scope);
    if (!isset($events['mix_double'])) {
        continue;
    }
    $group = trim((string)($c['age_group'] ?? ''));
    $mixKey = $group !== '' ? 'g:' . strtolower($group) : 'c:' . (int)$c['id'];
    if (isset($mixShown[$mixKey])) {
        continue;
    }
    $mixShown[$mixKey] = true;
    $paired = bm_paired_category_ids($pdo, $c);
    $teamSum = 0;
    foreach ($paired as $pcid) {
        $teamSum += (int)($teamCounts[$pcid . '|mix_double'] ?? 0);
    }
    $inList = implode(',', array_fill(0, count($paired), '?'));
    $mixUnplaced = $pdo->prepare("
        SELECT COUNT(DISTINCT e.player_id)
        FROM tournament_entries e
        WHERE e.tournament_id = ?
          AND e.event_code = 'mix_double'
          AND e.age_category_id IN ($inList)
          AND e.player_id NOT IN (
            SELECT m.player_id
            FROM tournament_team_members m
            JOIN tournament_teams t ON t.id = m.team_id
            WHERE t.tournament_id = ?
              AND t.event_code = 'mix_double'
              AND t.age_category_id IN ($inList)
          )
    ");
    $mixUnplaced->execute(array_merge([$id], $paired, [$id], $paired));
    $mixNotInTeam = (int)$mixUnplaced->fetchColumn();
    // Prefer boys category as the open link (still pools both in teams page)
    $linkId = (int)$c['id'];
    foreach ($categories as $pc) {
        if (in_array((int)$pc['id'], $paired, true) && bm_category_gender_scope($pc) === 'boys') {
            $linkId = (int)$pc['id'];
            break;
        }
    }
    $label = $group !== '' ? $group : $c['name'];
    $tableRows[] = [
        'label' => $label,
        'for' => 'Boys + Girls',
        'event_label' => 'Mix Double',
        'event_code' => 'mix_double',
        'category_id' => $linkId,
        'teams' => $teamSum,
        'not_in_team' => $mixNotInTeam,
    ];
}

$lookCategory = (int)($_GET['look_category'] ?? 0);
$lookEvent = trim((string)($_GET['look_event'] ?? ''));
$lookName = trim((string)($_GET['player_name'] ?? ''));
$lookupOn = isset($_GET['lookup']);
$foundTeams = [];
if ($lookupOn) {
    $foundTeams = bm_find_saved_teams($pdo, $id, $lookCategory, $lookEvent, $lookName);
}

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$pageTitle = $tournament['name'] . ' · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Tournament</p>
    <h1><?= bm_h($tournament['name']) ?></h1>
    <p class="lede">Held at <strong><?= bm_h($tournament['held_at']) ?></strong> on <strong><?= bm_h($dateText) ?></strong>. Boys/girls events stay separate; <strong>Mix Double</strong> appears once per age group for boy + girl teams.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="tournament-entries.php?tournament_id=<?= $id ?>">Assign players / categories / events</a>
    <a class="btn" href="letter-select.php?tournament_id=<?= $id ?>">Select teams &amp; export letter</a>
    <a class="btn" href="tournament-form.php?id=<?= $id ?>">Edit details</a>
  </div>
</section>

<section class="stat-row">
  <div class="stat"><span><?= $entryPlayerCount ?></span><small>Players entered here</small></div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Form teams by age category &amp; event</h2></div>
  <?php if (!$categories): ?>
  <p class="empty">Create age categories first. <a href="category-form.php">Create category</a></p>
  <?php elseif ($entryPlayerCount === 0): ?>
  <p class="empty">No players assigned for this tournament yet. <a href="tournament-entries.php?tournament_id=<?= $id ?>">Assign players, age categories and events</a> first.</p>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th>Age category</th>
        <th>For</th>
        <th>Event</th>
        <th>Teams saved</th>
        <th>Players not in team</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tableRows as $row): ?>
      <tr>
        <td><strong><?= bm_h($row['label']) ?></strong></td>
        <td><?= bm_h($row['for']) ?></td>
        <td><?= bm_h($row['event_label']) ?></td>
        <td><?= (int)$row['teams'] ?></td>
        <td><?= (int)($row['not_in_team'] ?? 0) ?></td>
        <td class="right">
          <a class="btn btn-sm btn-primary" href="tournament-teams.php?tournament_id=<?= $id ?>&category_id=<?= (int)$row['category_id'] ?>&event=<?= urlencode($row['event_code']) ?>">Open &amp; form teams</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="panel" id="team-lookup">
  <div class="panel-head"><h2>Find saved teams</h2></div>
  <p class="lede">Choose an age category, an event, or type a player name. The list shows teams in this tournament where that player is already saved.</p>
  <form method="get" class="filters">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="lookup" value="1">
    <select name="look_category">
      <option value="0">All age categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $lookCategory === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="look_event">
      <option value="">All events</option>
      <?php foreach (bm_event_defs() as $code => $def): ?>
        <option value="<?= bm_h($code) ?>" <?= $lookEvent === $code ? 'selected' : '' ?>><?= bm_h($def['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="player_name" value="<?= bm_h($lookName) ?>" placeholder="Player name">
    <button type="submit" class="btn btn-primary">Show teams</button>
    <a class="btn" href="tournament.php?id=<?= $id ?>">Clear</a>
  </form>
  <?php if ($lookupOn): ?>
    <?php if ($foundTeams): ?>
    <table class="table">
      <thead>
        <tr><th>S.No.</th><th>Event</th><th>Team</th><th>Players saved in team</th></tr>
      </thead>
      <tbody>
      <?php foreach ($foundTeams as $i => $team): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><strong><?= bm_h($team['heading']) ?></strong></td>
          <td><?= bm_h($team['team_label'] ?: 'Team') ?></td>
          <td>
            <?php foreach ($team['members'] as $m): ?>
              <?php
                $hit = $lookName !== '' && stripos((string)$m['full_name'], $lookName) !== false;
              ?>
              <div><?= $hit ? '<strong>' : '' ?><?= bm_h($m['full_name']) ?><?= $hit ? '</strong>' : '' ?>
                <span class="muted">(<?= bm_h(bm_gender_label($m['gender'])) ?><?= $m['bai_id'] ? ' · BAI ' . bm_h($m['bai_id']) : '' ?><?= $m['pbi_id'] ? ' · PBA ' . bm_h($m['pbi_id']) : '' ?>)</span>
              </div>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p class="empty">No saved team matches this category, event, or player name.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
