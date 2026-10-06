<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function bm_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PDO SQLite is not enabled on this hosting. Enable pdo_sqlite in Hostinger PHP settings.');
    }

    if (!is_dir(BM_DATA_DIR) && !@mkdir(BM_DATA_DIR, 0755, true) && !is_dir(BM_DATA_DIR)) {
        throw new RuntimeException('Cannot create data/ folder. Set writable permissions (755/775).');
    }
    if (!is_dir(BM_UPLOAD_DIR) && !@mkdir(BM_UPLOAD_DIR, 0755, true) && !is_dir(BM_UPLOAD_DIR)) {
        throw new RuntimeException('Cannot create data/uploads/ folder. Set writable permissions.');
    }
    if (!is_dir(BM_BACKUP_DIR) && !@mkdir(BM_BACKUP_DIR, 0755, true) && !is_dir(BM_BACKUP_DIR)) {
        throw new RuntimeException('Cannot create backups/ folder. Set writable permissions.');
    }
    if (!is_writable(BM_DATA_DIR)) {
        throw new RuntimeException('data/ folder is not writable. In File Manager set permission 755 or 775.');
    }

    try {
        $needInit = !file_exists(BM_DB_PATH);
        $pdo = new PDO('sqlite:' . BM_DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        if ($needInit) {
            bm_init_schema($pdo);
        } else {
            $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
            if (!$check) {
                bm_init_schema($pdo);
            } else {
                bm_migrate_schema($pdo);
            }
        }
        bm_seed_admin($pdo);
    } catch (Throwable $e) {
        throw new RuntimeException('Database error: ' . $e->getMessage());
    }

    return $pdo;
}

function bm_column_exists(PDO $pdo, string $table, string $column): bool
{
    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function bm_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$table]);
    return (bool)$st->fetch();
}

function bm_init_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS age_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            sort_order INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            gender_scope TEXT NOT NULL DEFAULT 'open',
            age_group TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            gender TEXT NOT NULL CHECK (gender IN ('boy','girl')),
            age_category_id INTEGER NULL,
            event_code TEXT NULL,
            bai_id TEXT,
            pbi_id TEXT,
            aadhaar_no TEXT,
            aadhaar_file TEXT,
            dob TEXT,
            dob_certificate_file TEXT,
            mobile TEXT,
            remarks TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT
        );

        CREATE TABLE IF NOT EXISTS tournaments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            held_at TEXT NOT NULL,
            date_from TEXT NOT NULL,
            date_to TEXT NOT NULL,
            notes TEXT,
            letter_to TEXT,
            letter_sign TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS tournament_teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            age_category_id INTEGER NOT NULL,
            event_code TEXT NOT NULL,
            team_label TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT
        );

        CREATE TABLE IF NOT EXISTS tournament_team_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(team_id, player_id),
            FOREIGN KEY (team_id) REFERENCES tournament_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS player_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INTEGER NOT NULL,
            event_code TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(player_id, event_code),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        );

        -- Per-tournament: a player may join many age categories and many events
        CREATE TABLE IF NOT EXISTS tournament_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            age_category_id INTEGER NOT NULL,
            event_code TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(tournament_id, player_id, age_category_id, event_code),
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT
        );
    ");
}

