<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_boot_session();

if (bm_is_logged_in()) {
    bm_redirect('index.php');
}
bm_require_recovery_pending();

$error = '';
$newKey = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $newKey = bm_complete_recovery_new_password($new, $confirm);
        bm_flash('success', 'New password saved. You are signed in. Save the new recovery key below.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Set new password · Badminton';
$wrapClass = 'wrap-login';
require __DIR__ . '/includes/header.php';
$userLabel = (string)($_SESSION['bm_recovery_username'] ?? $_SESSION['bm_username'] ?? 'admin');
?>
<section class="login-panel">
  <div class="login-visual" aria-hidden="true"><div class="court-lines"></div></div>
  <div class="login-card">
    <p class="eyebrow">Account recovery</p>
    <h1>Set new password</h1>
    <p class="lede">Recovery key worked for <strong><?= bm_h($userLabel) ?></strong>. Choose a new password to finish.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= bm_h($error) ?></div><?php endif; ?>

    <?php if ($newKey !== ''): ?>
      <div class="flash flash-success">
        Signed in with your new password.<br>
        <strong>Save this new recovery key</strong> (old key no longer works):
        <p style="margin:0.75rem 0 0;font-size:1.1rem;letter-spacing:0.04em;"><code id="recovery-key-text"><?= bm_h($newKey) ?></code></p>
        <button type="button" class="btn btn-sm" id="copy-key" style="margin-top:0.5rem;">Copy key</button>
      </div>
      <p class="hint"><a class="btn btn-primary" href="index.php">Continue to home</a></p>
      <script>
      (function(){
        var btn = document.getElementById('copy-key');
        var el = document.getElementById('recovery-key-text');
        if (!btn || !el) return;
        btn.addEventListener('click', function(){
          var t = el.textContent || '';
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t).then(function(){ btn.textContent = 'Copied'; });
          } else { window.prompt('Copy recovery key:', t); }
        });
      })();
      </script>
    <?php else: ?>
      <form method="post" class="form">
        <label>New password
          <input type="password" name="new_password" minlength="6" required autocomplete="new-password" autofocus>
        </label>
        <label>Confirm new password
          <input type="password" name="confirm_password" minlength="6" required autocomplete="new-password">
        </label>
        <button type="submit" class="btn btn-primary">Save new password &amp; sign in</button>
      </form>
      <p class="hint"><a href="login.php?cancel_recovery=1">Cancel and return to login</a></p>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
