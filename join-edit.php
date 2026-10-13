<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$code = trim((string)($_GET['p'] ?? $_POST['p'] ?? ''));
$receipt = trim((string)($_GET['r'] ?? $_POST['r'] ?? ''));
$tournament = bm_tournament_by_public_token($pdo, $token);
if (!$tournament || $code === '' || $receipt === '') {
    http_response_code(404);
    $pageTitle = 'Edit entry · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">This edit link is not valid.</p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

if (!bm_parent_owns_receipt($pdo, $tournament, $receipt, $code)) {
    http_response_code(403);
    $pageTitle = 'Edit entry · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">This entry does not belong to your saved list.</p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$sub = bm_parent_load_receipt($pdo, $receipt, (int)$tournament['id']);
if (!$sub) {
    bm_flash('error', 'Entry not found.');
    bm_redirect('join.php?t=' . rawurlencode($token));
}
$mine = 'join-mine.php?t=' . rawurlencode($token) . '&p=' . rawurlencode($code);

if (isset($_POST['save_parent_edit'])) {
    try {
        $father = trim((string)($_POST['father_name'] ?? ''));
        $mobile = bm_normalize_mobile((string)($_POST['contact_mobile'] ?? ''));
        if ($father === '') {
            throw new RuntimeException('Father’s name is required.');
        }
        $primary = $sub['primary'] ?: [];
        $updated = bm_parent_update_player_row($pdo, (int)$primary['id'], [
            'full_name' => (string)($_POST['full_name'] ?? ''),
            'gender' => (string)($_POST['gender'] ?? ''),
            'bai_id' => (string)($_POST['bai_id'] ?? ''),
            'pbi_id' => (string)($_POST['pbi_id'] ?? ''),
            'address' => (string)($_POST['address'] ?? ''),
        ], 'Player');
        if (!empty($sub['double_partner'])) {
            $dp = bm_parent_update_player_row($pdo, (int)$sub['double_partner']['id'], [
                'full_name' => (string)($_POST['d_full_name'] ?? ''),
                'gender' => (string)$updated['gender'],
                'bai_id' => (string)($_POST['d_bai_id'] ?? ''),
                'pbi_id' => (string)($_POST['d_pbi_id'] ?? ''),
                'address' => (string)($_POST['d_address'] ?? ''),
            ], 'Doubles partner');
            if ((int)$dp['id'] === (int)$updated['id']) {
                throw new RuntimeException('Doubles partner must be a different player.');
            }
        }
        if (!empty($sub['mix_partner'])) {
            $og = (string)$updated['gender'] === 'boy' ? 'girl' : 'boy';
            $mp = bm_parent_update_player_row($pdo, (int)$sub['mix_partner']['id'], [
                'full_name' => (string)($_POST['m_full_name'] ?? ''),
                'gender' => $og,
                'bai_id' => (string)($_POST['m_bai_id'] ?? ''),
                'pbi_id' => (string)($_POST['m_pbi_id'] ?? ''),
                'address' => (string)($_POST['m_address'] ?? ''),
            ], 'Mix Double partner');
            if ((int)$mp['id'] === (int)$updated['id']) {
                throw new RuntimeException('Mix Double partner must be a different player.');
            }
        }
        foreach (bm_parent_submission_team_ids($pdo, $sub) as $teamId) {
            bm_parent_refresh_team_label($pdo, $teamId);
        }
        $utr = trim((string)($sub['payment_utr'] ?? ''));
        $shotName = (string)($sub['payment_screenshot'] ?? '');
        $shotHash = (string)($sub['payment_screenshot_hash'] ?? '');
        $status = (string)($sub['payment_status'] ?? 'na');
        if ($status === 'pending') {
            $newUtr = trim((string)($_POST['payment_utr'] ?? ''));
            if ($newUtr !== '') {
                $utr = bm_validate_utr($newUtr);
            }
            $shot = $_FILES['payment_screenshot'] ?? null;
            if (is_array($shot) && (int)($shot['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $shotHash = bm_upload_sha256($shot);
                $shotName = bm_save_upload($shot, 'pay');
            }
            if ($utr !== '' || $shotHash !== '') {
                bm_assert_unique_payment($pdo, $utr, $shotHash, (int)$sub['id']);
            }
        }
        $pdo->prepare("UPDATE parent_submissions SET father_name=?, contact_mobile=?, payment_utr=?, payment_screenshot=?, payment_screenshot_hash=? WHERE id=?")
            ->execute([$father, $mobile, $utr !== '' ? $utr : null, $shotName !== '' ? $shotName : null, $shotHash !== '' ? $shotHash : null, (int)$sub['id']]);
        bm_flash('success', 'Entry updated.');
        bm_redirect($mine);
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
        $sub = bm_parent_load_receipt($pdo, $receipt, (int)$tournament['id']) ?: $sub;
    }
}

$p = $sub['primary'] ?: [];
$d = $sub['double_partner'] ?: [];
$m = $sub['mix_partner'] ?: [];
$eventLabels = [];
foreach ($sub['events'] as $ec) {
    $eventLabels[] = bm_event_label($ec);
}
$pageTitle = 'Edit your entry · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?></p>
    <h1>Edit your entry</h1>
    <p class="lede">Correct a mistake on this player. Other parents cannot see this page.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= bm_h($mine) ?>">Back to my entries</a>
  </div>
</section>
<section class="panel">
  <p><strong>Age category:</strong> <?= bm_h((string)(($sub['category']['name'] ?? ''))) ?></p>
  <p><strong>Events:</strong> <?= bm_h(implode(', ', $eventLabels)) ?></p>
  <form method="post" class="form form-grid" enctype="multipart/form-data">
    <input type="hidden" name="t" value="<?= bm_h($token) ?>">
    <input type="hidden" name="p" value="<?= bm_h($code) ?>">
    <input type="hidden" name="r" value="<?= bm_h($receipt) ?>">
    <p class="hint span-2">Parent / coach</p>
    <label>Father’s name<input type="text" name="father_name" required maxlength="160" value="<?= bm_h((string)($sub['father_name'] ?? '')) ?>"></label>
    <label>Contact number<input type="tel" name="contact_mobile" required maxlength="15" value="<?= bm_h((string)($sub['contact_mobile'] ?? '')) ?>"></label>
    <p class="hint span-2">Player</p>
    <label>Player name<input type="text" name="full_name" required maxlength="160" value="<?= bm_h((string)($p['full_name'] ?? '')) ?>"></label>
    <label>Gender
      <select name="gender" required>
        <option value="boy" <?= (($p['gender'] ?? '') === 'boy') ? 'selected' : '' ?>>Boy</option>
        <option value="girl" <?= (($p['gender'] ?? '') === 'girl') ? 'selected' : '' ?>>Girl</option>
      </select>
    </label>
    <label>BAI ID<input type="text" name="bai_id" maxlength="80" value="<?= bm_h((string)($p['bai_id'] ?? '')) ?>"></label>
    <label>PBA ID<input type="text" name="pbi_id" maxlength="80" value="<?= bm_h((string)($p['pbi_id'] ?? '')) ?>"></label>
    <label class="span-2">Address<textarea name="address" rows="2"><?= bm_h(bm_player_address($p)) ?></textarea></label>
    <?php if ($d): ?>
    <p class="hint span-2">Doubles partner</p>
    <label>Partner name<input type="text" name="d_full_name" required maxlength="160" value="<?= bm_h((string)($d['full_name'] ?? '')) ?>"></label>
    <label>Partner BAI ID<input type="text" name="d_bai_id" maxlength="80" value="<?= bm_h((string)($d['bai_id'] ?? '')) ?>"></label>
    <label>Partner PBA ID<input type="text" name="d_pbi_id" maxlength="80" value="<?= bm_h((string)($d['pbi_id'] ?? '')) ?>"></label>
    <label class="span-2">Partner address<textarea name="d_address" rows="2"><?= bm_h(bm_player_address($d)) ?></textarea></label>
    <?php endif; ?>
    <?php if ($m): ?>
    <p class="hint span-2">Mix Double partner</p>
    <label>Partner name<input type="text" name="m_full_name" required maxlength="160" value="<?= bm_h((string)($m['full_name'] ?? '')) ?>"></label>
    <label>Partner BAI ID<input type="text" name="m_bai_id" maxlength="80" value="<?= bm_h((string)($m['bai_id'] ?? '')) ?>"></label>
    <label>Partner PBA ID<input type="text" name="m_pbi_id" maxlength="80" value="<?= bm_h((string)($m['pbi_id'] ?? '')) ?>"></label>
    <label class="span-2">Partner address<textarea name="m_address" rows="2"><?= bm_h(bm_player_address($m)) ?></textarea></label>
    <?php endif; ?>
    <?php if (($sub['payment_status'] ?? '') === 'pending'): ?>
    <p class="hint span-2">Payment (pending)</p>
    <label>UTR / reference number<input type="text" name="payment_utr" inputmode="numeric" maxlength="18" value="<?= bm_h((string)($sub['payment_utr'] ?? '')) ?>"></label>
    <label class="span-2">Replace screenshot<input type="file" name="payment_screenshot" accept="image/png,image/jpeg,image/webp,application/pdf"></label>
    <?php endif; ?>
    <div class="form-actions span-2">
      <button type="submit" name="save_parent_edit" value="1" class="btn btn-primary">Save changes</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
