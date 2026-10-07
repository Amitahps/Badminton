<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tournamentId = (int)($_GET['tournament_id'] ?? 0);
$categoryId = (int)($_GET['category_id'] ?? 0);

$tournaments = $pdo->query('SELECT id, name, date_from FROM tournaments ORDER BY date_from DESC, id DESC')->fetchAll();
$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();

$sql = "
    SELECT t.event_code, t.tournament_id,
           c.id AS category_id, c.name AS category_name, c.gender_scope, c.age_group, c.sort_order
    FROM tournament_teams t
    JOIN age_categories c ON c.id = t.age_category_id
    WHERE 1=1
";
$params = [];
if ($tournamentId > 0) {
    $sql .= ' AND t.tournament_id = ?';
    $params[] = $tournamentId;
}
if ($categoryId > 0) {
    $sql .= ' AND t.age_category_id = ?';
    $params[] = $categoryId;
}
$sql .= ' ORDER BY c.sort_order, c.name, t.id';
$st = $pdo->prepare($sql);
$st->execute($params);
$teams = $st->fetchAll();

$blank = static function (): array {
    return [
        'boys_single' => 0,
        'girls_single' => 0,
        'boys_double' => 0,
        'girls_double' => 0,
        'mix' => 0,
    ];
};
$bump = static function (array &$row, string $event, string $scope): void {
    if ($event === 'single') {
        if ($scope === 'girls') {
            $row['girls_single']++;
        } else {
            $row['boys_single']++;
        }
    } elseif ($event === 'double_men') {
        $row['boys_double']++;
    } elseif ($event === 'double_girls') {
        $row['girls_double']++;
    } elseif ($event === 'mix_double') {
        $row['mix']++;
    }
};

$byGroup = [];
$totals = $blank();
foreach ($teams as $team) {
    $scope = bm_category_gender_scope($team);
    $group = trim((string)($team['age_group'] ?? ''));
    if ($group === '') {
        $group = (string)$team['category_name'];
    }
    if (!isset($byGroup[$group])) {
        $byGroup[$group] = $blank();
        $byGroup[$group]['sort'] = (int)$team['sort_order'];
        $byGroup[$group]['label'] = $group;
    }
    $bump($byGroup[$group], (string)$team['event_code'], $scope);
    $bump($totals, (string)$team['event_code'], $scope);
}
uasort($byGroup, static function ($a, $b) {
    return ($a['sort'] <=> $b['sort']) ?: strcmp($a['label'], $b['label']);
});

$grand = $totals['boys_single'] + $totals['girls_single'] + $totals['boys_double'] + $totals['girls_double'] + $totals['mix'];

if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="team_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Age group', 'Boys Singles', 'Girls Singles', 'Boys Doubles', 'Girls Doubles', 'Mix Doubles', 'Total teams']);
    foreach ($byGroup as $row) {
        $sum = $row['boys_single'] + $row['girls_single'] + $row['boys_double'] + $row['girls_double'] + $row['mix'];
        fputcsv($out, [$row['label'], $row['boys_single'], $row['girls_single'], $row['boys_double'], $row['girls_double'], $row['mix'], $sum]);
    }
    fputcsv($out, ['Total', $totals['boys_single'], $totals['girls_single'], $totals['boys_double'], $totals['girls_double'], $totals['mix'], $grand]);
    fclose($out);
    exit;
}

$stTournament = (int)($_GET['st_tournament'] ?? 0);
$stCategory = (int)($_GET['st_category'] ?? 0);
$stEvent = trim((string)($_GET['st_event'] ?? ''));
$stName = trim((string)($_GET['player_name'] ?? ''));
$savedOn = isset($_GET['saved']);
$foundTeams = [];
if ($savedOn) {
    $foundTeams = bm_find_saved_teams($pdo, $stTournament, $stCategory, $stEvent, $stName);
}

$filterQs = http_build_query([
    'tournament_id' => $tournamentId,
    'category_id' => $categoryId,
    'st_tournament' => $stTournament,
    'st_category' => $stCategory,
    'st_event' => $stEvent,
    'player_name' => $stName,
] + ($savedOn ? ['saved' => 1] : []));
$clearTotalsQs = http_build_query([
    'st_tournament' => $stTournament,
    'st_category' => $stCategory,
    'st_event' => $stEvent,
    'player_name' => $stName,
] + ($savedOn ? ['saved' => 1] : []));
$clearSavedQs = http_build_query([
    'tournament_id' => $tournamentId,
    'category_id' => $categoryId,
]);
$wrapClass = 'wrap-wide';
$pageTitle = 'Team report · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Report</p>
    <h1>Team totals</h1>
    <p class="lede">Saved teams: Boys Singles, Girls Singles, Boys Doubles, Girls Doubles, and Mix Doubles. View all tournaments, or filter by tournament and age category.</p>
  </div>
  <div class="page-actions no-print">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <a class="btn" href="report.php?<?= bm_h($filterQs) ?>&format=csv">Download CSV</a>
  </div>
</section>

