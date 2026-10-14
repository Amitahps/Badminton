<?php
declare(strict_types=1);
/** @var array<string,mixed> $tournament */
/** @var 'confirmed'|'receipts' $browseMode */
/** @var array<string, list<array<string,mixed>>> $grouped */
/** @var string $q */
/** @var string $filterAction */
/** @var array<string,string> $filterHidden */
/** @var string $token */
/** @var bool $openAll */

$browseMode = $browseMode ?? 'confirmed';
$grouped = $grouped ?? [];
$q = $q ?? '';
$filterAction = $filterAction ?? '';
$filterHidden = $filterHidden ?? [];
$token = $token ?? '';
$openAll = !empty($openAll) || trim($q) !== '';
$total = 0;
foreach ($grouped as $list) {
    $total += count($list);
}
$isReceipts = $browseMode === 'receipts';
?>
<section class="page-head">
  <div>
    <h1><?= bm_h((string)($tournament['name'] ?? 'Tournament')) ?></h1>
    <p class="lede"><?php if ($isReceipts): ?>
      Receipts for players whose online payment the organiser has confirmed. Entries still pending cash are not listed.
    <?php else: ?>
      Confirmed entries. Players accepted by the organiser are listed here, including those with fee pending.
    <?php endif; ?></p>
  </div>
</section>
<section class="panel no-print">
  <form method="get" action="<?= bm_h($filterAction) ?>" class="filters">
    <?php foreach ($filterHidden as $k => $v): ?>
      <input type="hidden" name="<?= bm_h($k) ?>" value="<?= bm_h($v) ?>">
    <?php endforeach; ?>
    <input type="search" name="q" value="<?= bm_h($q) ?>" placeholder="Player name" maxlength="120">
    <button type="submit" class="btn btn-primary"><?= $isReceipts ? 'Find receipt' : 'Find player' ?></button>
    <?php if ($q !== ''): ?>
      <a class="btn" href="<?= bm_h($filterAction . ($filterHidden ? ('?' . http_build_query($filterHidden)) : '')) ?>">Clear</a>
    <?php endif; ?>
  </form>
</section>
<?php if ($total === 0): ?>
<section class="panel">
  <p class="empty"><?php if ($q !== ''): ?>
    No player matching “<?= bm_h($q) ?>”.
  <?php elseif ($isReceipts): ?>
    No confirmed online payments yet.
  <?php else: ?>
    No confirmed entries yet.
  <?php endif; ?></p>
</section>
<?php else: ?>
  <?php foreach ($grouped as $catName => $list): ?>
  <details class="cat-drop"<?= $openAll ? ' open' : '' ?>>
    <summary><?= bm_h($catName) ?> <span class="muted">(<?= count($list) ?>)</span></summary>
    <table class="table">
      <thead>
        <tr>
          <th>Player</th>
          <?php if ($isReceipts): ?>
            <th>Events</th>
            <th>UTR</th>
            <th></th>
          <?php else: ?>
            <th>Gender</th>
            <th>BAI ID</th>
            <th>PBI ID</th>
            <th>Father</th>
            <th>Contact</th>
            <th>District</th>
            <th>Events</th>
            <th>Payment</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($list as $row): ?>
        <tr>
          <td>
            <strong><?= bm_h((string)($row['full_name'] ?? '')) ?></strong>
            <?php if (!$isReceipts && trim((string)($row['d_full_name'] ?? '')) !== ''): ?>
              <div class="muted">Doubles: <?= bm_h((string)$row['d_full_name']) ?></div>
            <?php endif; ?>
            <?php if (!$isReceipts && trim((string)($row['m_full_name'] ?? '')) !== ''): ?>
              <div class="muted">Mix: <?= bm_h((string)$row['m_full_name']) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($isReceipts): ?>
            <td><?= bm_h(bm_parent_row_event_labels($row)) ?></td>
            <td><?= bm_h((string)($row['payment_utr'] ?? '')) ?></td>
            <td class="right">
              <a class="btn btn-sm btn-primary" href="join-receipt.php?t=<?= bm_h(urlencode($token)) ?>&amp;r=<?= bm_h(urlencode((string)$row['receipt_code'])) ?>">Download receipt</a>
            </td>
          <?php else: ?>
            <td><?= bm_h(bm_gender_label((string)($row['gender'] ?? ''))) ?></td>
            <td><?= bm_h((string)($row['bai_id'] ?? '')) ?></td>
            <td><?= bm_h((string)($row['pbi_id'] ?? '')) ?></td>
            <td><?= bm_h((string)(($row['player_father'] ?? '') !== '' ? $row['player_father'] : ($row['father_name'] ?? ''))) ?></td>
            <td><?= bm_h((string)(($row['mobile'] ?? '') !== '' ? $row['mobile'] : ($row['contact_mobile'] ?? ''))) ?></td>
            <td><?= bm_h((string)($row['address'] ?? '')) ?></td>
            <td><?= bm_h(bm_parent_row_event_labels($row)) ?></td>
            <td><?= bm_h(bm_parent_payment_label((string)($row['payment_status'] ?? ''), (int)($row['payment_amount'] ?? 0))) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endforeach; ?>
<?php endif; ?>
