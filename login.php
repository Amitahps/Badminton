<?php
declare(strict_types=1);

try {
    require_once __DIR__ . '/helpers.php';
    bm_boot_session();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Badminton setup error:\n" . $e->getMessage() . "\n\nOpen check.php for details.";
    exit;
}

if (bm_is_logged_in()) {
    bm_redirect('index.php');
}
if (isset($_GET['cancel_recovery'])) {
    unset($_SESSION['bm_recovery_user_id'], $_SESSION['bm_recovery_username']);
    bm_redirect('login.php');
}
if (bm_recovery_pending()) {
    bm_redirect('set-new-password.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if (bm_login($user, $pass)) {
            bm_redirect('index.php');
        }
        // Lost password: same box accepts recovery key
        if (bm_try_recovery_login($user, $pass)) {
            bm_flash('success', 'Recovery key accepted. Set a new password to continue.');
            bm_redirect('set-new-password.php');
        }
        $error = 'Invalid username, password, or recovery key.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Login · Badminton';
$wrapClass = 'wrap-login';
require __DIR__ . '/includes/header.php';
?>
<section class="login-panel">
  <div class="login-visual" aria-hidden="true"><div class="court-lines"></div></div>
  <div class="login-card">
    <p class="eyebrow">District Badminton Association</p>
    <h1>Hoshiarpur</h1>
    <p class="lede">Online tournament entry — age categories, players, and PBA letter list.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= bm_h($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <label>Username
        <input type="text" name="username" required autofocus value="<?= bm_h($_POST['username'] ?? 'admin') ?>" autocomplete="username">
      </label>
      <label>Password or recovery key
        <input type="password" name="password" required autocomplete="current-password" placeholder="Password, or BM-XXXX-… recovery key">
      </label>
      <button type="submit" class="btn btn-primary">Sign in</button>
    </form>
    <p class="hint">If you lost the password, paste your <strong>recovery key</strong> in the password box, then set a new password on the next screen.</p>
    <p class="hint">Default login: <strong>admin</strong> / <strong>admin123</strong> — change after first login. Generate a recovery key under <strong>Password</strong> after sign-in.</p>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
