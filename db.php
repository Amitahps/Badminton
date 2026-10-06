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
        // Migrate single event_code → player_events (multi-event support)
        $rows = $pdo->query("SELECT id, event_code FROM players WHERE event_code IS NOT NULL AND event_code != ''")->fetchAll();
        $ins = $pdo->prepare('INSERT OR IGNORE INTO player_events (player_id, event_code) VALUES (?, ?)');
        foreach ($rows as $r) {
            $ins->execute([(int)$r['id'], $r['event_code']]);
        }
    }
}

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
            'genders' => ['boy', 'girl'], // any one player
            'rule' => 'one',
        ],
        'double_men' => [
            'label' => 'Double Men',
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

function bm_event_label(?string $code): string
{
    $defs = bm_event_defs();
    return $defs[$code]['label'] ?? (string)$code;
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
