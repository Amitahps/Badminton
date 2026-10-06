<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $st = $pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE age_category_id=?');
    $st->execute([$id]);
    $used = (int)$st->fetchColumn();
    $st2 = $pdo->prepare('SELECT COUNT(*) FROM tournament_teams WHERE age_category_id=?');
    $st2->execute([$id]);
    $used += (int)$st2->fetchColumn();
    if ($used > 0) {
        bm_flash('error', 'Cannot remove category while tournament entries or teams use it.');
    } else {
        $pdo->prepare('DELETE FROM age_categories WHERE id = ?')->execute([$id]);
        bm_flash('success', 'Age category removed.');
    }
    bm_redirect('categories.php');
}

$rows = $pdo->query("
    SELECT c.*,
      (SELECT COUNT(DISTINCT e.player_id) FROM tournament_entries e WHERE e.age_category_id = c.id) AS entry_count
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
    <p class="lede">Create categories such as Under 14 Boys / Under 14 Girls. Set the same age group on both so Mix Double can pair boy + girl.</p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="category-form.php">Create category</a></div>
</section>
<section class="panel">
  <?php if ($rows): ?>
  <table class="table">
    <thead><tr><th>Order</th><th>Category</th><th>For</th><th>Age group</th><th>Tournament entries</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['sort_order'] ?></td>
        <td><strong><?= bm_h($r['name']) ?></strong></td>
        <td><?= bm_h(bm_gender_scope_label((string)($r['gender_scope'] ?? 'open'))) ?></td>
        <td><?= bm_h($r['age_group'] ?: '—') ?></td>
        <td><?= (int)$r['entry_count'] ?></td>
        <td class="right actions">
          <a class="btn btn-sm" href="category-form.php?id=<?= (int)$r['id'] ?>">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Remove this category?');">
            <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger" <?= (int)$r['entry_count'] ? 'disabled title="In use"' : '' ?>>Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No age category yet. Create Under 14 Boys, Under 14 Girls, etc.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
