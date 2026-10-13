<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$fmt = strtolower(trim((string)($_GET['format'] ?? 'letter')));
$forParents = ((string)($_GET['for'] ?? $_POST['letter_for'] ?? '')) === 'parents';

$stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$stmt->execute([$id]);
$tournament = $stmt->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('tournaments.php');
}

$heldAt = $tournament['held_at'];
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$defaultSign = "Member\nDistrict Badminton Association\nHoshiarpur";
$defaultDate = date('d-m-Y');
$defaultSubject = 'Details of Players Participating in the Tournament Held at ' . $heldAt . ' on ' . $dateText;
if ($forParents) {
    $defaultTo = "Parents and Coaches";
    $defaultBody = 'Please find below the details of the players participating in the badminton tournament being held at ' . $heldAt . ' on ' . $dateText . '. The list of players is provided category-wise and event-wise for your kind information.';
} else {
    $defaultTo = "The Secretary\nPunjab Badminton Association";
    $defaultBody = 'With due respect, please find below the details of the players participating in the badminton tournament being held at ' . $heldAt . ' on ' . $dateText . '. The list of players is provided category-wise and event-wise for your kind information and record.';
}

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
    if ($forParents) {
        $pdo->prepare("UPDATE tournaments SET letter_parents_to=?, letter_parents_sign=?, letter_parents_date=?, letter_parents_subject=?, letter_parents_body=?, updated_at=datetime('now','localtime') WHERE id=?")
            ->execute([$to, $sign, $letterDate, $subject, $body, $id]);
        bm_flash('success', 'Parents and coaches letter saved. You can edit again anytime before print / copy.');
        bm_redirect('letter.php?id=' . $id . '&for=parents');
    }
    $cols = bm_letter_fields_from_post($_POST);
    $pdo->prepare("UPDATE tournaments SET letter_to=?, letter_sign=?, letter_date=?, letter_subject=?, letter_body=?, letter_columns=?, updated_at=datetime('now','localtime') WHERE id=?")
        ->execute([$to, $sign, $letterDate, $subject, $body, $cols, $id]);
    bm_flash('success', 'Letter text saved. You can edit again anytime before print / copy.');
    bm_redirect('letter.php?id=' . $id);
}

if ($forParents) {
    $letterTo = trim((string)($tournament['letter_parents_to'] ?? ''));
    $letterSign = trim((string)($tournament['letter_parents_sign'] ?? ''));
    $letterDate = trim((string)($tournament['letter_parents_date'] ?? ''));
    $letterSubject = trim((string)($tournament['letter_parents_subject'] ?? ''));
    $letterBody = trim((string)($tournament['letter_parents_body'] ?? ''));
    if ($letterSign === '') {
        $letterSign = trim((string)($tournament['letter_sign'] ?? ''));
    }
    if ($letterDate === '') {
        $letterDate = trim((string)($tournament['letter_date'] ?? ''));
    }
} else {
    $letterTo = trim((string)($tournament['letter_to'] ?? ''));
    $letterSign = trim((string)($tournament['letter_sign'] ?? ''));
    $letterDate = trim((string)($tournament['letter_date'] ?? ''));
    $letterSubject = trim((string)($tournament['letter_subject'] ?? ''));
    $letterBody = trim((string)($tournament['letter_body'] ?? ''));
}
if ($letterTo === '') {
    $letterTo = $defaultTo;
}
if ($letterSign === '') {
    $letterSign = $defaultSign;
}
if ($letterDate === '') {
    $letterDate = $defaultDate;
}
if ($letterSubject === '') {
    $letterSubject = $defaultSubject;
}
if ($letterBody === '') {
    $letterBody = $defaultBody;
}
$letterGreeting = $forParents ? 'Dear Parents and Coaches,' : 'Sir/Madam,';
$letterFields = bm_letter_fields_enabled($tournament['letter_columns'] ?? null);

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
    $head = ['Section', 'Team No', 'Player Name'];
    foreach (bm_letter_field_defs() as $key => $label) {
        if (bm_letter_shows($letterFields, $key)) {
            $head[] = $label;
        }
    }
    fputcsv($out, $head);
    foreach ($grouped as $block) {
        foreach ($block['teams'] as $ti => $team) {
            foreach ($team['members'] as $p) {
                $rowOut = [$block['heading'], $ti + 1, $p['full_name']];
                if (bm_letter_shows($letterFields, 'gender')) {
                    $rowOut[] = bm_gender_label($p['gender']);
                }
                if (bm_letter_shows($letterFields, 'bai')) {
                    $rowOut[] = $p['bai_id'] ?: '';
                }
                if (bm_letter_shows($letterFields, 'pba')) {
                    $rowOut[] = $p['pbi_id'] ?: '';
                }
                if (bm_letter_shows($letterFields, 'aadhaar')) {
                    $rowOut[] = $p['aadhaar_no'] ?: '';
                }
                if (bm_letter_shows($letterFields, 'dob')) {
                    $rowOut[] = $p['dob'] ? bm_fmt_date($p['dob']) : '';
                }
                if (bm_letter_shows($letterFields, 'address')) {
                    $rowOut[] = bm_player_address($p);
                }
                if (bm_letter_shows($letterFields, 'free')) {
                    $rowOut[] = bm_team_is_free($team) ? 'Free' : '';
                }
                fputcsv($out, $rowOut);
            }
        }
    }
    fclose($out);
    exit;
}

