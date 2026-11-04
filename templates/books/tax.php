<?php
/** @var list<array> $months @var ?string $editYm @var string $self */
use App\Http\Url;
use App\View\Format as F;
use App\View\View;
?>
<header class="page-head">
  <div><h1>酒税</h1><p class="page-head__sub">月単位。対象月は詰口日の月。同じ月の詰口は液量を合計してから計算します</p></div>
  <div class="page-head__actions"><a class="btn" href="<?= F::h(Url::to('export/tax.php')) ?>"><?= F::icon('download', 16) ?>CSV出力</a></div>
</header>
<div class="alert alert--info"><?= F::icon('info') ?><div>詰口量ベースの目安です。実際の課税は出荷時点の数量のため、申告に使う数量が違う場合は計算の基準を直します（CLAUDE.md 8章 #8 確認待ち）。</div></div>

<section class="card card--flush">
  <?php if ($months === []): ?><div class="empty"><p>まだ詰口の記録がありません</p></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>対象月</th><th class="num">詰口量 (L)</th><th class="num">計算酒税</th><th class="num">納付予定額</th><th>納期限</th><th class="num">申告額</th><th>納付日</th><th>状態</th><th>備考・ロット別の参考値</th><th><span class="sr-only">操作</span></th></tr></thead>
    <tbody>
      <?php foreach ($months as $m): ?><?= View::fragment('books/_tax_row', ['m' => $m, 'editing' => $editYm === $m['ym'], 'errors' => [], 'values' => [], 'self' => $self]) ?><?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card__body small muted">計算酒税 ＝ 詰口量(ml) × 税率 ÷ 1,000,000 × 軽減%（1円未満切り捨て）。納付予定額は100円未満切り捨て。納期限は対象月の翌々月末。ロット別の参考値は1円未満切り捨てで、月の合計とは一致しないことがあります。</div>
  <?php endif; ?>
</section>