function bm_migrate_schema(PDO $pdo): void
{
    // Newer tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tournament_teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            age_category_id INTEGER NOT NULL,
            event_code TEXT NOT NULL,
            team_label TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT
        );
        CREATE TABLE IF NOT EXISTS tournament_team_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(team_id, player_id),
            FOREIGN KEY (team_id) REFERENCES tournament_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS player_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INTEGER NOT NULL,
            event_code TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(player_id, event_code),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS tournament_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            age_category_id INTEGER NOT NULL,
            event_code TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(tournament_id, player_id, age_category_id, event_code),
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT
        );
    ");

    if (bm_table_exists($pdo, 'players')) {
        if (!bm_column_exists($pdo, 'players', 'gender')) {
            $pdo->exec("ALTER TABLE players ADD COLUMN gender TEXT DEFAULT 'boy'");
            $pdo->exec("UPDATE players SET gender='boy' WHERE gender IS NULL OR gender=''");
        }
        if (!bm_column_exists($pdo, 'players', 'event_code')) {
            $pdo->exec('ALTER TABLE players ADD COLUMN event_code TEXT NULL');
            if (bm_column_exists($pdo, 'players', 'play_type')) {
                $pdo->exec("UPDATE players SET event_code='single' WHERE play_type='single' AND (event_code IS NULL OR event_code='')");
                $pdo->exec("UPDATE players SET event_code='double_men' WHERE play_type='double' AND (event_code IS NULL OR event_code='')");
            }
        }
        // Legacy: keep player_events in sync for old DBs (not used for new tournament flow)
        $rows = $pdo->query("SELECT id, event_code FROM players WHERE event_code IS NOT NULL AND event_code != ''")->fetchAll();
        $ins = $pdo->prepare('INSERT OR IGNORE INTO player_events (player_id, event_code) VALUES (?, ?)');
        foreach ($rows as $r) {
            $ins->execute([(int)$r['id'], $r['event_code']]);
        }
    }

    if (bm_table_exists($pdo, 'age_categories')) {
        if (!bm_column_exists($pdo, 'age_categories', 'gender_scope')) {
            $pdo->exec("ALTER TABLE age_categories ADD COLUMN gender_scope TEXT NOT NULL DEFAULT 'open'");
        }
        if (!bm_column_exists($pdo, 'age_categories', 'age_group')) {
            $pdo->exec('ALTER TABLE age_categories ADD COLUMN age_group TEXT');
        }
        // Infer / repair boys/girls from category names
        $cats = $pdo->query("SELECT id, name, gender_scope, age_group FROM age_categories")->fetchAll();
        $upd = $pdo->prepare('UPDATE age_categories SET gender_scope=?, age_group=? WHERE id=?');
        foreach ($cats as $c) {
            $scope = strtolower(trim((string)($c['gender_scope'] ?: 'open')));
            if (!in_array($scope, ['boys', 'girls', 'open'], true)) {
                $scope = 'open';
            }
            $group = $c['age_group'];
            $inferred = bm_infer_category_meta($c['name']);
            // Trust clear name signal (fixes Girls categories wrongly saved as Boys)
            if ($inferred['gender_scope'] === 'girls' || $inferred['gender_scope'] === 'boys') {
                $scope = $inferred['gender_scope'];
            }
            if (($group === null || $group === '') && $inferred['age_group'] !== '') {
                $group = $inferred['age_group'];
            }
            $upd->execute([$scope, $group !== '' && $group !== null ? $group : null, (int)$c['id']]);
        }
    }

    if (bm_table_exists($pdo, 'tournaments')) {
        if (!bm_column_exists($pdo, 'tournaments', 'letter_to')) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN letter_to TEXT");
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_sign')) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN letter_sign TEXT");
        }
    }
}

/** Events assigned to a player inside one tournament. */
function bm_tournament_entry_events(PDO $pdo, int $tournamentId, int $playerId): array
{
    $st = $pdo->prepare('SELECT DISTINCT event_code FROM tournament_entries WHERE tournament_id=? AND player_id=? ORDER BY event_code');
    $st->execute([$tournamentId, $playerId]);
    return array_column($st->fetchAll(), 'event_code');
}

/** Age categories assigned to a player inside one tournament. */
function bm_tournament_entry_categories(PDO $pdo, int $tournamentId, int $playerId): array
{
    $st = $pdo->prepare('SELECT DISTINCT age_category_id FROM tournament_entries WHERE tournament_id=? AND player_id=? ORDER BY age_category_id');
    $st->execute([$tournamentId, $playerId]);
    return array_map('intval', array_column($st->fetchAll(), 'age_category_id'));
}

