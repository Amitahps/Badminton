<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
$stmt->execute([$id]);
$player = $stmt->fetch();
if (!$player) {
    bm_flash('error', 'Player not found.');
    bm_redirect('players.php');
}

$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();
$events = bm_event_defs();
$selectedEvents = bm_player_event_codes($pdo, $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $catId = (int)($_POST['age_category_id'] ?? 0);
    $picked = $_POST['event_codes'] ?? [];
    if (!is_array($picked)) {
        $picked = [];
    }
    $picked = array_values(array_unique(array_map('strval', $picked)));

    if ($catId <= 0) {
        bm_flash('error', 'Select an age category.');
    } elseif (!$picked) {
        bm_flash('error', 'Select at least one event. A player can join multiple events.');
    } else {
        $g = $player['gender'];
        $ok = true;
        foreach ($picked as $event) {
            if (!isset($events[$event])) {
                bm_flash('error', 'Invalid event selected.');
                $ok = false;
                break;
            }
            if ($event === 'double_men' && $g !== 'boy') {
                bm_flash('error', 'Double Men is for boys only.');
                $ok = false;
                break;
            }
            if ($event === 'double_girls' && $g !== 'girl') {
                bm_flash('error', 'Double Girls is for girls only.');
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $pdo->prepare("UPDATE players SET age_category_id=?, event_code=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$catId, $picked[0], $id]); // keep first as legacy column
            bm_set_player_events($pdo, $id, $picked);
            bm_flash('success', 'Age category and event(s) saved. Player can play in multiple events in a tournament.');
            bm_redirect('players.php');
        }
    }
    $selectedEvents = $picked;
}

$pageTitle = 'Assign Category & Events · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">After create</p>
    <h1>Select age category &amp; events</h1>
    <p class="lede">Player: <strong><?= bm_h($player['full_name']) ?></strong> (<?= bm_h(bm_gender_label($player['gender'])) ?>). Tick <strong>one or more events</strong>. The same player can participate in multiple events in a tournament.</p>
  </div>
</section>

<?php if (!$categories): ?>
<section class="panel">
  <p class="empty">Create an age category first. <a href="category-form.php">Create category</a></p>
</section>
<?php else: ?>
<section class="panel narrow">
  <form method="post" class="form">
    <label>Age category
      <select name="age_category_id" required>
        <option value="">Select category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)($player['age_category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <fieldset style="border:1px solid var(--line);border-radius:10px;padding:0.75rem 1rem;">
      <legend style="font-weight:600;padding:0 0.35rem;">Events (select multiple)</legend>
      <?php foreach ($events as $code => $def): ?>
        <?php
          $disabled = ($code === 'double_men' && $player['gender'] !== 'boy')
            || ($code === 'double_girls' && $player['gender'] !== 'girl');
        ?>
        <label class="check-inline" style="display:flex;margin:0.45rem 0;font-weight:500;">
          <input type="checkbox" name="event_codes[]" value="<?= bm_h($code) ?>"
            <?= in_array($code, $selectedEvents, true) ? 'checked' : '' ?>
            <?= $disabled ? 'disabled' : '' ?>>
          <?= bm_h($def['label']) ?>
          <?php if ($disabled): ?><span class="muted"> (not for this gender)</span><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </fieldset>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save &amp; show in list</button>
      <a class="btn" href="players.php">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
