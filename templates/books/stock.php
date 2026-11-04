<?php
/** @var string $tab @var string $filter @var string $today @var array $materials @var array $ledgers @var ?array $products @var list<array> $lots */
use App\Domain\ProcessType;
use App\Http\Url;
use App\View\Format as F;

$tabUrl = static fn (string $t, array $q = []) => Url::to('books/stock.php', ['tab' => $t] + $q);
$srcLabel = ['purchase' => '最終仕入', 'made' => '最後に作った工程', 'adjust' => '調整の単価', 'purchase_missing' => '単価未入力', 'none' => '単価なし'];
?>
<header class="page-head">
  <div><h1>在庫</h1><p class="page-head__sub"><?= F::h($today) ?> 時点</p></div>
  <div class="page-head__actions"><a class="btn" href="<?= F::h(Url::to('input/process.php')) ?>"><?= F::icon('plus', 16) ?>工程を入力</a></div>
</header>

<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;border-bottom:1px solid var(--border)">
  <nav class="tabs" style="border:0" aria-label="在庫の種類">
    <a href="<?= F::h($tabUrl('material')) ?>"<?= $tab === 'material' ? ' aria-current="page"' : '' ?>>資材</a>
    <a href="<?= F::h($tabUrl('product')) ?>"<?= $tab === 'product' ? ' aria-current="page"' : '' ?>>製品</a>
    <a href="<?= F::h($tabUrl('liquor')) ?>"<?= $tab === 'liquor' ? ' aria-current="page"' : '' ?>>酒</a>
  </nav>
  <div class="summary" style="padding-bottom:8px">
    <?php if ($materials['negative'] > 0): ?><a class="is-danger" href="<?= F::h($tabUrl('material', ['filter' => 'negative'])) ?>"><?= F::icon('stop', 16) ?>マイナス在庫 <?= (int) $materials['negative'] ?>件</a><?php endif; ?>
    <?php if ($materials['missing'] > 0): ?><a class="is-warn" href="<?= F::h($tabUrl('material', ['filter' => 'missing'])) ?>"><?= F::icon('warn', 16) ?>単価未入力 <?= (int) $materials['missing'] ?>件</a><?php endif; ?>
    <?php if ($materials['negative'] === 0 && $materials['missing'] === 0): ?><span class="is-ok"><?= F::icon('ok', 16) ?>問題なし</span><?php endif; ?>
  </div>
</div>

