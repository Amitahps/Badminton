<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function bm_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(BM_DATA_DIR)) {
        mkdir(BM_DATA_DIR, 0755, true);
    }
    if (!is_dir(BM_UPLOAD_DIR)) {
        mkdir(BM_UPLOAD_DIR, 0755, true);
    }
    if (!is_dir(BM_BACKUP_DIR)) {
        mkdir(BM_BACKUP_DIR, 0755, true);
    }

    $needInit = !file_exists(BM_DB_PATH);
    $pdo = new PDO('sqlite:' . BM_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($needInit) {
        bm_init_schema($pdo);
    } else {
        // Ensure schema exists even if empty file was uploaded
        $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        if (!$check) {
            bm_init_schema($pdo);
        }
    }

    return $pdo;
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
            play_type TEXT NOT NULL CHECK (play_type IN ('single','double')),
            partner_name TEXT,
            age_category_id INTEGER NOT NULL,
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

        CREATE TABLE IF NOT EXISTS tournament_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            age_category_id INTEGER NOT NULL,
            selected INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(tournament_id, player_id),
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT
        );
    ");

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([BM_DEFAULT_USER]);
    if (!$stmt->fetch()) {
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
        $ins->execute([BM_DEFAULT_USER, password_hash(BM_DEFAULT_PASS, PASSWORD_DEFAULT)]);
    }
}
