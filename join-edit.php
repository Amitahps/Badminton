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

if (isset($_POST['delete_parent'])) {
    try {
        bm_parent_delete_submission($pdo, $sub);
        bm_flash('success', 'Your entry was removed.');
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
    }
    bm_redirect($mine);
}

if (isset($_POST['save_parent_edit'])) {
    try {
        $mobile = bm_normalize_mobile((string)($_POST['contact_mobile'] ?? ''));
        $primary = $sub['primary'] ?: [];
        bm_parent_update_player_contact(
            $pdo,
            (int)$primary['id'],
            (string)($_POST['contact_mobile'] ?? ''),
            (string)($_POST['district'] ?? $_POST['address'] ?? ''),
            'Player'
        );
        if (!empty($sub['double_partner'])) {
            bm_parent_update_player_contact(
                $pdo,
                (int)$sub['double_partner']['id'],
                (string)($_POST['d_contact_mobile'] ?? ''),
                (string)($_POST['d_district'] ?? $_POST['d_address'] ?? ''),
                'Doubles partner'
            );
        }
        if (!empty($sub['mix_partner'])) {
            bm_parent_update_player_contact(
                $pdo,
                (int)$sub['mix_partner']['id'],
                (string)($_POST['m_contact_mobile'] ?? ''),
                (string)($_POST['m_district'] ?? $_POST['m_address'] ?? ''),
                'Mix Double partner'
            );
        }
        foreach (bm_parent_submission_team_ids($pdo, $sub) as $teamId) {
            bm_parent_refresh_team_label($pdo, $teamId);
        }
        $status = (string)($sub['payment_status'] ?? 'na');
        if (in_array($status, ['review', 'declined'], true)) {
            $payDate = bm_parse_date_input((string)($_POST['payment_date'] ?? ''));
            if ($payDate === null) {
                throw new RuntimeException('Date of payment is required (dd-mm-yyyy).');
            }
            $sender = bm_validate_sender_name((string)($_POST['payment_sender'] ?? ''));
            $utr = bm_validate_utr((string)($_POST['payment_utr'] ?? ''));
            if ($status === 'declined') {
                bm_parent_resubmit_payment($pdo, $sub, $payDate, $utr, $sender);
            } else {
                bm_assert_unique_utr($pdo, $utr, (int)$sub['id']);
                $pdo->prepare("UPDATE parent_submissions SET payment_utr=?, payment_date=?, payment_sender=? WHERE id=?")
                    ->execute([$utr, $payDate, $sender, (int)$sub['id']]);
            }
        }
        $pdo->prepare('UPDATE parent_submissions SET contact_mobile=? WHERE id=?')
            ->execute([$mobile, (int)$sub['id']]);
        bm_flash('success', $status === 'declined' ? 'Corrected details sent. The organiser will check the bank statement again.' : 'Entry updated.');
        bm_redirect(($status === 'declined' && bm_tournament_is_paid($tournament))
            ? 'join-declined.php?t=' . rawurlencode($token) . '&p=' . rawurlencode($code)
            : $mine);
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
$payStatus = (string)($sub['payment_status'] ?? 'na');
$declineReason = (string)($sub['decline_reason'] ?? '');
$canFixPay = in_array($payStatus, ['review', 'declined'], true);
$emptyDate = $payStatus === 'declined' && in_array($declineReason, ['date_mismatch', 'amount_mismatch'], true);
$emptyUtr = $payStatus === 'declined' && in_array($declineReason, ['utr_mismatch', 'amount_mismatch'], true);
$emptySender = $payStatus === 'declined' && in_array($declineReason, ['sender_mismatch', 'amount_mismatch'], true);
$pageTitle = 'Edit your entry · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<?php bm_parent_top_bar($token, $code, $payStatus === 'declined' ? 'declined' : 'new'); ?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?></p>
    <h1><?= $payStatus === 'declined' ? 'Correct declined entry' : 'Edit your entry' ?></h1>
    <p class="lede"><?php if ($payStatus === 'declined'): ?>
      <?= bm_h(bm_decline_reason_label($declineReason)) ?>. Other details are filled. Enter the correct field and submit again.
    <?php else: ?>
      Correct a mistake on this player. Other parents cannot see this page.
    <?php endif; ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= bm_h($mine) ?>">Back to my entries</a>
  </div>
</section>
<section class="panel">
  <p><strong>Age category:</strong> <?= bm_h((string)(($sub['category']['name'] ?? ''))) ?></p>
  <p><strong>Events:</strong> <?= bm_h(implode(', ', $eventLabels)) ?></p>
  <form method="post" class="form form-grid">
    <input type="hidden" name="t" value="<?= bm_h($token) ?>">
    <input type="hidden" name="p" value="<?= bm_h($code) ?>">
    <input type="hidden" name="r" value="<?= bm_h($receipt) ?>">
    <p class="hint span-2">Player</p>
    <label>Player name<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($p['full_name'] ?? '')) ?>"></label>
    <label>Gender<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h(bm_gender_label((string)($p['gender'] ?? ''))) ?>"></label>
    <label>BAI ID<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($p['bai_id'] ?? '')) ?>"></label>
    <label>PBA ID<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($p['pbi_id'] ?? '')) ?>"></label>
    <label>Contact<input type="tel" name="contact_mobile" required maxlength="15" value="<?= bm_h((string)($p['mobile'] ?? $sub['contact_mobile'] ?? '')) ?>"></label>
    <label class="span-2">District<input type="text" name="district" required maxlength="120" value="<?= bm_h(bm_player_address($p)) ?>"></label>
    <?php if ($d): ?>
    <p class="hint span-2">Doubles partner</p>
    <label>Partner name<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($d['full_name'] ?? '')) ?>"></label>
    <label>Partner BAI ID<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($d['bai_id'] ?? '')) ?>"></label>
    <label>Partner PBA ID<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($d['pbi_id'] ?? '')) ?>"></label>
    <label>Partner contact<input type="tel" name="d_contact_mobile" required maxlength="15" value="<?= bm_h((string)($d['mobile'] ?? '')) ?>"></label>
    <label class="span-2">Partner district<input type="text" name="d_district" required maxlength="120" value="<?= bm_h(bm_player_address($d)) ?>"></label>
    <?php endif; ?>
    <?php if ($m): ?>
    <p class="hint span-2">Mix Double partner</p>
    <label>Partner name<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($m['full_name'] ?? '')) ?>"></label>
    <label>Partner BAI ID<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($m['bai_id'] ?? '')) ?>"></label>
    <label>Partner PBA ID<input type="text" readonly class="is-locked" tabindex="-1" value="<?= bm_h((string)($m['pbi_id'] ?? '')) ?>"></label>
    <label>Partner contact<input type="tel" name="m_contact_mobile" required maxlength="15" value="<?= bm_h((string)($m['mobile'] ?? '')) ?>"></label>
    <label class="span-2">Partner district<input type="text" name="m_district" required maxlength="120" value="<?= bm_h(bm_player_address($m)) ?>"></label>
    <?php endif; ?>
    <?php if ($canFixPay): ?>
    <p class="hint span-2">Payment — date, reference no / UTR, sender name</p>
    <label>Date of payment (dd-mm-yyyy)
      <input type="text" name="payment_date" required maxlength="10" placeholder="dd-mm-yyyy" value="<?php
        if ($emptyDate) {
            echo '';
        } else {
            $pd = trim((string)($sub['payment_date'] ?? ''));
            echo $pd !== '' ? bm_h(bm_fmt_date($pd)) : '';
        }
      ?>">
    </label>
    <label>Reference no / UTR
      <input type="text" name="payment_utr" id="payment_utr" required inputmode="numeric" maxlength="18" pattern="[0-9]{8,18}" value="<?= $emptyUtr ? '' : bm_h((string)($sub['payment_utr'] ?? '')) ?>">
    </label>
    <label class="span-2">Sender name
      <input type="text" name="payment_sender" required maxlength="160" value="<?= $emptySender ? '' : bm_h((string)($sub['payment_sender'] ?? '')) ?>">
    </label>
    <?php endif; ?>
    <div class="form-actions span-2">
      <button type="submit" name="save_parent_edit" value="1" class="btn btn-primary">Save changes</button>
      <button type="submit" name="delete_parent" value="1" class="btn" onclick="return confirm('Remove this entry? Only this entry from your phone is removed.');">Remove this entry</button>
    </div>
  </form>
</section>
<?php if ($canFixPay): ?>
<script>
(function(){
  var utr = document.getElementById('payment_utr');
  if (utr) utr.addEventListener('input', function(){ utr.value = utr.value.replace(/[^0-9]/g, ''); });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
