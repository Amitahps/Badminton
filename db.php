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
            recovery_key_hash TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS age_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            gender_scope TEXT NOT NULL DEFAULT 'open',
            age_group TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE (tournament_id, name)
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
            father_name TEXT,
            remarks TEXT,
            address TEXT,
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
            letter_date TEXT,
            letter_subject TEXT,
            letter_body TEXT,
            letter_columns TEXT,
            letter_parents_to TEXT,
            letter_parents_sign TEXT,
            letter_parents_date TEXT,
            letter_parents_subject TEXT,
            letter_parents_body TEXT,
            public_token TEXT,
            is_paid INTEGER NOT NULL DEFAULT 0,
            fee_single INTEGER NOT NULL DEFAULT 0,
            fee_double INTEGER NOT NULL DEFAULT 0,
            fee_mix INTEGER NOT NULL DEFAULT 0,
            upi_id TEXT,
            qr_file TEXT,
            bank_account TEXT,
            bank_ifsc TEXT,
            bank_name TEXT,
            show_on_website INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS tournament_teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            age_category_id INTEGER NOT NULL,
            event_code TEXT NOT NULL,
            team_label TEXT,
            include_in_letter INTEGER NOT NULL DEFAULT 1,
            free_entry INTEGER NOT NULL DEFAULT 0,
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

        CREATE TABLE IF NOT EXISTS parent_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            receipt_code TEXT NOT NULL UNIQUE,
            age_category_id INTEGER NOT NULL,
            primary_player_id INTEGER NOT NULL,
            double_partner_id INTEGER,
            mix_partner_id INTEGER,
            event_codes TEXT NOT NULL,
            single_team_id INTEGER,
            double_team_id INTEGER,
            mix_team_id INTEGER,
            father_name TEXT,
            contact_mobile TEXT,
            parent_code TEXT,
            payment_status TEXT NOT NULL DEFAULT 'na',
            payment_amount INTEGER NOT NULL DEFAULT 0,
            payment_utr TEXT,
            payment_screenshot TEXT,
            payment_screenshot_hash TEXT,
            payment_screenshot_phash TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT,
            FOREIGN KEY (primary_player_id) REFERENCES players(id) ON DELETE CASCADE
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

    bm_migrate_age_categories_tournament_id($pdo);

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
        if (!bm_column_exists($pdo, 'tournaments', 'letter_date')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_date TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_subject')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_subject TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_body')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_body TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_columns')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_columns TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_parents_to')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_parents_to TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_parents_sign')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_parents_sign TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_parents_date')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_parents_date TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_parents_subject')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_parents_subject TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'letter_parents_body')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN letter_parents_body TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'public_token')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN public_token TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'is_paid')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN is_paid INTEGER NOT NULL DEFAULT 0');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'fee_single')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN fee_single INTEGER NOT NULL DEFAULT 0');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'fee_double')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN fee_double INTEGER NOT NULL DEFAULT 0');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'fee_mix')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN fee_mix INTEGER NOT NULL DEFAULT 0');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'upi_id')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN upi_id TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'qr_file')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN qr_file TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'bank_account')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN bank_account TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'bank_ifsc')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN bank_ifsc TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'bank_name')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN bank_name TEXT');
        }
        if (!bm_column_exists($pdo, 'tournaments', 'show_on_website')) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN show_on_website INTEGER NOT NULL DEFAULT 0');
        }
    }

    if (bm_table_exists($pdo, 'tournament_teams') && !bm_column_exists($pdo, 'tournament_teams', 'include_in_letter')) {
        $pdo->exec('ALTER TABLE tournament_teams ADD COLUMN include_in_letter INTEGER NOT NULL DEFAULT 1');
    }
    if (bm_table_exists($pdo, 'tournament_teams') && !bm_column_exists($pdo, 'tournament_teams', 'free_entry')) {
        $pdo->exec('ALTER TABLE tournament_teams ADD COLUMN free_entry INTEGER NOT NULL DEFAULT 0');
    }

    if (bm_table_exists($pdo, 'users') && !bm_column_exists($pdo, 'users', 'recovery_key_hash')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN recovery_key_hash TEXT');
    }

    if (bm_table_exists($pdo, 'players')) {
        if (!bm_column_exists($pdo, 'players', 'address')) {
            $pdo->exec('ALTER TABLE players ADD COLUMN address TEXT');
        }
        if (!bm_column_exists($pdo, 'players', 'father_name')) {
            $pdo->exec('ALTER TABLE players ADD COLUMN father_name TEXT');
        }
        if (bm_column_exists($pdo, 'players', 'remarks')) {
            $pdo->exec("UPDATE players SET address = remarks WHERE (address IS NULL OR TRIM(address) = '') AND remarks IS NOT NULL AND TRIM(remarks) != ''");
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS parent_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            receipt_code TEXT NOT NULL UNIQUE,
            age_category_id INTEGER NOT NULL,
            primary_player_id INTEGER NOT NULL,
            double_partner_id INTEGER,
            mix_partner_id INTEGER,
            event_codes TEXT NOT NULL,
            single_team_id INTEGER,
            double_team_id INTEGER,
            mix_team_id INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT,
            FOREIGN KEY (primary_player_id) REFERENCES players(id) ON DELETE CASCADE
        )
    ");
    if (bm_table_exists($pdo, 'parent_submissions')) {
        foreach (['single_team_id', 'double_team_id', 'mix_team_id'] as $col) {
            if (!bm_column_exists($pdo, 'parent_submissions', $col)) {
                $pdo->exec('ALTER TABLE parent_submissions ADD COLUMN ' . $col . ' INTEGER');
            }
        }
        $parentCols = [
            'father_name' => 'TEXT',
            'contact_mobile' => 'TEXT',
            'parent_code' => 'TEXT',
            'payment_status' => "TEXT NOT NULL DEFAULT 'na'",
            'payment_amount' => 'INTEGER NOT NULL DEFAULT 0',
            'payment_utr' => 'TEXT',
            'payment_screenshot' => 'TEXT',
            'payment_screenshot_hash' => 'TEXT',
            'payment_screenshot_phash' => 'TEXT',
            'payment_date' => 'TEXT',
            'payment_sender' => 'TEXT',
            'decline_reason' => 'TEXT',
        ];
        foreach ($parentCols as $col => $decl) {
            if (!bm_column_exists($pdo, 'parent_submissions', $col)) {
                $pdo->exec('ALTER TABLE parent_submissions ADD COLUMN ' . $col . ' ' . $decl);
            }
        }
        try {
            $pdo->exec("UPDATE parent_submissions SET payment_status='review' WHERE payment_status='pending' AND (payment_sender IS NULL OR TRIM(payment_sender)='')");
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec('DROP INDEX IF EXISTS idx_parent_payment_utr');
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_parent_payment_utr ON parent_submissions(payment_utr) WHERE payment_utr IS NOT NULL AND TRIM(payment_utr) != '' AND payment_status IN ('review','pending','paid')");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_parent_payment_shot ON parent_submissions(payment_screenshot_hash) WHERE payment_screenshot_hash IS NOT NULL AND TRIM(payment_screenshot_hash) != ''");
        } catch (Throwable $e) {
            // Keep going if older duplicate rows block the unique index.
        }
    }
}

