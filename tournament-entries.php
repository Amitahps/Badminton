<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$tournamentId = (int)($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? 0);
$editPlayerId = (int)($_GET['player_id'] ?? 0);

$tstmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$tstmt->execute([$tournamentId]);
$tournament = $tstmt->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('tournaments.php');
}

$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();
$events = bm_event_defs();
$allPlayers = $pdo->query('SELECT * FROM players ORDER BY full_name')->fetchAll();

if (isset($_POST['clear_player_id'])) {
    $pid = (int)$_POST['clear_player_id'];
    bm_clear_tournament_player_entries($pdo, $tournamentId, $pid);
    bm_flash('success', 'Player removed from this tournament’s category/event list.');
    bm_redirect('tournament-entries.php?tournament_id=' . $tournamentId);
}

if (isset($_POST['save_entry'])) {
    $pid = (int)($_POST['player_id'] ?? 0);
    $catIds = $_POST['category_ids'] ?? [];
    $rawEvents = $_POST['events'] ?? [];
    if (!is_array($catIds)) {
        $catIds = [];
    }
    if (!is_array($rawEvents)) {
        $rawEvents = [];
    }
    $catIds = array_values(array_unique(array_map('intval', $catIds)));

    $pst = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $pst->execute([$pid]);
    $player = $pst->fetch();

    if (!$player) {
        bm_flash('error', 'Select a player.');
    } elseif (!$categories) {
        bm_flash('error', 'Create age categories first.');
    } elseif (!$catIds) {
        bm_flash('error', 'Select at least one age category for this tournament.');
    } else {
        $catById = [];
        $validCat = [];
        foreach ($categories as $c) {
            $cid = (int)$c['id'];
            $catById[$cid] = $c;
            $cscope = bm_category_gender_scope($c);
            if ($player['gender'] === 'boy' && $cscope === 'girls') {
                continue;
            }
            if ($player['gender'] === 'girl' && $cscope === 'boys') {
                continue;
            }
            $validCat[$cid] = true;
        }
        $eventsByCategory = [];
        $ok = true;
        $g = $player['gender'];
        foreach ($catIds as $cid) {
            if (empty($validCat[$cid])) {
                bm_flash('error', 'Age category does not match this player’s gender.');
                $ok = false;
                break;
            }
            $codes = $rawEvents[$cid] ?? $rawEvents[(string)$cid] ?? [];
            if (!is_array($codes)) {
                $codes = [];
            }
            $codes = array_values(array_unique(array_filter(array_map('strval', $codes), static fn($x) => $x !== '')));
            if (!$codes) {
                $cname = (string)($catById[$cid]['name'] ?? 'this category');
                bm_flash('error', 'Select Single, Doubles or Mix Double for ' . $cname . '.');
                $ok = false;
                break;
            }
            $allowed = bm_events_for_gender_scope(bm_category_gender_scope($catById[$cid]));
            foreach ($codes as $code) {
                if (!isset($events[$code]) || !isset($allowed[$code])) {
                    bm_flash('error', 'Invalid event for ' . ($catById[$cid]['name'] ?? 'this category') . '.');
                    $ok = false;
                    break 2;
                }
                if ($code === 'double_men' && $g !== 'boy') {
                    bm_flash('error', 'Double Boys is for boys only.');
                    $ok = false;
                    break 2;
                }
                if ($code === 'double_girls' && $g !== 'girl') {
                    bm_flash('error', 'Double Girls is for girls only.');
                    $ok = false;
                    break 2;
                }
            }
            $eventsByCategory[$cid] = $codes;
        }
        if ($ok) {
            bm_set_tournament_player_entries($pdo, $tournamentId, $pid, $eventsByCategory);
            $tickCount = 0;
            foreach ($eventsByCategory as $codes) {
                $tickCount += count($codes);
            }
            bm_flash('success', $player['full_name'] . ' saved for this tournament (' . count($eventsByCategory) . ' categor' . (count($eventsByCategory) === 1 ? 'y' : 'ies') . ', ' . $tickCount . ' event' . ($tickCount === 1 ? '' : 's') . ').');
            bm_redirect('tournament-entries.php?tournament_id=' . $tournamentId);
        }
    }
    $editPlayerId = $pid;
}