<?php if ($tab === 'material'): ?>
<section class="card card--flush">
  <?php if ($filter !== 'all'): ?><div class="card__body"><a class="small" href="<?= F::h($tabUrl('material')) ?>">絞り込みを解除</a></div><?php endif; ?>
  <div class="table-wrap"><table class="table">
    <caption class="sr-only">資材の在庫</caption>
    <thead><tr><th scope="col">品名</th><th scope="col">単位</th><th scope="col" class="num">数量</th><th scope="col" class="num">単価</th><th scope="col">単価の出どころ</th><th scope="col" class="num">金額</th><th scope="col">台帳</th></tr></thead>
    <tbody>
    <?php $cat = null; foreach ($materials['rows'] as $m):
        $neg = $m['qty_milli'] < 0;
        $missing = in_array('単価未入力', $m['warnings'], true);
        if (($filter === 'negative' && !$neg) || ($filter === 'missing' && !$missing)) {
            continue;
        }
        if ($cat !== $m['category_name']): $cat = $m['category_name']; ?>
          <tr class="group"><td colspan="7"><?= F::h($cat) ?></td></tr>
        <?php endif; ?>
      <tr class="<?= $neg ? 'is-neg' : '' ?>">
        <td><?= F::h($m['name']) ?><?= (bool) $m['is_homemade'] ? ' <span class="small muted">自家製</span>' : '' ?></td>
        <td class="muted"><?= F::h($m['unit']) ?></td>
        <?= F::numCell(F::qty($m['qty_milli']), $m['qty_milli']) ?>
        <td class="num"><?= $m['source'] === 'none' ? '<span class="muted">—</span>' : F::h(F::price($m['price_micro'])) ?></td>
        <td class="small"><?= $missing ? F::badge('warn', '単価未入力') : F::h($srcLabel[$m['source']] ?? '') ?><?= $neg ? ' ' . F::badge('danger', 'マイナス在庫') : '' ?></td>
        <?= F::numCell(F::yen($m['amount_yen']), $m['amount_yen']) ?>
        <td>
          <?php if (($ledgers[(int) $m['id']] ?? []) !== []): ?>
          <details class="ledger"><summary><?= F::icon('arrow', 14) ?>開く</summary>
            <table class="table" style="margin-top:6px;min-width:420px"><tbody>
            <?php foreach ($ledgers[(int) $m['id']] as $l): ?>
              <tr><td class="date"><?= F::h($l['effective_date']) ?></td><td class="small"><a href="<?= F::h(Url::to('input/process.php', ['id' => $l['process_id']])) ?>"><?= F::h(ProcessType::from($l['type'])->label()) ?></a></td>
                <?= F::numCell(($l['qty_milli'] > 0 ? '+' : '') . F::qty((int) $l['qty_milli']), (int) $l['qty_milli']) ?>
                <td class="small"><?= F::h($l['lot_code'] ?? $l['partner_name'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
          </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <tr class="total"><td colspan="5">合計</td><td class="num"><?= F::yen($materials['total_yen']) ?></td><td></td></tr>
    </tbody>
  </table></div>
</section>

<?php elseif ($tab === 'product'): ?>
<section class="card card--flush">
  <div class="table-wrap"><table class="table cross">
    <caption class="sr-only">製品の在庫（SKU×容量の本数）</caption>
    <thead><tr><th scope="col">SKU</th><?php foreach ($products['capacities'] as $c): ?><th scope="col" class="num"><?= F::h($c['label']) ?> (本)</th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($products['skus'] as $s): ?>
      <tr><th scope="row" style="font-weight:400"><?= F::code((string) $s['code']) ?> <span class="small muted"><?= F::h($s['name']) ?></span></th>
        <?php foreach ($products['capacities'] as $c): $n = $products['stock'][(int) $s['id']][(int) $c['id']] ?? 0; ?><?= F::numCell((string) $n, $n) ?><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php else: ?>
<section class="card card--flush">
  <div class="card__body legend"><span><i style="background:var(--primary)"></i>詰口済み</span><span><i style="background:var(--bar-sample)"></i>試飲用</span><span><i style="background:var(--bar-rest)"></i>残り</span></div>
  <div class="table-wrap"><table class="table">
    <caption class="sr-only">酒（ロットごと）</caption>
    <thead><tr><th scope="col">ロット</th><th scope="col">状態</th><th scope="col" class="num">全液量 (L)</th><th scope="col" class="num">詰口済み (L)</th><th scope="col" class="num">試飲用 (L)</th><th scope="col" class="num">残り (L)</th><th scope="col">内訳</th></tr></thead>
    <tbody>
    <?php foreach ($lots as $l):
        $total = $l['total_ml'] === null ? null : (int) $l['total_ml'];
        $done = $total ? min(100, round((int) $l['bottles_ml'] / $total * 100, 1)) : 0;
        $sample = $total ? min(100 - $done, round((int) $l['sample_ml'] / $total * 100, 1)) : 0; ?>
      <tr>
        <td><?= F::code((string) $l['lot_code']) ?></td>
        <td><?= $l['status'] === 'active' ? F::badge('active', '進行中') : F::badge('muted', '終了') ?></td>
        <td class="num"><?= $total === null ? ((bool) $l['has_brew'] ? F::badge('warn', '未入力') : '<span class="muted">—</span>') : F::liters($total) ?></td>
        <td class="num"><?= F::liters((int) $l['bottles_ml']) ?></td>
        <td class="num"><?= F::liters((int) $l['sample_ml']) ?></td>
        <?= F::numCell($l['remaining_ml'] === null ? '—' : F::liters((int) $l['remaining_ml']), $l['remaining_ml'] === null ? null : (int) $l['remaining_ml']) ?>
        <td><?php if ($total): ?><div class="bar" role="img" aria-label="詰口済み <?= $done ?>%、試飲用 <?= $sample ?>%"><span class="bar__done" style="width:<?= $done ?>%"></span><span class="bar__sample" style="width:<?= $sample ?>%"></span></div><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
