<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        bm_flash('error', 'Tournament not found.');
        bm_redirect('tournaments.php');
    }
}

$post = $_POST;
$isPaidPost = isset($post['is_paid']) ? ((string)$post['is_paid'] === '1') : ($row ? bm_tournament_is_paid($row) : false);
$ageGroupsText = '';
if ($id > 0) {
    $ageGroupsText = implode("\n", bm_tournament_age_group_labels($pdo, $id));
}
if (isset($post['age_groups'])) {
    $ageGroupsText = (string)$post['age_groups'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim((string)($post['name'] ?? ''));
        $heldAt = trim((string)($post['held_at'] ?? ''));
        $from = bm_parse_date_input((string)($post['date_from'] ?? ''));
        $to = bm_parse_date_input((string)($post['date_to'] ?? ''));
        $notes = trim((string)($post['notes'] ?? ''));
        $isPaid = ((string)($post['is_paid'] ?? '0') === '1') ? 1 : 0;
        $feeSingle = max(0, (int)($post['fee_single'] ?? 0));
        $feeDouble = max(0, (int)($post['fee_double'] ?? 0));
        $feeMix = max(0, (int)($post['fee_mix'] ?? 0));
        $upi = trim((string)($post['upi_id'] ?? ''));
        $bankAccount = trim((string)($post['bank_account'] ?? ''));
        $bankIfsc = strtoupper(trim((string)($post['bank_ifsc'] ?? '')));
        $bankName = trim((string)($post['bank_name'] ?? ''));
        if ($name === '' || $heldAt === '' || !$from || !$to) {
            throw new RuntimeException('Tournament name, held at, and both dates are required (dd-mm-yyyy).');
        }
        if ($to < $from) {
            throw new RuntimeException('To date cannot be before From date.');
        }
        if ($isPaid && $feeSingle + $feeDouble + $feeMix <= 0) {
            throw new RuntimeException('Paid tournament needs a fee for Single, Doubles and/or Mix Double.');
        }
        $groups = preg_split('/\r\n|\r|\n/', (string)($post['age_groups'] ?? '')) ?: [];
        if ($id <= 0 && !array_filter(array_map('trim', $groups))) {
            throw new RuntimeException('Add at least one age category (one per line, e.g. Under 11).');
        }

        $qrKeep = $row['qr_file'] ?? null;
        $qrNew = bm_save_upload($_FILES['qr_file'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'qr');
        if ($qrNew) {
            if ($qrKeep) {
                bm_delete_upload((string)$qrKeep);
            }
            $qrKeep = $qrNew;
        }
        if ($isPaid === 0) {
            $feeSingle = $feeDouble = $feeMix = 0;
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE tournaments SET name=?, held_at=?, date_from=?, date_to=?, notes=?, is_paid=?, fee_single=?, fee_double=?, fee_mix=?, upi_id=?, qr_file=?, bank_account=?, bank_ifsc=?, bank_name=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$name, $heldAt, $from, $to, $notes !== '' ? $notes : null, $isPaid, $feeSingle, $feeDouble, $feeMix, $upi !== '' ? $upi : null, $qrKeep, $bankAccount !== '' ? $bankAccount : null, $bankIfsc !== '' ? $bankIfsc : null, $bankName !== '' ? $bankName : null, $id]);
            bm_ensure_tournament_age_groups($pdo, $id, $groups);
            bm_flash('success', 'Tournament updated.');
            bm_redirect('tournament.php?id=' . $id);
        } else {
            $pdo->prepare('INSERT INTO tournaments (name, held_at, date_from, date_to, notes, is_paid, fee_single, fee_double, fee_mix, upi_id, qr_file, bank_account, bank_ifsc, bank_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $heldAt, $from, $to, $notes !== '' ? $notes : null, $isPaid, $feeSingle, $feeDouble, $feeMix, $upi !== '' ? $upi : null, $qrKeep, $bankAccount !== '' ? $bankAccount : null, $bankIfsc !== '' ? $bankIfsc : null, $bankName !== '' ? $bankName : null]);
            $newId = (int)$pdo->lastInsertId();
            bm_ensure_tournament_age_groups($pdo, $newId, $groups);
            bm_flash('success', 'Tournament created. Parents will only see the age categories you added.');
            bm_redirect('tournament.php?id=' . $newId);
        }
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
        $isPaidPost = ((string)($post['is_paid'] ?? '0') === '1');
    }
}

$fromDisplay = !empty($row['date_from']) ? bm_fmt_date((string)$row['date_from']) : trim((string)($post['date_from'] ?? ''));
$toDisplay = !empty($row['date_to']) ? bm_fmt_date((string)$row['date_to']) : trim((string)($post['date_to'] ?? ''));
if ($fromDisplay === '—') {
    $fromDisplay = '';
}
if ($toDisplay === '—') {
    $toDisplay = '';
}