$pageTitle = ($forParents ? 'Parents & coaches list' : 'Participating List') . ' · ' . $tournament['name'];
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
    <p class="eyebrow"><?= $forParents ? 'Parents &amp; coaches' : 'Export' ?></p>
    <h1><?= $forParents ? 'Parents and coaches list' : 'Participating players list' ?></h1>
    <?php if ($forParents): ?>
    <p class="lede">Showing <strong><?= $selCount ?></strong> of <?= $totalTeams ?> team(s). Same one-file letter/PDF as the official letter — for parents and coaches. Extra columns match what you ticked on the letter.</p>
    <?php else: ?>
    <p class="lede">Showing <strong><?= $selCount ?></strong> of <?= $totalTeams ?> team(s). This is one letter/PDF for the whole tournament — not a file per parent. Only the extra columns you tick below appear on the letter.</p>
    <?php endif; ?>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="letter-select.php?tournament_id=<?= $id ?>">Select teams</a>
    <button type="button" class="btn" id="copy-letter">Copy letter</button>
    <button type="button" class="btn" onclick="window.print()">Print / Save as PDF</button>
    <?php if ($forParents): ?>
    <a class="btn" href="letter.php?id=<?= $id ?>">Official letter</a>
    <?php else: ?>
    <a class="btn" href="letter.php?id=<?= $id ?>&amp;for=parents">Parents &amp; coaches PDF</a>
    <a class="btn" href="letter.php?id=<?= $id ?>&amp;format=csv">Download CSV</a>
    <?php endif; ?>
    <a class="btn" href="tournament.php?id=<?= $id ?>">Back</a>
  </div>
</section>

<section class="panel no-print" style="max-width:720px;">
  <div class="panel-head"><h2><?= $forParents ? 'Edit parents &amp; coaches letter' : 'Edit letter' ?></h2></div>
  <form method="post" class="form">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($forParents): ?>
    <input type="hidden" name="letter_for" value="parents">
    <?php endif; ?>
    <label>Date (dd-mm-yyyy)
      <input type="text" name="letter_date" value="<?= bm_h($letterDate) ?>" maxlength="40" placeholder="dd-mm-yyyy">
    </label>
    <label><?= $forParents ? 'To (editable)' : 'To (editable — send to anyone)' ?>
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
    <?php if (!$forParents): ?>
    <fieldset class="letter-fields">
      <legend>Show in letter / PDF / Excel (player name is always shown)</legend>
      <?php foreach (bm_letter_field_defs() as $key => $label): ?>
        <label class="check-inline">
          <input type="checkbox" name="letter_fields[]" value="<?= bm_h($key) ?>" <?= bm_letter_shows($letterFields, $key) ? 'checked' : '' ?>>
          <?= bm_h($label) ?>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <?php else: ?>
    <p class="hint">Extra columns (gender, BAI, PBA, Aadhaar, date of birth, address, free entry) are the same as the official letter. Tick them there.</p>
    <?php endif; ?>
    <div class="form-actions">
      <button type="submit" name="save_letter" value="1" class="btn btn-primary"><?= $forParents ? 'Save parents &amp; coaches letter' : 'Save letter edits' ?></button>
    </div>
  </form>
</section>

<article class="letter-sheet" id="letter-content">
  <p class="letter-date">Date: <?= bm_h($letterDate) ?></p>
  <p>To<br><span class="letter-to"><?= bm_h($letterTo) ?></span></p>
  <p><strong>Subject:</strong> <?= bm_h($letterSubject) ?></p>
  <p><?= bm_h($letterGreeting) ?></p>
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
                <?php foreach (bm_letter_field_defs() as $key => $label): ?>
                  <?php if (bm_letter_shows($letterFields, $key)): ?>
                    <th><?= bm_h($label) ?></th>
                  <?php endif; ?>
                <?php endforeach; ?>
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
                <?php if (bm_letter_shows($letterFields, 'gender')): ?>
                  <td><?= bm_h(bm_gender_label($p['gender'])) ?></td>
                <?php endif; ?>
                <?php if (bm_letter_shows($letterFields, 'bai')): ?>
                  <td><?= bm_h($p['bai_id'] ?: '—') ?></td>
                <?php endif; ?>
                <?php if (bm_letter_shows($letterFields, 'pba')): ?>
                  <td><?= bm_h($p['pbi_id'] ?: '—') ?></td>
                <?php endif; ?>
                <?php if (bm_letter_shows($letterFields, 'aadhaar')): ?>
                  <td><?= bm_h($p['aadhaar_no'] ?: '—') ?></td>
                <?php endif; ?>
                <?php if (bm_letter_shows($letterFields, 'dob')): ?>
                  <td><?= bm_h(!empty($p['dob']) ? bm_fmt_date($p['dob']) : '—') ?></td>
                <?php endif; ?>
                <?php if (bm_letter_shows($letterFields, 'address')): ?>
                  <td><?= bm_h(($addr = bm_player_address($p)) !== '' ? $addr : '—') ?></td>
                <?php endif; ?>
                <?php if (bm_letter_shows($letterFields, 'free')): ?>
                  <td><?= bm_team_is_free($team) ? 'Free' : '—' ?></td>
                <?php endif; ?>
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
