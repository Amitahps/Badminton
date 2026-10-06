<?php
declare(strict_types=1);

try {
    require_once __DIR__ . '/helpers.php';
    bm_boot_session();
    $pdo = bm_db();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Badminton setup error:\n" . $e->getMessage();
    exit;
}

if (bm_is_logged_in()) {
    bm_redirect('index.php');
}

$error = '';
$newKeyShown = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim((string)($_POST['username'] ?? ''));
        $key = trim((string)($_POST['recovery_key'] ?? ''));
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($new !== $confirm) {
            throw new RuntimeException('New password and confirm password do not match.');
        }
        $newKeyShown = bm_recover_password($pdo, $username, $key, $new);
        bm_flash('success', 'Password reset successfully. Sign in with your new password. A new recovery key was created — save it now.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Recover password · Badminton';
$wrapClass = 'wrap-login';
require __DIR__ . '/includes/header.php';
?>
<section class="login-panel">
  <div class="login-visual" aria-hidden="true"><div class="court-lines"></div></div>
  <div class="login-card">
    <p class="eyebrow">Account recovery</p>
    <h1>Reset password</h1>
    <p class="lede">Enter your username and recovery key to set a new password.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= bm_h($error) ?></div><?php endif; ?>
    <?php if ($newKeyShown !== ''): ?>
      <div class="flash flash-success">
        Password updated. <strong>Save your new recovery key</strong> (the old one no longer works):
        <p style="margin:0.6rem 0 0;font-size:1.1rem;letter-spacing:0.04em;"><code><?= bm_h($newKeyShown) ?></code></p>
      </div>
      <p class="hint"><a href="login.php">Go to sign in</a></p>
    <?php else: ?>
    <form method="post" class="form">
      <label>Username
        <input type="text" name="username" required value="<?= bm_h($_POST['username'] ?? 'admin') ?>" autocomplete="username">
      </label>
      <label>Recovery key
        <input type="text" name="recovery_key" required placeholder="BM-XXXX-XXXX-XXXX-XXXX" autocomplete="off">
      </label>
      <label>New password
        <input type="password" name="new_password" minlength="6" required autocomplete="new-password">
      </label>
      <label>Confirm new password
        <input type="password" name="confirm_password" minlength="6" required autocomplete="new-password">
      </label>
      <button type="submit" class="btn btn-primary">Reset password</button>
    </form>
    <p class="hint"><a href="login.php">Back to sign in</a></p>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
