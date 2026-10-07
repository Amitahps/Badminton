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

$filterQs = 'tournament_id=' . $tournamentId . '&category_id=' . $categoryId;
$pageTitle = 'Team report · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Report</p>
    <h1>Team totals</h1>
    <p class="lede">Saved teams: Boys Singles, Girls Singles, Boys Doubles, Girls Doubles, and Mix Doubles. View all tournaments, or filter by tournament and age category.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="report.php?<?= bm_h($filterQs) ?>&format=csv">Download CSV</a>
  </div>
</section>

<section class="panel">
  <form method="get" class="filters">
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
    <a class="btn" href="report.php">Clear</a>
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
<?php require __DIR__ . '/includes/footer.php'; ?>