<div class="report-layout">
<div>
<section class="panel no-print">
  <form method="get" class="filters">
    <?php if ($savedOn): ?><input type="hidden" name="saved" value="1"><?php endif; ?>
    <input type="hidden" name="st_tournament" value="<?= $stTournament ?>">
    <input type="hidden" name="st_category" value="<?= $stCategory ?>">
    <input type="hidden" name="st_event" value="<?= bm_h($stEvent) ?>">
    <input type="hidden" name="player_name" value="<?= bm_h($stName) ?>">
    <select name="tournament_id">
      <option value="0">All tournaments</option>
      <?php foreach ($tournaments as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $tournamentId === (int)$t['id'] ? 'selected' : '' ?>><?= bm_h($t['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="category_id">
      <option value="0">All age categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Show report</button>
    <a class="btn" href="report.php?<?= bm_h($clearTotalsQs) ?>">Clear</a>
  </form>
</section>

<section class="stat-row">
  <div class="stat"><span><?= (int)$totals['boys_single'] ?></span><small>Boys Singles</small></div>
  <div class="stat"><span><?= (int)$totals['girls_single'] ?></span><small>Girls Singles</small></div>
  <div class="stat"><span><?= (int)$totals['boys_double'] ?></span><small>Boys Doubles</small></div>
  <div class="stat"><span><?= (int)$totals['girls_double'] ?></span><small>Girls Doubles</small></div>
  <div class="stat"><span><?= (int)$totals['mix'] ?></span><small>Mix Doubles</small></div>
  <div class="stat"><span><?= (int)$grand ?></span><small>Total teams</small></div>
</section>

<section class="panel">
  <div class="panel-head"><h2>By age group</h2></div>
  <?php if ($byGroup): ?>
  <table class="table">
    <thead>
      <tr>
        <th>Age group</th>
        <th>Boys Singles</th>
        <th>Girls Singles</th>
        <th>Boys Doubles</th>
        <th>Girls Doubles</th>
        <th>Mix Doubles</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($byGroup as $row): ?>
      <?php $sum = $row['boys_single'] + $row['girls_single'] + $row['boys_double'] + $row['girls_double'] + $row['mix']; ?>
      <tr>
        <td><strong><?= bm_h($row['label']) ?></strong></td>
        <td><?= (int)$row['boys_single'] ?></td>
        <td><?= (int)$row['girls_single'] ?></td>
        <td><?= (int)$row['boys_double'] ?></td>
        <td><?= (int)$row['girls_double'] ?></td>
        <td><?= (int)$row['mix'] ?></td>
        <td><strong><?= (int)$sum ?></strong></td>
      </tr>
    <?php endforeach; ?>
      <tr>
        <td><strong>Total</strong></td>
        <td><strong><?= (int)$totals['boys_single'] ?></strong></td>
        <td><strong><?= (int)$totals['girls_single'] ?></strong></td>
        <td><strong><?= (int)$totals['boys_double'] ?></strong></td>
        <td><strong><?= (int)$totals['girls_double'] ?></strong></td>
        <td><strong><?= (int)$totals['mix'] ?></strong></td>
        <td><strong><?= (int)$grand ?></strong></td>
      </tr>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No saved teams for this filter.</p>
  <?php endif; ?>
</section>
</div>

<aside class="report-side no-print" id="saved-teams">
  <section class="panel">
    <div class="panel-head"><h2>Saved teams</h2></div>
    <p class="lede">Pick a tournament, age category, event, or type a player name. The list shows teams where that player is already saved.</p>
    <form method="get" class="filters" action="report.php#saved-teams">
      <input type="hidden" name="saved" value="1">
      <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
      <input type="hidden" name="category_id" value="<?= $categoryId ?>">
      <select name="st_tournament">
        <option value="0">All tournaments</option>
        <?php foreach ($tournaments as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $stTournament === (int)$t['id'] ? 'selected' : '' ?>><?= bm_h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="st_category">
        <option value="0">All age categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $stCategory === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="st_event">
        <option value="">All events</option>
        <?php foreach (bm_event_defs() as $code => $def): ?>
          <option value="<?= bm_h($code) ?>" <?= $stEvent === $code ? 'selected' : '' ?>><?= bm_h($def['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="search" name="player_name" value="<?= bm_h($stName) ?>" placeholder="Player name">
      <button type="submit" class="btn btn-primary">Show teams</button>
      <a class="btn" href="report.php?<?= bm_h($clearSavedQs) ?>#saved-teams">Clear</a>
    </form>
    <?php if ($savedOn): ?>
      <?php if ($foundTeams): ?>
      <table class="table">
        <thead>
          <tr>
            <th>S.No.</th>
            <?php if ($stTournament === 0): ?><th>Tournament</th><?php endif; ?>
            <th>Event</th>
            <th>Players saved in team</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($foundTeams as $i => $team): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <?php if ($stTournament === 0): ?><td><?= bm_h($team['tournament_name']) ?></td><?php endif; ?>
            <td><strong><?= bm_h($team['heading']) ?></strong></td>
            <td>
              <?php foreach ($team['members'] as $m): ?>
                <?php $hit = $stName !== '' && stripos((string)$m['full_name'], $stName) !== false; ?>
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
      <p class="empty">No saved team matches this tournament, category, event, or player name.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
