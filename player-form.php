<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        bm_flash('error', 'Player not found.');
        bm_redirect('players.php');
    }
}

$categories = $pdo->query('SELECT * FROM age_categories ORDER BY sort_order, name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$categories) {
            throw new RuntimeException('Create an age category first.');
        }
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $playType = strtolower(trim((string)($_POST['play_type'] ?? '')));
        $partner = trim((string)($_POST['partner_name'] ?? ''));
        $catId = (int)($_POST['age_category_id'] ?? 0);
        $bai = trim((string)($_POST['bai_id'] ?? ''));
        $pbi = trim((string)($_POST['pbi_id'] ?? ''));
        $aadhaar = bm_clean_aadhaar((string)($_POST['aadhaar_no'] ?? ''));
        $dob = trim((string)($_POST['dob'] ?? ''));
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        if ($fullName === '') {
            throw new RuntimeException('Player name is required.');
        }
        if (!in_array($playType, ['single', 'double'], true)) {
            throw new RuntimeException('Select Single or Double.');
        }
        if ($playType === 'double' && $partner === '') {
            throw new RuntimeException('Partner name is required for Double.');
        }
        if ($playType === 'single') {
            $partner = '';
        }
        if ($catId <= 0) {
            throw new RuntimeException('Age category is required.');
        }
        if ($aadhaar !== '' && strlen($aadhaar) !== 12) {
            throw new RuntimeException('Aadhaar number must be 12 digits.');
        }

        $aadhaarFile = $row['aadhaar_file'] ?? null;
        $dobFile = $row['dob_certificate_file'] ?? null;
        $newAadhaar = bm_save_upload($_FILES['aadhaar_file'] ?? [], 'aadhaar');
        $newDob = bm_save_upload($_FILES['dob_certificate_file'] ?? [], 'dobcert');
        if ($newAadhaar) {
            bm_delete_upload($aadhaarFile);
            $aadhaarFile = $newAadhaar;
        }
        if ($newDob) {
            bm_delete_upload($dobFile);
            $dobFile = $newDob;
        }
        if (!empty($_POST['remove_aadhaar_file']) && !$newAadhaar) {
            bm_delete_upload($aadhaarFile);
            $aadhaarFile = null;
        }
        if (!empty($_POST['remove_dob_certificate_file']) && !$newDob) {
            bm_delete_upload($dobFile);
            $dobFile = null;
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE players SET
                full_name=?, play_type=?, partner_name=?, age_category_id=?,
                bai_id=?, pbi_id=?, aadhaar_no=?, aadhaar_file=?, dob=?,
                dob_certificate_file=?, mobile=?, remarks=?,
                updated_at=datetime('now','localtime')
                WHERE id=?")->execute([
                $fullName, $playType, $partner !== '' ? $partner : null, $catId,
                $bai !== '' ? $bai : null, $pbi !== '' ? $pbi : null,
                $aadhaar !== '' ? $aadhaar : null, $aadhaarFile,
                $dob !== '' ? $dob : null, $dobFile,
                $mobile !== '' ? $mobile : null, $remarks !== '' ? $remarks : null,
                $id,
            ]);
            bm_flash('success', 'Player details updated.');
        } else {
            $pdo->prepare("INSERT INTO players (
                full_name, play_type, partner_name, age_category_id,
                bai_id, pbi_id, aadhaar_no, aadhaar_file, dob, dob_certificate_file,
                mobile, remarks
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $fullName, $playType, $partner !== '' ? $partner : null, $catId,
                $bai !== '' ? $bai : null, $pbi !== '' ? $pbi : null,
                $aadhaar !== '' ? $aadhaar : null, $aadhaarFile,
                $dob !== '' ? $dob : null, $dobFile,
                $mobile !== '' ? $mobile : null, $remarks !== '' ? $remarks : null,
            ]);
            bm_flash('success', 'Player saved.');
        }
        bm_redirect('players.php');
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
    }
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: $row;
    }
}

