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

$heldAt = $tournament['held_at'];
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$defaultTo = "The Secretary\nPunjab Badminton Association";
$defaultSign = "Member\nDistrict Badminton Association\nHoshiarpur";
$defaultSubject = 'Details of Players Participating in the Tournament Held at ' . $heldAt . ' on ' . $dateText;
$defaultBody = 'With due respect, please find below the details of the players participating in the badminton tournament being held at ' . $heldAt . ' on ' . $dateText . '. The list of players is provided category-wise and event-wise for your kind information and record.';
$defaultDate = date('d-m-Y');

if (isset($_POST['save_letter'])) {
    $to = trim((string)($_POST['letter_to'] ?? ''));
    $sign = trim((string)($_POST['letter_sign'] ?? ''));
    $letterDate = trim((string)($_POST['letter_date'] ?? ''));
    $subject = trim((string)($_POST['letter_subject'] ?? ''));
    $body = trim((string)($_POST['letter_body'] ?? ''));
    if ($to === '') {
        $to = $defaultTo;
    }
    if ($sign === '') {
        $sign = $defaultSign;
    }
    if ($letterDate === '') {
        $letterDate = $defaultDate;
    }
    if ($subject === '') {
        $subject = $defaultSubject;
    }
    if ($body === '') {
        $body = $defaultBody;
    }
    $pdo->prepare("UPDATE tournaments SET letter_to=?, letter_sign=?, letter_date=?, letter_subject=?, letter_body=?, updated_at=datetime('now','localtime') WHERE id=?")
        ->execute([$to, $sign, $letterDate, $subject, $body, $id]);
    bm_flash('success', 'Letter text saved. You can edit again anytime before print / copy.');
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
$letterDate = trim((string)($tournament['letter_date'] ?? ''));
if ($letterDate === '') {
    $letterDate = $defaultDate;
}
$letterSubject = trim((string)($tournament['letter_subject'] ?? ''));
if ($letterSubject === '') {
    $letterSubject = $defaultSubject;
}
$letterBody = trim((string)($tournament['letter_body'] ?? ''));
if ($letterBody === '') {
    $letterBody = $defaultBody;
}

// Only teams ticked for letter
$teamRows = $pdo->prepare("
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
      AND IFNULL(t.include_in_letter, 1) = 1
    ORDER BY c.sort_order, c.name, event_sort, t.id
");
$teamRows->execute([$id]);
$rawTeams = $teamRows->fetchAll();

$sectionsByKey = [];
$sectionOrder = [];
foreach ($rawTeams as $team) {
    $cat = [
        'id' => (int)$team['age_category_id'],
        'name' => $team['category_name'],
        'gender_scope' => $team['category_scope'] ?: 'open',
        'age_group' => $team['category_age_group'],
    ];
    $code = (string)$team['event_code'];
    $heading = bm_letter_heading($cat, $code);

    if ($code === 'mix_double') {
        $group = trim((string)($cat['age_group'] ?? ''));
        if ($group === '') {
            $group = bm_infer_category_meta($cat['name'])['age_group'];
        }
        $key = 'mix:' . strtolower($group !== '' ? $group : ('cat-' . $cat['id']));
    } else {
        $key = 'cat:' . $cat['id'] . '|ev:' . $code;
    }

    $ms = $pdo->prepare("
        SELECT p.* FROM players p
        JOIN tournament_team_members m ON m.player_id = p.id
        WHERE m.team_id = ?
        ORDER BY CASE p.gender WHEN 'boy' THEN 0 ELSE 1 END, p.full_name
    ");
    $ms->execute([(int)$team['id']]);
    $team['members'] = $ms->fetchAll();

    if (!isset($sectionsByKey[$key])) {
        $sectionsByKey[$key] = [
            'heading' => $heading,
            'event' => $code,
            'label' => bm_event_label($code),
            'category_name' => $cat['name'],
            'teams' => [],
        ];
        $sectionOrder[] = $key;
    }
    $sectionsByKey[$key]['teams'][] = $team;
}

$grouped = [];
foreach ($sectionOrder as $key) {
    $grouped[] = $sectionsByKey[$key];
}

$tc = $pdo->prepare('SELECT COUNT(*) FROM tournament_teams WHERE tournament_id=?');
$tc->execute([$id]);
$totalTeams = (int)$tc->fetchColumn();
$selCount = 0;
foreach ($grouped as $b) {
    $selCount += count($b['teams']);
}

// CSV export (selected only)
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participants_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $tournament['name']) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', 'Team No', 'Player Name', 'Gender', 'BAI ID', 'PBA ID', 'Aadhaar', 'DOB', 'Address']);
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
                    $p['dob'] ? bm_fmt_date($p['dob']) : '',
                    bm_player_address($p),
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
.letter-body { white-space: pre-line; }
</style>
<section class="page-head no-print">
  <div>
    <p class="eyebrow">Export</p>
    <h1>Participating players list</h1>
    <p class="lede">Showing <strong><?= $selCount ?></strong> of <?= $totalTeams ?> team(s). Edit letter text below, or change which teams are included.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="letter-select.php?tournament_id=<?= $id ?>">Select teams</a>
    <button type="button" class="btn" id="copy-letter">Copy letter</button>
    <button type="button" class="btn" onclick="window.print()">Print / Save as PDF</button>
    <a class="btn" href="letter.php?id=<?= $id ?>&format=csv">Download CSV</a>
    <a class="btn" href="tournament.php?id=<?= $id ?>">Back</a>
  </div>
</section>

<section class="panel narrow no-print">
  <div class="panel-head"><h2>Edit letter</h2></div>
  <form method="post" class="form">
    <input type="hidden" name="id" value="<?= $id ?>">
    <label>Date (dd-mm-yyyy)
      <input type="text" name="letter_date" value="<?= bm_h($letterDate) ?>" maxlength="40" placeholder="dd-mm-yyyy">
    </label>
    <label>To (editable — send to anyone)
      <textarea name="letter_to" rows="4" required><?= bm_h($letterTo) ?></textarea>
    </label>
    <label>Subject
      <textarea name="letter_subject" rows="2" required><?= bm_h($letterSubject) ?></textarea>
    </label>
    <label>Opening paragraph
      <textarea name="letter_body" rows="4" required><?= bm_h($letterBody) ?></textarea>
    </label>
    <label>Sign-off (editable)
      <textarea name="letter_sign" rows="4" required><?= bm_h($letterSign) ?></textarea>
    </label>
    <div class="form-actions">
      <button type="submit" name="save_letter" value="1" class="btn btn-primary">Save letter edits</button>
    </div>
  </form>
</section>

<article class="letter-sheet" id="letter-content">
  <p class="letter-date">Date: <?= bm_h($letterDate) ?></p>
  <p>To<br><span class="letter-to"><?= bm_h($letterTo) ?></span></p>
  <p><strong>Subject:</strong> <?= bm_h($letterSubject) ?></p>
  <p>Sir/Madam,</p>
  <p class="letter-body"><?= bm_h($letterBody) ?></p>

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
                <th>PBA ID</th>
                <th>Aadhaar</th>
                <th>DOB</th>
                <th>Address</th>
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
                <td><?= bm_h(!empty($p['dob']) ? bm_fmt_date($p['dob']) : '—') ?></td>
                <td><?= bm_h(($addr = bm_player_address($p)) !== '' ? $addr : '—') ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
          </table>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <p><em>No teams selected for this letter. Use “Select teams” to tick teams for export.</em></p>
  <?php endif; ?>

  <p>Thanking You</p>
  <p class="letter-sign"><?= bm_h($letterSign) ?></p>
</article>
<script>
(function(){
  var btn = document.getElementById('copy-letter');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var el = document.getElementById('letter-content');
    if (!el) return;
    var text = el.innerText.replace(/\n{3,}/g, '\n\n').trim();
    function ok(){
      var old = btn.textContent;
      btn.textContent = 'Copied — paste anywhere';
      setTimeout(function(){ btn.textContent = old; }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(ok).catch(function(){
        window.prompt('Copy this letter text (Ctrl+C), then paste where you need:', text);
      });
    } else {
      window.prompt('Copy this letter text (Ctrl+C), then paste where you need:', text);
    }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
