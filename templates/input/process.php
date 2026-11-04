<?php
/**
 * @var ?array $process @var string $type @var array $values @var array<string,string> $errors @var list<array> $materials
 * @var list<array> $suppliers @var list<array> $lots @var string $today @var list<array> $recent @var ?array $lotCost
 */
use App\Domain\Num;
use App\Domain\ProcessType;
use App\Domain\Qty;
use App\Http\Url;
use App\View\Format as F;

$isEdit = $process !== null;
$readOnly = $isEdit && in_array($process['type'], ['bottling', 'stocktake'], true);
$v = static fn (string $k, mixed $d = '') => $values[$k] ?? $d;
// 明細行（入力し直し → 保存済み → 空）
$lines = [];
if (isset($values['lines']) && is_array($values['lines'])) {
    $lines = array_values($values['lines']);
} elseif ($isEdit) {
    foreach ($process['lines'] as $l) {
        $lines[] = ['material_id' => $l['material_id'], 'dir' => (int) $l['qty_milli'] > 0 ? '+' : '-',
            'qty' => Qty::plain(abs((int) $l['qty_milli'])), 'price' => $l['unit_price_micro'] === null ? '' : Qty::plain((int) $l['unit_price_micro'], 1_000_000)];
    }
}
$defaultDir = in_array($type, ['purchase'], true) ? '+' : '-';
$target = max(3, count($lines) + 1);   // 空の行を1行以上（最低3行）用意する
while (count($lines) < $target) {
    $lines[] = ['material_id' => '', 'dir' => $defaultDir, 'qty' => '', 'price' => ''];
}
$byCat = [];
foreach ($materials as $m) {
    $byCat[$m['category_name']][] = $m;
}
$typeHelp = [
    'polish' => '使った玄米（−）と、できた白米（＋）を入力します。できた量は実際に量った量です。',
    'germinate' => '使った玄米（−）と、できた発芽玄米（＋）を入力します。',
    'koji' => '使った白米・種麹（−）と、できた米麹（＋）を入力します。麹が増えても原価の総額は増えません。',
    'brew' => '仕込みで使った原料（−）を入力します。ロットの指定が必須です。',
    'purchase' => '仕入れた量（＋）と単価を入力します。容器単位（20Lを1本いくら）のときは「容器」の欄に入れると単価を計算します。',
    'adjust' => '在庫を手で直します。増やす行の単価は空でも構いません（その日の単価で評価。仕入単価は更新しません）。',
];
?>
<div data-page="process" hidden></div>
<header class="page-head">
  <div><h1><?= $isEdit ? '工程の修正' : '工程入力' ?></h1>
    <?php if ($isEdit): ?><p class="page-head__sub">工程 #<?= (int) $process['id'] ?> · <?= F::h(ProcessType::from($process['type'])->label()) ?> · 直すと在庫と原価は自動で再計算されます</p><?php endif; ?></div>
  <?php if ($isEdit): ?><div class="page-head__actions"><a class="btn btn--secondary" href="<?= F::h(Url::to('input/process.php')) ?>"><?= F::icon('plus', 16) ?>新しい工程</a></div><?php endif; ?>
</header>

<?php if ($errors !== []): ?>
  <div class="alert alert--danger" role="alert"><?= F::icon('stop') ?><div>入力を確かめてください<ul><?php foreach ($errors as $e): ?><li><?= F::h($e) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php if ($readOnly): ?>
  <div class="alert alert--info"><?= F::icon('info') ?><div>この工程は<?= $process['type'] === 'bottling' ? '詰口の登録で自動で作られたもの' : '棚卸し調整' ?>です。<?= $process['type'] === 'bottling' ? '詰口を直すと作り直されます。' : '棚卸し金額の画面から記録します。' ?></div></div>
  <section class="card card--flush"><div class="table-wrap"><table class="table">
    <thead><tr><th>資材</th><th class="num">数量</th></tr></thead>
    <tbody><?php foreach ($process['lines'] as $l): ?><tr><td><?= F::h($l['material_name']) ?></td><?= F::numCell(F::qty((int) $l['qty_milli']) . ' ' . $l['unit'], (int) $l['qty_milli']) ?></tr><?php endforeach; ?></tbody>
  </table></div></section>
