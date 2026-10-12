<?php
declare(strict_types=1);

/** Parent-link helpers. Admin assign / form-teams path is unchanged. */

function bm_random_code(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function bm_tournament_ensure_public_token(PDO $pdo, array &$tournament): string
{
    $tok = trim((string)($tournament['public_token'] ?? ''));
    if ($tok !== '') {
        return $tok;
    }
    $tok = bm_random_code(16);
    $pdo->prepare('UPDATE tournaments SET public_token=? WHERE id=?')->execute([$tok, (int)$tournament['id']]);
    $tournament['public_token'] = $tok;
    return $tok;
}

function bm_tournament_by_public_token(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $token)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM tournaments WHERE public_token = ? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

function bm_join_url(string $token): string
{
    $token = trim($token);
    $path = 'join.php?t=' . rawurlencode($token);
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return $path;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = rtrim($dir, '/');
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }
    return ($https ? 'https://' : 'http://') . $host . $dir . '/' . $path;
}

/**
 * Age choices for the parent form: one row per age group (Under 11, Under 13, …).
 *
 * @return list<array{key:string,label:string}>
 */
function bm_parent_age_options(PDO $pdo): array
{
    $cats = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();
    $seen = [];
    $out = [];
    foreach ($cats as $c) {
        $group = trim((string)($c['age_group'] ?? ''));
        if ($group === '') {
            $group = trim((string)$c['name']);
        }
        $key = 'g:' . $group;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['key' => $key, 'label' => $group];
    }
    return $out;
}

function bm_parent_resolve_category(PDO $pdo, string $ageKey, string $gender): array
{
    $gender = $gender === 'girl' ? 'girl' : ($gender === 'boy' ? 'boy' : '');
    if ($gender === '') {
        throw new RuntimeException('Select Boy or Girl.');
    }
    $wantScope = $gender === 'girl' ? 'girls' : 'boys';
    $group = '';
    if (strpos($ageKey, 'g:') === 0) {
        $group = trim(substr($ageKey, 2));
    }
    if ($group === '') {
        throw new RuntimeException('Select an age category.');
    }
    $cats = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();
    $match = null;
    $open = null;
    foreach ($cats as $c) {
        $g = trim((string)($c['age_group'] ?? ''));
        if ($g === '') {
            $g = trim((string)$c['name']);
        }
        if (strcasecmp($g, $group) !== 0) {
            continue;
        }
        $scope = bm_category_gender_scope($c);
        if ($scope === $wantScope) {
            $match = $c;
            break;
        }
        if ($scope === 'open' && $open === null) {
            $open = $c;
        }
    }
    $cat = $match ?: $open;
    if (!$cat) {
        $label = $gender === 'girl' ? 'Girls' : 'Boys';
        throw new RuntimeException('No ' . $group . ' ' . $label . ' age category is set up yet. Ask the organiser.');
    }
    return $cat;
}

/**
 * @param array{full_name:string,gender:string,bai_id?:string,pbi_id?:string,address?:string} $data
 */
