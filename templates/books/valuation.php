<?php
/**
 * @var string $date @var list<string> $dates @var int $fiscalEnd @var array $materials @var array<string,string> $errors
 * @var ?list<array> $preview @var array<int,int> $actuals @var list<array> $lots @var list<array> $perBottle @var ?int $lotSel
 */
use App\Domain\Qty;
use App\Http\Url;
use App\View\Format as F;

$isFiscal = static fn (string $d) => (int) substr($d, 5, 2) === $fiscalEnd && $d === date('Y-m-t', strtotime($d));
?>
<header class="page-head">
  <div><h1>棚卸し金額</h1><p class="page-head__sub">基準日 <?= F::h($date) ?><?= $isFiscal($date) ? '（決算日）' : '' ?> · 最終仕入原価法</p></div>
  <div class="page-head__actions">
    <a class="btn btn--secondary" href="<?= F::h(Url::to('print/valuation.php', ['date' => $date])) ?>"><?= F::icon('print', 16) ?>印刷</a>
    <a class="btn" href="<?= F::h(Url::to('export/valuation.php', ['date' => $date])) ?>"><?= F::icon('download', 16) ?>CSV出力</a>
  </div>
</header>

<form class="card card__body form-grid filters" method="get">
  <div class="field"><label for="date">基準日（月末・決算日）</label>
    <select class="select" id="date" name="date" data-autosubmit>
      <?php foreach ($dates as $d): ?><option value="<?= F::h($d) ?>"<?= F::selected($d, $date) ?>><?= F::h($d) ?><?= $isFiscal($d) ? '（決算日）' : '' ?><?= $d === date('Y-m-d') ? '（今日）' : '' ?></option><?php endforeach; ?>
    </select></div>
  <div class="field" style="justify-content:flex-end"><noscript><button class="btn" type="submit">表示</button></noscript></div>
</form>

<?php if ($preview !== null): ?>
<section class="card card__body" style="border-color:var(--warn)">
  <h2>棚卸し差異の確認（<?= F::h($date) ?>）</h2>
  <p class="small">次の差を「棚卸し調整」の工程として記録します。記録すると、この日以降の在庫と金額が変わります。</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>資材</th><th class="num">帳簿</th><th class="num">実数</th><th class="num">差異</th></tr></thead>
    <tbody><?php foreach ($preview as $p): ?>
      <tr><td><?= F::h($p['name']) ?></td><td class="num"><?= F::qty($p['book']) ?> <?= F::h($p['unit']) ?></td><td class="num"><?= F::qty($p['actual']) ?> <?= F::h($p['unit']) ?></td><?= F::numCell(($p['diff'] > 0 ? '+' : '') . F::qty($p['diff']), $p['diff']) ?></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <form method="post" class="btn-row" style="margin-top:12px">
    <?= F::csrfField() ?><input type="hidden" name="action" value="commit"><input type="hidden" name="date" value="<?= F::h($date) ?>">
    <?php foreach ($actuals as $mid => $q): ?><input type="hidden" name="actual[<?= (int) $mid ?>]" value="<?= F::h(Qty::plain($q)) ?>"><?php endforeach; ?>
    <input class="input" type="text" name="note" placeholder="備考（例: 8月末の目視棚卸し）" style="max-width:320px">
    <button class="btn" type="submit">差異を記録する</button>
    <a class="btn btn--secondary" href="<?= F::h(Url::to('books/valuation.php', ['date' => $date])) ?>">取消</a>
  </form>
</section>
<?php endif; ?>

<?php if ($errors !== []): ?><div class="alert alert--danger"><?= F::icon('stop') ?><div><?= F::h(implode(' / ', $errors)) ?></div></div><?php endif; ?>

