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
bm_remember_tournament_id($id);

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
if (isset($_POST['clear_bank_auto'])) {
    unset($_SESSION['bm_bank_auto'][$id]);
    bm_flash('success', 'Auto-check result cleared. Manual Accept / Decline still works.');
    bm_redirect('tournament.php?id=' . $id);
}
if (isset($_POST['bank_auto_check'])) {
    try {
        $bankRows = bm_bank_rows_from_upload($_FILES['bank_file'] ?? []);
        $st = $pdo->prepare("
            SELECT s.*, p.full_name
            FROM parent_submissions s
            JOIN players p ON p.id = s.primary_player_id
            WHERE s.tournament_id = ? AND s.payment_status = 'review'
        ");
        $st->execute([$id]);
        $result = bm_bank_auto_match($st->fetchAll(), $bankRows);
        $matchedIds = [];
        $matchedNames = [];
        foreach ($result['matched'] as $row) {
            $matchedIds[] = (int)$row['id'];
            $matchedNames[] = (string)($row['full_name'] ?? '');
        }
        $_SESSION['bm_bank_auto'][$id] = [
            'file' => (string)($_FILES['bank_file']['name'] ?? 'statement'),
            'bank_count' => count($bankRows),
            'matched_ids' => $matchedIds,
            'matched_names' => $matchedNames,
        ];
        $n = count($matchedIds);
        bm_flash(
            'success',
            $n > 0
                ? ($n . ' exact match(es) — UTR, amount and date. You may Accept all matched, or still do one by one.')
                : 'No exact UTR + amount + date matches. Check remaining entries by hand.'
        );
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
    }
    bm_redirect('tournament.php?id=' . $id);
}
if (isset($_POST['accept_bank_matched'])) {
    $ids = $_SESSION['bm_bank_auto'][$id]['matched_ids'] ?? [];
    $n = 0;
    foreach ($ids as $sid) {
        $sub = bm_parent_load_by_id($pdo, (int)$sid, $id);
        if ($sub && (string)($sub['payment_status'] ?? '') === 'review') {
            bm_parent_confirm_paid($pdo, $sub);
            $n++;
        }
    }
    unset($_SESSION['bm_bank_auto'][$id]);
    bm_flash('success', $n . ' matched ' . ($n === 1 ? 'entry' : 'entries') . ' accepted.');
    bm_redirect('tournament.php?id=' . $id);
}
if (isset($_POST['show_on_website_set'])) {
    $show = ((string)$_POST['show_on_website_set'] === '1') ? 1 : 0;
    $pdo->prepare('UPDATE tournaments SET show_on_website=?, updated_at=datetime(\'now\',\'localtime\') WHERE id=?')
        ->execute([$show, $id]);
    $tournament['show_on_website'] = $show;
    bm_flash('success', $show ? 'This tournament is now on the website list.' : 'This tournament is hidden from the website list. You can still send its own link.');
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

$bankAuto = $_SESSION['bm_bank_auto'][$id] ?? null;
$bankMatchIds = array_map('intval', is_array($bankAuto['matched_ids'] ?? null) ? $bankAuto['matched_ids'] : []);

$renderReviewRow = static function (array $ps, int $n) use ($bankMatchIds): void {
    $st = (string)($ps['payment_status'] ?? '');
    $pd = trim((string)($ps['payment_date'] ?? ''));
    $labs = [];
    foreach (explode(',', (string)$ps['event_codes']) as $code) {
        $code = trim($code);
        if ($code !== '') {
            $labs[] = bm_event_label($code);
        }
    }
    $amt = (int)($ps['payment_amount'] ?? 0);
    echo '<article class="review-row">';
    echo '<div class="review-grid">';
    echo '<div><span class="review-k">#</span><strong>' . $n . '</strong></div>';
    echo '<div><span class="review-k">Date</span><strong>' . bm_h($pd !== '' ? bm_fmt_date($pd) : '—') . '</strong></div>';
    echo '<div><span class="review-k">UTR</span><strong>' . bm_h((string)($ps['payment_utr'] ?? '')) . '</strong></div>';
    echo '<div><span class="review-k">Sender</span><strong>' . bm_h((string)($ps['payment_sender'] ?? '')) . '</strong></div>';
    echo '<div><span class="review-k">Amount</span><strong>' . ($amt > 0 ? '₹' . $amt : '—') . '</strong></div>';
    echo '<div><span class="review-k">Player</span><strong><a href="parent-edit.php?id=' . (int)$ps['id'] . '">' . bm_h((string)$ps['full_name']) . '</a></strong>';
    if (trim((string)($ps['father_name'] ?? '')) !== '') {
        echo '<div class="muted">' . bm_h((string)$ps['father_name']) . '</div>';
    }
    if (trim((string)($ps['contact_mobile'] ?? '')) !== '') {
        echo '<div class="muted">' . bm_h((string)$ps['contact_mobile']) . '</div>';
    }
    echo '</div>';
    echo '<div><span class="review-k">Age</span>' . bm_h((string)$ps['category_name']) . '</div>';
    echo '<div><span class="review-k">Event</span>' . bm_h(implode(', ', $labs)) . '</div>';
    echo '<div><span class="review-k">Status</span><span class="status-pill">' . bm_h(bm_parent_payment_label($st, $amt)) . '</span>';
    if (in_array((int)$ps['id'], $bankMatchIds, true)) {
        echo ' <span class="status-pill match-pill">UTR + amount + date match</span>';
    }
    echo '</div>';
    echo '</div><div class="review-actions">';
    echo '<form method="post" class="inline"><button type="submit" name="accept_paid" value="' . (int)$ps['id'] . '" class="btn btn-sm btn-primary" ' . bm_parent_confirm_attr('accept_paid') . '>Accept</button></form>';
    echo '<details class="drop"><summary class="btn btn-sm btn-danger">Decline</summary><div class="drop-menu">';
    foreach (bm_decline_reasons() as $code => $lab) {
        echo '<form method="post"><input type="hidden" name="decline_id" value="' . (int)$ps['id'] . '">';
        echo '<button type="submit" name="decline_reason" value="' . bm_h($code) . '" ' . bm_parent_confirm_attr($code) . '>' . bm_h($lab) . '</button></form>';
    }
    echo '</div></details>';
    echo '<form method="post" class="inline"><button type="submit" name="accept_pending" value="' . (int)$ps['id'] . '" class="btn btn-sm" ' . bm_parent_confirm_attr('accept_pending') . '>Accept without payment</button></form>';
    echo '</div></article>';
};

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$wrapClass = 'wrap-wide';
$pageTitle = $tournament['name'] . ' · Badminton';
require __DIR__ . '/includes/header.php';
$feeStats = bm_parent_tournament_stats($parentRows);
?>
<?php bm_admin_tournament_nav($id, 'tournament'); ?>
<section class="page-head">
  <div>
    <p class="eyebrow">Tournament</p>
    <h1><?= bm_h($tournament['name']) ?></h1>
    <p class="lede">Held at <strong><?= bm_h($tournament['held_at']) ?></strong> on <strong><?= bm_h($dateText) ?></strong>. Boys/girls events stay separate; <strong>Mix Double</strong> appears once per age group for boy + girl teams.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="tournament-entries.php?tournament_id=<?= $id ?>">Assign players / categories / events</a>
    <a class="btn" href="confirmed.php?id=<?= $id ?>">Confirmed entries</a>
    <a class="btn" href="declined.php?id=<?= $id ?>">Declined entries</a>
    <a class="btn" href="unpaid.php?id=<?= $id ?>">Not paid online</a>
    <a class="btn" href="parent-verify.php?tournament_id=<?= $id ?>">Verify / print / Excel</a>
    <a class="btn" href="report.php?tournament_id=<?= $id ?>">Report</a>
    <a class="btn" href="report.php?saved=1&amp;tournament_id=<?= $id ?>&amp;st_tournament=<?= $id ?>#saved-teams">Saved teams</a>
    <a class="btn" href="letter.php?id=<?= $id ?>">Share letter PDF</a>
    <a class="btn" href="letter.php?id=<?= $id ?>&amp;for=parents">Share parents &amp; coaches PDF</a>
    <a class="btn" href="letter-select.php?tournament_id=<?= $id ?>">Select teams &amp; export letter</a>
    <a class="btn" href="tournament-form.php?id=<?= $id ?>">Edit details</a>
  </div>
</section>
<?php bm_parent_render_fee_summary($feeStats); ?>

<section class="panel">
  <div class="panel-head"><h2>Website list and parent links</h2></div>
  <p class="lede">The website list link stays the same. Attach it on the PBA Tournament page. Only tournaments you <strong>show</strong> appear there. For a private or non-PBA event, keep it hidden and send this tournament’s own link.</p>
  <p class="hint" style="margin-top:0;">Website list (always the same)</p>
  <div class="share-link">
    <input id="website-list-link" type="text" readonly value="<?= bm_h(bm_public_list_url()) ?>">
    <button type="button" class="btn" id="copy-website-list">Copy list link</button>
  </div>
  <form method="post" class="form-actions" style="margin:0.85rem 0 0.4rem;">
    <?php if (bm_tournament_show_on_website($tournament)): ?>
      <p class="hint" style="margin:0 0.5rem 0 0;">Now <strong>showing</strong> on the website list (<?= bm_h(bm_tournament_year($tournament)) ?>).</p>
      <button type="submit" name="show_on_website_set" value="0" class="btn">Hide from website</button>
    <?php else: ?>
      <p class="hint" style="margin:0 0.5rem 0 0;">Now <strong>hidden</strong>. Parents will not see it on the website list.</p>
      <button type="submit" name="show_on_website_set" value="1" class="btn btn-primary">Show on website</button>
    <?php endif; ?>
    <a class="btn" href="open.php" target="_blank" rel="noopener">Open website list</a>
  </form>
  <p class="hint">This tournament only (WhatsApp / one event)</p>
  <div class="share-link">
    <input id="parent-link" type="text" readonly value="<?= bm_h($parentLink) ?>">
    <button type="button" class="btn btn-primary" id="copy-parent-link">Copy link</button>
  </div>
  <div class="bank-auto" style="margin-top:1rem;">
    <h3 style="margin:0 0 0.35rem;font-size:1.02rem;">Auto-check (optional)</h3>
    <p class="hint" style="margin-top:0;">Upload bank Excel or CSV. A match is only when <strong>UTR</strong>, <strong>amount</strong> and <strong>date</strong> are the same. UTR <strong>123456</strong> matches <strong>123456</strong> only — nothing before, after or between (not 123 456, 1234567, 012345, or 123456, 123456). You can still Accept or Decline one by one.</p>
    <form method="post" enctype="multipart/form-data" class="filters" style="margin:0.5rem 0 0;">
      <input type="file" name="bank_file" accept=".xlsx,.csv,.txt" required>
      <button type="submit" name="bank_auto_check" value="1" class="btn btn-primary">Auto-check</button>
    </form>
    <?php if (is_array($bankAuto)): ?>
      <p class="hint" style="margin-top:0.65rem;">
        File <?= bm_h((string)($bankAuto['file'] ?? '')) ?> · <?= (int)($bankAuto['bank_count'] ?? 0) ?> bank line(s) ·
        <strong><?= count($bankMatchIds) ?> exact match(es)</strong>
        <?php if ($bankAuto['matched_names'] ?? []): ?>
          — <?= bm_h(implode(', ', array_slice((array)$bankAuto['matched_names'], 0, 8))) ?><?= count((array)$bankAuto['matched_names']) > 8 ? '…' : '' ?>
        <?php endif; ?>
      </p>
      <form method="post" class="form-actions" style="margin-top:0.4rem;">
        <?php if ($bankMatchIds): ?>
        <button type="submit" name="accept_bank_matched" value="1" class="btn btn-primary" <?= bm_parent_confirm_attr('accept_bank_matched') ?>>Accept all matched</button>
        <?php endif; ?>
        <button type="submit" name="clear_bank_auto" value="1" class="btn">Clear auto-check</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($reviewByDate): ?>
  <p class="hint" style="margin-top:0.85rem;"><?= count($parentRows) ?> parent submission(s). Check date-wise against the bank statement. Decline opens four mismatch reasons. Accept and Decline ask you to confirm first.</p>
  <?php foreach ($reviewByDate as $dateKey => $list): ?>
  <details class="review-date" open>
    <summary class="review-date-head">
      <span>Payment date <?= $dateKey === '_none' ? 'not given' : bm_h(bm_fmt_date($dateKey)) ?></span>
      <span class="review-count"><?= count($list) ?></span>
    </summary>
    <div class="review-date-body">
    <?php foreach ($list as $i => $ps) { $renderReviewRow($ps, $i + 1); } ?>
    </div>
  </details>
  <?php endforeach; ?>
  <?php elseif ($parentRows): ?>
  <p class="hint" style="margin-top:0.85rem;">No entries waiting for a bank-statement check. Open <a href="confirmed.php?id=<?= $id ?>">Confirmed entries</a> for accepted players.</p>
  <?php endif; ?>
</section>
<script>
(function(){
  function bindCopy(btnId, inputId){
    var btn = document.getElementById(btnId);
    var inp = document.getElementById(inputId);
    if (!btn || !inp) return;
    btn.addEventListener('click', function(){
      var t = inp.value;
      function ok(){ var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = o; }, 1600); }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(ok).catch(function(){ inp.select(); document.execCommand('copy'); ok(); });
      } else { inp.select(); document.execCommand('copy'); ok(); }
    });
  }
  bindCopy('copy-parent-link', 'parent-link');
  bindCopy('copy-website-list', 'website-list-link');
  document.querySelectorAll('.drop').forEach(function(d){
    d.addEventListener('toggle', function(){
      if (!d.open) return;
      document.querySelectorAll('.drop').forEach(function(o){ if (o !== d) o.open = false; });
    });
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