$pageTitle = ($id ? 'Edit' : 'Add') . ' Player · Badminton';
require __DIR__ . '/includes/header.php';
$playType = $row['play_type'] ?? 'single';
?>
<section class="page-head"><div><p class="eyebrow">Player</p><h1><?= $id ? 'Edit player' : 'Add player' ?></h1></div></section>
<?php if (!$categories): ?>
<section class="panel"><p class="empty">Create at least one age category first. <a href="category-form.php">Create category</a></p></section>
<?php else: ?>
<section class="panel">
  <form method="post" enctype="multipart/form-data" class="form form-grid">
    <label>Player name
      <input type="text" name="full_name" required maxlength="160" value="<?= bm_h($row['full_name'] ?? '') ?>">
    </label>
    <label>Play type
      <select name="play_type" id="play_type" required>
        <option value="single" <?= $playType === 'single' ? 'selected' : '' ?>>Single</option>
        <option value="double" <?= $playType === 'double' ? 'selected' : '' ?>>Double</option>
      </select>
    </label>
    <label id="partner-wrap" class="<?= $playType === 'double' ? '' : 'is-hidden' ?>">Partner name (for Double)
      <input type="text" name="partner_name" maxlength="160" value="<?= bm_h($row['partner_name'] ?? '') ?>">
    </label>
    <label>Age category
      <select name="age_category_id" required>
        <option value="">Select category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= isset($row['age_category_id']) && (int)$row['age_category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= bm_h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>BAI ID<input type="text" name="bai_id" maxlength="80" value="<?= bm_h($row['bai_id'] ?? '') ?>"></label>
    <label>PBI ID<input type="text" name="pbi_id" maxlength="80" value="<?= bm_h($row['pbi_id'] ?? '') ?>"></label>
    <label>Aadhaar number
      <input type="text" name="aadhaar_no" inputmode="numeric" maxlength="14" value="<?= bm_h($row['aadhaar_no'] ?? '') ?>" placeholder="12 digits">
    </label>
    <label>Date of birth<input type="date" name="dob" value="<?= bm_h($row['dob'] ?? '') ?>"></label>
    <label>Mobile<input type="text" name="mobile" maxlength="20" value="<?= bm_h($row['mobile'] ?? '') ?>"></label>
    <label class="span-2">Aadhaar card file (PDF/image)
      <input type="file" name="aadhaar_file" accept=".pdf,.png,.jpg,.jpeg,.webp">
      <?php if (!empty($row['aadhaar_file'])): ?>
        <span class="file-meta">Current: <a href="view-file.php?f=<?= urlencode($row['aadhaar_file']) ?>" target="_blank"><?= bm_h($row['aadhaar_file']) ?></a>
          <label class="check-inline"><input type="checkbox" name="remove_aadhaar_file" value="1"> Remove</label>
        </span>
      <?php endif; ?>
    </label>
    <label class="span-2">Date of birth certificate (PDF/image)
      <input type="file" name="dob_certificate_file" accept=".pdf,.png,.jpg,.jpeg,.webp">
      <?php if (!empty($row['dob_certificate_file'])): ?>
        <span class="file-meta">Current: <a href="view-file.php?f=<?= urlencode($row['dob_certificate_file']) ?>" target="_blank"><?= bm_h($row['dob_certificate_file']) ?></a>
          <label class="check-inline"><input type="checkbox" name="remove_dob_certificate_file" value="1"> Remove</label>
        </span>
      <?php endif; ?>
    </label>
    <label class="span-2">Remarks<textarea name="remarks" rows="3"><?= bm_h($row['remarks'] ?? '') ?></textarea></label>
    <div class="form-actions span-2">
      <button type="submit" class="btn btn-primary">Save player</button>
      <a class="btn" href="players.php">Cancel</a>
    </div>
  </form>
</section>
<script>
(function(){
  var sel=document.getElementById('play_type');
  var wrap=document.getElementById('partner-wrap');
  if(!sel||!wrap)return;
  function sync(){ if(sel.value==='double') wrap.classList.remove('is-hidden'); else wrap.classList.add('is-hidden'); }
  sel.addEventListener('change',sync); sync();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
