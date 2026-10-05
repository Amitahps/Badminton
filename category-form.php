<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM age_categories WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        bm_flash('error', 'Category not found.');
        bm_redirect('categories.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($name === '') {
        bm_flash('error', 'Category name is required.');
    } else {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE age_categories SET name = ?, sort_order = ?, notes = ? WHERE id = ?')
                    ->execute([$name, $sort, $notes !== '' ? $notes : null, $id]);
                bm_flash('success', 'Age category updated.');
            } else {
                $pdo->prepare('INSERT INTO age_categories (name, sort_order, notes) VALUES (?, ?, ?)')
                    ->execute([$name, $sort, $notes !== '' ? $notes : null]);
                bm_flash('success', 'Age category created.');
            }
            bm_redirect('categories.php');
        } catch (Throwable $e) {
            bm_flash('error', 'A category with this name already exists.');
        }
    }
}

$pageTitle = ($id ? 'Edit' : 'Create') . ' Age Category · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head"><div><p class="eyebrow">Age category</p><h1><?= $id ? 'Edit age category' : 'Create age category' ?></h1></div></section>
<section class="panel narrow">
  <form method="post" class="form">
    <label>Category name
      <input type="text" name="name" required maxlength="120" value="<?= bm_h($row['name'] ?? '') ?>" placeholder="e.g. Under-15 Boys">
    </label>
    <label>Sort order
      <input type="number" name="sort_order" value="<?= bm_h((string)($row['sort_order'] ?? '0')) ?>">
    </label>
    <label>Notes
      <textarea name="notes" rows="3"><?= bm_h($row['notes'] ?? '') ?></textarea>
    </label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save</button>
      <a class="btn" href="categories.php">Cancel</a>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
