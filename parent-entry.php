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
    $payStatus = $needPay ? 'pending' : 'na';
    $utr = null;
    $shotName = null;
    $shotHash = null;
    if ($needPay) {
        $utr = bm_validate_utr((string)($post['payment_utr'] ?? ''));
        [$shotName, $shotHash] = bm_ingest_payment_screenshot($post, $files);
        bm_assert_unique_payment($pdo, $utr, $shotHash, 0);
    }
    $parentCode = bm_parent_code_from_post_or_session((string)($tournament['public_token'] ?? ''), (string)($post['parent_code'] ?? ''));

    $pdo->beginTransaction();
    try {
        if ($needPay) {
            bm_assert_unique_payment($pdo, (string)$utr, (string)$shotHash, 0);
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
            (tournament_id, receipt_code, age_category_id, primary_player_id, double_partner_id, mix_partner_id, event_codes, single_team_id, double_team_id, mix_team_id, father_name, contact_mobile, parent_code, payment_status, payment_amount, payment_utr, payment_screenshot, payment_screenshot_hash)
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
            $shotName,
            $shotHash,
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
 * @return array{0:string,1:string} filename, sha256
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
    $name = 'pay_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.jpg';
    $dest = BM_UPLOAD_DIR . '/' . $name;
    if (file_put_contents($dest, $bin) === false) {
        throw new RuntimeException('Could not save the payment screenshot.');
    }
    return [$name, $hash];
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

function bm_assert_unique_payment(PDO $pdo, string $utr, string $hash, int $exceptId = 0): void
{
    $utr = trim($utr);
    $hash = trim($hash);
    if ($utr !== '') {
        $st = $pdo->prepare("SELECT id FROM parent_submissions WHERE payment_utr = ? AND id != ? LIMIT 1");
        $st->execute([$utr, $exceptId]);
        if ($st->fetch()) {
            throw new RuntimeException('This UTR / reference number was already used. Entry not saved.');
        }
    }
    if ($hash !== '') {
        $st = $pdo->prepare("SELECT id FROM parent_submissions WHERE payment_screenshot_hash = ? AND id != ? LIMIT 1");
        $st->execute([$hash, $exceptId]);
        if ($st->fetch()) {
            throw new RuntimeException('This payment screenshot was already used. Entry not saved.');
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

function bm_parent_confirm_paid(PDO $pdo, array $sub): void
{
    $pdo->prepare("UPDATE parent_submissions SET payment_status='paid' WHERE id=?")->execute([(int)$sub['id']]);
    foreach (bm_parent_submission_team_ids($pdo, $sub) as $teamId) {
        $pdo->prepare("UPDATE tournament_teams SET include_in_letter=1, updated_at=datetime('now','localtime') WHERE id=?")
            ->execute([$teamId]);
    }
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

function bm_parent_payment_label(?string $status, int $amount): string
{
    if ($status === 'pending') {
        return 'Pending ₹' . $amount;
    }
    if ($status === 'paid') {
        return 'Paid ₹' . $amount;
    }
    return $amount > 0 ? ('₹' . $amount) : 'Free / no fee';
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

