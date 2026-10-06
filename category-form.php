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
    $scope = trim((string)($_POST['gender_scope'] ?? 'open'));
    if (!in_array($scope, ['boys', 'girls', 'open'], true)) {
        $scope = 'open';
    }
    $ageGroup = trim((string)($_POST['age_group'] ?? ''));
    if ($ageGroup === '') {
        $ageGroup = bm_infer_category_meta($name)['age_group'];
    }
    if ($name === '') {
        bm_flash('error', 'Category name is required.');
    } else {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE age_categories SET name=?, sort_order=?, notes=?, gender_scope=?, age_group=? WHERE id=?')
                    ->execute([$name, $sort, $notes !== '' ? $notes : null, $scope, $ageGroup !== '' ? $ageGroup : null, $id]);
                bm_flash('success', 'Age category updated.');
            } else {
                $pdo->prepare('INSERT INTO age_categories (name, sort_order, notes, gender_scope, age_group) VALUES (?,?,?,?,?)')
                    ->execute([$name, $sort, $notes !== '' ? $notes : null, $scope, $ageGroup !== '' ? $ageGroup : null]);
                bm_flash('success', 'Age category created.');
            }
            bm_redirect('categories.php');
        } catch (Throwable $e) {
            bm_flash('error', 'A category with this name already exists.');
        }
    }
}

$scope = $row['gender_scope'] ?? 'boys';
$ageGroup = $row['age_group'] ?? '';
$pageTitle = ($id ? 'Edit' : 'Create') . ' Age Category · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Age category</p>
    <h1><?= $id ? 'Edit age category' : 'Create age category' ?></h1>
    <p class="lede">Boys categories show boys events only; girls categories show girls events. Mix Double can include both when age group matches (e.g. Under 14).</p>
  </div>
</section>
<section class="panel narrow">
  <form method="post" class="form">
    <label>Category name
      <input type="text" name="name" required maxlength="120" value="<?= bm_h($row['name'] ?? '') ?>" placeholder="e.g. Under 14 Boys">
    </label>
    <label>For (gender)
      <select name="gender_scope" required>
        <option value="boys" <?= $scope === 'boys' ? 'selected' : '' ?>>Boys — Single, Double Men, Mix Double</option>
        <option value="girls" <?= $scope === 'girls' ? 'selected' : '' ?>>Girls — Single, Double Girls, Mix Double</option>
        <option value="open" <?= $scope === 'open' ? 'selected' : '' ?>>Open — all events</option>
      </select>
    </label>
    <label>Age group (for Mix Double pairing)
      <input type="text" name="age_group" maxlength="80" value="<?= bm_h((string)$ageGroup) ?>" placeholder="e.g. Under 14">
      <span class="hint">Use the same age group on Under 14 Boys and Under 14 Girls so Mix Double shows both.</span>
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
