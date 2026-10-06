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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $catId = (int)($_POST['age_category_id'] ?? 0);
    $event = trim((string)($_POST['event_code'] ?? ''));
    if ($catId <= 0) {
        bm_flash('error', 'Select an age category.');
    } elseif (!isset($events[$event])) {
        bm_flash('error', 'Select an event.');
    } else {
        // Validate gender vs event where needed
        $def = $events[$event];
        $g = $player['gender'];
        if ($event === 'double_men' && $g !== 'boy') {
            bm_flash('error', 'Double Men is for boys only.');
        } elseif ($event === 'double_girls' && $g !== 'girl') {
            bm_flash('error', 'Double Girls is for girls only.');
        } else {
            $pdo->prepare("UPDATE players SET age_category_id=?, event_code=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$catId, $event, $id]);
            bm_flash('success', 'Age category and event saved. Player will appear in the list.');
            bm_redirect('players.php');
        }
    }
}

$pageTitle = 'Assign Category & Event · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">After create</p>
    <h1>Select age category &amp; event</h1>
    <p class="lede">Player: <strong><?= bm_h($player['full_name']) ?></strong> (<?= bm_h(bm_gender_label($player['gender'])) ?>). After save, this name will show in the players list.</p>
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
    <label>Event
      <select name="event_code" required>
        <option value="">Select event</option>
        <?php foreach ($events as $code => $def): ?>
          <option value="<?= bm_h($code) ?>" <?= ($player['event_code'] ?? '') === $code ? 'selected' : '' ?>><?= bm_h($def['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save &amp; show in list</button>
      <a class="btn" href="players.php">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
