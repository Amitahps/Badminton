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
    try {
        $name = trim((string)($_POST['name'] ?? ''));
        $heldAt = trim((string)($_POST['held_at'] ?? ''));
        $from = bm_parse_date_input((string)($_POST['date_from'] ?? ''));
        $to = bm_parse_date_input((string)($_POST['date_to'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($name === '' || $heldAt === '' || !$from || !$to) {
            throw new RuntimeException('Tournament name, held at, and both dates are required (dd-mm-yyyy).');
        }
        if ($to < $from) {
            throw new RuntimeException('To date cannot be before From date.');
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE tournaments SET name=?, held_at=?, date_from=?, date_to=?, notes=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$name, $heldAt, $from, $to, $notes !== '' ? $notes : null, $id]);
            bm_flash('success', 'Tournament updated.');
            bm_redirect('tournament.php?id=' . $id);
        } else {
            $pdo->prepare('INSERT INTO tournaments (name, held_at, date_from, date_to, notes) VALUES (?,?,?,?,?)')
                ->execute([$name, $heldAt, $from, $to, $notes !== '' ? $notes : null]);
            $newId = (int)$pdo->lastInsertId();
            bm_flash('success', 'Tournament created. Assign players, then form teams.');
            bm_redirect('tournament.php?id=' . $newId);
        }
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
    }
}

$fromDisplay = !empty($row['date_from']) ? bm_fmt_date((string)$row['date_from']) : trim((string)($_POST['date_from'] ?? ''));
$toDisplay = !empty($row['date_to']) ? bm_fmt_date((string)$row['date_to']) : trim((string)($_POST['date_to'] ?? ''));
if ($fromDisplay === '—') {
    $fromDisplay = '';
}
if ($toDisplay === '—') {
    $toDisplay = '';
}

$pageTitle = ($id ? 'Edit' : 'Create') . ' Tournament · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head"><div><p class="eyebrow">Tournament</p><h1><?= $id ? 'Edit tournament' : 'Create tournament' ?></h1></div></section>
<section class="panel narrow">
  <form method="post" class="form">
    <label>Tournament name
      <input type="text" name="name" required maxlength="200" value="<?= bm_h($row['name'] ?? ($_POST['name'] ?? '')) ?>" placeholder="e.g. District Ranking Tournament">
    </label>
    <label>Held at
      <input type="text" name="held_at" required maxlength="200" value="<?= bm_h($row['held_at'] ?? ($_POST['held_at'] ?? '')) ?>" placeholder="Venue / city">
    </label>
    <div class="two-col">
      <label>From date (dd-mm-yyyy)
        <input type="text" name="date_from" required maxlength="10" placeholder="dd-mm-yyyy" value="<?= bm_h($fromDisplay) ?>">
      </label>
      <label>To date (dd-mm-yyyy)
        <input type="text" name="date_to" required maxlength="10" placeholder="dd-mm-yyyy" value="<?= bm_h($toDisplay) ?>">
      </label>
    </div>
    <label>Notes<textarea name="notes" rows="3"><?= bm_h($row['notes'] ?? ($_POST['notes'] ?? '')) ?></textarea></label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save tournament</button>
      <a class="btn" href="tournaments.php">Cancel</a>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
