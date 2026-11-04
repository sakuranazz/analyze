<?php
/**
 * @var array $tabs @var string $tab @var ?string $table @var array $cols @var list<array> $rows @var ?int $editId @var string $self
 * @var bool $showHidden @var App\Data\Masters $m @var list<array> $lots @var array $skuMaterials @var string $yymm
 */
use App\Domain\Qty;
use App\Http\Url;
use App\View\Format as F;
use App\View\View;

$codeJson = static fn (array $rows) => json_encode(array_column($rows, 'code', 'id'));
?>
<div data-page="master" hidden></div>
<header class="page-head"><h1>マスタ管理</h1></header>
<div style="display:grid;grid-template-columns:200px minmax(0,1fr);gap:24px;align-items:start" class="master-grid">
  <nav class="vtabs" aria-label="マスタの種類">
    <?php foreach ($tabs as $k => [$label]): ?><a href="<?= F::h(Url::to('master/index.php', ['tab' => $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= F::h($label) ?></a><?php endforeach; ?>
  </nav>
  <div style="display:flex;flex-direction:column;gap:16px;min-width:0">

  <?php if ($tab === 'lots'): ?>
    <form method="post" class="card card__body form" data-code-preview="lot"
          data-codes='<?= F::h(json_encode(['liquor' => array_column($m->codes('lot_liquor_codes'), 'code', 'id'), 'tank' => array_column($m->codes('tank_codes'), 'code', 'id')])) ?>'>
      <h2>ロットを作番する</h2>
      <?= F::csrfField() ?><input type="hidden" name="action" value="create_lot">
      <div class="form-grid">
        <div class="field"><label for="l-liq">酒類コード <span class="req">必須</span></label><select class="select" id="l-liq" name="liquor_code_id" data-part="liquor"><?php foreach ($m->codes('lot_liquor_codes') as $c): ?><option value="<?= (int) $c['id'] ?>"><?= F::h($c['code'] . '（' . $c['name'] . '）') ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="l-tank">タンクコード <span class="hint">任意</span></label><select class="select" id="l-tank" name="tank_code_id" data-part="tank"><option value="">なし</option><?php foreach ($m->codes('tank_codes') as $c): ?><option value="<?= (int) $c['id'] ?>"><?= F::h($c['code'] . '（' . $c['name'] . '）') ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="l-ym">年月 <span class="req">必須</span> <span class="hint">YYMM（例: 2611）</span></label><input class="input" id="l-ym" name="year_month" value="<?= F::h($yymm) ?>" inputmode="numeric" maxlength="4" data-part="yymm"></div>
        <div class="field"><label for="l-seq">回数 <span class="hint">空なら自動で次の番号</span></label><input class="input" id="l-seq" name="sequence_number" inputmode="numeric" data-part="seq"></div>
      </div>
      <div class="field"><span class="label">生成されるロットコード</span><div class="code-preview" data-preview aria-live="polite">—</div></div>
      <div class="field"><label for="l-note">備考</label><input class="input" id="l-note" name="note" placeholder="仕込みメモなど"></div>
      <div><button class="btn" type="submit">作番する</button></div>
    </form>
    <section class="card card--flush">
      <div class="card__head"><h2>ロット</h2><a class="small" href="<?= F::h(Url::to('books/sku_lots.php', ['filter' => 'all'])) ?>">SKU・ロット一覧で全液量・状態を直す</a></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>ロット</th><th>酒類</th><th>タンク</th><th>状態</th><th>備考</th></tr></thead><tbody>
        <?php foreach ($lots as $l): ?><tr><td><?= F::code((string) $l['lot_code']) ?></td><td><?= F::h($l['liquor_name']) ?></td><td><?= F::h($l['tank_code'] ?? '—') ?></td><td><?= $l['status'] === 'active' ? F::badge('active', '進行中') : F::badge('muted', '終了') ?></td><td class="small"><?= F::h($l['note']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>

  <?php else: ?>
    <?php if ($tab === 'skus'): ?>
    <form method="post" class="card card__body form" data-code-preview="sku"
          data-codes='<?= F::h(json_encode(['liquor' => array_column($m->codes('sku_liquor_codes'), 'code', 'id'), 'ingredient' => array_column($m->codes('ingredient_codes'), 'code', 'id'), 'capacity' => array_column($m->capacities(), 'ml', 'id')])) ?>'>
      <h2>SKUを作番する</h2>
      <?= F::csrfField() ?><input type="hidden" name="action" value="create_sku">
      <div class="form-grid">
        <div class="field"><label for="s-liq">酒類コード <span class="req">必須</span></label><select class="select" id="s-liq" name="sku_liquor_code_id" data-part="liquor"><?php foreach ($m->codes('sku_liquor_codes') as $c): ?><option value="<?= (int) $c['id'] ?>"><?= F::h($c['code'] . '（' . $c['name'] . '）') ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="s-ing">原料コード <span class="hint">任意</span></label><select class="select" id="s-ing" name="ingredient_code_id" data-part="ingredient"><option value="">なし</option><?php foreach ($m->codes('ingredient_codes') as $c): ?><option value="<?= (int) $c['id'] ?>"><?= F::h($c['code'] . '（' . $c['name'] . '）') ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="s-cap">容量 <span class="req">必須</span></label><select class="select" id="s-cap" name="capacity_id" data-part="capacity"><?php foreach ($m->capacities() as $c): ?><option value="<?= (int) $c['id'] ?>"><?= F::h($c['label']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="s-lot">ロットの酒類</label><select class="select" id="s-lot" name="lot_liquor_code_id"><option value="">—</option><?php foreach ($m->codes('lot_liquor_codes') as $c): ?><option value="<?= (int) $c['id'] ?>"><?= F::h($c['code'] . '（' . $c['name'] . '）') ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><span class="label">生成されるSKUコード</span><div class="code-preview" data-preview aria-live="polite">—</div></div>
      <div class="form-grid"><div class="field"><label for="s-name">商品名</label><input class="input" id="s-name" name="name" placeholder="例: 希米ふわり 桃 450ml"></div><div class="field"><label for="s-jan">JANコード</label><input class="input" id="s-jan" name="jan_code" inputmode="numeric"></div></div>
      <div><button class="btn" type="submit">作番する</button></div>
    </form>
    <?php endif; ?>

    <section class="card card--flush">
      <div class="card__head"><h2><?= F::h($tabs[$tab][0]) ?></h2>
        <a class="small" href="<?= F::h(Url::to('master/index.php', ['tab' => $tab, 'hidden' => $showHidden ? null : '1'])) ?>"><?= $showHidden ? '非表示の項目を隠す' : '非表示の項目も見る' ?></a></div>
      <?php if ($tab !== 'skus'): ?>
      <form method="post" action="<?= F::h($self) ?>" class="card__body form-grid" style="border-bottom:1px solid var(--border)">
        <?= F::csrfField() ?>
        <?php foreach ($cols as $c => $def): if ($c === 'is_active') { continue; } ?>
          <div class="field"><label for="new-<?= $c ?>"><?= F::h($def[0]) ?></label>
            <?php if ($def[1] === 'bool'): ?><input type="hidden" name="<?= $c ?>" value="0"><label class="small"><input type="checkbox" id="new-<?= $c ?>" name="<?= $c ?>" value="1"> はい</label>
            <?php elseif ($def[1] === 'select'): ?><select class="select" id="new-<?= $c ?>" name="<?= $c ?>"><option value="">—</option><?php foreach ($def[2] as $k => $l): ?><option value="<?= F::h($k) ?>"><?= F::h($l) ?></option><?php endforeach; ?></select>
            <?php else: ?><input class="input" id="new-<?= $c ?>" name="<?= $c ?>"<?= $def[1] === 'int' ? ' inputmode="numeric"' : '' ?>><?php endif; ?></div>
        <?php endforeach; ?>
        <input type="hidden" name="is_active" value="1">
        <div class="field" style="justify-content:flex-end"><button class="btn" type="submit"><?= F::icon('plus', 16) ?>追加</button></div>
      </form>
      <?php endif; ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><?php foreach ($cols as $def): ?><th scope="col"><?= F::h($def[0]) ?></th><?php endforeach; ?><th scope="col"><span class="sr-only">操作</span></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?><?= View::fragment('master/_row', ['r' => $r, 'cols' => $cols, 'editing' => $editId === (int) $r['id'], 'errors' => [], 'values' => [], 'self' => $self, 'table' => $table]) ?><?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if ($tab === 'materials'): ?><div class="card__body small muted">瓶は「瓶の容量」を選ぶと、その容量で詰口したときに1本ずつ自動で減ります。ラベルは SKU の画面で、SKU ごとに1本あたりの枚数を結びつけます。白米・発芽玄米・米麹は「自家製」にします（原価が工程の按分になります）。</div><?php endif; ?>
    </section>

    <?php if ($tab === 'skus'): $labelMats = array_values(array_filter($m->materials(true), static fn ($x) => $x['capacity_id'] === null)); ?>
    <section class="card card--flush">
      <div class="card__head"><h2>1本あたりの資材（ラベルなど）</h2><span class="small muted">詰口すると、本数 × 枚数が自動で減ります</span></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>SKU</th><th>結びついている資材</th><th>追加・変更（枚数0で外す）</th></tr></thead>
        <tbody>
        <?php foreach ($m->skus() as $s): ?>
          <tr><td><?= F::code((string) $s['code']) ?></td>
            <td class="small"><?= F::h(implode('、', array_map(static fn ($x) => $x['name'] . ' ×' . Qty::format($x['qty_per_bottle_milli']), $skuMaterials[(int) $s['id']] ?? []))) ?: '<span class="muted">なし</span>' ?></td>
            <td><form method="post" class="btn-row" style="flex-wrap:nowrap"><?= F::csrfField() ?><input type="hidden" name="action" value="sku_material"><input type="hidden" name="sku_id" value="<?= (int) $s['id'] ?>">
              <select class="select" name="material_id" aria-label="資材" style="max-width:220px"><?php foreach ($labelMats as $x): ?><option value="<?= (int) $x['id'] ?>"><?= F::h($x['category_name'] . '：' . $x['name']) ?></option><?php endforeach; ?></select>
              <input class="input input--num" name="qty" value="1" inputmode="decimal" size="3" aria-label="1本あたりの枚数" style="width:64px"><button class="btn btn--secondary" type="submit">保存</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
