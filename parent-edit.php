<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();
$pdo = bm_db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM parent_submissions WHERE id=?');
$st->execute([$id]);
$raw = $st->fetch();
if (!$raw) {
    bm_flash('error', 'Parent entry not found.');
    bm_redirect('tournaments.php');
}

$tstmt = $pdo->prepare('SELECT * FROM tournaments WHERE id=?');
$tstmt->execute([(int)$raw['tournament_id']]);
$tournament = $tstmt->fetch();
if (!$tournament) {
    bm_flash('error', 'Tournament not found.');
    bm_redirect('tournaments.php');
}

$sub = bm_parent_load_receipt($pdo, (string)$raw['receipt_code'], (int)$tournament['id']);
if (!$sub) {
    bm_flash('error', 'Parent entry not found.');
    bm_redirect('tournament.php?id=' . (int)$tournament['id']);
}

$redir = 'tournament.php?id=' . (int)$tournament['id'];

if (isset($_POST['delete_parent'])) {
    try {
        bm_parent_delete_submission($pdo, $sub);
        bm_flash('success', 'Parent entry removed. It is no longer in the letter or Excel.');
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
    }
    bm_redirect($redir);
}

if (isset($_POST['save_parent_edit'])) {
    try {
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
        bm_flash('success', 'Parent entry updated. Letter and Excel will use the new details.');
        bm_redirect($redir);
    } catch (Throwable $e) {
        bm_flash('error', $e->getMessage());
        $sub = bm_parent_load_receipt($pdo, (string)$raw['receipt_code'], (int)$tournament['id']) ?: $sub;
    }
}

$p = $sub['primary'] ?: [];
$d = $sub['double_partner'] ?: [];
$m = $sub['mix_partner'] ?: [];
$eventLabels = [];
foreach ($sub['events'] as $code) {
    $eventLabels[] = bm_event_label($code);
}
$pageTitle = 'Edit parent entry · ' . $tournament['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow"><?= bm_h($tournament['name']) ?></p>
    <h1>Edit parent entry</h1>
    <p class="lede">Change the details sent by the parent. This updates the letter and Excel. Removing the entry takes it out of the letter. Assign-players (old method) is not affected.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="letter.php?id=<?= (int)$tournament['id'] ?>">Open letter PDF</a>
    <a class="btn" href="tournament.php?id=<?= (int)$tournament['id'] ?>">Back</a>
  </div>
</section>

<section class="panel">
  <p><strong>Age category:</strong> <?= bm_h((string)(($sub['category']['name'] ?? ''))) ?></p>
  <p><strong>Events:</strong> <?= bm_h(implode(', ', $eventLabels)) ?></p>
  <form method="post" class="form form-grid">
    <input type="hidden" name="id" value="<?= $id ?>">
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

    <div class="form-actions span-2">
      <button type="submit" name="save_parent_edit" value="1" class="btn btn-primary">Save changes</button>
      <button type="submit" name="delete_parent" value="1" class="btn btn-danger" onclick="return confirm('Remove this parent entry from the tournament, letter and Excel?');">Remove entry</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