<?php else: ?>
<form method="post" class="card card__body form" data-guard novalidate action="<?= F::h(Url::to('input/process.php', ['id' => $process['id'] ?? null])) ?>">
  <?= F::csrfField() ?>
  <div class="field">
    <span class="label">工程の種別 <span class="req">必須</span></span>
    <div class="segmented" role="radiogroup" aria-label="工程の種別">
      <?php foreach (ProcessType::inputTypes() as $t): ?>
        <label><input type="radio" name="type" value="<?= $t->value ?>"<?= F::checked($type === $t->value) ?> data-type><span><?= F::h($t->label()) ?></span></label>
      <?php endforeach; ?>
    </div>
    <p class="field-note" data-type-help><?= F::h($typeHelp[$type] ?? '') ?></p>
    <script type="application/json" id="type-help"><?= json_encode($typeHelp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
  </div>

  <div class="form-grid">
    <div class="field"><label for="process_date">工程日 <span class="req">必須</span></label>
      <input class="input" type="date" id="process_date" name="process_date" value="<?= F::h($v('process_date', $process['process_date'] ?? $today)) ?>"<?= F::invalid($errors, 'process_date') ?>></div>
    <div class="field" data-only="purchase"><label for="purchase_date">仕入日 <span class="hint">単価の新旧はこの日で決まる</span></label>
      <input class="input" type="date" id="purchase_date" name="purchase_date" value="<?= F::h($v('purchase_date', $process['purchase_date'] ?? '')) ?>"></div>
    <div class="field" data-only="brew polish germinate koji adjust"><label for="lot_id">ロット <span class="req" data-only="brew">必須</span></label>
      <select class="select" id="lot_id" name="lot_id"<?= F::invalid($errors, 'lot_id') ?>>
        <option value="">—</option>
        <?php foreach ($lots as $l): ?><option value="<?= (int) $l['id'] ?>"<?= F::selected($l['id'], $v('lot_id', $process['lot_id'] ?? '')) ?>><?= F::h($l['lot_code']) ?></option><?php endforeach; ?>
      </select><?= F::error($errors, 'lot_id') ?></div>
    <div class="field" data-only="purchase"><label for="partner_name">仕入先</label>
      <input class="input" list="supplier-list" id="partner_name" name="partner_name" value="<?= F::h($v('partner_name', $process['partner_name'] ?? '')) ?>">
      <datalist id="supplier-list"><?php foreach ($suppliers as $s): ?><option value="<?= F::h($s['name']) ?>"><?php endforeach; ?></datalist></div>
  </div>

  <fieldset class="field">
    <legend class="label">明細 <span class="req">必須</span> <span class="hint">数量は正の数で入れ、向きを選びます</span></legend>
    <?= F::error($errors, 'lines') ?>
    <div class="table-wrap"><table class="table" data-lines>
      <thead><tr><th>資材</th><th>向き</th><th class="num">数量</th><th class="num" data-only="purchase adjust">単価（¥/単位）</th><th data-only="purchase">容器単位のとき（価格 ÷ 容量）</th><th><span class="sr-only">操作</span></th></tr></thead>
      <tbody>
      <?php foreach ($lines as $i => $l): ?>
        <tr data-line>
          <td><select class="select" name="lines[<?= $i ?>][material_id]" aria-label="資材" data-material>
            <option value="">—</option>
            <?php foreach ($byCat as $cat => $ms): ?><optgroup label="<?= F::h($cat) ?>"><?php foreach ($ms as $m): ?>
              <option value="<?= (int) $m['id'] ?>" data-unit="<?= F::h($m['unit']) ?>"<?= F::selected($m['id'], $l['material_id'] ?? '') ?>><?= F::h($m['name']) ?>（<?= F::h($m['unit']) ?>）</option>
            <?php endforeach; ?></optgroup><?php endforeach; ?></select></td>
          <td><select class="select" name="lines[<?= $i ?>][dir]" aria-label="向き" data-dir>
            <option value="-"<?= F::selected($l['dir'] ?? '-', '-') ?>>− 使った</option>
            <option value="+"<?= F::selected($l['dir'] ?? '-', '+') ?>>＋ 入った・できた</option></select></td>
          <td><input class="input input--num" type="text" inputmode="decimal" name="lines[<?= $i ?>][qty]" value="<?= F::h($l['qty'] ?? '') ?>" aria-label="数量" size="7"></td>
          <td data-only="purchase adjust"><input class="input input--num" type="text" inputmode="decimal" name="lines[<?= $i ?>][price]" value="<?= F::h($l['price'] ?? '') ?>" aria-label="単価" size="8" data-price></td>
          <td data-only="purchase"><div class="btn-row" style="flex-wrap:nowrap">
            <input class="input input--num" type="text" inputmode="decimal" name="lines[<?= $i ?>][container_price]" value="<?= F::h($l['container_price'] ?? '') ?>" aria-label="容器1本の価格（円）" placeholder="円" size="6" data-cprice>
            <span class="muted">÷</span>
            <input class="input input--num" type="text" inputmode="decimal" name="lines[<?= $i ?>][container_qty]" value="<?= F::h($l['container_qty'] ?? '') ?>" aria-label="容器1本の容量" placeholder="容量" size="5" data-cqty>
            <output class="small muted nowrap" data-cout></output></div></td>
          <td class="actions"><button type="button" class="btn btn--icon" data-remove aria-label="この行を消す"><?= F::icon('x', 16) ?></button></td>
        </tr>
        <?php if (isset($errors["lines.{$i}"])): ?><tr class="row-error"><td colspan="6"><?= F::h($errors["lines.{$i}"]) ?></td></tr><?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div><button type="button" class="btn btn--secondary" data-add-line><?= F::icon('plus', 16) ?>行を追加</button></div>
  </fieldset>

  <div class="field"><label for="note">備考</label><input class="input" type="text" id="note" name="note" value="<?= F::h($v('note', $process['note'] ?? '')) ?>"></div>
  <div class="btn-row">
    <button class="btn" type="submit"><?= $isEdit ? '更新する' : '登録する' ?></button>
    <?php if ($isEdit): ?><a class="btn btn--secondary" href="<?= F::h(Url::to('input/process.php')) ?>">取消</a><?php endif; ?>
  </div>
</form>
<?php if ($isEdit): ?>
  <form method="post" action="<?= F::h(Url::to('input/process.php', ['id' => $process['id']])) ?>" class="no-print"
        data-confirm="工程 #<?= (int) $process['id'] ?>（<?= F::h($process['process_date']) ?> <?= F::h(ProcessType::from($process['type'])->label()) ?>）を削除します。在庫と原価が変わります。" data-confirm-title="削除の確認" data-confirm-ok="削除する">
    <?= F::csrfField() ?><input type="hidden" name="action" value="delete">
    <button class="btn btn--text" type="submit"><?= F::icon('trash', 16) ?>この工程を削除</button>
  </form>
<?php endif; ?>
<?php if ($lotCost !== null): ?>
  <section class="card card__body">
    <h2>ロット <?= F::h($lotCost['lot_code']) ?> の原価</h2>
    <p class="small">原価合計 <?= F::yen((int) $lotCost['cost_yen']) ?> · 1ml原価 <?= $lotCost['per_ml_micro'] === null ? '全液量が未入力' : F::price((int) $lotCost['per_ml_micro']) ?><?= $lotCost['missing_price'] ? ' · ' . F::badge('warn', '単価未入力を含む') : '' ?></p>
  </section>
<?php endif; ?>
<?php endif; ?>

<section class="card card--flush">
  <div class="card__head"><h2>最近の工程</h2><span class="small muted">行を選ぶと、工程をまとまりで直せます</span></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>日付</th><th>種別</th><th>ロット</th><th>内容</th><th>相手先</th><th><span class="sr-only">操作</span></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $p): ?>
      <tr<?= isset($process['id']) && (int) $process['id'] === (int) $p['id'] ? ' class="is-editing"' : '' ?>>
        <td class="date"><?= F::h($p['effective_date']) ?></td>
        <td class="nowrap"><?= F::h(ProcessType::from($p['type'])->label()) ?><?= (int) $p['missing_prices'] > 0 ? ' ' . F::badge('warn', '単価未入力') : '' ?></td>
        <td><?= F::code($p['lot_code']) ?></td>
        <td class="small"><?= F::h(mb_strimwidth((string) $p['summary'], 0, 80, '…')) ?></td>
        <td class="small"><?= F::h($p['partner_name']) ?></td>
        <td class="actions"><a class="btn btn--icon" href="<?= F::h(Url::to('input/process.php', ['id' => $p['id']])) ?>" aria-label="工程 #<?= (int) $p['id'] ?> を開く"><?= F::icon('pencil', 16) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
