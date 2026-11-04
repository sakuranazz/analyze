<?php
/** @var list<array> $rows */
use App\Data\Analysis;
use App\View\Format as F;

$byLot = [];
foreach ($rows as $r) {
    $byLot[$r['lot_code']][] = $r;
}
?>
<?php foreach ($byLot as $lot => $list): ?>
<h2 style="font-size:12pt;margin:10px 0 4px;color:#000">ロット <?= F::h($lot) ?></h2>
<table class="table">
  <thead><tr><th>日付</th><?php foreach (Analysis::FIELDS as [$label, $unit]): ?><th class="num"><?= F::h($label) ?><?= $unit ? '(' . F::h($unit) . ')' : '' ?></th><?php endforeach; ?><th>備考</th></tr></thead>
  <tbody>
  <?php foreach ($list as $r): ?>
    <tr><td class="date"><?= F::h($r['entry_date']) ?></td><?php foreach (array_keys(Analysis::FIELDS) as $f): ?><td class="num"><?= F::num($r[$f]) ?></td><?php endforeach; ?><td><?= F::h($r['note']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>
<?php if ($rows === []): ?><p>記録がありません。</p><?php endif; ?>