$entryRows = $pdo->prepare("
    SELECT e.player_id, e.age_category_id, e.event_code,
           p.full_name, p.gender, c.name AS category_name, c.sort_order
    FROM tournament_entries e
    JOIN players p ON p.id = e.player_id
    JOIN age_categories c ON c.id = e.age_category_id
    WHERE e.tournament_id = ?
    ORDER BY p.full_name, c.sort_order, c.name, e.event_code
");
$entryRows->execute([$tournamentId]);
$byPlayer = [];
$savedMaps = [];
foreach ($entryRows->fetchAll() as $r) {
    $pid = (int)$r['player_id'];
    $cid = (int)$r['age_category_id'];
    if (!isset($byPlayer[$pid])) {
        $byPlayer[$pid] = [
            'player_id' => $pid,
            'full_name' => $r['full_name'],
            'gender' => $r['gender'],
            'by_cat' => [],
        ];
        $savedMaps[$pid] = [];
    }
    if (!isset($byPlayer[$pid]['by_cat'][$cid])) {
        $byPlayer[$pid]['by_cat'][$cid] = [
            'name' => $r['category_name'],
            'events' => [],
        ];
    }
    $byPlayer[$pid]['by_cat'][$cid]['events'][] = bm_event_label($r['event_code']);
    $savedMaps[$pid][$cid][] = (string)$r['event_code'];
}

$selectedMap = [];
$formPlayerId = $editPlayerId;
$formPlayer = null;
if ($editPlayerId > 0) {
    $selectedMap = bm_tournament_entry_map($pdo, $tournamentId, $editPlayerId);
    foreach ($allPlayers as $p) {
        if ((int)$p['id'] === $editPlayerId) {
            $formPlayer = $p;
            break;
        }
    }
}

$playerJs = [];
foreach ($allPlayers as $p) {
    $pid = (int)$p['id'];
    $dob = trim((string)($p['dob'] ?? ''));
    $playerJs[$pid] = [
        'gender' => bm_gender_label((string)($p['gender'] ?? '')),
        'gender_code' => strtolower(trim((string)($p['gender'] ?? ''))),
        'bai' => trim((string)($p['bai_id'] ?? '')) !== '' ? (string)$p['bai_id'] : '—',
        'pba' => trim((string)($p['pbi_id'] ?? '')) !== '' ? (string)$p['pbi_id'] : '—',
        'dob' => $dob !== '' ? bm_fmt_date($dob) : '—',
        'entries' => (object)($savedMaps[$pid] ?? []),
    ];
}

$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$pageTitle = 'Tournament entries · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?> · <?= bm_h($tournament['held_at']) ?> · <?= bm_h($dateText) ?></p>
    <h1>Assign players for this tournament</h1>
    <p class="lede">Select a player, then tick an age category — Single, Doubles and Mix Double appear for that category. Tick the next category the same way. Save once at the bottom when all selections are done.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="tournament.php?id=<?= $tournamentId ?>">Form teams next</a>
    <a class="btn" href="tournament.php?id=<?= $tournamentId ?>">Back</a>
  </div>
</section>

<?php if (!$categories): ?>
<section class="panel">
  <p class="empty">Create age categories first. <a href="category-form.php">Create category</a></p>
</section>
<?php elseif (!$allPlayers): ?>
<section class="panel">
  <p class="empty">Add players first (name list is one-time). <a href="player-form.php">Add player</a></p>
</section>
<?php else: ?>
<section class="panel">
  <form method="post" class="form" id="entry-form">
    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
    <label>Player
      <select name="player_id" id="player_id" required>
        <option value="">Select player</option>
        <?php foreach ($allPlayers as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $formPlayerId === (int)$p['id'] ? 'selected' : '' ?>>
            <?= bm_h($p['full_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <div id="player-meta" class="player-meta<?= $formPlayer ? '' : ' is-hidden' ?>">
      <div><dt>Gender</dt><dd id="pm-gender"><?= $formPlayer ? bm_h(bm_gender_label((string)$formPlayer['gender'])) : '—' ?></dd></div>
      <div><dt>PBA ID</dt><dd id="pm-pba"><?= $formPlayer && trim((string)($formPlayer['pbi_id'] ?? '')) !== '' ? bm_h((string)$formPlayer['pbi_id']) : '—' ?></dd></div>
      <div><dt>BAI ID</dt><dd id="pm-bai"><?= $formPlayer && trim((string)($formPlayer['bai_id'] ?? '')) !== '' ? bm_h((string)$formPlayer['bai_id']) : '—' ?></dd></div>
      <div><dt>Date of birth</dt><dd id="pm-dob"><?= $formPlayer && trim((string)($formPlayer['dob'] ?? '')) !== '' ? bm_h(bm_fmt_date((string)$formPlayer['dob'])) : '—' ?></dd></div>
    </div>

    <div id="cat-list" class="cat-list">
      <p class="hint" id="cat-hint"<?= $formPlayer ? ' hidden' : '' ?>>Select a player first. Age categories matching that gender will appear; tick a category to choose its events.</p>
      <?php foreach ($categories as $c):
          $cid = (int)$c['id'];
          $sc = bm_category_gender_scope($c);
          $allowed = bm_events_for_gender_scope($sc);
          $open = isset($selectedMap[$cid]);
          $fg = strtolower(trim((string)($formPlayer['gender'] ?? '')));
          $hideCat = $fg === '' || ($fg === 'boy' && $sc === 'girls') || ($fg === 'girl' && $sc === 'boys');
      ?>
        <div class="cat-block cat-opt<?= $open ? ' is-open' : '' ?>" data-scope="<?= bm_h($sc) ?>" data-id="<?= $cid ?>"<?= $hideCat ? ' style="display:none"' : '' ?>>
          <div class="cat-row">
            <input type="checkbox" class="cat-pick" id="cat-<?= $cid ?>" name="category_ids[]" value="<?= $cid ?>" <?= $open ? 'checked' : '' ?>>
            <label class="cat-name" for="cat-<?= $cid ?>"><?= bm_h($c['name']) ?> <span class="muted">(<?= bm_h(bm_gender_scope_label($sc)) ?>)</span></label>
          </div>
          <div class="cat-events">
            <?php foreach ($allowed as $code => $def): ?>
              <div class="event-row">
                <input type="checkbox" class="event-pick" id="ev-<?= $cid ?>-<?= bm_h($code) ?>" name="events[<?= $cid ?>][]" value="<?= bm_h($code) ?>"
                  <?= in_array($code, $selectedMap[$cid] ?? [], true) ? 'checked' : '' ?>
                  <?= $open ? '' : 'disabled' ?>>
                <label for="ev-<?= $cid ?>-<?= bm_h($code) ?>"><?= bm_h($def['label']) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="form-actions">
      <button type="submit" name="save_entry" value="1" class="btn btn-primary">Save for this tournament</button>
      <a class="btn" href="tournament-entries.php?tournament_id=<?= $tournamentId ?>">Clear form</a>
    </div>
  </form>
</section>
<script>
(function(){
  var sel = document.getElementById('player_id');
  var meta = document.getElementById('player-meta');
  var hint = document.getElementById('cat-hint');
  var players = <?= json_encode($playerJs, JSON_UNESCAPED_UNICODE) ?>;
  function dash(v){ return v ? String(v) : '—'; }
  function setText(id, v){
    var el = document.getElementById(id);
    if (el) el.textContent = dash(v);
  }
  function player(){
    var id = sel.value ? String(parseInt(sel.value, 10)) : '';
    return id && players[id] ? players[id] : null;
  }
  function syncPlayer(){
    var p = player();
    if (!p) {
      meta.classList.add('is-hidden');
      if (hint) hint.hidden = false;
      document.querySelectorAll('.cat-opt').forEach(function(block){
        block.style.display = 'none';
        var cat = block.querySelector('.cat-pick');
        cat.checked = false;
        cat.disabled = true;
        block.classList.remove('is-open');
        block.querySelectorAll('.event-pick').forEach(function(ev){
          ev.checked = false;
          ev.disabled = true;
        });
      });
      return;
    }
    meta.classList.remove('is-hidden');
    if (hint) hint.hidden = true;
    setText('pm-gender', p.gender);
    setText('pm-pba', p.pba);
    setText('pm-bai', p.bai);
    setText('pm-dob', p.dob);
    var g = String(p.gender_code || '').toLowerCase();
    var saved = p.entries || {};
    document.querySelectorAll('.cat-opt').forEach(function(block){
      var scope = String(block.getAttribute('data-scope') || 'open').toLowerCase();
      var hide = (g === 'boy' && scope === 'girls') || (g === 'girl' && scope === 'boys');
      var cat = block.querySelector('.cat-pick');
      var cid = String(block.getAttribute('data-id') || '');
      var had = saved[cid] || saved[String(parseInt(cid, 10))] || [];
      block.style.display = hide ? 'none' : 'block';
      cat.disabled = hide;
      if (hide) {
        cat.checked = false;
        block.classList.remove('is-open');
        block.querySelectorAll('.event-pick').forEach(function(ev){
          ev.checked = false;
          ev.disabled = true;
        });
        return;
      }
      var open = Array.isArray(had) && had.length > 0;
      cat.checked = open;
      block.classList.toggle('is-open', open);
      block.querySelectorAll('.event-pick').forEach(function(ev){
        ev.disabled = !open;
        ev.checked = open && had.indexOf(ev.value) !== -1;
      });
    });
  }
  document.querySelectorAll('.cat-pick').forEach(function(cat){
    cat.addEventListener('change', function(){
      var block = cat.closest('.cat-block');
      var open = cat.checked;
      block.classList.toggle('is-open', open);
      block.querySelectorAll('.event-pick').forEach(function(ev){
        ev.disabled = !open;
        if (!open) ev.checked = false;
      });
    });
  });
  sel.addEventListener('change', syncPlayer);
  syncPlayer();
})();
</script>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2>Saved for this tournament (<?= count($byPlayer) ?> players)</h2></div>
  <?php if ($byPlayer): ?>
  <table class="table">
    <thead>
      <tr><th>Player</th><th>Gender</th><th>Categories and events</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($byPlayer as $row): ?>
      <tr>
        <td><strong><?= bm_h($row['full_name']) ?></strong></td>
        <td><?= bm_h(bm_gender_label($row['gender'])) ?></td>
        <td>
          <?php foreach ($row['by_cat'] as $cat): ?>
            <div><?= bm_h($cat['name']) ?> — <?= bm_h(implode(', ', $cat['events'])) ?></div>
          <?php endforeach; ?>
        </td>
        <td class="right actions">
          <a class="btn btn-sm" href="tournament-entries.php?tournament_id=<?= $tournamentId ?>&player_id=<?= (int)$row['player_id'] ?>">Change</a>
          <form method="post" class="inline" onsubmit="return confirm('Remove this player from this tournament only?');">
            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
            <input type="hidden" name="clear_player_id" value="<?= (int)$row['player_id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No players assigned to categories/events in this tournament yet.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