function bm_migrate_age_categories_tournament_id(PDO $pdo): void
{
    if (!bm_table_exists($pdo, 'age_categories') || bm_column_exists($pdo, 'age_categories', 'tournament_id')) {
        return;
    }
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec("
        CREATE TABLE age_categories__t (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            gender_scope TEXT NOT NULL DEFAULT 'open',
            age_group TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE (tournament_id, name)
        )
    ");
    $rows = $pdo->query('SELECT * FROM age_categories')->fetchAll();
    $ins = $pdo->prepare('INSERT INTO age_categories__t (id, tournament_id, name, sort_order, notes, gender_scope, age_group, created_at) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) {
        $ins->execute([
            (int)$r['id'],
            0,
            (string)$r['name'],
            (int)($r['sort_order'] ?? 0),
            $r['notes'] ?? null,
            (string)($r['gender_scope'] ?? 'open') ?: 'open',
            $r['age_group'] ?? null,
            (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }
    $pdo->exec('DROP TABLE age_categories');
    $pdo->exec('ALTER TABLE age_categories__t RENAME TO age_categories');
    $pdo->exec('PRAGMA foreign_keys = ON');
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
 * Category → event codes already saved for a player in one tournament.
 *
 * @return array<int, list<string>>
 */
function bm_tournament_entry_map(PDO $pdo, int $tournamentId, int $playerId): array
{
    $st = $pdo->prepare('SELECT age_category_id, event_code FROM tournament_entries WHERE tournament_id=? AND player_id=? ORDER BY age_category_id, event_code');
    $st->execute([$tournamentId, $playerId]);
    $map = [];
    foreach ($st->fetchAll() as $row) {
        $map[(int)$row['age_category_id']][] = (string)$row['event_code'];
    }
    return $map;
}

/**
 * Replace a player's entries for one tournament.
 * $eventsByCategory is age_category_id => list of event codes (chosen per category).
 *
 * @param array<int|string, mixed> $eventsByCategory
 */
function bm_set_tournament_player_entries(PDO $pdo, int $tournamentId, int $playerId, array $eventsByCategory): void
{
    $pdo->prepare('DELETE FROM tournament_entries WHERE tournament_id=? AND player_id=?')
        ->execute([$tournamentId, $playerId]);
    $defs = bm_event_defs();
    $ins = $pdo->prepare('INSERT INTO tournament_entries (tournament_id, player_id, age_category_id, event_code) VALUES (?,?,?,?)');
    $cats = [];
    foreach ($eventsByCategory as $cid => $codes) {
        $cid = (int)$cid;
        if ($cid > 0 && is_array($codes)) {
            $cats[$cid] = $codes;
        }
    }
    if (!$cats) {
        bm_drop_exports_for_removed_entries($pdo, $tournamentId);
        return;
    }
    $in = implode(',', array_fill(0, count($cats), '?'));
    $st = $pdo->prepare("SELECT id, gender_scope FROM age_categories WHERE id IN ($in)");
    $st->execute(array_keys($cats));
    $scopeById = [];
    foreach ($st->fetchAll() as $row) {
        $scopeById[(int)$row['id']] = $row['gender_scope'] ?: 'open';
    }
    foreach ($cats as $cid => $codes) {
        $allowed = bm_events_for_gender_scope($scopeById[$cid] ?? 'open');
        $seen = [];
        foreach ($codes as $code) {
            $code = trim((string)$code);
            if ($code === '' || isset($seen[$code]) || !isset($defs[$code]) || !isset($allowed[$code])) {
                continue;
            }
            $ins->execute([$tournamentId, $playerId, $cid, $code]);
            $seen[$code] = true;
        }
    }
    bm_drop_exports_for_removed_entries($pdo, $tournamentId);
}

function bm_clear_tournament_player_entries(PDO $pdo, int $tournamentId, int $playerId): void
{
    $pdo->prepare('DELETE FROM tournament_entries WHERE tournament_id=? AND player_id=?')
        ->execute([$tournamentId, $playerId]);
    bm_drop_exports_for_removed_entries($pdo, $tournamentId);
}

/** True when this player still has the event on the tournament entry list. Mix checks both paired age categories. */
function bm_player_on_event(PDO $pdo, int $tournamentId, int $playerId, int $categoryId, string $eventCode): bool
{
    if ($playerId <= 0 || $categoryId <= 0 || $eventCode === '') {
        return false;
    }
    if ($eventCode === 'mix_double') {
        $st = $pdo->prepare('SELECT * FROM age_categories WHERE id=?');
        $st->execute([$categoryId]);
        $cat = $st->fetch();
        $ids = $cat ? bm_paired_category_ids($pdo, $cat) : [$categoryId];
        foreach ($ids as $cid) {
            if (bm_tournament_has_entry($pdo, $tournamentId, $playerId, (int)$cid, $eventCode)) {
                return true;
            }
        }
        return false;
    }
    return bm_tournament_has_entry($pdo, $tournamentId, $playerId, $categoryId, $eventCode);
}

function bm_team_members_still_entered(PDO $pdo, int $tournamentId, int $teamId, int $categoryId, string $eventCode): bool
{
    $ms = $pdo->prepare('SELECT player_id FROM tournament_team_members WHERE team_id=?');
    $ms->execute([$teamId]);
    $ids = array_map('intval', array_column($ms->fetchAll(), 'player_id'));
    if ($ids === []) {
        return false;
    }
    foreach ($ids as $pid) {
        if (!bm_player_on_event($pdo, $tournamentId, $pid, $categoryId, $eventCode)) {
            return false;
        }
    }
    return true;
}

/**
 * A removed entry must leave the letter, Excel, and the confirmed list parents see.
 * Teams and parent submissions stay only for events still on the entry list.
 */
function bm_drop_exports_for_removed_entries(PDO $pdo, int $tournamentId): void
{
    if ($tournamentId <= 0) {
        return;
    }
    $teams = $pdo->prepare('SELECT id, age_category_id, event_code FROM tournament_teams WHERE tournament_id=?');
    $teams->execute([$tournamentId]);
    $delTeam = $pdo->prepare('DELETE FROM tournament_teams WHERE id=? AND tournament_id=?');
    foreach ($teams->fetchAll() as $team) {
        $teamId = (int)$team['id'];
        if (!bm_team_members_still_entered($pdo, $tournamentId, $teamId, (int)$team['age_category_id'], (string)$team['event_code'])) {
            $delTeam->execute([$teamId, $tournamentId]);
        }
    }

    $subs = $pdo->prepare('SELECT * FROM parent_submissions WHERE tournament_id=?');
    $subs->execute([$tournamentId]);
    $delSub = $pdo->prepare('DELETE FROM parent_submissions WHERE id=?');
    $updSub = $pdo->prepare('UPDATE parent_submissions SET event_codes=?, single_team_id=?, double_team_id=?, mix_team_id=?, double_partner_id=?, mix_partner_id=? WHERE id=?');
    foreach ($subs->fetchAll() as $sub) {
        $catId = (int)$sub['age_category_id'];
        $primary = (int)$sub['primary_player_id'];
        $live = [];
        foreach (explode(',', (string)($sub['event_codes'] ?? '')) as $code) {
            $code = trim($code);
            if ($code === '' || !bm_player_on_event($pdo, $tournamentId, $primary, $catId, $code)) {
                continue;
            }
            if (($code === 'double_men' || $code === 'double_girls')) {
                $partner = (int)($sub['double_partner_id'] ?? 0);
                if ($partner > 0 && !bm_player_on_event($pdo, $tournamentId, $partner, $catId, $code)) {
                    continue;
                }
            }
            if ($code === 'mix_double') {
                $partner = (int)($sub['mix_partner_id'] ?? 0);
                if ($partner > 0 && !bm_player_on_event($pdo, $tournamentId, $partner, $catId, $code)) {
                    continue;
                }
            }
            $live[] = $code;
        }
        $subId = (int)$sub['id'];
        if ($live === []) {
            $delSub->execute([$subId]);
            continue;
        }
        $keepDouble = in_array('double_men', $live, true) || in_array('double_girls', $live, true);
        $keepMix = in_array('mix_double', $live, true);
        $single = in_array('single', $live, true) ? ($sub['single_team_id'] ?? null) : null;
        $double = $keepDouble ? ($sub['double_team_id'] ?? null) : null;
        $mix = $keepMix ? ($sub['mix_team_id'] ?? null) : null;
        $doublePartner = $keepDouble ? ($sub['double_partner_id'] ?? null) : null;
        $mixPartner = $keepMix ? ($sub['mix_partner_id'] ?? null) : null;
        $newCodes = implode(',', $live);
        if ($newCodes !== (string)$sub['event_codes']
            || (string)$single !== (string)($sub['single_team_id'] ?? '')
            || (string)$double !== (string)($sub['double_team_id'] ?? '')
            || (string)$mix !== (string)($sub['mix_team_id'] ?? '')
            || (string)$doublePartner !== (string)($sub['double_partner_id'] ?? '')
            || (string)$mixPartner !== (string)($sub['mix_partner_id'] ?? '')) {
            $updSub->execute([
                $newCodes,
                $single ?: null,
                $double ?: null,
                $mix ?: null,
                $doublePartner ?: null,
                $mixPartner ?: null,
                $subId,
            ]);
        }
    }
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
    $stmt = $pdo->prepare('SELECT id, recovery_key_hash FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([BM_DEFAULT_USER]);
    $row = $stmt->fetch();
    if (!$row) {
        $plainKey = bm_make_recovery_key();
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, recovery_key_hash) VALUES (?, ?, ?)');
        $ins->execute([
            BM_DEFAULT_USER,
            password_hash(BM_DEFAULT_PASS, PASSWORD_DEFAULT),
            password_hash($plainKey, PASSWORD_DEFAULT),
        ]);
        bm_save_recovery_key_file($plainKey);
    }
}

/** Random recovery key like BM-XXXX-XXXX-XXXX */
function bm_make_recovery_key(): string
{
    $chunk = static function (): string {
        return strtoupper(bin2hex(random_bytes(2)));
    };
    return 'BM-' . $chunk() . '-' . $chunk() . '-' . $chunk() . '-' . $chunk();
}

function bm_save_recovery_key_file(string $plainKey): void
{
    if (!is_dir(BM_DATA_DIR)) {
        @mkdir(BM_DATA_DIR, 0755, true);
    }
    $path = BM_DATA_DIR . '/RECOVERY_KEY.txt';
    $body = "Badminton Tournament Entry — recovery key\n"
        . "Keep this file private. Use it on recover.php if you forget the password.\n"
        . "Generated: " . date('d-m-Y H:i') . "\n\n"
        . $plainKey . "\n";
    @file_put_contents($path, $body);
}

function bm_user_has_recovery_key(PDO $pdo, int $userId): bool
{
    $st = $pdo->prepare('SELECT recovery_key_hash FROM users WHERE id = ?');
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    return is_string($hash) && $hash !== '';
}

/** Create/replace recovery key for a user; returns plaintext once. */
function bm_set_user_recovery_key(PDO $pdo, int $userId): string
{
    $plain = bm_make_recovery_key();
    $pdo->prepare('UPDATE users SET recovery_key_hash = ? WHERE id = ?')
        ->execute([password_hash($plain, PASSWORD_DEFAULT), $userId]);
    bm_save_recovery_key_file($plain);
    return $plain;
}

/**
 * Reset password using recovery key. Returns true on success.
 * Regenerates recovery key after use (old key stops working).
 */
function bm_recover_password(PDO $pdo, string $username, string $recoveryKey, string $newPassword): string
{
    $username = trim($username);
    $recoveryKey = strtoupper(trim($recoveryKey));
    if ($username === '' || $recoveryKey === '') {
        throw new RuntimeException('Username and recovery key are required.');
    }
    if (strlen($newPassword) < 6) {
        throw new RuntimeException('New password must be at least 6 characters.');
    }
    $st = $pdo->prepare('SELECT id, recovery_key_hash FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();
    if (!$user || empty($user['recovery_key_hash'])) {
        throw new RuntimeException('No recovery key is set for this user. Sign in (or ask admin) and generate one under Password.');
    }
    if (!password_verify($recoveryKey, (string)$user['recovery_key_hash'])) {
        throw new RuntimeException('Invalid recovery key.');
    }
    $uid = (int)$user['id'];
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $uid]);
    // Invalidate used key and issue a new one (saved to data/RECOVERY_KEY.txt)
    $newKey = bm_set_user_recovery_key($pdo, $uid);
    return $newKey;
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
    $tid = (int)($category['tournament_id'] ?? 0);
    if ($tid > 0) {
        $st = $pdo->prepare('SELECT id FROM age_categories WHERE age_group = ? AND tournament_id = ?');
        $st->execute([$group, $tid]);
    } else {
        $st = $pdo->prepare('SELECT id FROM age_categories WHERE age_group = ? AND IFNULL(tournament_id,0) = 0');
        $st->execute([$group]);
    }
    $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    return $ids ?: [$id];
}

function bm_event_label(?string $code): string
{
    $defs = bm_event_defs();
    return $defs[$code]['label'] ?? (string)$code;
}

/**
 * Letter / export heading.
 * Examples: Under 15 Boys Singles, Under 15 Girls Doubles, Under 15 Mix Doubles.
 */
function bm_letter_heading(array $category, string $eventCode): string
{
    $name = trim((string)($category['name'] ?? ''));
    $group = trim((string)($category['age_group'] ?? ''));
    if ($group === '') {
        $group = bm_infer_category_meta($name)['age_group'];
    }
    $scope = bm_category_gender_scope($category);

    if ($eventCode === 'mix_double') {
        $base = $group !== '' ? $group : trim(preg_replace('/\b(boys?|girls?|men|women)\b/iu', '', $name) ?? '');
        $base = trim(preg_replace('/\s{2,}/', ' ', $base) ?? '');
        return trim($base . ' Mix Doubles');
    }

    // Prefer "Under 15 Boys Singles" style from age group + gender
    if ($group !== '' && ($scope === 'boys' || $scope === 'girls')) {
        $genderWord = $scope === 'boys' ? 'Boys' : 'Girls';
        if ($eventCode === 'single') {
            return $group . ' ' . $genderWord . ' Singles';
        }
        if ($eventCode === 'double_men' || $eventCode === 'double_girls') {
            return $group . ' ' . $genderWord . ' Doubles';
        }
    }

    // Fallback: category name + Singles/Doubles
    if ($eventCode === 'single') {
        return trim($name . ' Singles');
    }
    if ($eventCode === 'double_men' || $eventCode === 'double_girls') {
        return trim($name . ' Doubles');
    }
    return trim($name . ' ' . bm_event_label($eventCode));
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
