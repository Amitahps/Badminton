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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $gender = strtolower(trim((string)($_POST['gender'] ?? '')));
        $bai = trim((string)($_POST['bai_id'] ?? ''));
        $pbi = trim((string)($_POST['pbi_id'] ?? ''));
        $aadhaar = bm_clean_aadhaar((string)($_POST['aadhaar_no'] ?? ''));
        $dob = trim((string)($_POST['dob'] ?? ''));
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        if ($fullName === '') {
            throw new RuntimeException('Player name is required.');
        }
        if (!in_array($gender, ['boy', 'girl'], true)) {
            throw new RuntimeException('Select Boy or Girl.');
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
            // Keep existing category/event on edit of basic details
            $pdo->prepare("UPDATE players SET
                full_name=?, gender=?, bai_id=?, pbi_id=?, aadhaar_no=?, aadhaar_file=?, dob=?,
                dob_certificate_file=?, mobile=?, remarks=?,
                updated_at=datetime('now','localtime')
                WHERE id=?")->execute([
                $fullName, $gender,
                $bai !== '' ? $bai : null, $pbi !== '' ? $pbi : null,
                $aadhaar !== '' ? $aadhaar : null, $aadhaarFile,
                $dob !== '' ? $dob : null, $dobFile,
                $mobile !== '' ? $mobile : null, $remarks !== '' ? $remarks : null,
                $id,
            ]);
            bm_flash('success', 'Player details updated.');
            bm_redirect('players.php');
        } else {
            $pdo->prepare("INSERT INTO players (
                full_name, gender, age_category_id, event_code,
                bai_id, pbi_id, aadhaar_no, aadhaar_file, dob, dob_certificate_file,
                mobile, remarks
            ) VALUES (?,?,NULL,NULL,?,?,?,?,?,?,?,?)")->execute([
                $fullName, $gender,
                $bai !== '' ? $bai : null, $pbi !== '' ? $pbi : null,
                $aadhaar !== '' ? $aadhaar : null, $aadhaarFile,
                $dob !== '' ? $dob : null, $dobFile,
                $mobile !== '' ? $mobile : null, $remarks !== '' ? $remarks : null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            bm_flash('success', 'Player created. Now select Age Category and Event.');
            bm_redirect('player-assign.php?id=' . $newId);
        }
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
$gender = $row['gender'] ?? 'boy';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Player</p>
    <h1><?= $id ? 'Edit player' : 'Create player' ?></h1>
    <p class="lede">Enter player name and details. Age category and event are selected in the next step.</p>
  </div>
</section>
<section class="panel">
  <form method="post" enctype="multipart/form-data" class="form form-grid">
    <label>Player name
      <input type="text" name="full_name" required maxlength="160" value="<?= bm_h($row['full_name'] ?? '') ?>">
    </label>
    <label>Gender
      <select name="gender" required>
        <option value="boy" <?= $gender === 'boy' ? 'selected' : '' ?>>Boy</option>
        <option value="girl" <?= $gender === 'girl' ? 'selected' : '' ?>>Girl</option>
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
      <button type="submit" class="btn btn-primary"><?= $id ? 'Save player' : 'Create & select category / event' ?></button>
      <a class="btn" href="players.php">Cancel</a>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
