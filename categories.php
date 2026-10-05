<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $cstmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE age_category_id = ?');
    $cstmt->execute([$id]);
    $used = (int)$cstmt->fetchColumn();
    if ($used > 0) {
        bm_flash('error', 'Cannot remove category while players are linked to it.');
    } else {
        $pdo->prepare('DELETE FROM age_categories WHERE id = ?')->execute([$id]);
        bm_flash('success', 'Age category removed.');
    }
    bm_redirect('categories.php');
}

$rows = $pdo->query("
    SELECT c.*,
      (SELECT COUNT(*) FROM players p WHERE p.age_category_id = c.id) AS player_count
    FROM age_categories c
    ORDER BY c.sort_order ASC, c.name ASC
")->fetchAll();

$pageTitle = 'Age Categories · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Setup</p>
    <h1>Age categories</h1>
    <p class="lede">Create categories such as U-13, U-15, U-17, U-19, Senior. Players are linked to one category.</p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="category-form.php">Create category</a></div>
</section>
<section class="panel">
  <?php if ($rows): ?>
  <table class="table">
    <thead><tr><th>Order</th><th>Category</th><th>Players</th><th>Notes</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['sort_order'] ?></td>
        <td><strong><?= bm_h($r['name']) ?></strong></td>
        <td><?= (int)$r['player_count'] ?></td>
        <td><?= bm_h($r['notes'] ?: '—') ?></td>
        <td class="right actions">
          <a class="btn btn-sm" href="category-form.php?id=<?= (int)$r['id'] ?>">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Remove this category?');">
            <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger" <?= (int)$r['player_count'] ? 'disabled title="Remove players first"' : '' ?>>Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No age category yet. Create U-13, U-15, etc. before adding players.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
