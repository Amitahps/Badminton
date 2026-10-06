<?php
/**
 * One-time emergency reset for Badminton admin.
 * Use only if you lost the password AND have no recovery key.
 *
 * Steps on Hostinger File Manager:
 * 1. Upload this file into public_html (same folder as login.php)
 * 2. In public_html/data/ create an empty file named: ALLOW_EMERGENCY_RESET
 * 3. Open: https://YOUR-SITE/emergency-reset.php
 * 4. Copy the new password + recovery key shown
 * 5. Delete emergency-reset.php and data/ALLOW_EMERGENCY_RESET
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

try {
    require_once __DIR__ . '/helpers.php';
    $pdo = bm_db();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Setup error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

$allowFile = BM_DATA_DIR . '/ALLOW_EMERGENCY_RESET';
$doneFile = BM_DATA_DIR . '/EMERGENCY_RESET_DONE';

if (!is_file($allowFile)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Emergency reset</title></head><body style="font-family:sans-serif;max-width:40rem;margin:2rem auto;padding:1rem;">';
    echo '<h1>Emergency reset locked</h1>';
    echo '<p>To unlock, in File Manager open the <code>data</code> folder and create an empty file named:</p>';
    echo '<p><strong>ALLOW_EMERGENCY_RESET</strong></p>';
    echo '<p>Then refresh this page. After reset, delete that file and delete <code>emergency-reset.php</code>.</p>';
    echo '</body></html>';
    exit;
}

if (is_file($doneFile)) {
    @unlink($allowFile);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Already used</title></head><body style="font-family:sans-serif;max-width:40rem;margin:2rem auto;padding:1rem;">';
    echo '<h1>Already used</h1>';
    echo '<p>Emergency reset was already run. Delete <code>emergency-reset.php</code> from the server.</p>';
    echo '<p>If you are still locked out, delete <code>data/EMERGENCY_RESET_DONE</code>, keep <code>ALLOW_EMERGENCY_RESET</code>, and refresh.</p>';
    echo '</body></html>';
    exit;
}

$st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([BM_DEFAULT_USER]);
$user = $st->fetch();
if (!$user) {
    // Recreate admin
    $plainKey = bm_make_recovery_key();
    $tempPass = 'Badminton' . random_int(1000, 9999);
    $pdo->prepare('INSERT INTO users (username, password_hash, recovery_key_hash) VALUES (?,?,?)')->execute([
        BM_DEFAULT_USER,
        password_hash($tempPass, PASSWORD_DEFAULT),
        password_hash($plainKey, PASSWORD_DEFAULT),
    ]);
    bm_save_recovery_key_file($plainKey);
} else {
    $uid = (int)$user['id'];
    $tempPass = 'Badminton' . random_int(1000, 9999);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($tempPass, PASSWORD_DEFAULT), $uid]);
    $plainKey = bm_set_user_recovery_key($pdo, $uid);
}

@file_put_contents($doneFile, date('c') . "\n");
@unlink($allowFile);

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Emergency reset done</title></head><body style="font-family:sans-serif;max-width:40rem;margin:2rem auto;padding:1rem;">';
echo '<h1>Reset complete — copy now</h1>';
echo '<p><strong>Username:</strong> <code>' . htmlspecialchars(BM_DEFAULT_USER) . '</code></p>';
echo '<p><strong>Temporary password:</strong> <code style="font-size:1.2rem;">' . htmlspecialchars($tempPass) . '</code></p>';
echo '<p><strong>Recovery key:</strong> <code style="font-size:1.2rem;">' . htmlspecialchars($plainKey) . '</code></p>';
echo '<ol>';
echo '<li>Open <a href="login.php">login.php</a> and sign in with the temporary password (or paste the recovery key in the password box).</li>';
echo '<li>Go to <strong>Password</strong> and set your own password. Keep the recovery key safe.</li>';
echo '<li><strong>Delete</strong> this file <code>emergency-reset.php</code> from File Manager now.</li>';
echo '</ol>';
echo '<p style="color:#a00;">Do not leave this page/file on the website.</p>';
echo '</body></html>';
