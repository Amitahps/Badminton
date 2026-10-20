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

function bm_app_abs_url(string $path): string
{
    $path = ltrim($path, '/');
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

function bm_join_url(string $token): string
{
    return bm_app_abs_url('join.php?t=' . rawurlencode(trim($token)));
}

function bm_public_list_url(?int $year = null): string
{
    $path = 'open.php';
    if ($year !== null && $year > 1990) {
        $path .= '?year=' . $year;
    }
    return bm_app_abs_url($path);
}

function bm_tournament_show_on_website(array $tournament): bool
{
    return (int)($tournament['show_on_website'] ?? 0) === 1;
}

function bm_tournament_year(array $tournament): string
{
    $from = trim((string)($tournament['date_from'] ?? ''));
    if (preg_match('/^(\d{4})/', $from, $m)) {
        return $m[1];
    }
    return 'Other';
}

/**
 * @return array<string, list<array<string,mixed>>>
 */
function bm_public_tournaments_by_year(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT * FROM tournaments
        WHERE IFNULL(show_on_website, 0) = 1
        ORDER BY date_from DESC, name COLLATE NOCASE, id DESC
    ")->fetchAll();
    $byYear = [];
    foreach ($rows as $row) {
        $byYear[bm_tournament_year($row)][] = $row;
    }
    return $byYear;
}

/**
 * Age choices for the parent form: one row per age group (Under 11, Under 13, …).
 *
 * @return list<array{key:string,label:string}>
 */
function bm_parent_age_options(PDO $pdo, int $tournamentId = 0): array
{
    $cats = bm_tournament_categories($pdo, $tournamentId);
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

function bm_parent_resolve_category(PDO $pdo, string $ageKey, string $gender, int $tournamentId = 0): array
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
    $cats = bm_tournament_categories($pdo, $tournamentId);
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
 * @param array{full_name:string,gender:string,bai_id?:string,pbi_id?:string,address?:string,father_name?:string,mobile?:string} $data
 */
function bm_parent_find_or_create_player(PDO $pdo, array $data, string $who = 'Player'): array
{
    $name = trim((string)($data['full_name'] ?? ''));
    $gender = strtolower(trim((string)($data['gender'] ?? '')));
    $bai = trim((string)($data['bai_id'] ?? ''));
    $pba = trim((string)($data['pbi_id'] ?? ''));
    $address = trim((string)($data['address'] ?? $data['district'] ?? ''));
    $father = trim((string)($data['father_name'] ?? ''));
    $mobile = trim((string)($data['mobile'] ?? $data['contact_mobile'] ?? ''));
    if ($mobile !== '') {
        $mobile = bm_normalize_mobile($mobile);
    }
    if ($name === '') {
        throw new RuntimeException($who . ' name is required.');
    }
    if (!in_array($gender, ['boy', 'girl'], true)) {
        throw new RuntimeException('Select Boy or Girl for ' . $who . '.');
    }
    if ($father === '') {
        throw new RuntimeException('Father’s name is required for ' . $who . '.');
    }
    if ($mobile === '') {
        throw new RuntimeException('Contact number is required for ' . $who . '.');
    }
    if ($address === '') {
        throw new RuntimeException('District is required for ' . $who . '.');
    }

    $existing = null;
    $matchedId = '';
    foreach ([$bai, $pba] as $idValue) {
        if ($idValue === '') {
            continue;
        }
        $found = bm_player_by_registration_id($pdo, $idValue, 0);
        if (!$found) {
            continue;
        }
        if (!$existing) {
            $existing = $found;
            $matchedId = $idValue;
            continue;
        }
        if ((int)$found['id'] !== (int)$existing['id']) {
            throw new RuntimeException($idValue . ' already used by ' . (string)$found['full_name'] . '.');
        }
    }

    if ($existing) {
        $eid = (int)$existing['id'];
        if (!bm_player_names_match($name, (string)$existing['full_name'])) {
            throw new RuntimeException($matchedId . ' already used by ' . (string)$existing['full_name'] . '.');
        }
        bm_assert_unique_player_ids($pdo, $bai !== '' ? $bai : null, $pba !== '' ? $pba : null, $eid);
        if ((string)$existing['gender'] !== $gender) {
            throw new RuntimeException('This BAI/PBI ID is already saved as ' . bm_gender_label((string)$existing['gender']) . '.');
        }
        $pdo->prepare("UPDATE players SET
            full_name=?, bai_id=COALESCE(NULLIF(?, ''), bai_id), pbi_id=COALESCE(NULLIF(?, ''), pbi_id),
            address=COALESCE(NULLIF(?, ''), address), remarks=COALESCE(NULLIF(?, ''), remarks),
            father_name=COALESCE(NULLIF(?, ''), father_name), mobile=COALESCE(NULLIF(?, ''), mobile),
            updated_at=datetime('now','localtime')
            WHERE id=?")->execute([
            $name,
            $bai !== '' ? $bai : null,
            $pba !== '' ? $pba : null,
            $address !== '' ? $address : null,
            $address !== '' ? $address : null,
            $father !== '' ? $father : null,
            $mobile !== '' ? $mobile : null,
            $eid,
        ]);
        $st = $pdo->prepare('SELECT * FROM players WHERE id=?');
        $st->execute([$eid]);
        return $st->fetch();
    }

    bm_assert_unique_player_ids($pdo, $bai !== '' ? $bai : null, $pba !== '' ? $pba : null, 0);
    $pdo->prepare("INSERT INTO players (full_name, gender, bai_id, pbi_id, address, remarks, father_name, mobile) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([
            $name,
            $gender,
            $bai !== '' ? $bai : null,
            $pba !== '' ? $pba : null,
            $address !== '' ? $address : null,
            $address !== '' ? $address : null,
            $father !== '' ? $father : null,
            $mobile !== '' ? $mobile : null,
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

function bm_parent_add_team(PDO $pdo, int $tournamentId, int $catId, string $event, array $playerIds, array $busyCatIds, int $includeInLetter = 1): int
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
    $pdo->prepare('INSERT INTO tournament_teams (tournament_id, age_category_id, event_code, team_label, include_in_letter) VALUES (?,?,?,?,?)')
        ->execute([$tournamentId, $catId, $event, $label, $includeInLetter ? 1 : 0]);
    $teamId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO tournament_team_members (team_id, player_id) VALUES (?,?)');
    foreach ($playerIds as $pid) {
        $ins->execute([$teamId, $pid]);
    }
    return $teamId;
}

/**
 * @param array<string,mixed> $post
 * @return array{receipt:string,category:array<string,mixed>}
 */
function bm_parent_save_submission(PDO $pdo, array $tournament, array $post, array $files = []): array
{
    $tournamentId = (int)$tournament['id'];
    $ageKey = trim((string)($post['age_key'] ?? ''));
    $gender = strtolower(trim((string)($post['gender'] ?? '')));
    $father = trim((string)($post['father_name'] ?? ''));
    $mobile = bm_normalize_mobile((string)($post['contact_mobile'] ?? ''));
    if ($father === '') {
        throw new RuntimeException('Father’s name is required.');
    }
    $cat = bm_parent_resolve_category($pdo, $ageKey, $gender, $tournamentId);
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
        'address' => (string)($post['district'] ?? $post['address'] ?? ''),
        'father_name' => (string)($post['father_name'] ?? ''),
        'mobile' => (string)($post['contact_mobile'] ?? ''),
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
            'address' => (string)($post['d_district'] ?? $post['d_address'] ?? ''),
            'father_name' => (string)($post['d_father_name'] ?? ''),
            'mobile' => (string)($post['d_contact_mobile'] ?? ''),
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
            'address' => (string)($post['m_district'] ?? $post['m_address'] ?? ''),
            'father_name' => (string)($post['m_father_name'] ?? ''),
            'mobile' => (string)($post['m_contact_mobile'] ?? ''),
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
    $singleTeam = null;
    $doubleTeam = null;
    $mixTeam = null;
    $amount = bm_parent_fee_total($tournament, $eventCodes);
    $needPay = bm_tournament_is_paid($tournament) && $amount > 0;
    $includeLetter = $needPay ? 0 : 1;
    $payStatus = $needPay ? 'review' : 'na';
    $utr = null;
    $payDate = null;
    $paySender = null;
    if ($needPay) {
        $payDate = bm_parse_date_input((string)($post['payment_date'] ?? ''));
        if ($payDate === null) {
            throw new RuntimeException('Date of payment is required (dd-mm-yyyy).');
        }
        $paySender = bm_validate_sender_name((string)($post['payment_sender'] ?? ''));
        $utr = bm_validate_utr((string)($post['payment_utr'] ?? ''));
        bm_assert_unique_utr($pdo, $utr, 0);
    }
    $parentCode = bm_parent_code_from_post_or_session((string)($tournament['public_token'] ?? ''), (string)($post['parent_code'] ?? ''));

    $pdo->beginTransaction();
    try {
        if ($needPay) {
            bm_assert_unique_utr($pdo, (string)$utr, 0);
        }
        foreach ($eventCodes as $code) {
            bm_parent_add_entry($pdo, $tournamentId, $pid, $catId, $code);
            if ($code === 'single') {
                $singleTeam = bm_parent_add_team($pdo, $tournamentId, $catId, 'single', [$pid], [$catId], $includeLetter);
            } elseif ($code === 'double_men' || $code === 'double_girls') {
                $dp = (int)$doublePartner['id'];
                bm_parent_add_entry($pdo, $tournamentId, $dp, $catId, $code);
                $doubleTeam = bm_parent_add_team($pdo, $tournamentId, $catId, $code, [$pid, $dp], [$catId], $includeLetter);
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
                $mixTeam = bm_parent_add_team($pdo, $tournamentId, $mixCat, 'mix_double', [$pid, $mp], $paired, $includeLetter);
            }
        }

        $receipt = bm_random_code(16);
        $pdo->prepare("INSERT INTO parent_submissions
            (tournament_id, receipt_code, age_category_id, primary_player_id, double_partner_id, mix_partner_id, event_codes, single_team_id, double_team_id, mix_team_id, father_name, contact_mobile, parent_code, payment_status, payment_amount, payment_utr, payment_date, payment_sender)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $tournamentId,
            $receipt,
            $catId,
            $pid,
            $doublePartner ? (int)$doublePartner['id'] : null,
            $mixPartner ? (int)$mixPartner['id'] : null,
            implode(',', $eventCodes),
            $singleTeam,
            $doubleTeam,
            $mixTeam,
            $father,
            $mobile,
            $parentCode,
            $payStatus,
            $amount,
            $utr,
            $payDate,
            $paySender,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['receipt' => $receipt, 'category' => $cat, 'parent_code' => $parentCode];
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

function bm_normalize_mobile(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 12 && str_starts_with($d, '91')) {
        $d = substr($d, 2);
    }
    if (!preg_match('/^[6-9][0-9]{9}$/', $d)) {
        throw new RuntimeException('Enter a valid 10-digit mobile number.');
    }
    return $d;
}

function bm_validate_utr(string $raw): string
{
    $utr = trim($raw);
    if ($utr === '') {
        throw new RuntimeException('UTR / reference number is required.');
    }
    if (preg_match('/[.,\s]|[^0-9]/', $utr) || !preg_match('/^[0-9]{8,18}$/', $utr)) {
        throw new RuntimeException('UTR / reference number must be digits only. Dots, commas, spaces or words at the start, middle or end are not accepted.');
    }
    return $utr;
}

function bm_validate_sender_name(string $raw): string
{
    $name = trim($raw);
    if ($name === '' || mb_strlen($name) < 2) {
        throw new RuntimeException('Sender name is required.');
    }
    return $name;
}

function bm_assert_unique_utr(PDO $pdo, string $utr, int $exceptId = 0): void
{
    $utr = trim($utr);
    if ($utr === '') {
        return;
    }
    $st = $pdo->prepare("SELECT id FROM parent_submissions WHERE payment_utr = ? AND id != ? AND payment_status IN ('review','pending','paid') LIMIT 1");
    $st->execute([$utr, $exceptId]);
    if ($st->fetch()) {
        throw new RuntimeException('This UTR / reference number was already used. Entry not saved.');
    }
}

function bm_decline_reasons(): array
{
    return [
        'utr_mismatch' => 'Reference no mismatch',
        'date_mismatch' => 'Date mismatch',
        'sender_mismatch' => 'Sender name mismatch',
        'amount_mismatch' => 'Amount mismatch',
    ];
}

function bm_decline_reason_label(?string $reason): string
{
    $map = bm_decline_reasons();
    $reason = (string)$reason;
    return $map[$reason] ?? ($reason !== '' ? $reason : '—');
}

function bm_payment_on_letter(?string $status): bool
{
    return in_array((string)$status, ['paid', 'pending'], true);
}

/** True only when two UTRs are the same digits. Nearby numbers are different payments. */
function bm_utr_too_similar(string $a, string $b): bool
{
    $a = preg_replace('/\D+/', '', $a) ?? '';
    $b = preg_replace('/\D+/', '', $b) ?? '';
    return $a !== '' && $a === $b;
}

function bm_upload_sha256(array $file): string
{
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new RuntimeException('Upload the payment screenshot.');
    }
    $hash = hash_file('sha256', $tmp);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Could not read the payment screenshot.');
    }
    return $hash;
}

/**
 * @return array{0:string,1:string,2:string} filename, sha256, perceptual fingerprint
 */
function bm_ingest_payment_screenshot(array $post, array $files): array
{
    $b64 = trim((string)($post['payment_screenshot_b64'] ?? ''));
    $bin = null;
    if ($b64 !== '') {
        if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $b64)) {
            throw new RuntimeException('Payment screenshot is not a valid image.');
        }
        $raw = base64_decode(substr($b64, (int)strpos($b64, ',') + 1), true);
        if (!is_string($raw) || strlen($raw) < 80) {
            throw new RuntimeException('Upload the payment screenshot.');
        }
        if (strlen($raw) > 2_500_000) {
            throw new RuntimeException('Payment screenshot is too large. Crop and try again.');
        }
        $bin = $raw;
    } else {
        $file = $files['payment_screenshot'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Upload the payment screenshot.');
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Upload the payment screenshot.');
        }
        $bin = (string)file_get_contents($tmp);
    }
    $bin = bm_compress_image_bytes($bin);
    $hash = hash('sha256', $bin);
    $phash = bm_screenshot_fingerprint($bin);
    $name = 'pay_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.jpg';
    $dest = BM_UPLOAD_DIR . '/' . $name;
    if (file_put_contents($dest, $bin) === false) {
        throw new RuntimeException('Could not save the payment screenshot.');
    }
    return [$name, $hash, $phash];
}

