<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();
$userId = (int)$_SESSION['bm_user_id'];

$shownKey = '';
if (isset($_POST['change_password'])) {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = (string)$stmt->fetchColumn();
    if (!$hash || !password_verify($current, $hash)) {
        bm_flash('error', 'Current password is incorrect.');
    } elseif (strlen($new) < 6) {
        bm_flash('error', 'New password must be at least 6 characters.');
    } elseif ($new !== $confirm) {
        bm_flash('error', 'New password and confirm password do not match.');
    } else {
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        bm_flash('success', 'Password changed successfully.');
        bm_redirect('change-password.php');
    }
}

if (isset($_POST['make_recovery_key'])) {
    $current = (string)($_POST['current_password_for_key'] ?? '');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = (string)$stmt->fetchColumn();
    if (!$hash || !password_verify($current, $hash)) {
        bm_flash('error', 'Enter your current password to create or renew the recovery key.');
    } else {
        $shownKey = bm_set_user_recovery_key($pdo, $userId);
        bm_flash('success', 'Recovery key created. Copy it now — it will not be shown again.');
    }
}

$hasKey = bm_user_has_recovery_key($pdo, $userId);

$pageTitle = 'Change Password · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Security</p>
    <h1>Password &amp; recovery key</h1>
    <p class="lede">Change your login password, and keep a recovery key in case the password is lost.</p>
  </div>
</section>

<section class="panel narrow">
  <div class="panel-head"><h2>Change password</h2></div>
  <form method="post" class="form">
    <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>New password (min 6 characters)<input type="password" name="new_password" minlength="6" required autocomplete="new-password"></label>
    <label>Confirm new password<input type="password" name="confirm_password" minlength="6" required autocomplete="new-password"></label>
    <div class="form-actions">
      <button type="submit" name="change_password" value="1" class="btn btn-primary">Update password</button>
    </div>
  </form>
</section>

<section class="panel narrow">
  <div class="panel-head"><h2>Recovery key</h2></div>
  <?php if ($shownKey !== ''): ?>
    <div class="flash flash-success">
      Save this key in a safe place (phone notes / paper). Anyone with this key can reset your password.
      <p style="margin:0.75rem 0 0;font-size:1.15rem;letter-spacing:0.05em;"><code id="recovery-key-text"><?= bm_h($shownKey) ?></code></p>
      <p class="hint" style="margin-top:0.5rem;">Also saved on the server as <code>data/RECOVERY_KEY.txt</code> (keep that file private).</p>
      <button type="button" class="btn btn-sm" id="copy-key">Copy key</button>
    </div>
  <?php else: ?>
    <p class="lede" style="margin-top:0;">
      <?php if ($hasKey): ?>
        A recovery key is already set. If you lost it, generate a new one below (the old key will stop working).
      <?php else: ?>
        No recovery key yet. Generate one and store it safely.
      <?php endif; ?>
    </p>
    <form method="post" class="form">
      <label>Current password (required to <?= $hasKey ? 'renew' : 'create' ?> key)
        <input type="password" name="current_password_for_key" required autocomplete="current-password">
      </label>
      <div class="form-actions">
        <button type="submit" name="make_recovery_key" value="1" class="btn btn-primary"><?= $hasKey ? 'Generate new recovery key' : 'Generate recovery key' ?></button>
      </div>
    </form>
  <?php endif; ?>
  <p class="hint">Forgot password page: <a href="recover.php">recover.php</a> (also linked from login).</p>
</section>
<script>
(function(){
  var btn = document.getElementById('copy-key');
  var el = document.getElementById('recovery-key-text');
  if (!btn || !el) return;
  btn.addEventListener('click', function(){
    var t = el.textContent || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(function(){ btn.textContent = 'Copied'; });
    } else {
      window.prompt('Copy recovery key:', t);
    }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
