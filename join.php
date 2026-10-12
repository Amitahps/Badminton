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

$ageOptions = bm_parent_age_options($pdo);
$dateText = bm_date_range($tournament['date_from'], $tournament['date_to']);
$ownReceipt = bm_own_parent_receipt($token);

if (isset($_POST['save_parent'])) {
    try {
        if (!$ageOptions) {
            throw new RuntimeException('Age categories are not set up yet. Ask the organiser.');
        }
        $result = bm_parent_save_submission($pdo, $tournament, $_POST);
        bm_remember_parent_receipt($token, $result['receipt']);
        bm_flash('success', 'Saved. This copy is only for you — other parents cannot see it.');
        bm_redirect('join-receipt.php?t=' . rawurlencode($token) . '&r=' . rawurlencode($result['receipt']));
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
    }
}

$form = [
    'age_key' => (string)($_POST['age_key'] ?? ''),
    'full_name' => (string)($_POST['full_name'] ?? ''),
    'gender' => (string)($_POST['gender'] ?? ''),
    'bai_id' => (string)($_POST['bai_id'] ?? ''),
    'pbi_id' => (string)($_POST['pbi_id'] ?? ''),
    'address' => (string)($_POST['address'] ?? ''),
    'events' => is_array($_POST['events'] ?? null) ? $_POST['events'] : [],
    'd_full_name' => (string)($_POST['d_full_name'] ?? ''),
    'd_bai_id' => (string)($_POST['d_bai_id'] ?? ''),
    'd_pbi_id' => (string)($_POST['d_pbi_id'] ?? ''),
    'd_address' => (string)($_POST['d_address'] ?? ''),
    'm_full_name' => (string)($_POST['m_full_name'] ?? ''),
    'm_bai_id' => (string)($_POST['m_bai_id'] ?? ''),
    'm_pbi_id' => (string)($_POST['m_pbi_id'] ?? ''),
    'm_address' => (string)($_POST['m_address'] ?? ''),
];

$pageTitle = $tournament['name'] . ' · Parent entry';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Parent entry</p>
    <h1><?= bm_h($tournament['name']) ?></h1>
    <p class="lede">Held at <strong><?= bm_h($tournament['held_at']) ?></strong> on <strong><?= bm_h($dateText) ?></strong>. Fill <strong>one age category</strong> at a time. After save you will see only your own details — not other parents.</p>
  </div>
</section>

<?php if ($ownReceipt): ?>
<p class="hint" style="margin-top:-0.5rem;"><a href="join-receipt.php?t=<?= bm_h(urlencode($token)) ?>&amp;r=<?= bm_h(urlencode($ownReceipt)) ?>">View the details you already sent</a> (only yours).</p>
<?php endif; ?>

<?php if (!$ageOptions): ?>
<section class="panel">
  <p class="empty">Age categories are not ready. Please contact the organiser.</p>