function bm_compress_image_bytes(string $bin): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return $bin;
    }
    $im = @imagecreatefromstring($bin);
    if (!$im) {
        throw new RuntimeException('Payment screenshot must be a JPG, PNG or WEBP photo.');
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $max = 1400;
    if ($w > $max || $h > $max) {
        $scale = $max / max($w, $h);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $dst;
    }
    $quality = 78;
    $out = $bin;
    for ($i = 0; $i < 6; $i++) {
        ob_start();
        imagejpeg($im, null, $quality);
        $out = (string)ob_get_clean();
        if (strlen($out) <= 180000 || $quality <= 45) {
            break;
        }
        $quality -= 8;
    }
    imagedestroy($im);
    return $out !== '' ? $out : $bin;
}

function bm_gd_gray_at($im, int $x, int $y): int
{
    $rgb = imagecolorat($im, $x, $y);
    $r = ($rgb >> 16) & 255;
    $g = ($rgb >> 8) & 255;
    $b = $rgb & 255;
    return (int)round(0.299 * $r + 0.587 * $g + 0.114 * $b);
}

function bm_gd_row_is_border($im, int $y, int $w, int $ref, int $tol): bool
{
    $hit = 0;
    $step = max(1, (int)floor($w / 24));
    $n = 0;
    for ($x = 0; $x < $w; $x += $step) {
        $n++;
        if (abs(bm_gd_gray_at($im, $x, $y) - $ref) <= $tol) {
            $hit++;
        }
    }
    return $n > 0 && ($hit / $n) >= 0.88;
}