function bm_parent_find_or_create_player(PDO $pdo, array $data, string $who = 'Player'): array
{
    $name = trim((string)($data['full_name'] ?? ''));
    $gender = strtolower(trim((string)($data['gender'] ?? '')));
    $bai = trim((string)($data['bai_id'] ?? ''));
    $pba = trim((string)($data['pbi_id'] ?? ''));
    $address = trim((string)($data['address'] ?? ''));
    if ($name === '') {
        throw new RuntimeException($who . ' name is required.');
    }
    if (!in_array($gender, ['boy', 'girl'], true)) {
        throw new RuntimeException('Select Boy or Girl for ' . $who . '.');
    }

    $existing = null;
    if ($bai !== '') {
        $st = $pdo->prepare('SELECT * FROM players WHERE LOWER(TRIM(bai_id)) = LOWER(?) LIMIT 1');
        $st->execute([$bai]);
        $existing = $st->fetch() ?: null;
    }
    if (!$existing && $pba !== '') {
        $st = $pdo->prepare('SELECT * FROM players WHERE LOWER(TRIM(pbi_id)) = LOWER(?) LIMIT 1');
        $st->execute([$pba]);
        $existing = $st->fetch() ?: null;
    }

    if ($existing) {
        $eid = (int)$existing['id'];
        if ($bai !== '') {
            $st = $pdo->prepare('SELECT full_name FROM players WHERE LOWER(TRIM(bai_id)) = LOWER(?) AND id != ? LIMIT 1');
            $st->execute([$bai, $eid]);
            $other = $st->fetchColumn();
            if ($other) {
                throw new RuntimeException('BAI ID already used by ' . $other . '.');
            }
        }
        if ($pba !== '') {
            $st = $pdo->prepare('SELECT full_name FROM players WHERE LOWER(TRIM(pbi_id)) = LOWER(?) AND id != ? LIMIT 1');
            $st->execute([$pba, $eid]);
            $other = $st->fetchColumn();
            if ($other) {
                throw new RuntimeException('PBA ID already used by ' . $other . '.');
            }
        }
        if ((string)$existing['gender'] !== $gender) {
            throw new RuntimeException('This BAI/PBA ID is already saved as ' . bm_gender_label((string)$existing['gender']) . '.');
        }
        $pdo->prepare("UPDATE players SET
            full_name=?, bai_id=COALESCE(NULLIF(?, ''), bai_id), pbi_id=COALESCE(NULLIF(?, ''), pbi_id),
            address=COALESCE(NULLIF(?, ''), address), remarks=COALESCE(NULLIF(?, ''), remarks),
            updated_at=datetime('now','localtime')
            WHERE id=?")->execute([
            $name,
            $bai !== '' ? $bai : null,
            $pba !== '' ? $pba : null,
            $address !== '' ? $address : null,
            $address !== '' ? $address : null,
            $eid,
        ]);
        $st = $pdo->prepare('SELECT * FROM players WHERE id=?');
        $st->execute([$eid]);
        return $st->fetch();
    }

    bm_assert_unique_player_ids($pdo, $bai !== '' ? $bai : null, $pba !== '' ? $pba : null, 0);
    $pdo->prepare("INSERT INTO players (full_name, gender, bai_id, pbi_id, address, remarks) VALUES (?,?,?,?,?,?)")
        ->execute([
            $name,
            $gender,
            $bai !== '' ? $bai : null,
            $pba !== '' ? $pba : null,
            $address !== '' ? $address : null,
            $address !== '' ? $address : null,
        ]);
    $id = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM players WHERE id=?');
    $st->execute([$id]);
    return $st->fetch();
}

function bm_parent_add_entry(PDO $pdo, int $tournamentId, int $playerId, int $categoryId, string $eventCode): void
{
    $pdo->prepare('INSERT OR IGNORE INTO tournament_entries (tournament_id, player_id, age_category_id, event_code) VALUES (?,?,?,?)')
        ->execute([$tournamentId, $playerId, $categoryId, $eventCode]);
}