</section>
<?php else: ?>
<section class="panel">
  <form method="post" class="form form-grid" id="parent-form">
    <input type="hidden" name="t" value="<?= bm_h($token) ?>">

    <label class="span-2">1. Age category
      <select name="age_key" id="age_key" required>
        <option value="">Select age</option>
        <?php foreach ($ageOptions as $opt): ?>
          <option value="<?= bm_h($opt['key']) ?>" <?= $form['age_key'] === $opt['key'] ? 'selected' : '' ?>><?= bm_h($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <p class="hint span-2">2. Player details</p>
    <label>Player name
      <input type="text" name="full_name" required maxlength="160" value="<?= bm_h($form['full_name']) ?>">
    </label>
    <label>Gender
      <select name="gender" id="gender" required>
        <option value="">Select</option>
        <option value="boy" <?= $form['gender'] === 'boy' ? 'selected' : '' ?>>Boy</option>
        <option value="girl" <?= $form['gender'] === 'girl' ? 'selected' : '' ?>>Girl</option>
      </select>
    </label>
    <label>BAI ID<input type="text" name="bai_id" maxlength="80" value="<?= bm_h($form['bai_id']) ?>"></label>
    <label>PBA ID<input type="text" name="pbi_id" maxlength="80" value="<?= bm_h($form['pbi_id']) ?>"></label>
    <label class="span-2">Address<textarea name="address" rows="2"><?= bm_h($form['address']) ?></textarea></label>

    <div id="event-box" class="event-pick-box">
      <p class="hint">3. Events for this age (tick all that apply)</p>
      <div class="event-row">
        <input type="checkbox" class="ev" id="ev-single" name="events[]" value="single" <?= in_array('single', $form['events'], true) ? 'checked' : '' ?>>
        <label for="ev-single">Single</label>
      </div>
      <div class="event-row">
        <input type="checkbox" class="ev" id="ev-double" name="events[]" value="double" <?= in_array('double', $form['events'], true) ? 'checked' : '' ?>>
        <label for="ev-double" id="lab-double">Doubles</label>
      </div>
      <div class="event-row">
        <input type="checkbox" class="ev" id="ev-mix" name="events[]" value="mix" <?= in_array('mix', $form['events'], true) ? 'checked' : '' ?>>
        <label for="ev-mix">Mix Double</label>
      </div>
    </div>

    <div id="double-box" class="partner-box">
      <h2>Doubles partner</h2>
      <p class="hint">Required because Doubles is selected. Same gender as the player.</p>
      <label>Partner name<input type="text" name="d_full_name" id="d_full_name" maxlength="160" value="<?= bm_h($form['d_full_name']) ?>"></label>
      <p class="hint" id="d-gender-hint"></p>
      <label>Partner BAI ID<input type="text" name="d_bai_id" maxlength="80" value="<?= bm_h($form['d_bai_id']) ?>"></label>
      <label>Partner PBA ID<input type="text" name="d_pbi_id" maxlength="80" value="<?= bm_h($form['d_pbi_id']) ?>"></label>
      <label class="span-2">Partner address<textarea name="d_address" rows="2"><?= bm_h($form['d_address']) ?></textarea></label>
    </div>

    <div id="mix-box" class="partner-box">
      <h2>Mix Double partner</h2>
      <p class="hint">Required because Mix Double is selected. Opposite gender (boy + girl).</p>
      <label>Partner name<input type="text" name="m_full_name" id="m_full_name" maxlength="160" value="<?= bm_h($form['m_full_name']) ?>"></label>
      <p class="hint" id="m-gender-hint"></p>
      <label>Partner BAI ID<input type="text" name="m_bai_id" maxlength="80" value="<?= bm_h($form['m_bai_id']) ?>"></label>
      <label>Partner PBA ID<input type="text" name="m_pbi_id" maxlength="80" value="<?= bm_h($form['m_pbi_id']) ?>"></label>
      <label class="span-2">Partner address<textarea name="m_address" rows="2"><?= bm_h($form['m_address']) ?></textarea></label>
    </div>

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
  var evDouble = document.getElementById('ev-double');
  var evMix = document.getElementById('ev-mix');
  var labDouble = document.getElementById('lab-double');
  var dHint = document.getElementById('d-gender-hint');
  var mHint = document.getElementById('m-gender-hint');
  var dName = document.getElementById('d_full_name');
  var mName = document.getElementById('m_full_name');
  function g(){ return String(gender.value || ''); }
  function sync(){
    var hasG = g() === 'boy' || g() === 'girl';
    evBox.style.display = hasG ? 'block' : 'none';
    if (g() === 'boy') labDouble.textContent = 'Double Boys';
    else if (g() === 'girl') labDouble.textContent = 'Double Girls';
    else labDouble.textContent = 'Doubles';
    var showD = hasG && evDouble.checked;
    var showM = hasG && evMix.checked;
    dBox.style.display = showD ? 'block' : 'none';
    mBox.style.display = showM ? 'block' : 'none';
    dName.required = showD;
    mName.required = showM;
    dHint.textContent = showD ? ('Partner gender: ' + (g() === 'girl' ? 'Girl' : 'Boy') + ' (same as player)') : '';
    mHint.textContent = showM ? ('Partner gender: ' + (g() === 'girl' ? 'Boy' : 'Girl') + ' (opposite, for Mix Double)') : '';
  }
  gender.addEventListener('change', sync);
  evDouble.addEventListener('change', sync);
  evMix.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