function bm_gd_col_is_border($im, int $x, int $h, int $ref, int $tol): bool
{
    $hit = 0;
    $step = max(1, (int)floor($h / 24));
    $n = 0;
    for ($y = 0; $y < $h; $y += $step) {
        $n++;
        if (abs(bm_gd_gray_at($im, $x, $y) - $ref) <= $tol) {
            $hit++;
        }
    }
    return $n > 0 && ($hit / $n) >= 0.88;
}

function bm_gd_trim_borders($im)
{
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 16 || $h < 16) {
        return $im;
    }
    $ref = bm_gd_gray_at($im, 1, 1);
    $tol = 24;
    $top = 0;
    $bottom = $h - 1;
    $left = 0;
    $right = $w - 1;
    while ($top < (int)($h * 0.22) && bm_gd_row_is_border($im, $top, $w, $ref, $tol)) {
        $top++;
    }
    while ($bottom > (int)($h * 0.78) && bm_gd_row_is_border($im, $bottom, $w, $ref, $tol)) {
        $bottom--;
    }
    while ($left < (int)($w * 0.22) && bm_gd_col_is_border($im, $left, $h, $ref, $tol)) {
        $left++;
    }
    while ($right > (int)($w * 0.78) && bm_gd_col_is_border($im, $right, $h, $ref, $tol)) {
        $right--;
    }
    $nw = $right - $left + 1;
    $nh = $bottom - $top + 1;
    if ($nw < 16 || $nh < 16 || ($nw === $w && $nh === $h)) {
        return $im;
    }
    $crop = imagecreatetruecolor($nw, $nh);
    imagecopy($crop, $im, 0, 0, $left, $top, $nw, $nh);
    imagedestroy($im);
    return $crop;
}

function bm_gd_center_crop($im, float $ratio)
{
    $w = imagesx($im);
    $h = imagesy($im);
    $cw = max(8, (int)round($w * $ratio));
    $ch = max(8, (int)round($h * $ratio));
    $x = (int)max(0, floor(($w - $cw) / 2));
    $y = (int)max(0, floor(($h - $ch) / 2));
    $crop = imagecreatetruecolor($cw, $ch);
    imagecopy($crop, $im, 0, 0, $x, $y, $cw, $ch);
    return $crop;
}

function bm_bits_to_hex(string $bits): string
{
    $hex = '';
    foreach (str_split($bits, 4) as $nibble) {
        $hex .= dechex(bindec(str_pad($nibble, 4, '0')));
    }
    return $hex;
}

function bm_image_dhash($im, int $blocks = 8): string
{
    $sw = $blocks + 1;
    $sh = $blocks;
    $small = imagecreatetruecolor($sw, $sh);
    imagecopyresampled($small, $im, 0, 0, 0, 0, $sw, $sh, imagesx($im), imagesy($im));
    $bits = '';
    for ($y = 0; $y < $sh; $y++) {
        for ($x = 0; $x < $blocks; $x++) {
            $bits .= bm_gd_gray_at($small, $x, $y) < bm_gd_gray_at($small, $x + 1, $y) ? '1' : '0';
        }
    }
    imagedestroy($small);
    return bm_bits_to_hex($bits);
}

function bm_image_ahash($im, int $blocks = 8): string
{
    $small = imagecreatetruecolor($blocks, $blocks);
    imagecopyresampled($small, $im, 0, 0, 0, 0, $blocks, $blocks, imagesx($im), imagesy($im));
    $vals = [];
    $sum = 0;
    for ($y = 0; $y < $blocks; $y++) {
        for ($x = 0; $x < $blocks; $x++) {
            $g = bm_gd_gray_at($small, $x, $y);
            $vals[] = $g;
            $sum += $g;
        }
    }
    imagedestroy($small);
    $avg = $sum / max(1, count($vals));
    $bits = '';
    foreach ($vals as $g) {
        $bits .= $g >= $avg ? '1' : '0';
    }
    return bm_bits_to_hex($bits);
}

function bm_image_band_dhash($im, int $bands = 4): string
{
    $w = imagesx($im);
    $h = imagesy($im);
    $out = '';
    $bh = max(8, (int)floor($h / $bands));
    for ($i = 0; $i < $bands; $i++) {
        $y = min($h - $bh, $i * $bh);
        $band = imagecreatetruecolor($w, $bh);
        imagecopy($band, $im, 0, 0, 0, $y, $w, $bh);
        $out .= bm_image_dhash($band, 8);
        imagedestroy($band);
    }
    return $out;
}

function bm_image_window_dhashes($im): string
{
    $specs = [
        [0.00, 0.00, 1.00, 1.00],
        [0.00, 0.00, 1.00, 0.84],
        [0.00, 0.00, 1.00, 0.76],
        [0.00, 0.00, 1.00, 0.68],
        [0.00, 0.00, 1.00, 0.58],
        [0.00, 0.15, 1.00, 1.00],
        [0.00, 0.15, 1.00, 0.82],
        [0.08, 0.16, 0.92, 0.80],
        [0.00, 0.22, 1.00, 0.90],
    ];
    $w = imagesx($im);
    $h = imagesy($im);
    $out = '';
    foreach ($specs as [$x0, $y0, $x1, $y1]) {
        $sx = (int)max(0, floor($w * $x0));
        $sy = (int)max(0, floor($h * $y0));
        $sw = max(8, (int)floor($w * ($x1 - $x0)));
        $sh = max(8, (int)floor($h * ($y1 - $y0)));
        if ($sx + $sw > $w) {
            $sx = max(0, $w - $sw);
        }
        if ($sy + $sh > $h) {
            $sy = max(0, $h - $sh);
        }
        $slice = imagecreatetruecolor($sw, $sh);
        imagecopy($slice, $im, 0, 0, $sx, $sy, $sw, $sh);
        $out .= bm_image_dhash($slice, 8);
        imagedestroy($slice);
    }
    return $out;
}

function bm_image_tile_dhashes($im, int $nx = 4, int $ny = 4): string
{
    $w = imagesx($im);
    $h = imagesy($im);
    $tw = max(8, (int)floor($w / $nx));
    $th = max(8, (int)floor($h / $ny));
    $out = '';
    for ($ty = 0; $ty < $ny; $ty++) {
        for ($tx = 0; $tx < $nx; $tx++) {
            $x = min($w - $tw, $tx * $tw);
            $y = min($h - $th, $ty * $th);
            $tile = imagecreatetruecolor($tw, $th);
            imagecopy($tile, $im, 0, 0, $x, $y, $tw, $th);
            $out .= bm_image_dhash($tile, 8);
            imagedestroy($tile);
        }
    }
    return $out;
}

function bm_screenshot_fingerprint(string $bin): string
{
    if (!function_exists('imagecreatefromstring')) {
        return '';
    }
    $im = @imagecreatefromstring($bin);
    if (!$im) {
        return '';
    }
    imagefilter($im, IMG_FILTER_GRAYSCALE);
    $im = bm_gd_trim_borders($im);
    $center = bm_gd_center_crop($im, 0.72);
    $inner = bm_gd_center_crop($im, 0.86);
    $fp = [
        'd' => bm_image_dhash($im),
        'c' => bm_image_dhash($center),
        'm' => bm_image_dhash($inner),
        'a' => bm_image_ahash($im),
        'b' => bm_image_band_dhash($im, 4),
        'w' => bm_image_window_dhashes($im),
        't' => bm_image_tile_dhashes($im),
        'u' => implode(',', bm_ocr_digit_strings($bin)),
    ];
    imagedestroy($center);
    imagedestroy($inner);
    imagedestroy($im);
    $json = json_encode($fp);
    return is_string($json) ? $json : '';
}

