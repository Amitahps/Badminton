<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = (int)($_GET['id'] ?? 0);
$fmt = strtolower(trim((string)($_GET['format'] ?? 'letter')));

$stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$stmt->execute([$id]);
$tournament = $stmt->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('tournaments.php');
}

// Group teams by category then event
$cats = $pdo->prepare("
    SELECT DISTINCT c.*
    FROM age_categories c
    JOIN tournament_teams t ON t.age_category_id = c.id
    WHERE t.tournament_id = ?
    ORDER BY c.sort_order, c.name
");
$cats->execute([$id]);
$categories = $cats->fetchAll();
$grouped = [];
foreach ($categories as $cat) {
    $eventsBlock = [];
    foreach (bm_event_defs() as $code => $def) {
        $ts = $pdo->prepare("
            SELECT * FROM tournament_teams
            WHERE tournament_id=? AND age_category_id=? AND event_code=?
            ORDER BY id
        ");
        $ts->execute([$id, (int)$cat['id'], $code]);
        $teams = $ts->fetchAll();
        if (!$teams) {
            continue;
        }
        foreach ($teams as &$team) {
            $ms = $pdo->prepare("
                SELECT p.* FROM players p
                JOIN tournament_team_members m ON m.player_id = p.id
                WHERE m.team_id=?
                ORDER BY CASE p.gender WHEN 'boy' THEN 0 ELSE 1 END, p.full_name
            ");
            $ms->execute([(int)$team['id']]);
            $team['members'] = $ms->fetchAll();
        }
        unset($team);
        $eventsBlock[] = ['event' => $code, 'label' => $def['label'], 'teams' => $teams];
    }
    if ($eventsBlock) {
        $grouped[] = ['category' => $cat, 'events' => $eventsBlock];
    }
}

$heldAt = $tournament['held_at'];
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$today = date('d-m-Y');

// CSV export
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participants_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $tournament['name']) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Category', 'Event', 'Team No', 'Player Name', 'Gender', 'BAI ID', 'PBI ID', 'Aadhaar', 'DOB']);
    foreach ($grouped as $block) {
        foreach ($block['events'] as $ev) {
            foreach ($ev['teams'] as $ti => $team) {
                foreach ($team['members'] as $p) {
                    fputcsv($out, [
                        $block['category']['name'],
                        $ev['label'],
                        $ti + 1,
                        $p['full_name'],
                        bm_gender_label($p['gender']),
                        $p['bai_id'] ?: '',
                        $p['pbi_id'] ?: '',
                        $p['aadhaar_no'] ?: '',
                        $p['dob'] ?: '',
                    ]);
                }
            }
        }
    }
    fclose($out);
    exit;
}

$pageTitle = 'Participating List · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<style>
@media print {
  .topbar, .no-print, .flash { display: none !important; }
  body { background: #fff; }
  .wrap { max-width: none; padding: 0; margin: 0; width: auto; }
  .letter-sheet { box-shadow: none; border: none; margin: 0; padding: 12mm 14mm; }
}
</style>
<section class="page-head no-print">
  <div>
    <p class="eyebrow">Export</p>
    <h1>Participating players list</h1>
    <p class="lede">Category-wise teams for <?= bm_h($tournament['name']) ?>.</p>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    <a class="btn" href="letter.php?id=<?= $id ?>&format=csv">Download CSV</a>
    <a class="btn" href="tournament.php?id=<?= $id ?>">Back</a>
  </div>
</section>

<article class="letter-sheet">
  <p class="letter-date">Date: <?= bm_h($today) ?></p>
  <p>To<br>The Secretary<br>Punjab Badminton Association</p>
  <p><strong>Subject:</strong> Details of Players Participating in the Tournament Held at <?= bm_h($heldAt) ?> on <?= bm_h($dateText) ?></p>
  <p>Sir/Madam,</p>
  <p>With due respect, please find below the details of the players participating in the badminton tournament being held at <?= bm_h($heldAt) ?> on <?= bm_h($dateText) ?>. The list of players is provided category-wise and event-wise for your kind information and record.</p>

  <?php if ($grouped): ?>
    <?php foreach ($grouped as $block): ?>
      <section class="letter-category">
        <h2><?= bm_h($block['category']['name']) ?></h2>
        <?php foreach ($block['events'] as $ev): ?>
          <h3 style="margin:0.75rem 0 0.35rem;font-size:1.05rem;"><?= bm_h($ev['label']) ?></h3>
          <table class="letter-table">
            <thead>
              <tr>
                <th>Team</th>
                <th>Player Name(s)</th>
                <th>Gender</th>
                <th>BAI ID</th>
                <th>PBI ID</th>
                <th>Aadhaar</th>
                <th>DOB</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($ev['teams'] as $ti => $team): ?>
              <?php foreach ($team['members'] as $mi => $p): ?>
              <tr>
                <?php if ($mi === 0): ?>
                  <td rowspan="<?= count($team['members']) ?>"><?= $ti + 1 ?></td>
                <?php endif; ?>
                <td><?= bm_h($p['full_name']) ?></td>
                <td><?= bm_h(bm_gender_label($p['gender'])) ?></td>
                <td><?= bm_h($p['bai_id'] ?: '—') ?></td>
                <td><?= bm_h($p['pbi_id'] ?: '—') ?></td>
                <td><?= bm_h($p['aadhaar_no'] ?: '—') ?></td>
                <td><?= bm_h($p['dob'] ?: '—') ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <p><em>No teams formed yet. Open tournament → category + event → form teams.</em></p>
  <?php endif; ?>

  <p>Thanking You</p>
  <p class="letter-sign">Member<br>District Badminton Association<br>Hoshiarpur</p>
</article>
<?php require __DIR__ . '/includes/footer.php'; ?>
