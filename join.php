<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$pdo = bm_db();

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$tournament = bm_tournament_by_public_token($pdo, $token);
if (!$tournament) {
    http_response_code(404);
    $pageTitle = 'Entry link · Badminton';
    require __DIR__ . '/includes/header.php';
    echo '<section class="panel"><p class="empty">This tournament link is not valid. Ask the organiser for a new link.</p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$ageOptions = bm_parent_age_options($pdo, (int)$tournament['id']);
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$parentCode = bm_own_parent_code($token);
$ownReceipt = bm_own_parent_receipt($token);
$isPaid = bm_tournament_is_paid($tournament);

if (isset($_POST['save_parent'])) {
    try {
        if (!$ageOptions) {
            throw new RuntimeException('Age categories are not set up yet. Ask the organiser.');
        }
        $result = bm_parent_save_submission($pdo, $tournament, $_POST, $_FILES);
        bm_remember_parent_receipt($token, $result['receipt']);
        bm_remember_parent_code($token, (string)$result['parent_code']);
        bm_flash('success', $isPaid
            ? 'Saved. For a paid entry the organiser will check date, UTR and sender name against the bank statement before the player is on the list.'
            : 'Saved.');
        bm_redirect('join-mine.php?t=' . rawurlencode($token) . '&p=' . rawurlencode((string)$result['parent_code']));
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
    }
}

$form = [
    'age_key' => (string)($_POST['age_key'] ?? ''),
    'father_name' => (string)($_POST['father_name'] ?? ''),
    'contact_mobile' => (string)($_POST['contact_mobile'] ?? ''),
    'full_name' => (string)($_POST['full_name'] ?? ''),
    'gender' => (string)($_POST['gender'] ?? ''),
    'bai_id' => (string)($_POST['bai_id'] ?? ''),
    'pbi_id' => (string)($_POST['pbi_id'] ?? ''),
    'district' => (string)($_POST['district'] ?? $_POST['address'] ?? ''),
    'events' => is_array($_POST['events'] ?? null) ? $_POST['events'] : [],
    'd_full_name' => (string)($_POST['d_full_name'] ?? ''),
    'd_bai_id' => (string)($_POST['d_bai_id'] ?? ''),
    'd_pbi_id' => (string)($_POST['d_pbi_id'] ?? ''),
    'd_father_name' => (string)($_POST['d_father_name'] ?? ''),
    'd_contact_mobile' => (string)($_POST['d_contact_mobile'] ?? ''),
    'd_district' => (string)($_POST['d_district'] ?? ''),
    'm_full_name' => (string)($_POST['m_full_name'] ?? ''),
    'm_bai_id' => (string)($_POST['m_bai_id'] ?? ''),
    'm_pbi_id' => (string)($_POST['m_pbi_id'] ?? ''),
    'm_father_name' => (string)($_POST['m_father_name'] ?? ''),
    'm_contact_mobile' => (string)($_POST['m_contact_mobile'] ?? ''),
    'm_district' => (string)($_POST['m_district'] ?? ''),
    'payment_utr' => (string)($_POST['payment_utr'] ?? ''),
    'payment_date' => (string)($_POST['payment_date'] ?? ''),
    'payment_sender' => (string)($_POST['payment_sender'] ?? ''),
];

$showForm = isset($_POST['save_parent']) || (string)($_GET['go'] ?? '') === 'new';
$pageTitle = $tournament['name'] . ' · Parent entry';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Parent / coach entry</p>
    <h1><?= bm_h($tournament['name']) ?></h1>
    <p class="lede">Held at <strong><?= bm_h($tournament['held_at']) ?></strong> on <strong><?= bm_h($dateText) ?></strong>.</p>
  </div>
</section>
<?php bm_parent_choice_cards($token, $parentCode, $showForm ? 'new' : ''); ?>
<?php if (!$showForm): ?>
<section class="panel">
  <p class="lede" style="max-width:none;"><?php if ($isPaid): ?>Choose <strong>New Entry</strong> to add a player, <strong>Confirmed Entries</strong> for names accepted by officials, or <strong>Declined Entries</strong> if payment, sender name, date or amount did not match and needs a correction.<?php else: ?>Choose <strong>New Entry</strong> to add a player, or <strong>Confirmed Entries</strong> for names accepted by officials.<?php endif; ?></p>
</section>
<?php else: ?>
<section class="page-head" style="margin-top:0.4rem;">
  <div>
    <h2 style="margin:0;font-family:var(--display);font-size:1.25rem;">New entry</h2>
    <p class="lede">Fill <strong>one player / age category</strong> at a time. You can add more players after save. You will see only your own entries.</p>
  </div>
</section>

<?php if ($parentCode): ?>
<p class="hint" style="margin-top:-0.5rem;"><a href="join-mine.php?t=<?= bm_h(urlencode($token)) ?>&amp;p=<?= bm_h(urlencode($parentCode)) ?>">View / print PDF of entries you already sent</a> (only yours).</p>
<?php elseif ($ownReceipt): ?>
<p class="hint" style="margin-top:-0.5rem;"><a href="join-receipt.php?t=<?= bm_h(urlencode($token)) ?>&amp;r=<?= bm_h(urlencode($ownReceipt)) ?>">View the details you already sent</a> (only yours).</p>
<?php endif; ?>

<?php if (!$ageOptions): ?>
<section class="panel">
  <p class="empty">Age categories for this tournament are not ready. Please contact the organiser.</p>
</section>
<?php else: ?>
<section class="panel">
  <form method="post" class="form form-grid" id="parent-form">
    <input type="hidden" name="t" value="<?= bm_h($token) ?>">
    <?php if ($parentCode): ?>
    <input type="hidden" name="parent_code" value="<?= bm_h($parentCode) ?>">
    <?php endif; ?>

    <label class="span-2">Age category
      <select name="age_key" id="age_key" required>
        <option value="">Select age</option>
        <?php foreach ($ageOptions as $opt): ?>
          <option value="<?= bm_h($opt['key']) ?>" <?= $form['age_key'] === $opt['key'] ? 'selected' : '' ?>><?= bm_h($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>BAI ID<input type="text" name="bai_id" id="bai_id" maxlength="80" value="<?= bm_h($form['bai_id']) ?>"></label>
    <label>PBI ID<input type="text" name="pbi_id" id="pbi_id" maxlength="80" value="<?= bm_h($form['pbi_id']) ?>"></label>
    <p class="hint span-2" id="player-id-note">If this BAI ID or PBI ID was saved before, name, gender, father, contact and district fill in. Empty fields can be typed. Every field can be changed.</p>
    <label>Player name
      <input type="text" name="full_name" id="full_name" required maxlength="160" value="<?= bm_h($form['full_name']) ?>">
    </label>
    <label>Gender
      <select name="gender" id="gender" required>
        <option value="">Select</option>
        <option value="boy" <?= $form['gender'] === 'boy' ? 'selected' : '' ?>>Boy</option>
        <option value="girl" <?= $form['gender'] === 'girl' ? 'selected' : '' ?>>Girl</option>
      </select>
    </label>
    <label>Father’s name
      <input type="text" name="father_name" id="father_name" required maxlength="160" value="<?= bm_h($form['father_name']) ?>">
    </label>
    <label>Contact
      <input type="tel" name="contact_mobile" id="contact_mobile" required maxlength="15" inputmode="numeric" value="<?= bm_h($form['contact_mobile']) ?>" placeholder="10-digit mobile">
    </label>
    <label class="span-2">District
      <input type="text" name="district" id="district" required maxlength="120" value="<?= bm_h($form['district']) ?>">
    </label>

    <div id="event-box" class="event-pick-box">
      <p class="hint">Events for this age (tick all that apply)</p>
      <div class="event-row">
        <input type="checkbox" class="ev" id="ev-single" name="events[]" value="single" <?= in_array('single', $form['events'], true) ? 'checked' : '' ?>>
        <label for="ev-single">Single<?php if ($isPaid && (int)$tournament['fee_single'] > 0): ?> <span class="muted">(<?= bm_h(bm_rupees((int)$tournament['fee_single'])) ?>)</span><?php endif; ?></label>
      </div>
      <div class="event-row">
        <input type="checkbox" class="ev" id="ev-double" name="events[]" value="double" <?= in_array('double', $form['events'], true) ? 'checked' : '' ?>>
        <label for="ev-double" id="lab-double">Doubles<?php if ($isPaid && (int)$tournament['fee_double'] > 0): ?> <span class="muted">(<?= bm_h(bm_rupees((int)$tournament['fee_double'])) ?>)</span><?php endif; ?></label>
      </div>
      <div class="event-row">
        <input type="checkbox" class="ev" id="ev-mix" name="events[]" value="mix" <?= in_array('mix', $form['events'], true) ? 'checked' : '' ?>>
        <label for="ev-mix">Mix Double<?php if ($isPaid && (int)$tournament['fee_mix'] > 0): ?> <span class="muted">(<?= bm_h(bm_rupees((int)$tournament['fee_mix'])) ?>)</span><?php endif; ?></label>
      </div>
      <?php if ($isPaid): ?>
      <p class="fee-total" id="fee-total"></p>
      <?php endif; ?>
    </div>

    <div id="double-box" class="partner-box form-grid">
      <h2>Doubles partner</h2>
      <p class="hint">Required because Doubles is selected. Same gender as the player.</p>
      <label>Partner BAI ID<input type="text" name="d_bai_id" id="d_bai_id" maxlength="80" value="<?= bm_h($form['d_bai_id']) ?>"></label>
      <label>Partner PBI ID<input type="text" name="d_pbi_id" id="d_pbi_id" maxlength="80" value="<?= bm_h($form['d_pbi_id']) ?>"></label>
      <p class="hint span-2" id="d-id-note"></p>
      <label>Partner name<input type="text" name="d_full_name" id="d_full_name" maxlength="160" value="<?= bm_h($form['d_full_name']) ?>"></label>
      <p class="hint" id="d-gender-hint"></p>
      <label>Partner father’s name<input type="text" name="d_father_name" id="d_father_name" maxlength="160" value="<?= bm_h($form['d_father_name']) ?>"></label>
      <label>Partner contact<input type="tel" name="d_contact_mobile" id="d_contact_mobile" maxlength="15" inputmode="numeric" value="<?= bm_h($form['d_contact_mobile']) ?>"></label>
      <label class="span-2">Partner district<input type="text" name="d_district" id="d_district" maxlength="120" value="<?= bm_h($form['d_district']) ?>"></label>
    </div>

    <div id="mix-box" class="partner-box form-grid">
      <h2>Mix Double partner</h2>
      <p class="hint">Required because Mix Double is selected. Opposite gender (boy + girl).</p>
      <label>Partner BAI ID<input type="text" name="m_bai_id" id="m_bai_id" maxlength="80" value="<?= bm_h($form['m_bai_id']) ?>"></label>
      <label>Partner PBI ID<input type="text" name="m_pbi_id" id="m_pbi_id" maxlength="80" value="<?= bm_h($form['m_pbi_id']) ?>"></label>
      <p class="hint span-2" id="m-id-note"></p>
      <label>Partner name<input type="text" name="m_full_name" id="m_full_name" maxlength="160" value="<?= bm_h($form['m_full_name']) ?>"></label>
      <p class="hint" id="m-gender-hint"></p>
      <label>Partner father’s name<input type="text" name="m_father_name" id="m_father_name" maxlength="160" value="<?= bm_h($form['m_father_name']) ?>"></label>
      <label>Partner contact<input type="tel" name="m_contact_mobile" id="m_contact_mobile" maxlength="15" inputmode="numeric" value="<?= bm_h($form['m_contact_mobile']) ?>"></label>
      <label class="span-2">Partner district<input type="text" name="m_district" id="m_district" maxlength="120" value="<?= bm_h($form['m_district']) ?>"></label>
    </div>

    <?php if ($isPaid): ?>
    <div id="pay-box" class="partner-box pay-box form-grid">
      <h2>Payment</h2>
      <p class="hint">Pay by QR / UPI or by bank transfer. Then fill date of payment, reference no / UTR, and sender name. No screenshot. The organiser will check the bank statement and accept or decline.</p>
      <?php if (!empty($tournament['qr_file'])): ?>
        <p><img class="qr-img" src="join-qr.php?t=<?= bm_h(urlencode($token)) ?>" alt="Payment QR"></p>
      <?php endif; ?>
      <?php if (trim((string)($tournament['upi_id'] ?? '')) !== ''): ?>
        <p><strong>UPI:</strong> <?= bm_h((string)$tournament['upi_id']) ?></p>
      <?php endif; ?>
      <?php if (trim((string)($tournament['bank_account'] ?? '')) !== '' || trim((string)($tournament['bank_ifsc'] ?? '')) !== '' || trim((string)($tournament['bank_name'] ?? '')) !== ''): ?>
        <div class="bank-pay">
          <p><strong>Or pay to bank account</strong></p>
          <?php if (trim((string)($tournament['bank_name'] ?? '')) !== ''): ?>
            <p>Bank: <?= bm_h((string)$tournament['bank_name']) ?></p>
          <?php endif; ?>
          <?php if (trim((string)($tournament['bank_account'] ?? '')) !== ''): ?>
            <p>Account number: <?= bm_h((string)$tournament['bank_account']) ?></p>
          <?php endif; ?>
          <?php if (trim((string)($tournament['bank_ifsc'] ?? '')) !== ''): ?>
            <p>IFSC: <?= bm_h((string)$tournament['bank_ifsc']) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <label>Date of payment (dd-mm-yyyy)
        <input type="text" name="payment_date" id="payment_date" maxlength="10" placeholder="dd-mm-yyyy" value="<?= bm_h($form['payment_date']) ?>">
      </label>
      <label>Reference no / UTR
        <input type="text" name="payment_utr" id="payment_utr" inputmode="numeric" maxlength="18" pattern="[0-9]{8,18}" value="<?= bm_h($form['payment_utr']) ?>" placeholder="digits only">
      </label>
      <label class="span-2">Sender name
        <input type="text" name="payment_sender" id="payment_sender" maxlength="160" value="<?= bm_h($form['payment_sender']) ?>" placeholder="Name as on the bank / UPI account that paid">
      </label>
    </div>
    <?php endif; ?>

    <div class="form-actions">
      <button type="submit" name="save_parent" value="1" class="btn btn-primary">Save</button>
    </div>
  </form>
</section>
<script>
(function(){
  var gender = document.getElementById('gender');
  var evBox = document.getElementById('event-box');
  var dBox = document.getElementById('double-box');
  var mBox = document.getElementById('mix-box');
  var payBox = document.getElementById('pay-box');
  var evSingle = document.getElementById('ev-single');
  var evDouble = document.getElementById('ev-double');
  var evMix = document.getElementById('ev-mix');
  var labDouble = document.getElementById('lab-double');
  var dHint = document.getElementById('d-gender-hint');
  var mHint = document.getElementById('m-gender-hint');
  var dName = document.getElementById('d_full_name');
  var mName = document.getElementById('m_full_name');
  var dFather = document.getElementById('d_father_name');
  var dContact = document.getElementById('d_contact_mobile');
  var dDist = document.getElementById('d_district');
  var mFather = document.getElementById('m_father_name');
  var mContact = document.getElementById('m_contact_mobile');
  var mDist = document.getElementById('m_district');
  var feeEl = document.getElementById('fee-total');
  var utr = document.getElementById('payment_utr');
  var payDate = document.getElementById('payment_date');
  var paySender = document.getElementById('payment_sender');
  var fees = {
    single: <?= (int)$tournament['fee_single'] ?>,
    double: <?= (int)$tournament['fee_double'] ?>,
    mix: <?= (int)$tournament['fee_mix'] ?>
  };
  var paid = <?= $isPaid ? 'true' : 'false' ?>;
  function g(){ return String(gender.value || ''); }
  function total(){
    var t = 0;
    if (evSingle && evSingle.checked) t += fees.single;
    if (evDouble && evDouble.checked) t += fees.double;
    if (evMix && evMix.checked) t += fees.mix;
    return t;
  }
  function sync(){
    var hasG = g() === 'boy' || g() === 'girl';
    evBox.style.display = hasG ? 'block' : 'none';
    if (g() === 'boy') labDouble.firstChild.textContent = 'Double Boys';
    else if (g() === 'girl') labDouble.firstChild.textContent = 'Double Girls';
    else labDouble.firstChild.textContent = 'Doubles';
    var showD = hasG && evDouble.checked;
    var showM = hasG && evMix.checked;
    dBox.style.display = showD ? 'grid' : 'none';
    mBox.style.display = showM ? 'grid' : 'none';
    dName.required = showD;
    mName.required = showM;
    if (dFather) dFather.required = showD;
    if (dContact) dContact.required = showD;
    if (dDist) dDist.required = showD;
    if (mFather) mFather.required = showM;
    if (mContact) mContact.required = showM;
    if (mDist) mDist.required = showM;
    dHint.textContent = showD ? ('Partner gender: ' + (g() === 'girl' ? 'Girl' : 'Boy') + ' (same as player)') : '';
    mHint.textContent = showM ? ('Partner gender: ' + (g() === 'girl' ? 'Boy' : 'Girl') + ' (opposite, for Mix Double)') : '';
    var tot = total();
    if (feeEl) feeEl.textContent = tot > 0 ? ('Total to pay: ₹' + tot) : '';
    if (payBox) {
      var showPay = paid && tot > 0;
      payBox.style.display = showPay ? 'grid' : 'none';
      if (utr) utr.required = showPay;
      if (payDate) payDate.required = showPay;
      if (paySender) paySender.required = showPay;
    }
  }
  gender.addEventListener('change', sync);
  evSingle.addEventListener('change', sync);
  evDouble.addEventListener('change', sync);
  evMix.addEventListener('change', sync);
  if (utr) utr.addEventListener('input', function(){
    utr.value = utr.value.replace(/[^0-9]/g, '');
  });
  sync();

  var lookupToken = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
  function watchIds(baiId, pbiId, fields, noteId, useGender) {
    var baiEl = document.getElementById(baiId);
    var pbiEl = document.getElementById(pbiId);
    var note = document.getElementById(noteId);
    var loaded = null;
    function val(el) { return el ? String(el.value || '') : ''; }
    function fill(el, key) {
      if (!el) return;
      var incoming = loadedNext[key] || '';
      if (val(el) === '' || (loaded && val(el) === (loaded[key] || ''))) el.value = incoming;
    }
    var loadedNext = null;
    function apply(data) {
      if (data && data.conflict) {
        if (note) note.textContent = data.message || 'These IDs belong to different players.';
        return;
      }
      if (data && data.found) {
        loadedNext = data;
        fill(fields.name, 'full_name');
        fill(fields.father, 'father_name');
        fill(fields.contact, 'contact_mobile');
        fill(fields.district, 'district');
        if (useGender && fields.gender && (val(fields.gender) === '' || (loaded && val(fields.gender) === (loaded.gender || '')))) {
          fields.gender.value = data.gender || '';
        }
        if (baiEl && val(baiEl).trim() === '' && data.bai_id) baiEl.value = data.bai_id;
        if (pbiEl && val(pbiEl).trim() === '' && data.pbi_id) pbiEl.value = data.pbi_id;
        loaded = data;
        if (note) note.textContent = 'Saved details filled in. You can change any field.';
        if (useGender) sync();
        return;
      }
      if (loaded) {
        [['name','full_name'],['father','father_name'],['contact','contact_mobile'],['district','district']].forEach(function(pair){
          var el = fields[pair[0]];
          if (el && val(el) === (loaded[pair[1]] || '')) el.value = '';
        });
        if (useGender && fields.gender && val(fields.gender) === (loaded.gender || '')) fields.gender.value = '';
        loaded = null;
        if (useGender) sync();
      }
      if (note && noteId !== 'player-id-note') note.textContent = '';
      if (note && noteId === 'player-id-note') note.textContent = 'If this BAI ID or PBI ID was saved before, name, gender, father, contact and district fill in. Empty fields can be typed. Every field can be changed.';
    }
    function run() {
      var bai = baiEl ? baiEl.value.trim() : '';
      var pbi = pbiEl ? pbiEl.value.trim() : '';
      if (!bai && !pbi) { apply({found:false}); return; }
      var url = 'join-lookup.php?t=' + encodeURIComponent(lookupToken) + '&bai=' + encodeURIComponent(bai) + '&pbi=' + encodeURIComponent(pbi);
      fetch(url).then(function(r){ return r.json(); }).then(apply).catch(function(){});
    }
    if (baiEl) { baiEl.addEventListener('change', run); baiEl.addEventListener('blur', run); }
    if (pbiEl) { pbiEl.addEventListener('change', run); pbiEl.addEventListener('blur', run); }
  }
  watchIds('bai_id', 'pbi_id', {
    name: document.getElementById('full_name'),
    gender: gender,
    father: document.getElementById('father_name'),
    contact: document.getElementById('contact_mobile'),
    district: document.getElementById('district')
  }, 'player-id-note', true);
  watchIds('d_bai_id', 'd_pbi_id', {
    name: dName,
    father: dFather,
    contact: dContact,
    district: dDist
  }, 'd-id-note', false);
  watchIds('m_bai_id', 'm_pbi_id', {
    name: mName,
    father: mFather,
    contact: mContact,
    district: mDist
  }, 'm-id-note', false);
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
