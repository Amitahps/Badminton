<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        bm_flash('error', 'Tournament not found.');
        bm_redirect('tournaments.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $heldAt = trim((string)($_POST['held_at'] ?? ''));
    $from = trim((string)($_POST['date_from'] ?? ''));
    $to = trim((string)($_POST['date_to'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($name === '' || $heldAt === '' || $from === '' || $to === '') {
        bm_flash('error', 'Tournament name, held at, and both dates are required.');
    } elseif ($to < $from) {
        bm_flash('error', 'To date cannot be before From date.');
    } else {
        if ($id > 0) {
            $pdo->prepare("UPDATE tournaments SET name=?, held_at=?, date_from=?, date_to=?, notes=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$name, $heldAt, $from, $to, $notes !== '' ? $notes : null, $id]);
            bm_flash('success', 'Tournament updated.');
            bm_redirect('tournament.php?id=' . $id);
        } else {
            $pdo->prepare('INSERT INTO tournaments (name, held_at, date_from, date_to, notes) VALUES (?,?,?,?,?)')
                ->execute([$name, $heldAt, $from, $to, $notes !== '' ? $notes : null]);
            $newId = (int)$pdo->lastInsertId();
            bm_flash('success', 'Tournament created. Open a category and tick participating players.');
            bm_redirect('tournament.php?id=' . $newId);
        }
    }
}

$pageTitle = ($id ? 'Edit' : 'Create') . ' Tournament · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head"><div><p class="eyebrow">Tournament</p><h1><?= $id ? 'Edit tournament' : 'Create tournament' ?></h1></div></section>
<section class="panel narrow">
  <form method="post" class="form">
    <label>Tournament name
      <input type="text" name="name" required maxlength="200" value="<?= bm_h($row['name'] ?? '') ?>" placeholder="e.g. District Ranking Tournament">
    </label>
    <label>Held at
      <input type="text" name="held_at" required maxlength="200" value="<?= bm_h($row['held_at'] ?? '') ?>" placeholder="Venue / city">
    </label>
    <div class="two-col">
      <label>From date<input type="date" name="date_from" required value="<?= bm_h($row['date_from'] ?? '') ?>"></label>
      <label>To date<input type="date" name="date_to" required value="<?= bm_h($row['date_to'] ?? '') ?>"></label>
    </div>
    <label>Notes<textarea name="notes" rows="3"><?= bm_h($row['notes'] ?? '') ?></textarea></label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save tournament</button>
      <a class="btn" href="tournaments.php">Cancel</a>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
