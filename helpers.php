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

function bm_confirm_attr(string $message): string
{
    $js = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    return 'onclick="return confirm(' . htmlspecialchars((string)$js, ENT_QUOTES, 'UTF-8') . ');"';
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

function bm_remember_tournament_id(int $id): void
{
    if ($id <= 0) {
        return;
    }
    bm_boot_session();
    $_SESSION['bm_tournament_id'] = $id;
}

function bm_current_tournament_id(): int
{
    bm_boot_session();
    return (int)($_SESSION['bm_tournament_id'] ?? 0);
}

function bm_resolve_tournament_id(int $requested): int
{
    if ($requested > 0) {
        bm_remember_tournament_id($requested);
        return $requested;
    }
    return bm_current_tournament_id();
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

/** Mobile from the player row, else the parent submission contact. */
function bm_player_contact(array $player, array $parentRow = []): string
{
    $own = trim((string)($player['mobile'] ?? ''));
    if ($own !== '') {
        return $own;
    }
    return trim((string)($parentRow['contact_mobile'] ?? ''));
}

function bm_player_address(array $player): string
{
    $address = trim((string)($player['address'] ?? ''));
    if ($address !== '') {
        return $address;
    }
    return trim((string)($player['remarks'] ?? ''));
}

/**
 * Saved teams for a tournament (0 = all), optional category, event, and player name.
 * Mix Double teams are stored on one category of the age group, so a boys or girls
 * category still returns the Mix Double teams of that age group.
 * Each row includes members and a letter-style heading.
 *
 * @return list<array<string,mixed>>
 */
function bm_find_saved_teams(PDO $pdo, int $tournamentId, int $categoryId, string $eventCode, string $playerName): array
{
    $sql = "
        SELECT DISTINCT t.id, t.tournament_id, t.team_label, t.event_code, t.age_category_id,
               c.name AS category_name, c.gender_scope, c.age_group, c.sort_order,
               tn.name AS tournament_name
        FROM tournament_teams t
        JOIN age_categories c ON c.id = t.age_category_id
        JOIN tournaments tn ON tn.id = t.tournament_id
        JOIN tournament_team_members mf ON mf.team_id = t.id
        JOIN players pf ON pf.id = mf.player_id
        WHERE 1=1
    ";
    $params = [];
    if ($tournamentId > 0) {
        $sql .= ' AND t.tournament_id = ?';
        $params[] = $tournamentId;
    }
    if ($categoryId > 0) {
        $catSt = $pdo->prepare('SELECT * FROM age_categories WHERE id = ?');
        $catSt->execute([$categoryId]);
        $cat = $catSt->fetch();
        $paired = $cat ? bm_paired_category_ids($pdo, $cat) : [$categoryId];
        $in = implode(',', array_fill(0, count($paired), '?'));
        $sql .= " AND (t.age_category_id = ? OR (t.event_code = 'mix_double' AND t.age_category_id IN ($in)))";
        $params[] = $categoryId;
        foreach ($paired as $pid) {
            $params[] = $pid;
        }
    }
    $eventCode = trim($eventCode);
    if ($eventCode !== '' && isset(bm_event_defs()[$eventCode])) {
        $sql .= ' AND t.event_code = ?';
        $params[] = $eventCode;
    }
    $playerName = trim($playerName);
    if ($playerName !== '') {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $playerName);
        $sql .= " AND pf.full_name LIKE ? ESCAPE '\\'";
        $params[] = '%' . $like . '%';
    }
    $sql .= ' ORDER BY tn.date_from DESC, c.sort_order, c.name, t.event_code, t.id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $teams = $st->fetchAll();
    $ms = $pdo->prepare("
        SELECT p.id, p.full_name, p.gender, p.bai_id, p.pbi_id
        FROM players p
        JOIN tournament_team_members m ON m.player_id = p.id
        WHERE m.team_id = ?
        ORDER BY CASE p.gender WHEN 'boy' THEN 0 ELSE 1 END, p.full_name
    ");
    foreach ($teams as &$team) {
        $ms->execute([(int)$team['id']]);
        $team['members'] = $ms->fetchAll();
        $team['heading'] = bm_letter_heading([
            'name' => $team['category_name'],
            'gender_scope' => $team['gender_scope'] ?: 'open',
            'age_group' => $team['age_group'],
        ], (string)$team['event_code']);
    }
    unset($team);
    return $teams;
}

function bm_player_names_match(string $a, string $b): bool
{
    $norm = static function (string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    };
    $a = $norm($a);
    return $a !== '' && $a === $norm($b);
}

/**
 * A BAI ID or PBA ID belongs to one player. The same number cannot be reused
 * as either ID by someone else. Blank IDs are allowed. Father name, contact
 * and address may be shared.
 *
 * @return array<string,mixed>|null
 */
function bm_player_by_registration_id(PDO $pdo, string $idValue, int $exceptPlayerId = 0): ?array
{
    $idValue = trim($idValue);
    if ($idValue === '') {
        return null;
    }
    $st = $pdo->prepare("
        SELECT * FROM players
        WHERE id != ?
          AND (
            (TRIM(IFNULL(bai_id, '')) != '' AND LOWER(TRIM(bai_id)) = LOWER(?))
            OR (TRIM(IFNULL(pbi_id, '')) != '' AND LOWER(TRIM(pbi_id)) = LOWER(?))
          )
        LIMIT 1
    ");
    $st->execute([$exceptPlayerId, $idValue, $idValue]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Find a player by one ID column. A PBA lookup must not return a player
 * whose BAI happens to be the same number.
 */
function bm_player_by_id_column(PDO $pdo, string $column, string $idValue, int $exceptPlayerId = 0): ?array
{
    $other = match ($column) {
        'bai_id' => 'pbi_id',
        'pbi_id' => 'bai_id',
        default => '',
    };
    if ($other === '') {
        throw new InvalidArgumentException('Unknown player id column.');
    }
    $idValue = trim($idValue);
    if ($idValue === '') {
        return null;
    }
    $st = $pdo->prepare("
        SELECT * FROM players
        WHERE id != ?
          AND TRIM(IFNULL($column, '')) != ''
          AND LOWER(TRIM($column)) = LOWER(?)
        ORDER BY
          CASE
            WHEN TRIM(IFNULL($other, '')) != '' AND LOWER(TRIM($other)) != LOWER(?) THEN 0
            WHEN TRIM(IFNULL($other, '')) = '' THEN 1
            ELSE 2
          END,
          id
        LIMIT 1
    ");
    $st->execute([$exceptPlayerId, $idValue, $idValue]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Reject a second player with the same BAI ID or PBA ID.
 * Blank IDs are allowed. Editing the same player keeps their own IDs.
 */
function bm_assert_unique_player_ids(PDO $pdo, ?string $bai, ?string $pba, int $exceptPlayerId = 0): void
{
    foreach ([trim((string)$bai), trim((string)$pba)] as $idValue) {
        if ($idValue === '') {
            continue;
        }
        $owner = bm_player_by_registration_id($pdo, $idValue, $exceptPlayerId);
        if ($owner) {
            throw new RuntimeException($idValue . ' already used by ' . (string)$owner['full_name'] . '.');
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

/** Extra columns admin may tick for the letter / Excel. Name is always shown. */
function bm_letter_field_defs(): array
{
    return [
        'gender' => 'Gender',
        'bai' => 'BAI ID',
        'pba' => 'PBA ID',
        'aadhaar' => 'Aadhaar',
        'dob' => 'Date of birth',
        'address' => 'District',
        'free' => 'Free entry',
        'contact' => 'Contact',
        'utr' => 'Payment reference',
    ];
}

function bm_team_is_free(array $team): bool
{
    return (int)($team['free_entry'] ?? 0) === 1;
}

/** @return list<string> */
function bm_letter_fields_enabled(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return array_keys(bm_letter_field_defs());
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array_keys(bm_letter_field_defs());
    }
    $out = [];
    foreach (array_keys(bm_letter_field_defs()) as $key) {
        if (in_array($key, $decoded, true)) {
            $out[] = $key;
        }
    }
    return $out;
}

function bm_letter_fields_from_post(array $post): string
{
    $picked = $post['letter_fields'] ?? [];
    if (!is_array($picked)) {
        $picked = [];
    }
    $ok = [];
    foreach (array_keys(bm_letter_field_defs()) as $key) {
        if (in_array($key, $picked, true)) {
            $ok[] = $key;
        }
    }
    return json_encode($ok);
}

function bm_letter_shows(array $enabled, string $key): bool
{
    return in_array($key, $enabled, true);
}

function bm_tournament_is_paid(array $tournament): bool
{
    return (int)($tournament['is_paid'] ?? 0) === 1;
}

/** @return list<array<string,mixed>> */
function bm_tournament_categories(PDO $pdo, int $tournamentId): array
{
    if ($tournamentId > 0) {
        $st = $pdo->prepare('SELECT * FROM age_categories WHERE tournament_id = ? ORDER BY sort_order, name');
        $st->execute([$tournamentId]);
        $rows = $st->fetchAll();
        if ($rows) {
            return $rows;
        }
    }
    return $pdo->query('SELECT * FROM age_categories WHERE IFNULL(tournament_id,0) = 0 ORDER BY sort_order, name')->fetchAll();
}

/** @return list<string> */
function bm_tournament_age_group_labels(PDO $pdo, int $tournamentId): array
{
    $st = $pdo->prepare("
        SELECT age_group FROM age_categories
        WHERE tournament_id = ? AND TRIM(IFNULL(age_group,'')) != ''
        GROUP BY age_group
        ORDER BY MIN(sort_order), age_group
    ");
    $st->execute([$tournamentId]);
    return array_values(array_filter(array_map('strval', array_column($st->fetchAll(), 'age_group'))));
}

/**
 * @param list<string> $groups
 */
function bm_ensure_tournament_age_groups(PDO $pdo, int $tournamentId, array $groups): void
{
    $clean = [];
    foreach ($groups as $g) {
        $g = trim((string)$g);
        if ($g !== '' && !in_array($g, $clean, true)) {
            $clean[] = $g;
        }
    }
    if (!$clean) {
        return;
    }
    $sortSt = $pdo->prepare('SELECT IFNULL(MAX(sort_order),0) FROM age_categories WHERE tournament_id=?');
    $sortSt->execute([$tournamentId]);
    $sort = (int)$sortSt->fetchColumn();
    foreach ($clean as $group) {
        foreach ([['boys', 'Boys'], ['girls', 'Girls']] as $pair) {
            $name = $group . ' ' . $pair[1];
            $find = $pdo->prepare('SELECT id FROM age_categories WHERE tournament_id=? AND LOWER(name)=LOWER(?) LIMIT 1');
            $find->execute([$tournamentId, $name]);
            if ($find->fetch()) {
                continue;
            }
            $sort += 10;
            $pdo->prepare('INSERT INTO age_categories (tournament_id, name, sort_order, gender_scope, age_group) VALUES (?,?,?,?,?)')
                ->execute([$tournamentId, $name, $sort, $pair[0], $group]);
        }
    }
}

function bm_event_fee_amount(array $tournament, string $eventCode): int
{
    if ($eventCode === 'single') {
        return max(0, (int)($tournament['fee_single'] ?? 0));
    }
    if ($eventCode === 'double_men' || $eventCode === 'double_girls') {
        return max(0, (int)($tournament['fee_double'] ?? 0));
    }
    if ($eventCode === 'mix_double') {
        return max(0, (int)($tournament['fee_mix'] ?? 0));
    }
    return 0;
}

/** @param list<string> $eventCodes */
function bm_parent_fee_total(array $tournament, array $eventCodes): int
{
    $sum = 0;
    foreach ($eventCodes as $code) {
        $sum += bm_event_fee_amount($tournament, (string)$code);
    }
    return $sum;
}

function bm_rupees(int $n): string
{
    return '₹' . number_format($n, 0, '.', '');
}

require_once __DIR__ . '/parent-entry.php';