<form method="post" class="card card--flush" action="<?= F::h(Url::to('books/valuation.php', ['date' => $date])) ?>">
  <?= F::csrfField() ?><input type="hidden" name="action" value="preview">
  <div class="table-wrap"><table class="table">
    <caption class="sr-only">資材の棚卸し金額</caption>
    <thead><tr><th scope="col">品名</th><th scope="col" class="num">数量</th><th scope="col">単位</th><th scope="col" class="num">単価</th><th scope="col" class="num">金額</th><th scope="col" class="num">実数 <span class="small">（目視）</span></th><th scope="col"></th></tr></thead>
    <tbody>
    <?php $cat = null; $catSum = []; foreach ($materials['rows'] as $m) { $catSum[$m['category_name']] = ($catSum[$m['category_name']] ?? 0) + $m['amount_yen']; }
    foreach ($materials['rows'] as $m):
        if ($cat !== $m['category_name']): $cat = $m['category_name']; ?>
          <tr class="group"><td colspan="4"><?= F::h($cat) ?></td><td class="num"><?= F::yen($catSum[$cat]) ?></td><td colspan="2"></td></tr>
        <?php endif; ?>
      <tr class="<?= $m['qty_milli'] < 0 ? 'is-neg' : '' ?>">
        <td><?= F::h($m['name']) ?></td>
        <?= F::numCell(F::qty($m['qty_milli']), $m['qty_milli']) ?>
        <td class="muted"><?= F::h($m['unit']) ?></td>
        <td class="num"><?= $m['source'] === 'none' ? '<span class="muted">—</span>' : F::h(F::price($m['price_micro'])) ?></td>
        <?= F::numCell(F::yen($m['amount_yen']), $m['amount_yen']) ?>
        <td class="num"><input class="input input--num" type="text" inputmode="decimal" name="actual[<?= (int) $m['id'] ?>]" value="<?= F::h(isset($actuals[(int) $m['id']]) ? Qty::plain($actuals[(int) $m['id']]) : '') ?>" aria-label="<?= F::h($m['name']) ?> の実数" size="7" style="max-width:110px"></td>
        <td class="small"><?php foreach ($m['warnings'] as $w): ?><?= F::badge($w === 'マイナス在庫' ? 'danger' : 'warn', $w) ?> <?php endforeach; ?></td>
      </tr>
    <?php endforeach; ?>
    <tr class="total"><td colspan="4">合計（原材料・瓶・ラベル）</td><td class="num"><?= F::yen($materials['total_yen']) ?></td><td colspan="2"></td></tr>
    </tbody>
  </table></div>
  <div class="card__body btn-row"><button class="btn btn--secondary" type="submit">実数との差異を確認</button><span class="small muted">目視棚卸しの実数を入れた行だけ比べます</span></div>
</form>

<section class="card card--flush">
  <div class="card__head"><h2>ロットごとの原価（第1段階）</h2><span class="small muted">仕込みで使った原料を、使った日の最終単価で積んだもの</span></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>ロット</th><th class="num">原価合計</th><th class="num">全液量 (L)</th><th class="num">1ml原価</th><th>1本あたり</th></tr></thead>
    <tbody>
    <?php foreach ($lots as $l): ?>
      <tr><td><?= F::code((string) $l['lot_code']) ?><?= $l['missing_price'] ? ' ' . F::badge('warn', '単価未入力を含む') : '' ?></td>
        <td class="num"><?= F::yen((int) $l['cost_yen']) ?></td>
        <td class="num"><?= $l['total_ml'] === null ? F::badge('warn', '未入力') : F::liters((int) $l['total_ml']) ?></td>
        <td class="num"><?= $l['per_ml_micro'] === null ? '—' : F::h(F::price((int) $l['per_ml_micro'])) ?></td>
        <td><?php if ($l['per_ml_micro'] !== null): ?><a class="small" href="<?= F::h(Url::to('books/valuation.php', ['date' => $date, 'lot' => $l['id']])) ?>#per-bottle">計算する</a><?php endif; ?></td></tr>
      <?php if ($lotSel === (int) $l['id']): ?>
        <tr id="per-bottle"><td colspan="5">
          <?php if ($perBottle === []): ?><span class="small muted">このロットの詰口がありません</span><?php else: ?>
          <table class="table"><thead><tr><th>SKU</th><th class="num">容量 (ml)</th><th class="num">酒の分</th><th class="num">1本あたり原価（酒＋瓶＋ラベル）</th></tr></thead><tbody>
          <?php foreach ($perBottle as $b): ?><tr><td><?= F::code($b['sku_code']) ?></td><td class="num"><?= F::ml($b['ml']) ?></td><td class="num"><?= F::yen($b['liquor_yen']) ?></td><td class="num"><?= F::yen($b['yen']) ?><?= $b['materials'] === 0 ? ' <span class="small muted">（瓶・ラベル未登録）</span>' : '' ?></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