/** @return list<string> */
function bm_phash_values(string $packed): array
{
    $packed = trim($packed);
    if ($packed === '') {
        return [];
    }
    $dec = json_decode($packed, true);
    if (!is_array($dec)) {
        return [$packed];
    }
    $out = [];
    foreach (['d', 'c', 'm', 'a', 'b'] as $k) {
        $v = strtolower(preg_replace('/[^0-9a-f]/', '', (string)($dec[$k] ?? '')) ?? '');
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

function bm_fp_decode(string $packed): array
{
    $dec = json_decode(trim($packed), true);
    return is_array($dec) ? $dec : [];
}

function bm_ocr_digit_strings(string $bin): array
{
    if ($bin === '' || !function_exists('exec')) {
        return [];
    }
    $tess = '';
    foreach (['/usr/bin/tesseract', '/usr/local/bin/tesseract'] as $p) {
        if (is_executable($p)) {
            $tess = $p;
            break;
        }
    }
    if ($tess === '') {
        return [];
    }
    $prefix = tempnam(sys_get_temp_dir(), 'bmocr');
    if ($prefix === false) {
        return [];
    }
    $img = $prefix . '.jpg';
    @unlink($prefix);
    if (file_put_contents($img, $bin) === false) {
        return [];
    }
    $cmd = escapeshellcmd($tess) . ' ' . escapeshellarg($img) . ' ' . escapeshellarg($prefix)
        . ' -l eng --psm 6 digits';
    @exec($cmd . ' 2>/dev/null');
    $read = $prefix . '.txt';
    $out = [];
    if (is_file($read)) {
        $text = (string)file_get_contents($read);
        if (preg_match_all('/[0-9]{8,18}/', $text, $m)) {
            $out = array_values(array_unique($m[0]));
        }
        @unlink($read);
    }
    @unlink($img);
    return $out;
}

function bm_fp_utrs(string $packed): array
{
    $u = trim((string)(bm_fp_decode($packed)['u'] ?? ''));
    if ($u === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $u) as $part) {
        $d = preg_replace('/\D+/', '', $part) ?? '';
        if (strlen($d) >= 8) {
            $out[] = $d;
        }
    }
    return $out;
}

function bm_hamming_hex(string $a, string $b): int
{
    $n = min(strlen($a), strlen($b));
    if ($n === 0) {
        return 999;
    }
    $d = 0;
    for ($i = 0; $i < $n; $i++) {
        $x = hexdec($a[$i]) ^ hexdec($b[$i]);
        $d += substr_count(decbin($x), '1');
    }
    $d += (int)(abs(strlen($a) - strlen($b)) * 4);
    return $d;
}

function bm_windows_too_similar(string $wa, string $wb): bool
{
    $wa = strtolower(preg_replace('/[^0-9a-f]/', '', $wa) ?? '');
    $wb = strtolower(preg_replace('/[^0-9a-f]/', '', $wb) ?? '');
    if (strlen($wa) < 16 || strlen($wb) < 16) {
        return false;
    }
    $as = str_split($wa, 16);
    $bs = str_split($wb, 16);
    foreach ($as as $ha) {
        if (strlen($ha) < 16) {
            continue;
        }
        foreach ($bs as $hb) {
            if (strlen($hb) < 16) {
                continue;
            }
            if (bm_hamming_hex($ha, $hb) <= 8) {
                return true;
            }
        }
    }
    return false;
}

function bm_tiles_too_similar(string $ta, string $tb): bool
{
    $ta = strtolower(preg_replace('/[^0-9a-f]/', '', $ta) ?? '');
    $tb = strtolower(preg_replace('/[^0-9a-f]/', '', $tb) ?? '');
    $size = 16;
    if (strlen($ta) < $size * 8 || strlen($tb) < $size * 8) {
        return false;
    }
    $na = (int)floor(strlen($ta) / $size);
    $nb = (int)floor(strlen($tb) / $size);
    $n = min($na, $nb, 16);
    $hits = 0;
    $inner = 0;
    $innerIdx = [5, 6, 9, 10];
    for ($i = 0; $i < $n; $i++) {
        $d = bm_hamming_hex(substr($ta, $i * $size, $size), substr($tb, $i * $size, $size));
        if ($d <= 10) {
            $hits++;
            if (in_array($i, $innerIdx, true)) {
                $inner++;
            }
        }
    }
    return ($hits >= 7 && $inner >= 2) || $inner >= 3;
}

function bm_screenshot_too_similar(string $fpA, string $fpB): bool
{
    $A = bm_phash_values($fpA);
    $B = bm_phash_values($fpB);
    $close = 0;
    $best = 999;
    foreach ($A as $ha) {
        foreach ($B as $hb) {
            $len = min(strlen($ha), strlen($hb));
            if ($len < 8) {
                continue;
            }
            $ha2 = substr($ha, 0, $len);
            $hb2 = substr($hb, 0, $len);
            $bits = $len * 4;
            $d = bm_hamming_hex($ha2, $hb2);
            $best = min($best, $d);
            $limit = $bits >= 200 ? 28 : ($bits >= 64 ? 12 : 8);
            if ($d <= $limit) {
                $close++;
            }
            if ($d <= 6) {
                return true;
            }
        }
    }
    if ($close >= 2 || $best <= 10) {
        return true;
    }
    $fa = bm_fp_decode($fpA);
    $fb = bm_fp_decode($fpB);
    if (bm_windows_too_similar((string)($fa['w'] ?? ''), (string)($fb['w'] ?? ''))) {
        return true;
    }
    if (bm_tiles_too_similar((string)($fa['t'] ?? ''), (string)($fb['t'] ?? ''))) {
        return true;
    }
    foreach (bm_fp_utrs($fpA) as $ua) {
        foreach (bm_fp_utrs($fpB) as $ub) {
            if (bm_utr_too_similar($ua, $ub)) {
                return true;
            }
        }
    }
    return false;
}

function bm_parent_shot_fingerprint(PDO $pdo, array $row): string
{
    $have = trim((string)($row['payment_screenshot_phash'] ?? ''));
    if ($have !== '') {
        return $have;
    }
    $file = basename((string)($row['payment_screenshot'] ?? ''));
    if ($file === '' || !preg_match('/^pay_[A-Za-z0-9._-]+$/', $file)) {
        return '';
    }
    $path = BM_UPLOAD_DIR . '/' . $file;
    if (!is_file($path)) {
        return '';
    }
    $bin = (string)file_get_contents($path);
    if ($bin === '') {
        return '';
    }
    $fp = bm_screenshot_fingerprint($bin);
    if ($fp !== '' && bm_column_exists($pdo, 'parent_submissions', 'payment_screenshot_phash')) {
        $pdo->prepare('UPDATE parent_submissions SET payment_screenshot_phash=? WHERE id=?')
            ->execute([$fp, (int)$row['id']]);
    }
    return $fp;
}

function bm_assert_unique_payment(PDO $pdo, string $utr, string $hash, int $exceptId = 0, string $phash = ''): void
{
    $utr = trim($utr);
    $hash = trim($hash);
    $phash = trim($phash);
    $newSeen = $phash !== '' ? bm_fp_utrs($phash) : [];
    $st = $pdo->prepare('SELECT id, payment_utr, payment_screenshot, payment_screenshot_hash, payment_screenshot_phash FROM parent_submissions WHERE id != ?');
    $st->execute([$exceptId]);
    foreach ($st->fetchAll() as $row) {
        $oldUtr = trim((string)($row['payment_utr'] ?? ''));
        if ($utr !== '' && $oldUtr !== '' && bm_utr_too_similar($utr, $oldUtr)) {
            throw new RuntimeException('This UTR / reference number was already used, or is only a small change from one already used. Entry not saved.');
        }
        $oldHash = trim((string)($row['payment_screenshot_hash'] ?? ''));
        if ($hash !== '' && $oldHash !== '' && hash_equals($oldHash, $hash)) {
            throw new RuntimeException('This payment screenshot was already used. Entry not saved.');
        }
        $oldFp = '';
        if ($phash !== '' || $utr !== '' || $newSeen) {
            $oldFp = bm_parent_shot_fingerprint($pdo, $row);
        }
        $oldSeen = $oldFp !== '' ? bm_fp_utrs($oldFp) : [];
        if ($utr !== '') {
            foreach ($oldSeen as $seen) {
                if (bm_utr_too_similar($utr, $seen)) {
                    throw new RuntimeException('This UTR / reference number already appears on a saved payment screenshot. Entry not saved.');
                }
            }
        }
        if ($oldUtr !== '') {
            foreach ($newSeen as $seen) {
                if (bm_utr_too_similar($oldUtr, $seen)) {
                    throw new RuntimeException('This payment screenshot shows a UTR / reference number that was already used. Entry not saved.');
                }
            }
        }
        if ($phash !== '' && $oldFp !== '' && bm_screenshot_too_similar($phash, $oldFp)) {
            throw new RuntimeException('This payment screenshot was already used — even if the UTR is cropped out, resized, or photographed from another phone. Entry not saved.');
        }
    }
}

function bm_parent_ctx_key(string $publicToken): string
{
    return 'bm_pc_' . substr(hash('sha256', $publicToken), 0, 16);
}

function bm_parent_code_from_post_or_session(string $publicToken, string $posted = ''): string
{
    $posted = trim($posted);
    if ($posted !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $posted)) {
        bm_remember_parent_code($publicToken, $posted);
        return $posted;
    }
    $have = bm_own_parent_code($publicToken);
    if ($have !== '') {
        return $have;
    }
    $code = bm_random_code(16);
    bm_remember_parent_code($publicToken, $code);
    return $code;
}

