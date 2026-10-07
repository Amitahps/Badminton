<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function bm_boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(BM_SESSION_NAME);
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

function bm_h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function bm_flash(string $type, string $message): void
{
    bm_boot_session();
    $_SESSION['bm_flash'][] = ['type' => $type, 'message' => $message];
}

function bm_get_flashes(): array
{
    bm_boot_session();
    $f = $_SESSION['bm_flash'] ?? [];
    unset($_SESSION['bm_flash']);
    return is_array($f) ? $f : [];
}

function bm_is_logged_in(): bool
{
    bm_boot_session();
    return !empty($_SESSION['bm_user_id']);
}

function bm_require_login(): void
{
    if (!bm_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function bm_login(string $username, string $password): bool
{
    $pdo = bm_db();
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    bm_boot_session();
    session_regenerate_id(true);
    unset($_SESSION['bm_recovery_user_id'], $_SESSION['bm_recovery_username']);
    $_SESSION['bm_user_id'] = (int)$user['id'];
    $_SESSION['bm_username'] = $user['username'];
    return true;
}

/**
 * If password fails, try recovery key. On match, start recovery session
 * (not full login) so user must set a new password next.
 */
function bm_try_recovery_login(string $username, string $recoveryKey): bool
{
    $pdo = bm_db();
    $username = trim($username);
    $recoveryKey = strtoupper(trim($recoveryKey));
    if ($username === '' || $recoveryKey === '') {
        return false;
    }
    $st = $pdo->prepare('SELECT id, username, recovery_key_hash FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();
    if (!$user || empty($user['recovery_key_hash'])) {
        return false;
    }
    if (!password_verify($recoveryKey, (string)$user['recovery_key_hash'])) {
        return false;
    }
    bm_boot_session();
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['bm_recovery_user_id'] = (int)$user['id'];
    $_SESSION['bm_recovery_username'] = $user['username'];
    return true;
}

function bm_recovery_pending(): bool
{
    bm_boot_session();
    return !empty($_SESSION['bm_recovery_user_id']);
}

function bm_require_recovery_pending(): void
{
    if (!bm_recovery_pending()) {
        header('Location: login.php');
        exit;
    }
}

function bm_complete_recovery_new_password(string $newPassword, string $confirm): string
{
    bm_require_recovery_pending();
    if (strlen($newPassword) < 6) {
        throw new RuntimeException('New password must be at least 6 characters.');
    }
    if ($newPassword !== $confirm) {
        throw new RuntimeException('New password and confirm password do not match.');
    }
    $pdo = bm_db();
    $uid = (int)$_SESSION['bm_recovery_user_id'];
    $username = (string)($_SESSION['bm_recovery_username'] ?? '');
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $uid]);
    $newKey = bm_set_user_recovery_key($pdo, $uid);
    // Full login
    unset($_SESSION['bm_recovery_user_id'], $_SESSION['bm_recovery_username']);
    session_regenerate_id(true);
    $_SESSION['bm_user_id'] = $uid;
    $_SESSION['bm_username'] = $username;
    return $newKey;
}

function bm_logout(): void
{
    bm_boot_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function bm_fmt_date(?string $ymd): string
{
    if (!$ymd) {
        return '—';
    }
    $ymd = trim($ymd);
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if ($dt instanceof DateTime) {
        return $dt->format('d-m-Y');
    }
    // Already day-month-year
    $dt2 = DateTime::createFromFormat('d-m-Y', $ymd);
    if ($dt2 instanceof DateTime) {
        return $dt2->format('d-m-Y');
    }
    return $ymd;
}

/** Parse user date (dd-mm-yyyy or yyyy-mm-dd) to Y-m-d for DB storage. */
function bm_parse_date_input(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    foreach (['d-m-Y', 'd/m/Y', 'Y-m-d'] as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $raw);
        if ($dt instanceof DateTime) {
            $errors = DateTime::getLastErrors();
            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $dt->format('Y-m-d');
            }
        }
    }
    throw new RuntimeException('Invalid date. Use day-month-year (dd-mm-yyyy), e.g. 15-03-2012.');
}

function bm_date_range(string $from, string $to): string
{
    $a = bm_fmt_date($from);
    $b = bm_fmt_date($to);
    return $a === $b ? $a : ($a . ' to ' . $b);
}

function bm_clean_aadhaar(string $v): string
{
    return preg_replace('/\D+/', '', $v) ?? '';
}

/**
 * Reject a second player with the same BAI ID or PBA ID (any name).
 * Blank IDs are allowed. Editing the same player keeps their own IDs.
 */
function bm_assert_unique_player_ids(PDO $pdo, ?string $bai, ?string $pba, int $exceptPlayerId = 0): void
{
    $bai = trim((string)$bai);
    $pba = trim((string)$pba);
    if ($bai !== '') {
        $st = $pdo->prepare('SELECT full_name FROM players WHERE LOWER(TRIM(bai_id)) = LOWER(?) AND id != ? LIMIT 1');
        $st->execute([$bai, $exceptPlayerId]);
        $name = $st->fetchColumn();
        if ($name) {
            throw new RuntimeException('BAI ID already used by ' . $name . '.');
        }
    }
    if ($pba !== '') {
        $st = $pdo->prepare('SELECT full_name FROM players WHERE LOWER(TRIM(pbi_id)) = LOWER(?) AND id != ? LIMIT 1');
        $st->execute([$pba, $exceptPlayerId]);
        $name = $st->fetchColumn();
        if ($name) {
            throw new RuntimeException('PBA ID already used by ' . $name . '.');
        }
    }
}

function bm_allowed_upload(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true);
}

function bm_save_upload(array $file, string $prefix): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed.');
    }
    $orig = (string)($file['name'] ?? '');
    if (!bm_allowed_upload($orig)) {
        throw new RuntimeException('Only PDF, PNG, JPG, JPEG, WEBP allowed.');
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($orig)) ?: 'file';
    $name = $prefix . '_' . date('YmdHis') . '_' . $safe;
    $dest = BM_UPLOAD_DIR . '/' . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save uploaded file.');
    }
    return $name;
}

function bm_delete_upload(?string $filename): void
{
    if (!$filename) {
        return;
    }
    $path = BM_UPLOAD_DIR . '/' . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

function bm_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}
