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

$categories = bm_tournament_categories($pdo, $id);

if (isset($_POST['confirm_paid']) || isset($_POST['accept_paid'])) {
    $sid = (int)($_POST['confirm_paid'] ?? $_POST['accept_paid'] ?? 0);
    $sub = bm_parent_load_by_id($pdo, $sid, $id);
    if ($sub) {
        bm_parent_confirm_paid($pdo, $sub);
        bm_flash('success', 'Accepted. Entry is on the tournament list.');
    }
    bm_redirect('tournament.php?id=' . $id);
}
if (isset($_POST['accept_pending'])) {
    $sid = (int)$_POST['accept_pending'];
    $sub = bm_parent_load_by_id($pdo, $sid, $id);
    if ($sub) {
        bm_parent_accept_pending_cash($pdo, $sub);
        bm_flash('success', 'Entry accepted with payment pending.');
    }
    bm_redirect('tournament.php?id=' . $id);
}
if (isset($_POST['mark_cash'])) {
    $sid = (int)$_POST['mark_cash'];
    $sub = bm_parent_load_by_id($pdo, $sid, $id);
    if ($sub) {
        bm_parent_mark_cash_received($pdo, $sub);
        bm_flash('success', 'Payment received. Status is now paid.');
    }
    bm_redirect('tournament.php?id=' . $id);
}
if (isset($_POST['decline_reason']) && isset($_POST['decline_id'])) {
    $sub = bm_parent_load_by_id($pdo, (int)$_POST['decline_id'], $id);
    if ($sub) {
        try {
            bm_parent_decline_payment($pdo, $sub, (string)$_POST['decline_reason']);
            bm_flash('success', 'Declined: ' . bm_decline_reason_label((string)$_POST['decline_reason']));
        } catch (Throwable $e) {
            bm_flash('error', $e->getMessage());
        }
    }
    bm_redirect('tournament.php?id=' . $id);
}

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