function bm_remember_parent_code(string $publicToken, string $code): void
{
    bm_boot_session();
    $_SESSION['bm_parent_code'][$publicToken] = $code;
    $cookie = bm_parent_ctx_key($publicToken);
    if (!headers_sent()) {
        setcookie($cookie, $code, [
            'expires' => time() + 60 * 60 * 24 * 120,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function bm_own_parent_code(string $publicToken): string
{
    bm_boot_session();
    $code = trim((string)($_SESSION['bm_parent_code'][$publicToken] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $cookie = trim((string)($_COOKIE[bm_parent_ctx_key($publicToken)] ?? ''));
    if ($cookie !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $cookie)) {
        $_SESSION['bm_parent_code'][$publicToken] = $cookie;
        return $cookie;
    }
    return '';
}

/** @return list<array<string,mixed>> */
function bm_parent_declined_for_code(PDO $pdo, int $tournamentId, string $parentCode): array
{
    $all = bm_parent_submissions_for_code($pdo, $tournamentId, $parentCode);
    $out = [];
    foreach ($all as $row) {
        if ((string)($row['payment_status'] ?? '') === 'declined') {
            $out[] = $row;
        }
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function bm_parent_submissions_for_code(PDO $pdo, int $tournamentId, string $parentCode): array
{
    $parentCode = trim($parentCode);
    if ($parentCode === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $parentCode)) {
        return [];
    }
    $st = $pdo->prepare('SELECT receipt_code FROM parent_submissions WHERE tournament_id=? AND parent_code=? ORDER BY id');
    $st->execute([$tournamentId, $parentCode]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $loaded = bm_parent_load_receipt($pdo, (string)$row['receipt_code'], $tournamentId);
        if ($loaded) {
            $out[] = $loaded;
        }
    }
    return $out;
}

function bm_parent_owns_receipt(PDO $pdo, array $tournament, string $receipt, string $parentCode): bool
{
    $row = bm_parent_load_receipt($pdo, $receipt, (int)$tournament['id']);
    if (!$row) {
        return false;
    }
    return hash_equals(trim((string)($row['parent_code'] ?? '')), trim($parentCode));
}

function bm_parent_set_letter_flag(PDO $pdo, array $sub, bool $on): void
{
    foreach (bm_parent_submission_team_ids($pdo, $sub) as $teamId) {
        $pdo->prepare("UPDATE tournament_teams SET include_in_letter=?, updated_at=datetime('now','localtime') WHERE id=?")
            ->execute([$on ? 1 : 0, $teamId]);
    }
}

function bm_parent_confirm_paid(PDO $pdo, array $sub): void
{
    $pdo->prepare("UPDATE parent_submissions SET payment_status='paid', decline_reason=NULL WHERE id=?")->execute([(int)$sub['id']]);
    bm_parent_set_letter_flag($pdo, $sub, true);
}

function bm_parent_accept_pending_cash(PDO $pdo, array $sub): void
{
    $pdo->prepare("UPDATE parent_submissions SET payment_status='pending', decline_reason=NULL WHERE id=?")->execute([(int)$sub['id']]);
    bm_parent_set_letter_flag($pdo, $sub, true);
}

function bm_parent_decline_payment(PDO $pdo, array $sub, string $reason): void
{
    if (!isset(bm_decline_reasons()[$reason])) {
        throw new RuntimeException('Choose date mismatch, reference no mismatch or sender name mismatch.');
    }
    $pdo->prepare("UPDATE parent_submissions SET payment_status='declined', decline_reason=? WHERE id=?")->execute([$reason, (int)$sub['id']]);
    bm_parent_set_letter_flag($pdo, $sub, false);
}

function bm_parent_mark_cash_received(PDO $pdo, array $sub): void
{
    $pdo->prepare("UPDATE parent_submissions SET payment_status='paid', decline_reason=NULL WHERE id=?")->execute([(int)$sub['id']]);
    bm_parent_set_letter_flag($pdo, $sub, true);
}

function bm_parent_resubmit_payment(PDO $pdo, array $sub, string $dateYmd, string $utr, string $sender): void
{
    bm_assert_unique_utr($pdo, $utr, (int)$sub['id']);
    $pdo->prepare("UPDATE parent_submissions SET payment_status='review', decline_reason=NULL, payment_date=?, payment_utr=?, payment_sender=? WHERE id=?")
        ->execute([$dateYmd, $utr, $sender, (int)$sub['id']]);
    bm_parent_set_letter_flag($pdo, $sub, false);
}

/**
 * @return array<int, array<string,mixed>>
 */
function bm_tournament_parent_meta_by_player(PDO $pdo, int $tournamentId): array
{
    $st = $pdo->prepare('SELECT * FROM parent_submissions WHERE tournament_id=? ORDER BY id');
    $st->execute([$tournamentId]);
    $map = [];
    foreach ($st->fetchAll() as $row) {
        foreach (['primary_player_id', 'double_partner_id', 'mix_partner_id'] as $col) {
            $pid = (int)($row[$col] ?? 0);
            if ($pid > 0 && !isset($map[$pid])) {
                $map[$pid] = $row;
            }
        }
    }
    return $map;
}

function bm_letter_payment_cell(array $meta): string
{
    if ((string)($meta['payment_status'] ?? '') === 'pending') {
        return 'Pending';
    }
    return trim((string)($meta['payment_utr'] ?? ''));
}

function bm_parent_payment_label(?string $status, int $amount): string
{
    $rupee = $amount > 0 ? (' ₹' . $amount) : '';
    if ($status === 'review') {
        return 'Awaiting check' . $rupee;
    }
    if ($status === 'declined') {
        return 'Declined' . $rupee;
    }
    if ($status === 'pending') {
        return 'Pending' . $rupee;
    }
    if ($status === 'paid') {
        return 'Paid' . $rupee;
    }
    return $amount > 0 ? ('₹' . $amount) : 'Free / no fee';
}

/** @return array<string, string> */
function bm_parent_confirm_messages(): array
{
    return [
        'accept_paid' => 'Accept this entry? Date, UTR and sender name match the bank statement?',
        'accept_pending' => 'Accept this entry without payment? The player will be on the list. Cash can be collected later on the tournament day.',
        'mark_cash' => 'Mark cash received and change this payment to Paid?',
        'date_mismatch' => 'Decline this entry because the date of payment does not match the bank statement?',
        'utr_mismatch' => 'Decline this entry because the reference / UTR does not match the bank statement?',
        'sender_mismatch' => 'Decline this entry because the sender name does not match the bank statement?',
        'amount_mismatch' => 'Decline this entry because the amount does not match the bank statement?',
    ];
}

function bm_parent_confirm_attr(string $key): string
{
    $map = bm_parent_confirm_messages();
    return bm_confirm_attr($map[$key] ?? 'Are you sure?');
}

/**
 * @param list<string> $statuses
 * @return list<array<string,mixed>>
 */
function bm_parent_list_for_browse(PDO $pdo, int $tournamentId, array $statuses, string $q = ''): array
{
    if ($statuses === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "
        SELECT s.id, s.receipt_code, s.event_codes, s.payment_status, s.payment_amount,
               s.payment_utr, s.payment_date, s.payment_sender, s.decline_reason, s.father_name, s.contact_mobile,
               c.id AS category_id, c.name AS category_name, c.sort_order,
               p.full_name, p.gender, p.bai_id, p.pbi_id,
               p.father_name AS player_father, p.mobile, p.address,
               dp.full_name AS d_full_name, dp.gender AS d_gender, dp.bai_id AS d_bai_id, dp.pbi_id AS d_pbi_id,
               dp.father_name AS d_father_name, dp.mobile AS d_mobile, dp.address AS d_address,
               mp.full_name AS m_full_name, mp.gender AS m_gender, mp.bai_id AS m_bai_id, mp.pbi_id AS m_pbi_id,
               mp.father_name AS m_father_name, mp.mobile AS m_mobile, mp.address AS m_address
        FROM parent_submissions s
        JOIN age_categories c ON c.id = s.age_category_id
        JOIN players p ON p.id = s.primary_player_id
        LEFT JOIN players dp ON dp.id = s.double_partner_id
        LEFT JOIN players mp ON mp.id = s.mix_partner_id
        WHERE s.tournament_id = ?
          AND s.payment_status IN ($in)
    ";
    $params = array_merge([$tournamentId], array_values($statuses));
    $q = trim($q);
    if ($q !== '') {
        $sql .= " AND (
            p.full_name LIKE ? OR IFNULL(dp.full_name,'') LIKE ? OR IFNULL(mp.full_name,'') LIKE ?
        )";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY c.sort_order, c.name COLLATE NOCASE, p.full_name COLLATE NOCASE, s.id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array<string, list<array<string,mixed>>>
 */
function bm_parent_group_by_category(array $rows): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['category_name'] ?? '')) !== '' ? (string)$row['category_name'] : 'Age';
        $grouped[$name][] = $row;
    }
    return $grouped;
}

function bm_parent_row_event_labels(array $row): string
{
    $labs = [];
    foreach (explode(',', (string)($row['event_codes'] ?? '')) as $code) {
        $code = trim($code);
        if ($code !== '') {
            $labs[] = bm_event_label($code);
        }
    }
    return implode(', ', $labs);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function bm_parent_filter_by_category_id(array $rows, int $categoryId): array
{
    if ($categoryId <= 0) {
        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        if ((int)($row['category_id'] ?? 0) === $categoryId) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 */
function bm_parent_output_confirmed_csv(array $tournament, array $rows, string $label = 'all'): void
{
    bm_parent_output_browse_csv($tournament, $rows, $label, 'confirmed');
}

/**
 * @param list<array<string,mixed>> $rows
 */
function bm_parent_output_declined_csv(array $tournament, array $rows, string $label = 'all'): void
{
    bm_parent_output_browse_csv($tournament, $rows, $label, 'declined');
}

/** @return list<string> */
function bm_parent_row_event_codes(array $row): array
{
    $codes = [];
    foreach (explode(',', (string)($row['event_codes'] ?? '')) as $code) {
        $code = trim($code);
        if ($code !== '') {
            $codes[] = $code;
        }
    }
    return $codes !== [] ? $codes : [''];
}

function bm_event_sort_index(string $code): int
{
    $order = ['single' => 0, 'double_men' => 1, 'double_girls' => 2, 'mix_double' => 3];
    return $order[$code] ?? 9;
}

/**
 * One Excel row per age category + event, so U11 Boys then a gap then U11 Boys Doubles.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function bm_parent_expand_rows_for_excel(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        foreach (bm_parent_row_event_codes($row) as $code) {
            $copy = $row;
            $copy['_excel_event'] = $code;
            $copy['_excel_group'] = trim((string)($row['category_name'] ?? 'Age') . '|' . $code);
            $out[] = $copy;
        }
    }
    usort($out, static function (array $a, array $b): int {
        $sa = (int)($a['sort_order'] ?? 0);
        $sb = (int)($b['sort_order'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        $ca = strcasecmp((string)($a['category_name'] ?? ''), (string)($b['category_name'] ?? ''));
        if ($ca !== 0) {
            return $ca;
        }
        $ea = bm_event_sort_index((string)($a['_excel_event'] ?? ''));
        $eb = bm_event_sort_index((string)($b['_excel_event'] ?? ''));
        if ($ea !== $eb) {
            return $ea <=> $eb;
        }
        return strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
    });
    return $out;
}

/**
 * @param resource $out
 * @param list<array<string,mixed>> $rows
 * @param callable(array<string,mixed>): list<mixed> $mapRow
 */
function bm_csv_write_with_group_gaps($out, array $rows, callable $mapRow, string $groupKey = '_excel_group'): void
{
    $prev = null;
    $started = false;
    foreach ($rows as $row) {
        $key = (string)($row[$groupKey] ?? '');
        if ($started && $key !== $prev) {
            fputcsv($out, []);
        }
        fputcsv($out, $mapRow($row));
        $prev = $key;
        $started = true;
    }
}

/**
 * @param list<array<string,mixed>> $rows
 * @param 'confirmed'|'declined' $mode
 */
function bm_parent_output_browse_csv(array $tournament, array $rows, string $label, string $mode): void
{
    $isDeclined = $mode === 'declined';
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $label) ?: 'all';
    $file = ($isDeclined ? 'declined_' : 'confirmed_') . $safe . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [(string)($tournament['name'] ?? 'Tournament'), $isDeclined ? 'Declined entries' : 'Confirmed entries']);
    $head = ['Category', 'Player', 'Gender', 'BAI ID', 'PBI ID', 'Father', 'Contact', 'District', 'Events'];
    if ($isDeclined) {
        $head = array_merge($head, ['Mismatch', 'Date', 'UTR', 'Sender', 'Amount', 'Doubles partner', 'Mix partner']);
    } else {
        $head = array_merge($head, ['Payment', 'UTR', 'Doubles partner', 'Mix partner']);
    }
    fputcsv($out, $head);
    $expanded = bm_parent_expand_rows_for_excel($rows);
    bm_csv_write_with_group_gaps($out, $expanded, static function (array $r) use ($isDeclined): array {
        $eventCode = (string)($r['_excel_event'] ?? '');
        $eventLabel = $eventCode !== '' ? bm_event_label($eventCode) : bm_parent_row_event_labels($r);
        $base = [
            (string)($r['category_name'] ?? ''),
            (string)($r['full_name'] ?? ''),
            bm_gender_label((string)($r['gender'] ?? '')),
            (string)($r['bai_id'] ?? ''),
            (string)($r['pbi_id'] ?? ''),
            (string)(($r['player_father'] ?? '') !== '' ? $r['player_father'] : ($r['father_name'] ?? '')),
            (string)(($r['mobile'] ?? '') !== '' ? $r['mobile'] : ($r['contact_mobile'] ?? '')),
            (string)($r['address'] ?? ''),
            $eventLabel,
        ];
        $dName = in_array($eventCode, ['double_men', 'double_girls', ''], true) ? (string)($r['d_full_name'] ?? '') : '';
        $mName = in_array($eventCode, ['mix_double', ''], true) ? (string)($r['m_full_name'] ?? '') : '';
        if ($isDeclined) {
            $payDate = trim((string)($r['payment_date'] ?? ''));
            return array_merge($base, [
                bm_decline_reason_label((string)($r['decline_reason'] ?? '')),
                $payDate !== '' ? $payDate : '',
                (string)($r['payment_utr'] ?? ''),
                (string)($r['payment_sender'] ?? ''),
                (string)((int)($r['payment_amount'] ?? 0)),
                $dName,
                $mName,
            ]);
        }
        return array_merge($base, [
            bm_parent_payment_label((string)($r['payment_status'] ?? ''), (int)($r['payment_amount'] ?? 0)),
            (string)($r['payment_utr'] ?? ''),
            $dName,
            $mName,
        ]);
    });
    fclose($out);
    exit;
}

/**
 * @param list<array<string,mixed>> $rows
 */
function bm_parent_output_unpaid_csv(array $tournament, array $rows, string $label = 'all'): void
{
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $label) ?: 'all';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unpaid_' . $safe . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [(string)($tournament['name'] ?? 'Tournament'), 'Not paid online']);
    fputcsv($out, ['Category', 'Player', 'Events', 'Amount']);
    $grouped = [];
    foreach ($rows as $r) {
        $copy = $r;
        $copy['_excel_group'] = (string)($r['category_name'] ?? 'Age');
        $grouped[] = $copy;
    }
    bm_csv_write_with_group_gaps($out, $grouped, static function (array $r): array {
        return [
            (string)($r['category_name'] ?? ''),
            (string)($r['full_name'] ?? ''),
            bm_parent_row_event_labels($r),
            (string)((int)($r['payment_amount'] ?? 0)),
        ];
    });
    fclose($out);
    exit;
}

function bm_parent_confirmed_note(array $tournament): string
{
    if (bm_tournament_is_paid($tournament)) {
        return 'Download and carry the payment receipt on tournament day. Players confirmed without online payment must pay the fee and collect their receipt at the venue.';
    }
    return 'This list includes only the names of players whose entries have been confirmed. The participation fee is pending and must be paid on the day of the tournament.';
}

function bm_parent_choice_cards(string $token, string $parentCode = '', string $current = 'new'): void
{
    $t = rawurlencode($token);
    $p = $parentCode !== '' ? '&amp;p=' . rawurlencode($parentCode) : '';
    $tournament = bm_tournament_by_public_token(bm_db(), $token);
    $showDeclined = $tournament ? bm_tournament_is_paid($tournament) : true;
    $items = [
        'new' => [
            'href' => 'join.php?t=' . $t . '&amp;go=new',
            'title' => 'New Entry',
            'note' => 'Add a new player for this tournament. Fill name, age category, events and payment details.',
        ],
        'confirmed' => [
            'href' => 'join-confirmed.php?t=' . $t,
            'title' => 'Confirmed Entries',
            'note' => $tournament ? bm_parent_confirmed_note($tournament) : bm_parent_confirmed_note(['is_paid' => 1]),
        ],
    ];
    if ($showDeclined) {
        $items['declined'] = [
            'href' => 'join-declined.php?t=' . $t . $p,
            'title' => 'Declined Entries',
            'note' => 'Correct the details as per the rejection reason and resubmit.',
        ];
    }
    echo '<p class="hint no-print" style="margin-top:0;"><a href="open.php">All tournaments</a></p>';
    echo '<div class="choice-grid' . ($showDeclined ? '' : ' choice-2') . '">';
    foreach ($items as $key => $item) {
        $cls = 'choice-card' . ($current === $key ? ' is-current' : '');
        echo '<a class="' . $cls . '" href="' . $item['href'] . '">';
        echo '<strong>' . bm_h($item['title']) . '</strong>';
        echo '<span>' . bm_h($item['note']) . '</span>';
        echo '</a>';
    }
    echo '</div>';
}

function bm_parent_top_bar(string $token, string $parentCode = '', string $current = ''): void
{
    bm_parent_choice_cards($token, $parentCode, $current);
}

function bm_parent_event_bucket(string $code): ?string
{
    if ($code === 'single') {
        return 'single';
    }
    if ($code === 'double_men' || $code === 'double_girls') {
        return 'double';
    }
    if ($code === 'mix_double') {
        return 'mix';
    }
    return null;
}

/**
 * Declined entries are left out of totals. After a parent corrects and resubmits,
 * the same player counts again. If declined again, they drop out of the totals.
 *
 * @param list<array<string,mixed>> $rows
 * @return array<string, array{count:int, amount:int}>
 */
function bm_parent_tournament_stats(array $rows): array
{
    $z = ['count' => 0, 'amount' => 0];
    $stats = [
        'total' => $z,
        'single' => $z,
        'double' => $z,
        'mix' => $z,
        'confirmed' => $z,
        'pending' => $z,
        'unpaid' => $z,
    ];
    foreach ($rows as $r) {
        $st = (string)($r['payment_status'] ?? '');
        if ($st === 'declined') {
            continue;
        }
        $amt = (int)($r['payment_amount'] ?? 0);
        $stats['total']['count']++;
        $stats['total']['amount'] += $amt;
        if (in_array($st, ['paid', 'na'], true)) {
            $stats['confirmed']['count']++;
            $stats['confirmed']['amount'] += $amt;
        } elseif ($st === 'review') {
            $stats['pending']['count']++;
            $stats['pending']['amount'] += $amt;
        } elseif ($st === 'pending') {
            $stats['unpaid']['count']++;
            $stats['unpaid']['amount'] += $amt;
        }
        $buckets = [];
        foreach (bm_parent_row_event_codes($r) as $code) {
            $b = bm_parent_event_bucket($code);
            if ($b !== null) {
                $buckets[$b] = true;
            }
        }
        $keys = array_keys($buckets);
        $n = count($keys);
        if ($n === 0) {
            continue;
        }
        $left = $amt;
        foreach ($keys as $i => $b) {
            $share = ($i === $n - 1) ? $left : (int)floor($amt / $n);
            $left -= $share;
            $stats[$b]['count']++;
            $stats[$b]['amount'] += $share;
        }
    }
    return $stats;
}

function bm_parent_render_fee_summary(array $stats): void
{
    $cell = static function (array $s): string {
        return (int)$s['count'] . ' · ' . bm_rupees((int)$s['amount']);
    };
    echo '<section class="fee-summary">';
    echo '<div class="fee-card">';
    echo '<h3>Total entries received</h3>';
    echo '<div class="nums">' . (int)$stats['total']['count'] . ' <small>' . bm_h(bm_rupees((int)$stats['total']['amount'])) . '</small></div>';
    echo '<div class="fee-split">';
    echo '<div><span>Single</span><strong>' . bm_h($cell($stats['single'])) . '</strong></div>';
    echo '<div><span>Doubles</span><strong>' . bm_h($cell($stats['double'])) . '</strong></div>';
    echo '<div><span>Mix</span><strong>' . bm_h($cell($stats['mix'])) . '</strong></div>';
    echo '</div></div>';
    echo '<div class="fee-card">';
    echo '<h3>Confirmed entries</h3>';
    echo '<div class="nums">' . (int)$stats['confirmed']['count'] . ' <small>' . bm_h(bm_rupees((int)$stats['confirmed']['amount'])) . '</small></div>';
    echo '<p class="muted" style="margin:0.45rem 0 0;">Accepted with online payment, or free.</p>';
    echo '</div>';
    echo '<div class="fee-card">';
    echo '<h3>Pending entries</h3>';
    echo '<div class="nums">' . (int)$stats['pending']['count'] . ' <small>' . bm_h(bm_rupees((int)$stats['pending']['amount'])) . '</small></div>';
    echo '<p class="muted" style="margin:0.45rem 0 0;">Still waiting for a bank-statement check.</p>';
    echo '</div>';
    echo '<div class="fee-card">';
    echo '<h3>Not paid online</h3>';
    echo '<div class="nums">' . (int)$stats['unpaid']['count'] . ' <small>' . bm_h(bm_rupees((int)$stats['unpaid']['amount'])) . '</small></div>';
    echo '<p class="muted" style="margin:0.45rem 0 0;">Accepted without online payment. Collect on the day.</p>';
    echo '</div></section>';
}

function bm_admin_tournament_nav(int $tid, string $current = ''): void
{
    if ($tid <= 0) {
        return;
    }
    $backHref = $current === 'tournament' ? 'tournaments.php' : ('tournament.php?id=' . $tid);
    $backLabel = $current === 'tournament' ? 'Back to list' : 'Back';
    $items = [
        'tournament' => ['href' => 'tournament.php?id=' . $tid, 'label' => 'Tournament'],
        'confirmed' => ['href' => 'confirmed.php?id=' . $tid, 'label' => 'Confirmed'],
        'declined' => ['href' => 'declined.php?id=' . $tid, 'label' => 'Declined'],
        'unpaid' => ['href' => 'unpaid.php?id=' . $tid, 'label' => 'Not paid online'],
        'verify' => ['href' => 'parent-verify.php?tournament_id=' . $tid, 'label' => 'Verify'],
        'report' => ['href' => 'report.php?tournament_id=' . $tid, 'label' => 'Report'],
    ];
    echo '<nav class="page-switch no-print">';
    echo '<a class="btn" href="' . bm_h($backHref) . '">' . bm_h($backLabel) . '</a>';
    foreach ($items as $key => $it) {
        $cls = 'btn' . ($current === $key ? ' btn-primary' : '');
        echo '<a class="' . $cls . '" href="' . bm_h($it['href']) . '">' . bm_h($it['label']) . '</a>';
    }
    echo '</nav>';
}

function bm_parent_load_by_id(PDO $pdo, int $id, int $tournamentId = 0): ?array
{
    if ($id <= 0) {
        return null;
    }
    if ($tournamentId > 0) {
        $st = $pdo->prepare('SELECT * FROM parent_submissions WHERE id=? AND tournament_id=? LIMIT 1');
        $st->execute([$id, $tournamentId]);
    } else {
        $st = $pdo->prepare('SELECT * FROM parent_submissions WHERE id=? LIMIT 1');
        $st->execute([$id]);
    }
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return bm_parent_load_receipt($pdo, (string)$row['receipt_code'], (int)$row['tournament_id']);
}

function bm_parent_update_player_row(PDO $pdo, int $playerId, array $data, string $who): array
{
    $st = $pdo->prepare('SELECT * FROM players WHERE id=?');
    $st->execute([$playerId]);
    $cur = $st->fetch();
    if (!$cur) {
        throw new RuntimeException($who . ' was not found.');
    }
    $name = trim((string)($data['full_name'] ?? ''));
    $gender = strtolower(trim((string)($data['gender'] ?? '')));
    $bai = trim((string)($data['bai_id'] ?? ''));
    $pba = trim((string)($data['pbi_id'] ?? ''));
    $address = trim((string)($data['address'] ?? $data['district'] ?? ''));
    $father = trim((string)($data['father_name'] ?? ''));
    $mobile = trim((string)($data['mobile'] ?? $data['contact_mobile'] ?? ''));
    if ($mobile !== '') {
        $mobile = bm_normalize_mobile($mobile);
    }
    if ($name === '') {
        throw new RuntimeException($who . ' name is required.');
    }
    if (!in_array($gender, ['boy', 'girl'], true)) {
        throw new RuntimeException('Select Boy or Girl for ' . $who . '.');
    }
    if ($father === '') {
        throw new RuntimeException('Father’s name is required for ' . $who . '.');
    }
    if ($mobile === '') {
        throw new RuntimeException('Contact number is required for ' . $who . '.');
    }
    if ($address === '') {
        throw new RuntimeException('District is required for ' . $who . '.');
    }
    bm_assert_unique_player_ids($pdo, $bai !== '' ? $bai : null, $pba !== '' ? $pba : null, $playerId);
    $pdo->prepare("UPDATE players SET full_name=?, gender=?, bai_id=?, pbi_id=?, address=?, remarks=?, father_name=?, mobile=?, updated_at=datetime('now','localtime') WHERE id=?")
        ->execute([
            $name,
            $gender,
            $bai !== '' ? $bai : null,
            $pba !== '' ? $pba : null,
            $address !== '' ? $address : null,
            $address !== '' ? $address : null,
            $father !== '' ? $father : null,
            $mobile !== '' ? $mobile : null,
            $playerId,
        ]);
    $st->execute([$playerId]);
    return $st->fetch();
}

function bm_parent_refresh_team_label(PDO $pdo, int $teamId): void
{
    if ($teamId <= 0) {
        return;
    }
    $ms = $pdo->prepare("
        SELECT p.full_name FROM players p
        JOIN tournament_team_members m ON m.player_id = p.id
        WHERE m.team_id = ?
        ORDER BY CASE p.gender WHEN 'boy' THEN 0 ELSE 1 END, p.full_name
    ");
    $ms->execute([$teamId]);
    $names = array_column($ms->fetchAll(), 'full_name');
    if ($names) {
        $pdo->prepare("UPDATE tournament_teams SET team_label=?, updated_at=datetime('now','localtime') WHERE id=?")
            ->execute([implode(' / ', $names), $teamId]);
    }
}

/**
 * @return list<int>
 */
function bm_parent_submission_team_ids(PDO $pdo, array $sub): array
{
    $ids = [];
    foreach (['single_team_id', 'double_team_id', 'mix_team_id'] as $col) {
        $tid = (int)($sub[$col] ?? 0);
        if ($tid > 0) {
            $ids[] = $tid;
        }
    }
    if ($ids) {
        return array_values(array_unique($ids));
    }
    $people = array_values(array_filter([
        (int)($sub['primary_player_id'] ?? 0),
        (int)($sub['double_partner_id'] ?? 0),
        (int)($sub['mix_partner_id'] ?? 0),
    ]));
    $events = array_values(array_filter(explode(',', (string)($sub['event_codes'] ?? ''))));
    if (!$people || !$events) {
        return [];
    }
    $inP = implode(',', array_fill(0, count($people), '?'));
    $inE = implode(',', array_fill(0, count($events), '?'));
    $st = $pdo->prepare("
        SELECT t.id FROM tournament_teams t
        WHERE t.tournament_id = ?
          AND t.event_code IN ($inE)
          AND NOT EXISTS (
            SELECT 1 FROM tournament_team_members m
            WHERE m.team_id = t.id AND m.player_id NOT IN ($inP)
          )
          AND EXISTS (
            SELECT 1 FROM tournament_team_members m2
            WHERE m2.team_id = t.id AND m2.player_id = ?
          )
    ");
    $st->execute(array_merge([(int)$sub['tournament_id']], $events, $people, [(int)$sub['primary_player_id']]));
    return array_map('intval', array_column($st->fetchAll(), 'id'));
}

function bm_parent_delete_submission(PDO $pdo, array $sub): void
{
    $tid = (int)$sub['tournament_id'];
    $catId = (int)$sub['age_category_id'];
    $events = array_values(array_filter(explode(',', (string)($sub['event_codes'] ?? ''))));
    $people = array_values(array_filter([
        (int)($sub['primary_player_id'] ?? 0),
        (int)($sub['double_partner_id'] ?? 0),
        (int)($sub['mix_partner_id'] ?? 0),
    ]));
    $pdo->beginTransaction();
    try {
        foreach (bm_parent_submission_team_ids($pdo, $sub) as $teamId) {
            $pdo->prepare('DELETE FROM tournament_teams WHERE id=? AND tournament_id=?')->execute([$teamId, $tid]);
        }
        $cat = $pdo->prepare('SELECT * FROM age_categories WHERE id=?');
        $cat->execute([$catId]);
        $catRow = $cat->fetch() ?: ['id' => $catId];
        $cats = bm_paired_category_ids($pdo, $catRow);
        foreach ($people as $pid) {
            foreach ($events as $code) {
                $useCats = $code === 'mix_double' ? $cats : [$catId];
                $in = implode(',', array_fill(0, count($useCats), '?'));
                $pdo->prepare("DELETE FROM tournament_entries WHERE tournament_id=? AND player_id=? AND event_code=? AND age_category_id IN ($in)")
                    ->execute(array_merge([$tid, $pid, $code], $useCats));
            }
        }
        $pdo->prepare('DELETE FROM parent_submissions WHERE id=?')->execute([(int)$sub['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

