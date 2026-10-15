<?php
declare(strict_types=1);
/** @var array<string,mixed> $tournament */
/** @var 'confirmed'|'receipts'|'declined'|'unpaid' $browseMode */
/** @var array<string, list<array<string,mixed>>> $grouped */
/** @var string $q */
/** @var string $filterAction */
/** @var array<string,string> $filterHidden */
/** @var string $token */
/** @var bool $openAll */
/** @var string $view */
/** @var string $receiptsUrl */

$browseMode = $browseMode ?? 'confirmed';
$grouped = $grouped ?? [];
$q = $q ?? '';
$filterAction = $filterAction ?? '';
$filterHidden = $filterHidden ?? [];
$token = $token ?? '';
$view = $view ?? 'category';
if (!in_array($view, ['all', 'category'], true)) {
    $view = 'category';
}
$receiptsUrl = $receiptsUrl ?? '';
$openAll = !empty($openAll) || trim($q) !== '' || $view === 'all';
$total = 0;
$flat = [];
foreach ($grouped as $list) {
    $total += count($list);
    foreach ($list as $row) {
        $flat[] = $row;
    }
}
$isReceipts = $browseMode === 'receipts';
$isDeclined = $browseMode === 'declined';
$isUnpaid = $browseMode === 'unpaid';
$exportBase = $filterHidden;
if ($q !== '') {
    $exportBase['q'] = $q;
}
$excelAll = $filterAction . '?' . http_build_query(array_merge($exportBase, ['format' => 'csv']));
$viewAllUrl = $filterAction . '?' . http_build_query(array_merge($exportBase, ['view' => 'all']));
$viewCatUrl = $filterAction . '?' . http_build_query(array_merge($exportBase, ['view' => 'category']));
$catChoices = [];
foreach ($grouped as $catName => $list) {
    $cid = (int)(($list[0]['category_id'] ?? 0));
    if ($cid > 0) {
        $catChoices[$cid] = $catName;
    }
}

$renderConfirmedCells = static function (array $row, string $mode, string $token): void {
    $isReceipts = $mode === 'receipts';
    $isDeclined = $mode === 'declined';
    $isUnpaid = $mode === 'unpaid';
    echo '<td><strong>' . bm_h((string)($row['full_name'] ?? '')) . '</strong>';
    if (!$isReceipts && trim((string)($row['d_full_name'] ?? '')) !== '') {
        echo '<div class="muted">Doubles: ' . bm_h((string)$row['d_full_name']) . '</div>';
    }
    if (!$isReceipts && trim((string)($row['m_full_name'] ?? '')) !== '') {
        echo '<div class="muted">Mix: ' . bm_h((string)$row['m_full_name']) . '</div>';
    }
    echo '</td>';
    if ($isReceipts) {
        echo '<td>' . bm_h(bm_parent_row_event_labels($row)) . '</td>';
        echo '<td>' . bm_h((string)($row['payment_utr'] ?? '')) . '</td>';
        echo '<td class="right"><a class="btn btn-sm btn-primary" href="join-receipt.php?t=' . bm_h(urlencode($token)) . '&amp;r=' . bm_h(urlencode((string)$row['receipt_code'])) . '">Download receipt</a></td>';
        return;
    }
    if ($isUnpaid) {
        echo '<td>' . bm_h(bm_parent_row_event_labels($row)) . '</td>';
        echo '<td>' . bm_h(bm_rupees((int)($row['payment_amount'] ?? 0))) . '</td>';
        return;
    }
    echo '<td>' . bm_h(bm_gender_label((string)($row['gender'] ?? ''))) . '</td>';
    echo '<td>' . bm_h((string)($row['bai_id'] ?? '')) . '</td>';
    echo '<td>' . bm_h((string)($row['pbi_id'] ?? '')) . '</td>';
    echo '<td>' . bm_h((string)(($row['player_father'] ?? '') !== '' ? $row['player_father'] : ($row['father_name'] ?? ''))) . '</td>';
    echo '<td>' . bm_h((string)(($row['mobile'] ?? '') !== '' ? $row['mobile'] : ($row['contact_mobile'] ?? ''))) . '</td>';
    echo '<td>' . bm_h((string)($row['address'] ?? '')) . '</td>';
    echo '<td>' . bm_h(bm_parent_row_event_labels($row)) . '</td>';
    if ($isDeclined) {
        echo '<td>' . bm_h(bm_decline_reason_label((string)($row['decline_reason'] ?? ''))) . '</td>';
        echo '<td class="right no-print"><a class="btn btn-sm" href="parent-edit.php?id=' . (int)($row['id'] ?? 0) . '">Open</a></td>';
        return;
    }
    echo '<td>' . bm_h(bm_parent_payment_label((string)($row['payment_status'] ?? ''), (int)($row['payment_amount'] ?? 0))) . '</td>';
    echo '<td class="right no-print">';
    if ((string)($row['payment_status'] ?? '') === 'paid') {
        echo '<a class="btn btn-sm btn-primary" href="join-receipt.php?t=' . bm_h(urlencode($token)) . '&amp;r=' . bm_h(urlencode((string)$row['receipt_code'])) . '">Download receipt</a>';
    } else {
        echo '<span class="muted">Receipt after paid</span>';
    }
    echo '</td>';
};
?>
<section class="page-head">
  <div>
    <h1><?= bm_h((string)($tournament['name'] ?? 'Tournament')) ?></h1>
    <p class="lede"><?php if ($isReceipts): ?>
      Receipts for players whose online payment the organiser has confirmed. Entries still pending cash are not listed.
    <?php elseif ($isDeclined): ?>
      Your payment / sender name, payment date / amount sent did not match the bank statement. Open your player details, correct the empty field and submit again.
    <?php elseif ($isUnpaid): ?>
      Entries accepted without online payment. Names, event and amount. Collect cash on the tournament day.
    <?php else: ?>
      List of entries accepted by officials. Download a receipt only after online payment is confirmed. Copy of receipt is mandatory on the day of tournament.
    <?php endif; ?></p>
  </div>
  <?php if (!$isReceipts && $total > 0): ?>
  <div class="page-actions no-print">
    <a class="btn <?= $view === 'all' ? 'btn-primary' : '' ?>" href="<?= bm_h($viewAllUrl) ?>">Show all</a>
    <a class="btn <?= $view === 'category' ? 'btn-primary' : '' ?>" href="<?= bm_h($viewCatUrl) ?>">Category wise</a>
    <button type="button" class="btn btn-primary" data-print-all>Print / PDF all</button>
    <a class="btn btn-primary" href="<?= bm_h($excelAll) ?>">Excel all</a>
  </div>
  <?php endif; ?>