$publicToken = bm_tournament_ensure_public_token($pdo, $tournament);
$parentLink = bm_join_url($publicToken);
$parentSubs = $pdo->prepare("
    SELECT s.id, s.created_at, s.event_codes, s.receipt_code, s.father_name, s.contact_mobile,
           s.payment_status, s.payment_amount, s.payment_utr, s.payment_date, s.payment_sender, s.decline_reason,
           c.name AS category_name, p.full_name
    FROM parent_submissions s
    JOIN age_categories c ON c.id = s.age_category_id
    JOIN players p ON p.id = s.primary_player_id
    WHERE s.tournament_id = ?
    ORDER BY CASE WHEN s.payment_status = 'review' THEN 0 ELSE 1 END,
             CASE WHEN IFNULL(s.payment_date,'') = '' THEN 1 ELSE 0 END,
             s.payment_date ASC, s.payment_utr ASC, s.id ASC
");
$parentSubs->execute([$id]);
$parentRows = $parentSubs->fetchAll();
$reviewByDate = [];
$otherParentRows = [];
foreach ($parentRows as $ps) {
    if ((string)($ps['payment_status'] ?? '') === 'review') {
        $dk = trim((string)($ps['payment_date'] ?? ''));
        $reviewByDate[$dk !== '' ? $dk : '_none'][] = $ps;
    } else {
        $otherParentRows[] = $ps;
    }
}

$renderParentRow = static function (array $ps): void {
    $st = (string)($ps['payment_status'] ?? '');
    $pd = trim((string)($ps['payment_date'] ?? ''));
    $labs = [];
    foreach (explode(',', (string)$ps['event_codes']) as $code) {
        $code = trim($code);
        if ($code !== '') {
            $labs[] = bm_event_label($code);
        }
    }
    echo '<tr>';
    echo '<td><strong>' . bm_h($pd !== '' ? bm_fmt_date($pd) : '—') . '</strong></td>';
    echo '<td>' . bm_h((string)($ps['payment_utr'] ?? '')) . '</td>';
    echo '<td>' . bm_h((string)($ps['payment_sender'] ?? '')) . '</td>';
    echo '<td>' . ((int)($ps['payment_amount'] ?? 0) > 0 ? '₹' . (int)$ps['payment_amount'] : '—') . '</td>';
    echo '<td><strong>' . bm_h((string)$ps['full_name']) . '</strong>';
    if (trim((string)($ps['father_name'] ?? '')) !== '') {
        echo '<div class="muted">' . bm_h((string)$ps['father_name']) . '</div>';
    }
    if (trim((string)($ps['contact_mobile'] ?? '')) !== '') {
        echo '<div class="muted">' . bm_h((string)$ps['contact_mobile']) . '</div>';
    }
    echo '</td>';
    echo '<td>' . bm_h((string)$ps['category_name']) . '</td>';
    echo '<td>' . bm_h(implode(', ', $labs)) . '</td>';
    echo '<td>' . bm_h(bm_parent_payment_label($st, (int)($ps['payment_amount'] ?? 0)));
    if ($st === 'declined') {
        echo '<div class="muted">' . bm_h(bm_decline_reason_label((string)($ps['decline_reason'] ?? ''))) . '</div>';
    }
    echo '</td>';
    echo '<td class="right actions">';
    echo '<a class="btn btn-sm" href="parent-edit.php?id=' . (int)$ps['id'] . '">Open</a>';
    if (in_array($st, ['review', 'declined'], true)) {
        echo '<form method="post" class="inline"><button type="submit" name="accept_paid" value="' . (int)$ps['id'] . '" class="btn btn-sm btn-primary" ' . bm_parent_confirm_attr('accept_paid') . '>Accept</button></form>';
        echo '<form method="post" class="inline"><button type="submit" name="accept_pending" value="' . (int)$ps['id'] . '" class="btn btn-sm" ' . bm_parent_confirm_attr('accept_pending') . '>Payment pending</button></form>';
        foreach (bm_decline_reasons() as $code => $lab) {
            echo '<form method="post" class="inline"><input type="hidden" name="decline_id" value="' . (int)$ps['id'] . '">';
            echo '<button type="submit" name="decline_reason" value="' . bm_h($code) . '" class="btn btn-sm btn-danger" ' . bm_parent_confirm_attr($code) . '>' . bm_h($lab) . '</button></form>';
        }
    } elseif ($st === 'pending') {
        echo '<form method="post" class="inline"><button type="submit" name="mark_cash" value="' . (int)$ps['id'] . '" class="btn btn-sm btn-primary" ' . bm_parent_confirm_attr('mark_cash') . '>Payment received</button></form>';
    }
    echo '<form method="post" action="parent-edit.php" class="inline" onsubmit="return confirm(\'Remove this parent entry from the tournament, letter and Excel?\');">';
    echo '<input type="hidden" name="id" value="' . (int)$ps['id'] . '">';
    echo '<button type="submit" name="delete_parent" value="1" class="btn btn-sm btn-danger">Remove</button></form>';
    echo '</td></tr>';
};

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$wrapClass = 'wrap-wide';
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
    <a class="btn" href="confirmed.php?id=<?= $id ?>">Confirmed entries</a>
    <a class="btn" href="receipts.php?id=<?= $id ?>">Download receipts</a>
    <a class="btn" href="parent-verify.php?tournament_id=<?= $id ?>">Verify / print / Excel</a>
    <a class="btn" href="letter.php?id=<?= $id ?>">Share letter PDF</a>
    <a class="btn" href="letter.php?id=<?= $id ?>&amp;for=parents">Share parents &amp; coaches PDF</a>
    <a class="btn" href="letter-select.php?tournament_id=<?= $id ?>">Select teams &amp; export letter</a>
    <a class="btn" href="tournament-form.php?id=<?= $id ?>">Edit details</a>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Parent entry link</h2></div>
  <p class="lede">Send this link to parents and coaches. They only see this tournament’s age categories. After save they get a PDF of every player they sent, and can edit mistakes. Assign-players still works as before.</p>
  <div class="share-link">
    <input id="parent-link" type="text" readonly value="<?= bm_h($parentLink) ?>">
    <button type="button" class="btn btn-primary" id="copy-parent-link">Copy link</button>
  </div>
  <?php if ($parentRows): ?>
  <p class="hint" style="margin-top:0.85rem;"><?= count($parentRows) ?> parent submission(s), grouped by the payment date the parent entered so you can match the bank statement. Each Accept or Decline asks you to confirm first.</p>
  <?php if ($reviewByDate): ?>
  <h3 class="date-band">Awaiting check — by payment date</h3>
  <?php foreach ($reviewByDate as $dateKey => $list): ?>
  <p class="date-band"><strong>Payment date <?= $dateKey === '_none' ? 'not given' : bm_h(bm_fmt_date($dateKey)) ?></strong> · <?= count($list) ?></p>
  <table class="table">
    <thead><tr><th>Pay date</th><th>UTR</th><th>Sender</th><th>₹</th><th>Player</th><th>Age</th><th>Events</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $ps) { $renderParentRow($ps); } ?>
    </tbody>
  </table>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($otherParentRows): ?>
  <h3 class="date-band">Already decided</h3>
  <table class="table">
    <thead><tr><th>Pay date</th><th>UTR</th><th>Sender</th><th>₹</th><th>Player</th><th>Age</th><th>Events</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($otherParentRows as $ps) { $renderParentRow($ps); } ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php endif; ?>
</section>
<script>
(function(){
  var btn = document.getElementById('copy-parent-link');
  var inp = document.getElementById('parent-link');
  if (!btn || !inp) return;
  btn.addEventListener('click', function(){
    var t = inp.value;
    function ok(){ var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = o; }, 1600); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(ok).catch(function(){ inp.select(); document.execCommand('copy'); ok(); });
    } else { inp.select(); document.execCommand('copy'); ok(); }
  });
})();
</script>

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
<?php require __DIR__ . '/includes/footer.php'; ?>
