<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$fmt = strtolower(trim((string)($_GET['format'] ?? 'letter')));

$stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$stmt->execute([$id]);
$tournament = $stmt->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('tournaments.php');
}

$defaultTo = "The Secretary\nPunjab Badminton Association";
$defaultSign = "Member\nDistrict Badminton Association\nHoshiarpur";

if (isset($_POST['save_letter'])) {
    $to = trim((string)($_POST['letter_to'] ?? ''));
    $sign = trim((string)($_POST['letter_sign'] ?? ''));
    if ($to === '') {
        $to = $defaultTo;
    }
    if ($sign === '') {
        $sign = $defaultSign;
    }
    $pdo->prepare("UPDATE tournaments SET letter_to=?, letter_sign=?, updated_at=datetime('now','localtime') WHERE id=?")
        ->execute([$to, $sign, $id]);
    bm_flash('success', 'Letter address saved. You can print or send this to anyone.');
    bm_redirect('letter.php?id=' . $id);
}

$letterTo = trim((string)($tournament['letter_to'] ?? ''));
if ($letterTo === '') {
    $letterTo = $defaultTo;
}
$letterSign = trim((string)($tournament['letter_sign'] ?? ''));
if ($letterSign === '') {
    $letterSign = $defaultSign;
}

// Flat letter sections: "Under 13 Boys Singles", "Under 15 Mix Double" (mix not under Boys/Girls)
$cats = $pdo->prepare("
    SELECT DISTINCT c.*
    FROM age_categories c
    JOIN tournament_teams t ON t.age_category_id = c.id
    WHERE t.tournament_id = ?
    ORDER BY c.sort_order, c.name
");
$cats->execute([$id]);
$categories = $cats->fetchAll();
$sections = [];
$mixSeen = [];
foreach ($categories as $cat) {
    $allowed = bm_events_for_gender_scope(bm_category_gender_scope($cat));
    foreach ($allowed as $code => $def) {
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

        $heading = bm_letter_heading($cat, $code);
        if ($code === 'mix_double') {
            $group = trim((string)($cat['age_group'] ?? ''));
            if ($group === '') {
                $group = bm_infer_category_meta($cat['name'])['age_group'];
            }
            $mixKey = $group !== '' ? 'g:' . mb_strtolower($group) : 'c:' . (int)$cat['id'];
            if (isset($mixSeen[$mixKey])) {
                // Merge teams into the first Mix Double section for this age group
                $sections[$mixSeen[$mixKey]]['teams'] = array_merge($sections[$mixSeen[$mixKey]]['teams'], $teams);
                continue;
            }
            $mixSeen[$mixKey] = count($sections);
        }

        $sections[] = [
            'heading' => $heading,
            'event' => $code,
            'label' => $def['label'],
            'category_name' => $cat['name'],
            'teams' => $teams,
        ];
    }
}
// Keep $grouped for any legacy reference → map to sections shape used below
$grouped = $sections;

$heldAt = $tournament['held_at'];
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$today = date('d-m-Y');

// CSV export
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participants_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $tournament['name']) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', 'Team No', 'Player Name', 'Gender', 'BAI ID', 'PBI ID', 'Aadhaar', 'DOB']);
    foreach ($grouped as $block) {
        foreach ($block['teams'] as $ti => $team) {
            foreach ($team['members'] as $p) {
                fputcsv($out, [
                    $block['heading'],
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
.letter-to { white-space: pre-line; }
.letter-sign { white-space: pre-line; }
</style>
<section class="page-head no-print">
  <div>
    <p class="eyebrow">Export</p>
    <h1>Participating players list</h1>
    <p class="lede">Edit the “To” address below so this letter can go to Punjab Badminton Association or anyone else.</p>
  </div>
  <div class="page-actions">
    <button type="button" class="btn btn-primary" id="copy-letter">Copy letter</button>
    <button type="button" class="btn" onclick="window.print()">Print / Save as PDF</button>
    <a class="btn" href="letter.php?id=<?= $id ?>&format=csv">Download CSV</a>
    <a class="btn" href="tournament.php?id=<?= $id ?>">Back</a>
  </div>
</section>

<section class="panel narrow no-print">
  <form method="post" class="form">
    <input type="hidden" name="id" value="<?= $id ?>">
    <label>To (editable — send to anyone)
      <textarea name="letter_to" rows="4" required><?= bm_h($letterTo) ?></textarea>
    </label>
    <label>Sign-off (editable)
      <textarea name="letter_sign" rows="4" required><?= bm_h($letterSign) ?></textarea>
    </label>
    <div class="form-actions">
      <button type="submit" name="save_letter" value="1" class="btn btn-primary">Save letter address</button>
    </div>
  </form>
</section>

<article class="letter-sheet">
  <p class="letter-date">Date: <?= bm_h($today) ?></p>
  <p>To<br><span class="letter-to"><?= bm_h($letterTo) ?></span></p>
  <p><strong>Subject:</strong> Details of Players Participating in the Tournament Held at <?= bm_h($heldAt) ?> on <?= bm_h($dateText) ?></p>
  <p>Sir/Madam,</p>
  <p>With due respect, please find below the details of the players participating in the badminton tournament being held at <?= bm_h($heldAt) ?> on <?= bm_h($dateText) ?>. The list of players is provided category-wise and event-wise for your kind information and record.</p>

  <?php if ($grouped): ?>
    <?php foreach ($grouped as $block): ?>
      <section class="letter-category">
        <h2><?= bm_h($block['heading']) ?></h2>
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
            <?php foreach ($block['teams'] as $ti => $team): ?>
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
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <p><em>No teams formed yet. Open tournament → assign players → form teams.</em></p>
  <?php endif; ?>

  <p>Thanking You</p>
  <p class="letter-sign"><?= bm_h($letterSign) ?></p>
</article>
<?php require __DIR__ . '/includes/footer.php'; ?>
