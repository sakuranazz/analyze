<?php
/** @var array $materials */
use App\View\Format as F;
?>
<table class="table">
  <thead><tr><th>分類</th><th>品名</th><th class="num">数量</th><th>単位</th><th class="num">単価</th><th class="num">金額</th><th>注記</th></tr></thead>
  <tbody>
  <?php foreach ($materials['rows'] as $m): if ($m['qty_milli'] === 0) { continue; } ?>
    <tr><td><?= F::h($m['category_name']) ?></td><td><?= F::h($m['name']) ?></td><td class="num"><?= F::qty($m['qty_milli']) ?></td><td><?= F::h($m['unit']) ?></td>
      <td class="num"><?= F::price($m['price_micro']) ?></td><td class="num"><?= F::yen($m['amount_yen']) ?></td><td><?= F::h(implode('、', $m['warnings'])) ?></td></tr>
  <?php endforeach; ?>
  <tr class="total"><td colspan="5">合計</td><td class="num"><?= F::yen($materials['total_yen']) ?></td><td></td></tr>
  </tbody>
</table>
<p style="font-size:8pt;margin-top:6px">評価方法: 最終仕入原価法（仕入れた原材料は基準日までの最終仕入単価、白米・発芽玄米・米麹は最後に作った工程の按分単価）。円未満は切り捨て。</p>
