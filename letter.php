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

$cats = $pdo->prepare("
    SELECT DISTINCT c.*
    FROM age_categories c
    JOIN tournament_entries e ON e.age_category_id = c.id
    WHERE e.tournament_id = ? AND e.selected = 1
    ORDER BY c.sort_order, c.name
");
$cats->execute([$id]);
$categories = $cats->fetchAll();
$grouped = [];
foreach ($categories as $cat) {
    $ps = $pdo->prepare("
        SELECT p.* FROM players p
        JOIN tournament_entries e ON e.player_id = p.id
        WHERE e.tournament_id = ? AND e.selected = 1 AND e.age_category_id = ?
        ORDER BY p.full_name
    ");
    $ps->execute([$id, (int)$cat['id']]);
    $players = $ps->fetchAll();
    if ($players) {
        $grouped[] = ['category' => $cat, 'players' => $players];
    }
}
$heldAt = $tournament['held_at'];
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$today = date('d-m-Y');

$pageTitle = 'Player Letter · ' . $tournament['name'];
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
    <h1>PBA letter format</h1>
    <p class="lede">Category-wise selected players for <?= bm_h($tournament['name']) ?>.</p>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    <a class="btn" href="tournament.php?id=<?= $id ?>">Back</a>
  </div>
</section>

<article class="letter-sheet">
  <p class="letter-date">Date: <?= bm_h($today) ?></p>
  <p>To<br>The Secretary<br>Punjab Badminton Association</p>
  <p><strong>Subject:</strong> Details of Players Participating in the Tournament Held at <?= bm_h($heldAt) ?> on <?= bm_h($dateText) ?></p>
  <p>Sir/Madam,</p>
  <p>With due respect, please find below the details of the players participating in the badminton tournament being held at <?= bm_h($heldAt) ?> on <?= bm_h($dateText) ?>. The list of players is provided category-wise for your kind information and record.</p>

  <?php if ($grouped): ?>
    <?php foreach ($grouped as $block): ?>
      <section class="letter-category">
        <h2><?= bm_h($block['category']['name']) ?></h2>
        <table class="letter-table">
          <thead>
            <tr>
              <th>S.No.</th><th>Player Name</th><th>Single / Double</th><th>Partner</th>
              <th>BAI ID</th><th>PBI ID</th><th>Aadhaar</th><th>DOB</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($block['players'] as $i => $p): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><?= bm_h($p['full_name']) ?></td>
              <td class="cap"><?= bm_h($p['play_type']) ?></td>
              <td><?= bm_h($p['partner_name'] ?: '—') ?></td>
              <td><?= bm_h($p['bai_id'] ?: '—') ?></td>
              <td><?= bm_h($p['pbi_id'] ?: '—') ?></td>
              <td><?= bm_h($p['aadhaar_no'] ?: '—') ?></td>
              <td><?= bm_h($p['dob'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <p><em>No players selected yet. Open each age category in the tournament and tick participants.</em></p>
  <?php endif; ?>

  <p>Thanking You</p>
  <p class="letter-sign">Member<br>District Badminton Association<br>Hoshiarpur</p>
</article>
<?php require __DIR__ . '/includes/footer.php'; ?>
