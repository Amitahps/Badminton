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
        $dobRaw = trim((string)($_POST['dob'] ?? ''));
        $dob = null;
        if ($dobRaw !== '') {
            $dob = bm_parse_date_input($dobRaw);
        }
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        $address = trim((string)($_POST['address'] ?? $_POST['remarks'] ?? ''));

        if ($fullName === '') {
            throw new RuntimeException('Player name is required.');
        }
        if (!in_array($gender, ['boy', 'girl'], true)) {
            throw new RuntimeException('Select Boy or Girl.');
        }
        if ($aadhaar !== '' && strlen($aadhaar) !== 12) {
            throw new RuntimeException('Aadhaar number must be 12 digits.');
        }
        bm_assert_unique_player_ids($pdo, $bai, $pbi, $id);

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
                dob_certificate_file=?, mobile=?, remarks=?, address=?,
                updated_at=datetime('now','localtime')
                WHERE id=?")->execute([
                $fullName, $gender,
                $bai !== '' ? $bai : null, $pbi !== '' ? $pbi : null,
                $aadhaar !== '' ? $aadhaar : null, $aadhaarFile,
                $dob, $dobFile,
                $mobile !== '' ? $mobile : null,
                $address !== '' ? $address : null,
                $address !== '' ? $address : null,
                $id,
            ]);
            bm_flash('success', 'Player details updated.');
            bm_redirect('players.php');
        } else {
            $pdo->prepare("INSERT INTO players (
                full_name, gender, age_category_id, event_code,
                bai_id, pbi_id, aadhaar_no, aadhaar_file, dob, dob_certificate_file,
                mobile, remarks, address
            ) VALUES (?,?,NULL,NULL,?,?,?,?,?,?,?,?,?)")->execute([
                $fullName, $gender,
                $bai !== '' ? $bai : null, $pbi !== '' ? $pbi : null,
                $aadhaar !== '' ? $aadhaar : null, $aadhaarFile,
                $dob, $dobFile,
                $mobile !== '' ? $mobile : null,
                $address !== '' ? $address : null,
                $address !== '' ? $address : null,
            ]);
            bm_flash('success', 'Player added to the list. Age category and events are chosen inside each tournament.');
            bm_redirect('players.php');
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
$dobDisplay = '';
if (!empty($row['dob'])) {
    $dobDisplay = bm_fmt_date((string)$row['dob']);
    if ($dobDisplay === '—') {
        $dobDisplay = (string)$row['dob'];
    }
} elseif (!empty($_POST['dob'])) {
    $dobDisplay = trim((string)$_POST['dob']);
}
$addressDisplay = bm_player_address($row ?: []);
if ($addressDisplay === '' && !empty($_POST['address'])) {
    $addressDisplay = trim((string)$_POST['address']);
}
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Player</p>
    <h1><?= $id ? 'Edit player' : 'Create player' ?></h1>
    <p class="lede">Enter player name and details once. Age categories and events are decided later inside each tournament.</p>
  </div>
</section>
<section class="panel">
  <form method="post" enctype="multipart/form-data" class="form form-grid">
    <label>Player name
      <input type="text" name="full_name" required maxlength="160" value="<?= bm_h($row['full_name'] ?? ($_POST['full_name'] ?? '')) ?>">
    </label>
    <label>Gender
      <select name="gender" required>
        <option value="boy" <?= $gender === 'boy' ? 'selected' : '' ?>>Boy</option>
        <option value="girl" <?= $gender === 'girl' ? 'selected' : '' ?>>Girl</option>
      </select>
    </label>
    <label>BAI ID<input type="text" name="bai_id" maxlength="80" value="<?= bm_h($row['bai_id'] ?? '') ?>"></label>
    <label>PBA ID<input type="text" name="pbi_id" maxlength="80" value="<?= bm_h($row['pbi_id'] ?? '') ?>"></label>
    <label>Aadhaar number
      <input type="text" name="aadhaar_no" inputmode="numeric" maxlength="14" value="<?= bm_h($row['aadhaar_no'] ?? '') ?>" placeholder="12 digits">
    </label>
    <label>Date of birth (dd-mm-yyyy)
      <input type="text" name="dob" inputmode="numeric" maxlength="10" placeholder="dd-mm-yyyy" value="<?= bm_h($dobDisplay) ?>">
    </label>
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
    <label class="span-2">Address<textarea name="address" rows="3"><?= bm_h($addressDisplay) ?></textarea></label>
    <div class="form-actions span-2">
      <button type="submit" class="btn btn-primary"><?= $id ? 'Save player' : 'Save player' ?></button>
      <a class="btn" href="players.php">Cancel</a>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