function bm_tournament_has_entry(PDO $pdo, int $tournamentId, int $playerId, int $categoryId, string $eventCode): bool
{
    $st = $pdo->prepare('SELECT 1 FROM tournament_entries WHERE tournament_id=? AND player_id=? AND age_category_id=? AND event_code=? LIMIT 1');
    $st->execute([$tournamentId, $playerId, $categoryId, $eventCode]);
    return (bool)$st->fetch();
}

/**
 * Replace a player's category×event entries for one tournament.
 * Only valid events for each category gender_scope are stored.
 */
function bm_set_tournament_player_entries(PDO $pdo, int $tournamentId, int $playerId, array $categoryIds, array $eventCodes): void
{
    $pdo->prepare('DELETE FROM tournament_entries WHERE tournament_id=? AND player_id=?')
        ->execute([$tournamentId, $playerId]);
    $defs = bm_event_defs();
    $ins = $pdo->prepare('INSERT INTO tournament_entries (tournament_id, player_id, age_category_id, event_code) VALUES (?,?,?,?)');
    $cats = [];
    foreach ($categoryIds as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) {
            $cats[$cid] = true;
        }
    }
    $evs = [];
    foreach ($eventCodes as $code) {
        $code = trim((string)$code);
        if (isset($defs[$code])) {
            $evs[$code] = true;
        }
    }
    if (!$cats || !$evs) {
        return;
    }
    $in = implode(',', array_fill(0, count($cats), '?'));
    $st = $pdo->prepare("SELECT id, gender_scope FROM age_categories WHERE id IN ($in)");
    $st->execute(array_keys($cats));
    $scopeById = [];
    foreach ($st->fetchAll() as $row) {
        $scopeById[(int)$row['id']] = $row['gender_scope'] ?: 'open';
    }
    foreach (array_keys($cats) as $cid) {
        $allowed = bm_events_for_gender_scope($scopeById[$cid] ?? 'open');
        foreach (array_keys($evs) as $code) {
            if (isset($allowed[$code])) {
                $ins->execute([$tournamentId, $playerId, $cid, $code]);
            }
        }
    }
}

function bm_clear_tournament_player_entries(PDO $pdo, int $tournamentId, int $playerId): void
{
    $pdo->prepare('DELETE FROM tournament_entries WHERE tournament_id=? AND player_id=?')
        ->execute([$tournamentId, $playerId]);
}

/** @deprecated Global player events — kept for old data only. Use tournament_entries. */
function bm_player_event_codes(PDO $pdo, int $playerId): array
{
    $st = $pdo->prepare('SELECT event_code FROM player_events WHERE player_id = ? ORDER BY event_code');
    $st->execute([$playerId]);
    return array_column($st->fetchAll(), 'event_code');
}

function bm_player_has_event(PDO $pdo, int $playerId, string $eventCode): bool
{
    $st = $pdo->prepare('SELECT 1 FROM player_events WHERE player_id = ? AND event_code = ? LIMIT 1');
    $st->execute([$playerId, $eventCode]);
    return (bool)$st->fetch();
}

function bm_set_player_events(PDO $pdo, int $playerId, array $eventCodes): void
{
    $pdo->prepare('DELETE FROM player_events WHERE player_id = ?')->execute([$playerId]);
    $ins = $pdo->prepare('INSERT INTO player_events (player_id, event_code) VALUES (?, ?)');
    $defs = bm_event_defs();
    foreach ($eventCodes as $code) {
        $code = trim((string)$code);
        if (isset($defs[$code])) {
            $ins->execute([$playerId, $code]);
        }
    }
}

function bm_seed_admin(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([BM_DEFAULT_USER]);
    if (!$stmt->fetch()) {
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
        $ins->execute([BM_DEFAULT_USER, password_hash(BM_DEFAULT_PASS, PASSWORD_DEFAULT)]);
    }
}

