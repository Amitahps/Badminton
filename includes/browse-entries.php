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
$exportBase = $filterHidden;
if ($q !== '') {
    $exportBase['q'] = $q;
}
$excelAll = $filterAction . '?' . http_build_query(array_merge($exportBase, ['format' => 'csv']));
$catChoices = [];
foreach ($grouped as $catName => $list) {
    $cid = (int)(($list[0]['category_id'] ?? 0));
    if ($cid > 0) {
        $catChoices[$cid] = $catName;
    }
}
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
  <?php if (!$isReceipts && $total > 0): ?>
  <div class="page-actions no-print">
    <button type="button" class="btn btn-primary" data-print-all>Print / PDF — all</button>
    <a class="btn" href="<?= bm_h($excelAll) ?>">Excel — all</a>
  </div>
  <?php endif; ?>
</section>
<p class="print-only print-title"><?= bm_h((string)($tournament['name'] ?? 'Tournament')) ?><br>Confirmed entries<?php if ($q !== ''): ?> · <?= bm_h($q) ?><?php endif; ?></p>
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
  <?php if (!$isReceipts && $catChoices): ?>
  <form class="filters" id="cat-export" onsubmit="return false;">
    <select name="category_id" id="cat-export-id">
      <option value="">One category…</option>
      <?php foreach ($catChoices as $cid => $cname): ?>
        <option value="<?= (int)$cid ?>"><?= bm_h($cname) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn" data-print-cat-select>Print / PDF this category</button>
    <button type="button" class="btn" data-excel-cat-select>Excel this category</button>
  </form>
  <?php endif; ?>
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
  <?php $cid = (int)(($list[0]['category_id'] ?? 0)); ?>
  <details class="cat-drop" data-cat-id="<?= $cid ?>"<?= $openAll ? ' open' : '' ?>>
    <summary>
      <?= bm_h($catName) ?> <span class="muted">(<?= count($list) ?>)</span>
      <?php if (!$isReceipts && $cid > 0): ?>
      <span class="cat-export no-print">
        <button type="button" class="btn btn-sm" data-print-cat="<?= $cid ?>">Print / PDF</button>
        <a class="btn btn-sm" href="<?= bm_h($filterAction . '?' . http_build_query(array_merge($exportBase, ['format' => 'csv', 'category_id' => (string)$cid]))) ?>">Excel</a>
      </span>
      <?php endif; ?>
    </summary>
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
<?php if (!$isReceipts && $total > 0): ?>
<script>
(function(){
  var excelBase = <?= json_encode($filterAction . '?' . http_build_query($exportBase), JSON_UNESCAPED_UNICODE) ?>;
  function cats(){ return Array.prototype.slice.call(document.querySelectorAll('.cat-drop[data-cat-id]')); }
  function resetPrint(){
    document.body.classList.remove('print-one');
    cats().forEach(function(d){ d.classList.remove('print-hide'); });
  }
  function printAll(){
    resetPrint();
    cats().forEach(function(d){ d.open = true; });
    window.print();
  }
  function printCat(id){
    resetPrint();
    document.body.classList.add('print-one');
    cats().forEach(function(d){
      var match = String(d.getAttribute('data-cat-id')) === String(id);
      d.open = match;
      d.classList.toggle('print-hide', !match);
    });
    window.print();
  }
  document.addEventListener('click', function(e){
    var t = e.target;
    if (t.closest && t.closest('.cat-export')) { e.stopPropagation(); }
    if (t.closest && t.closest('[data-print-all]')) { e.preventDefault(); printAll(); return; }
    var one = t.closest && t.closest('[data-print-cat]');
    if (one) { e.preventDefault(); printCat(one.getAttribute('data-print-cat')); return; }
    if (t.closest && t.closest('[data-print-cat-select]')) {
      e.preventDefault();
      var id = (document.getElementById('cat-export-id') || {}).value;
      if (!id) { window.alert('Choose a category first.'); return; }
      printCat(id);
      return;
    }
    if (t.closest && t.closest('[data-excel-cat-select]')) {
      e.preventDefault();
      var id2 = (document.getElementById('cat-export-id') || {}).value;
      if (!id2) { window.alert('Choose a category first.'); return; }
      var join = excelBase.indexOf('?') >= 0 ? '&' : '?';
      window.location.href = excelBase + join + 'format=csv&category_id=' + encodeURIComponent(id2);
    }
  }, true);
  window.addEventListener('afterprint', resetPrint);
})();
</script>
<?php endif; ?>
