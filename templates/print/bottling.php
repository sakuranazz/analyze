<?php
/** @var list<array> $rows @var list<array> $summary */
use App\Service\MovementService;
use App\View\Format as F;
?>
<h2 style="font-size:12pt;margin:6px 0 4px;color:#000">年度別サマリ（SKU毎・本）</h2>
<table class="table">
  <thead><tr><th>SKU</th><th class="num">前年繰越</th><th class="num">期間内詰口</th><th class="num">卸売業者</th><th class="num">小売業者</th><th class="num">小売販売</th><th class="num">期末在庫</th></tr></thead>
  <tbody><?php foreach ($summary as $s): ?><tr><td><?= F::h($s['sku_code']) ?></td><td class="num"><?= $s['carry'] ?></td><td class="num"><?= $s['bottled'] ?></td><td class="num"><?= $s['wholesale_distributor'] ?></td><td class="num"><?= $s['wholesale_retailer'] ?></td><td class="num"><?= $s['retail'] ?></td><td class="num"><?= $s['end'] ?></td></tr><?php endforeach; ?></tbody>
</table>
<h2 style="font-size:12pt;margin:12px 0 4px;color:#000">台帳</h2>
<table class="table">
  <thead><tr><th>日付</th><th>区分</th><th>SKU</th><th class="num">容量(ml)</th><th class="num">本数・量</th><th>ロット</th><th>販売先</th><th>備考</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $label = match ($r['row_kind']) { 'bottling' => '詰口', 'sample' => '試飲用', 'movement' => MovementService::KINDS[$r['kind']] ?? $r['kind'], default => $r['kind'] === 'tasting' ? '試飲用を試飲' : '試飲用を処分' }; ?>
    <tr><td class="date"><?= F::h($r['date']) ?></td><td><?= F::h($label) ?></td><td><?= F::h($r['sku_code']) ?></td>
      <td class="num"><?= $r['ml'] === null ? '' : F::ml((int) $r['ml']) ?></td>
      <td class="num"><?= match ($r['row_kind']) { 'sample' => F::ml((int) $r['sample_ml']) . 'ml', 'sample_use' => F::ml((int) $r['count']) . 'ml', default => (int) $r['count'] . '本' } ?></td>
      <td><?= F::h($r['lot_code']) ?></td><td><?= F::h($r['channel']) ?></td><td><?= F::h($r['note']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
