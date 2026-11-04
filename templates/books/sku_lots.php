<?php
/** @var string $filter @var list<array> $lots @var array<int,string> $tax @var ?int $editId @var string $self @var array $products */
use App\Http\Url;
use App\View\Format as F;
use App\View\View;

$chips = ['active' => '進行中', 'closed' => '終了', 'all' => 'すべて', 'no_total' => '全液量が未入力'];
?>
<header class="page-head">
  <div><h1>SKU・ロット一覧</h1></div>
  <div class="page-head__actions"><a class="btn" href="<?= F::h(Url::to('master/index.php', ['tab' => 'lots'])) ?>"><?= F::icon('plus', 16) ?>ロットを作番</a></div>
</header>

<section class="card card--flush">
  <div class="card__head"><h2>ロット</h2>
    <nav class="filter-chips" aria-label="ロットの状態">
      <?php foreach ($chips as $k => $label): ?><a href="<?= F::h(Url::to('books/sku_lots.php', ['filter' => $k])) ?>"<?= $filter === $k ? ' aria-current="true"' : '' ?>><?= F::h($label) ?></a><?php endforeach; ?>
    </nav>
  </div>
  <?php if ($lots === []): ?><div class="empty"><p>該当するロットはありません</p></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>ロット</th><th>酒類</th><th>状態</th><th class="num">全液量 (L)</th><th class="num">詰口済み (L)</th><th class="num">残り (L)</th><th></th><th class="num">1ml原価</th><th>酒税</th><th>備考</th><th><span class="sr-only">操作</span></th></tr></thead>
    <tbody>
      <?php foreach ($lots as $l): ?>
        <?= View::fragment('books/_lot_row', ['l' => $l, 'editing' => $editId === (int) $l['id'], 'errors' => [], 'self' => $self, 'tax' => $tax[(int) $l['id']] ?? 'none']) ?>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card__body small muted">1ml原価 ＝ 仕込みで使った原料の原価 ÷ 全液量（最終仕入原価法）。酒税は、そのロットを詰口した月の状態です（正式な単位は月。「酒税」画面で確認）。</div>
  <?php endif; ?>
</section>

<section class="card card--flush">
  <div class="card__head"><h2>SKU の現在庫</h2><a class="small" href="<?= F::h(Url::to('books/stock.php', ['tab' => 'product'])) ?>">容量ごとの在庫へ</a></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>SKU</th><th>商品名</th><th>JAN</th><th class="num">在庫 (本)</th><th>容量ごと</th></tr></thead>
    <tbody>
    <?php $capLabel = array_column($products['capacities'], 'label', 'id');
    foreach ($products['skus'] as $s):
        $byCap = $products['stock'][(int) $s['id']] ?? [];
        $sum = array_sum($byCap); ?>
      <tr><td><?= F::code((string) $s['code']) ?></td><td><?= F::h($s['name']) ?></td><td class="mono small"><?= F::h($s['jan_code'] ?? '') ?></td>
        <?= F::numCell((string) $sum, $sum) ?>
        <td class="small"><?= F::h(implode('、', array_map(static fn ($c, $n) => ($capLabel[$c] ?? '?') . ' ' . $n . '本', array_keys(array_filter($byCap)), array_filter($byCap)))) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
