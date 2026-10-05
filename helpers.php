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
    $_SESSION['bm_user_id'] = (int)$user['id'];
    $_SESSION['bm_username'] = $user['username'];
    return true;
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
        return '__________';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    return $dt ? $dt->format('d-m-Y') : $ymd;
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
