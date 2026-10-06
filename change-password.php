<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['bm_user_id']]);
    $hash = (string)$stmt->fetchColumn();
    if (!$hash || !password_verify($current, $hash)) {
        bm_flash('error', 'Current password is incorrect.');
    } elseif (strlen($new) < 6) {
        bm_flash('error', 'New password must be at least 6 characters.');
    } elseif ($new !== $confirm) {
        bm_flash('error', 'New password and confirm password do not match.');
    } else {
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['bm_user_id']]);
        bm_flash('success', 'Password changed successfully. Use the new password next login (and when deleting a tournament).');
        bm_redirect('index.php');
    }
}

$pageTitle = 'Change Password · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Security</p>
    <h1>Change password</h1>
    <p class="lede">Update your admin login password. The same password is required to remove a tournament (to avoid accidental delete).</p>
  </div>
</section>
<section class="panel narrow">
  <form method="post" class="form">
    <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>New password (min 6 characters)<input type="password" name="new_password" minlength="6" required autocomplete="new-password"></label>
    <label>Confirm new password<input type="password" name="confirm_password" minlength="6" required autocomplete="new-password"></label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Update password</button>
      <a class="btn" href="index.php">Cancel</a>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