/** Event codes used across the app (formerly play type). */
function bm_event_defs(): array
{
    return [
        'single' => [
            'label' => 'Single',
            'team_size' => 1,
            'genders' => ['boy', 'girl'],
            'rule' => 'one',
        ],
        'double_men' => [
            'label' => 'Double Boys',
            'team_size' => 2,
            'genders' => ['boy'],
            'rule' => 'same',
        ],
        'double_girls' => [
            'label' => 'Double Girls',
            'team_size' => 2,
            'genders' => ['girl'],
            'rule' => 'same',
        ],
        'mix_double' => [
            'label' => 'Mix Double',
            'team_size' => 2,
            'genders' => ['boy', 'girl'],
            'rule' => 'mix',
        ],
    ];
}

/** Guess boys/girls + age group from a category name like "Under 14 Boys". */
function bm_infer_category_meta(string $name): array
{
    $n = strtolower(trim($name));
    $scope = 'open';
    // Girls first: "female" contains "male", "women" contains "men"
    if (preg_match('/\b(girls?|women|female|ladies)\b/u', $n) || strpos($n, 'girl') !== false) {
        $scope = 'girls';
    } elseif (preg_match('/\b(boys?|men|male)\b/u', $n) || strpos($n, 'boy') !== false) {
        $scope = 'boys';
    }
    $group = trim(preg_replace('/\b(boys?|girls?|men|women|male|female|ladies)\b/iu', '', $name) ?? '');
    $group = trim(preg_replace('/\s{2,}/', ' ', $group) ?? '');
    $group = trim($group, " -\t");
    return ['gender_scope' => $scope, 'age_group' => $group];
}

/** Events allowed for a category gender_scope. */
function bm_events_for_gender_scope(string $scope): array
{
    $all = bm_event_defs();
    if ($scope === 'boys') {
        return array_intersect_key($all, array_flip(['single', 'double_men', 'mix_double']));
    }
    if ($scope === 'girls') {
        return array_intersect_key($all, array_flip(['single', 'double_girls', 'mix_double']));
    }
    return $all;
}

function bm_category_gender_scope(array $category): string
{
    $s = $category['gender_scope'] ?? 'open';
    return in_array($s, ['boys', 'girls', 'open'], true) ? $s : 'open';
}

function bm_gender_scope_label(string $scope): string
{
    if ($scope === 'boys') {
        return 'Boys';
    }
    if ($scope === 'girls') {
        return 'Girls';
    }
    return 'Open (all)';
}

/** Category IDs that share the same age_group (for Mix Double boy+girl pool). */
function bm_paired_category_ids(PDO $pdo, array $category): array
{
    $id = (int)$category['id'];
    $group = trim((string)($category['age_group'] ?? ''));
    if ($group === '') {
        return [$id];
    }
    $st = $pdo->prepare('SELECT id FROM age_categories WHERE age_group = ?');
    $st->execute([$group]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    return $ids ?: [$id];
}

function bm_event_label(?string $code): string
{
    $defs = bm_event_defs();
    return $defs[$code]['label'] ?? (string)$code;
}

/**
 * Letter / export heading, e.g. "Under 13 Boys Singles", "Under 15 Mix Double".
 * Mix Double uses age group only (not Boys/Girls).
 */
function bm_letter_heading(array $category, string $eventCode): string
{
    $name = trim((string)($category['name'] ?? ''));
    $group = trim((string)($category['age_group'] ?? ''));
    if ($group === '') {
        $group = bm_infer_category_meta($name)['age_group'];
    }

    if ($eventCode === 'mix_double') {
        $base = $group !== '' ? $group : trim(preg_replace('/\b(boys?|girls?|men|women)\b/iu', '', $name) ?? '');
        $base = trim(preg_replace('/\s{2,}/', ' ', $base) ?? '');
        return trim($base . ' Mix Double');
    }

    $suffix = 'Event';
    if ($eventCode === 'single') {
        $suffix = 'Singles';
    } elseif ($eventCode === 'double_men' || $eventCode === 'double_girls') {
        $suffix = 'Double';
    } else {
        $suffix = bm_event_label($eventCode);
    }

    return trim($name . ' ' . $suffix);
}

function bm_gender_label(?string $g): string
{
    if ($g === 'boy') {
        return 'Boy';
    }
    if ($g === 'girl') {
        return 'Girl';
    }
    return '—';
}