</section>
<p class="print-only print-title"><?= bm_h((string)($tournament['name'] ?? 'Tournament')) ?><br><?php
    if ($isReceipts) {
        echo 'Receipts';
    } elseif ($isDeclined) {
        echo 'Declined entries';
    } elseif ($isUnpaid) {
        echo 'Not paid online';
    } else {
        echo 'Confirmed entries';
    }
?><?php if ($q !== ''): ?> · <?= bm_h($q) ?><?php endif; ?></p>
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
  <?php elseif ($isDeclined): ?>
    No declined entries yet.
  <?php elseif ($isUnpaid): ?>
    No entries waiting for cash / not paid online.
  <?php else: ?>
    No confirmed entries yet.
  <?php endif; ?></p>
</section>
<?php elseif (!$isReceipts && $view === 'all'): ?>
<section class="panel" id="all-entries">
  <table class="table">
    <thead>
      <tr>
        <th>Category</th>
        <th>Player</th>
        <?php if ($isUnpaid): ?>
        <th>Events</th>
        <th>Amount</th>
        <?php else: ?>
        <th>Gender</th>
        <th>BAI ID</th>
        <th>PBI ID</th>
        <th>Father</th>
        <th>Contact</th>
        <th>District</th>
        <th>Events</th>
        <th><?= $isDeclined ? 'Mismatch' : 'Payment' ?></th>
        <th class="no-print"></th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($flat as $row): ?>
      <tr data-cat-id="<?= (int)($row['category_id'] ?? 0) ?>">
        <td><?= bm_h((string)($row['category_name'] ?? '')) ?></td>
        <?php $renderConfirmedCells($row, $browseMode, $token); ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
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
          <?php elseif ($isUnpaid): ?>
            <th>Events</th>
            <th>Amount</th>
          <?php else: ?>
            <th>Gender</th>
            <th>BAI ID</th>
            <th>PBI ID</th>
            <th>Father</th>
            <th>Contact</th>
            <th>District</th>
            <th>Events</th>
            <th><?= $isDeclined ? 'Mismatch' : 'Payment' ?></th>
            <th class="no-print"></th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($list as $row): ?>
        <tr data-cat-id="<?= $cid ?>">
          <?php $renderConfirmedCells($row, $browseMode, $token); ?>
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
  function rows(){ return Array.prototype.slice.call(document.querySelectorAll('tr[data-cat-id]')); }
  function resetPrint(){
    document.body.classList.remove('print-one');
    cats().forEach(function(d){ d.classList.remove('print-hide'); });
    rows().forEach(function(r){ r.classList.remove('print-hide'); });
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
    rows().forEach(function(r){
      r.classList.toggle('print-hide', String(r.getAttribute('data-cat-id')) !== String(id));
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