$pageTitle = ($id ? 'Edit' : 'Create') . ' Tournament · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head"><div><p class="eyebrow">Tournament</p><h1><?= $id ? 'Edit tournament' : 'Create tournament' ?></h1>
  <p class="lede">Choose paid or free, set fees if paid, and list age categories for this tournament only. Events stay the same: Single, Boys/Girls Doubles, Mix Double.</p>
</div></section>
<section class="panel" style="max-width:720px;">
  <form method="post" class="form" enctype="multipart/form-data" id="t-form">
    <label>Tournament name
      <input type="text" name="name" required maxlength="200" value="<?= bm_h($row['name'] ?? ($post['name'] ?? '')) ?>" placeholder="e.g. District Ranking Tournament">
    </label>
    <label>Held at
      <input type="text" name="held_at" required maxlength="200" value="<?= bm_h($row['held_at'] ?? ($post['held_at'] ?? '')) ?>" placeholder="Venue / city">
    </label>
    <div class="two-col">
      <label>From date (dd-mm-yyyy)
        <input type="text" name="date_from" required maxlength="10" placeholder="dd-mm-yyyy" value="<?= bm_h($fromDisplay) ?>">
      </label>
      <label>To date (dd-mm-yyyy)
        <input type="text" name="date_to" required maxlength="10" placeholder="dd-mm-yyyy" value="<?= bm_h($toDisplay) ?>">
      </label>
    </div>
    <fieldset class="letter-fields">
      <legend>Entry fee</legend>
      <label class="check-inline"><input type="radio" name="is_paid" value="0" <?= !$isPaidPost ? 'checked' : '' ?>> Free — parents see no fee</label>
      <label class="check-inline"><input type="radio" name="is_paid" value="1" <?= $isPaidPost ? 'checked' : '' ?>> Paid</label>
    </fieldset>
    <div id="paid-box" class="pay-fields">
      <div class="two-col">
        <label>Single fee (₹)
          <input type="number" name="fee_single" min="0" step="1" value="<?= bm_h((string)($post['fee_single'] ?? $row['fee_single'] ?? '0')) ?>">
        </label>
        <label>Doubles fee (₹)
          <input type="number" name="fee_double" min="0" step="1" value="<?= bm_h((string)($post['fee_double'] ?? $row['fee_double'] ?? '0')) ?>">
        </label>
      </div>
      <label>Mix Double fee (₹)
        <input type="number" name="fee_mix" min="0" step="1" value="<?= bm_h((string)($post['fee_mix'] ?? $row['fee_mix'] ?? '0')) ?>">
      </label>
      <label>UPI ID (shown to parents)
        <input type="text" name="upi_id" maxlength="120" value="<?= bm_h((string)($post['upi_id'] ?? $row['upi_id'] ?? '')) ?>" placeholder="name@upi">
      </label>
      <label>Payment QR image
        <input type="file" name="qr_file" accept="image/png,image/jpeg,image/webp,application/pdf">
        <?php if (!empty($row['qr_file'])): ?>
          <span class="file-meta">Current: <a href="view-file.php?f=<?= bm_h(urlencode((string)$row['qr_file'])) ?>" target="_blank">open QR</a></span>
        <?php endif; ?>
      </label>
      <p class="hint">Parents also see bank account details if you fill them (pay by QR/UPI or by transfer).</p>
      <label>Bank name
        <input type="text" name="bank_name" maxlength="120" value="<?= bm_h((string)($post['bank_name'] ?? $row['bank_name'] ?? '')) ?>">
      </label>
      <label>Account number
        <input type="text" name="bank_account" maxlength="30" inputmode="numeric" value="<?= bm_h((string)($post['bank_account'] ?? $row['bank_account'] ?? '')) ?>">
      </label>
      <label>IFSC
        <input type="text" name="bank_ifsc" maxlength="11" value="<?= bm_h((string)($post['bank_ifsc'] ?? $row['bank_ifsc'] ?? '')) ?>" placeholder="e.g. SBIN0001234">
      </label>
    </div>
    <label>Age categories for this tournament (one per line)
      <textarea name="age_groups" rows="5" <?= $id ? '' : 'required' ?> placeholder="Under 11&#10;Under 13&#10;Under 17"><?= bm_h($ageGroupsText) ?></textarea>
      <span class="hint">Parents and the assign-players page only see these ages. For each line we add Boys and Girls. Events (Single, Doubles, Mix Double) stay the same.</span>
    </label>
    <label>Notes<textarea name="notes" rows="3"><?= bm_h($row['notes'] ?? ($post['notes'] ?? '')) ?></textarea></label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save tournament</button>
      <a class="btn" href="tournaments.php">Cancel</a>
    </div>
  </form>
</section>
<script>
(function(){
  var box = document.getElementById('paid-box');
  function sync(){
    var paid = document.querySelector('input[name="is_paid"]:checked');
    box.style.display = (paid && paid.value === '1') ? 'grid' : 'none';
  }
  document.querySelectorAll('input[name="is_paid"]').forEach(function(r){ r.addEventListener('change', sync); });
  sync();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