function bm_parent_add_team(PDO $pdo, int $tournamentId, int $catId, string $event, array $playerIds, array $busyCatIds): void
{
    $playerIds = array_values(array_unique(array_map('intval', $playerIds)));
    $catIn = implode(',', array_fill(0, count($busyCatIds), '?'));
    $names = [];
    foreach ($playerIds as $pid) {
        $busy = $pdo->prepare("
            SELECT t.id FROM tournament_teams t
            JOIN tournament_team_members m ON m.team_id = t.id
            WHERE t.tournament_id=? AND t.event_code=? AND t.age_category_id IN ($catIn) AND m.player_id=?
        ");
        $busy->execute(array_merge([$tournamentId, $event], $busyCatIds, [$pid]));
        if ($busy->fetch()) {
            $nm = $pdo->prepare('SELECT full_name FROM players WHERE id=?');
            $nm->execute([$pid]);
            throw new RuntimeException(($nm->fetchColumn() ?: 'Player') . ' is already in a team for this event.');
        }
        $nm = $pdo->prepare('SELECT full_name FROM players WHERE id=?');
        $nm->execute([$pid]);
        $names[] = (string)$nm->fetchColumn();
    }
    $label = implode(' / ', $names);
    $pdo->prepare('INSERT INTO tournament_teams (tournament_id, age_category_id, event_code, team_label, include_in_letter) VALUES (?,?,?,?,1)')
        ->execute([$tournamentId, $catId, $event, $label]);
    $teamId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO tournament_team_members (team_id, player_id) VALUES (?,?)');
    foreach ($playerIds as $pid) {
        $ins->execute([$teamId, $pid]);
    }
}

/**
 * @param array<string,mixed> $post
 * @return array{receipt:string,category:array<string,mixed>}
 */
function bm_parent_save_submission(PDO $pdo, array $tournament, array $post): array
{
    $tournamentId = (int)$tournament['id'];
    $ageKey = trim((string)($post['age_key'] ?? ''));
    $gender = strtolower(trim((string)($post['gender'] ?? '')));
    $cat = bm_parent_resolve_category($pdo, $ageKey, $gender);
    $catId = (int)$cat['id'];
    $scope = bm_category_gender_scope($cat);
    $allowed = bm_events_for_gender_scope($scope);

    $want = $post['events'] ?? [];
    if (!is_array($want)) {
        $want = [];
    }
    $want = array_values(array_unique(array_map('strval', $want)));
    $eventCodes = [];
    foreach ($want as $w) {
        if ($w === 'single' && isset($allowed['single'])) {
            $eventCodes[] = 'single';
        } elseif ($w === 'double' && $gender === 'boy' && isset($allowed['double_men'])) {
            $eventCodes[] = 'double_men';
        } elseif ($w === 'double' && $gender === 'girl' && isset($allowed['double_girls'])) {
            $eventCodes[] = 'double_girls';
        } elseif ($w === 'mix' && isset($allowed['mix_double'])) {
            $eventCodes[] = 'mix_double';
        }
    }
    $eventCodes = array_values(array_unique($eventCodes));
    if (!$eventCodes) {
        throw new RuntimeException('Select Single, Doubles and/or Mix Double.');
    }

    $needDouble = in_array('double_men', $eventCodes, true) || in_array('double_girls', $eventCodes, true);
    $needMix = in_array('mix_double', $eventCodes, true);

    $primary = bm_parent_find_or_create_player($pdo, [
        'full_name' => (string)($post['full_name'] ?? ''),
        'gender' => $gender,
        'bai_id' => (string)($post['bai_id'] ?? ''),
        'pbi_id' => (string)($post['pbi_id'] ?? ''),
        'address' => (string)($post['address'] ?? ''),
    ], 'Player');

    $doublePartner = null;
    $mixPartner = null;
    if ($needDouble) {
        $pg = $gender;
        $doublePartner = bm_parent_find_or_create_player($pdo, [
            'full_name' => (string)($post['d_full_name'] ?? ''),
            'gender' => $pg,
            'bai_id' => (string)($post['d_bai_id'] ?? ''),
            'pbi_id' => (string)($post['d_pbi_id'] ?? ''),
            'address' => (string)($post['d_address'] ?? ''),
        ], 'Doubles partner');
        if ((int)$doublePartner['id'] === (int)$primary['id']) {
            throw new RuntimeException('Doubles partner must be a different player.');
        }
        if ((string)$doublePartner['gender'] !== $gender) {
            throw new RuntimeException('Doubles partner must be the same gender.');
        }
    }
    if ($needMix) {
        $og = $gender === 'boy' ? 'girl' : 'boy';
        $mixPartner = bm_parent_find_or_create_player($pdo, [
            'full_name' => (string)($post['m_full_name'] ?? ''),
            'gender' => $og,
            'bai_id' => (string)($post['m_bai_id'] ?? ''),
            'pbi_id' => (string)($post['m_pbi_id'] ?? ''),
            'address' => (string)($post['m_address'] ?? ''),
        ], 'Mix Double partner');
        if ((int)$mixPartner['id'] === (int)$primary['id']) {
            throw new RuntimeException('Mix Double partner must be a different player.');
        }
        if ($doublePartner && (int)$mixPartner['id'] === (int)$doublePartner['id']) {
            throw new RuntimeException('Mix Double partner must be different from the Doubles partner.');
        }
        $g2 = [(string)$primary['gender'], (string)$mixPartner['gender']];
        sort($g2);
        if ($g2 !== ['boy', 'girl']) {
            throw new RuntimeException('Mix Double team must be 1 boy and 1 girl.');
        }
    }

    $paired = bm_paired_category_ids($pdo, $cat);
    $pid = (int)$primary['id'];

    $pdo->beginTransaction();
    try {
        foreach ($eventCodes as $code) {
            bm_parent_add_entry($pdo, $tournamentId, $pid, $catId, $code);
            if ($code === 'single') {
                bm_parent_add_team($pdo, $tournamentId, $catId, 'single', [$pid], [$catId]);
            } elseif ($code === 'double_men' || $code === 'double_girls') {
                $dp = (int)$doublePartner['id'];
                bm_parent_add_entry($pdo, $tournamentId, $dp, $catId, $code);
                bm_parent_add_team($pdo, $tournamentId, $catId, $code, [$pid, $dp], [$catId]);
            } elseif ($code === 'mix_double') {
                $mp = (int)$mixPartner['id'];
                $mixCat = $catId;
                foreach ($paired as $pcid) {
                    $crow = $pdo->prepare('SELECT * FROM age_categories WHERE id=?');
                    $crow->execute([$pcid]);
                    $pc = $crow->fetch();
                    if ($pc && bm_category_gender_scope($pc) === 'boys') {
                        $mixCat = $pcid;
                        break;
                    }
                }
                bm_parent_add_entry($pdo, $tournamentId, $pid, $catId, 'mix_double');
                $partnerCat = $catId;
                foreach ($paired as $pcid) {
                    if ($pcid !== $catId) {
                        $partnerCat = $pcid;
                        break;
                    }
                }
                bm_parent_add_entry($pdo, $tournamentId, $mp, $partnerCat, 'mix_double');
                bm_parent_add_team($pdo, $tournamentId, $mixCat, 'mix_double', [$pid, $mp], $paired);
            }
        }

        $receipt = bm_random_code(16);
        $pdo->prepare("INSERT INTO parent_submissions
            (tournament_id, receipt_code, age_category_id, primary_player_id, double_partner_id, mix_partner_id, event_codes)
            VALUES (?,?,?,?,?,?,?)")->execute([
            $tournamentId,
            $receipt,
            $catId,
            $pid,
            $doublePartner ? (int)$doublePartner['id'] : null,
            $mixPartner ? (int)$mixPartner['id'] : null,
            implode(',', $eventCodes),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['receipt' => $receipt, 'category' => $cat];
}

function bm_parent_load_receipt(PDO $pdo, string $receipt, int $tournamentId): ?array
{
    $receipt = trim($receipt);
    if ($receipt === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $receipt)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM parent_submissions WHERE receipt_code=? AND tournament_id=? LIMIT 1');
    $st->execute([$receipt, $tournamentId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $load = static function (int $id) use ($pdo): ?array {
        if ($id <= 0) {
            return null;
        }
        $s = $pdo->prepare('SELECT * FROM players WHERE id=?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    };
    $cat = $pdo->prepare('SELECT * FROM age_categories WHERE id=?');
    $cat->execute([(int)$row['age_category_id']]);
    $row['category'] = $cat->fetch() ?: null;
    $row['primary'] = $load((int)$row['primary_player_id']);
    $row['double_partner'] = $load((int)($row['double_partner_id'] ?? 0));
    $row['mix_partner'] = $load((int)($row['mix_partner_id'] ?? 0));
    $row['events'] = array_values(array_filter(explode(',', (string)$row['event_codes'])));
    return $row;
}

function bm_remember_parent_receipt(string $publicToken, string $receipt): void
{
    bm_boot_session();
    $_SESSION['bm_parent_receipts'][$publicToken] = $receipt;
}

function bm_own_parent_receipt(string $publicToken): string
{
    bm_boot_session();
    return trim((string)($_SESSION['bm_parent_receipts'][$publicToken] ?? ''));
}
