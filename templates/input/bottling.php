<?php
/**
 * @var string $tab @var array<string, string> $errors @var array<string, mixed> $values @var ?array $session
 * @var list<array> $skus @var list<array> $capacities @var list<array> $channels @var list<array> $lots @var list<array> $allLots
 * @var ?array $lot @var int $otherMl @var string $today @var array<int,int> $bottleByCap @var array $labelsBySku
 * @var array<int,string> $materialNames @var list<array> $samples
 */
use App\Http\Url;
use App\Service\MovementService;
use App\View\Format as F;

$isEdit = $session !== null;
$v = static fn (string $k, mixed $d = '') => $values[$k] ?? $d;
$items = [];
foreach ($session['items'] ?? [] as $it) {
    $items[(int) $it['capacity_id']] = (int) $it['bottle_count'];
}
foreach ($values['items'] ?? [] as $c => $n) {
    $items[(int) $c] = $n;
}
$remaining = $lot && $lot['total_ml'] !== null ? (int) $lot['total_ml'] - $otherMl : null;
$sessionMl = $v('session_ml', $session['session_ml'] ?? ($remaining !== null && $remaining > 0 ? $remaining : ''));
$tabUrl = static fn (string $t) => Url::to('input/bottling.php', ['tab' => $t]);
$calc = [
    'caps' => array_map(static fn ($c) => ['id' => (int) $c['id'], 'ml' => (int) $c['ml']], $capacities),
    'bottleByCap' => $bottleByCap,
    'labelsBySku' => $labelsBySku,
    'names' => $materialNames,
    'lotTotal' => $lot['total_ml'] ?? null,
    'otherMl' => $otherMl,
];
?>
<div data-page="bottling" hidden></div>
<header class="page-head"><h1><?= $isEdit ? '詰口の修正' : '詰口・出庫' ?></h1></header>

<?php if (!$isEdit): ?>
<nav class="tabs" aria-label="入力の種類">
  <a href="<?= F::h($tabUrl('bottling')) ?>"<?= $tab === 'bottling' ? ' aria-current="page"' : '' ?>>詰口</a>
  <a href="<?= F::h($tabUrl('movement')) ?>"<?= $tab === 'movement' ? ' aria-current="page"' : '' ?>>出庫</a>
  <a href="<?= F::h($tabUrl('sample')) ?>"<?= $tab === 'sample' ? ' aria-current="page"' : '' ?>>試飲用の残り</a>
</nav>
<?php endif; ?>

<?php if ($errors !== []): ?>
  <div class="alert alert--danger" role="alert"><?= F::icon('stop') ?><div>入力を確かめてください<ul><?php foreach ($errors as $e): ?><li><?= F::h($e) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php if ($tab === 'bottling'): ?>
<div class="grid-2">
  <form method="post" class="card card__body form" id="bottling-form" data-guard novalidate
        action="<?= F::h(Url::to('input/bottling.php', ['id' => $session['id'] ?? null])) ?>"
        data-calc='<?= F::h(json_encode($calc, JSON_UNESCAPED_UNICODE)) ?>'>
    <?= F::csrfField() ?><input type="hidden" name="action" value="bottling">
    <div class="form-grid">
      <div class="field">
        <label for="bottling_date">詰口日 <span class="req">必須</span></label>
        <input class="input" type="date" id="bottling_date" name="bottling_date" value="<?= F::h($v('bottling_date', $session['bottling_date'] ?? $today)) ?>"<?= F::invalid($errors, 'bottling_date') ?>>
      </div>
      <div class="field">
        <label for="sku_id">SKU <span class="req">必須</span></label>
        <select class="select" id="sku_id" name="sku_id"<?= F::invalid($errors, 'sku_id') ?> data-sku>
          <option value="">選んでください</option>
          <?php foreach ($skus as $s): ?><option value="<?= (int) $s['id'] ?>"<?= F::selected($s['id'], $v('sku_id', $session['sku_id'] ?? '')) ?>><?= F::h($s['code'] . '　' . $s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="lot_id">ロット <span class="req">必須</span> <span class="hint">進行中のみ</span></label>
        <select class="select" id="lot_id" name="lot_id"<?= F::invalid($errors, 'lot_id') ?> data-lot-reload>
          <?php foreach ($lots as $l): ?><option value="<?= (int) $l['id'] ?>"<?= F::selected($l['id'], $lot['id'] ?? '') ?>><?= F::h($l['lot_code']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if ($lot !== null && $lot['total_ml'] === null): ?>
      <div class="alert alert--warn"><?= F::icon('warn') ?><div>このロットは<strong>全液量が未入力</strong>です。詰口の前に入力してください（CLAUDE.md 6.1）。</div></div>
    <?php else: ?>
    <div class="field">
      <label for="session_ml">今回の液量 <span class="req">必須</span> <span class="hint">初期値はロットの残り</span></label>
      <div class="input-unit" style="max-width:240px"><input class="input input--num" type="text" inputmode="numeric" id="session_ml" name="session_ml" value="<?= F::h($sessionMl) ?>" data-session-ml<?= F::invalid($errors, 'session_ml') ?>><span class="unit">ml</span></div>
    </div>
    <fieldset class="field">
      <legend class="label">容量ごとの本数 <span class="req">必須</span> <span class="hint">半端な量は試飲用になります</span></legend>
      <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr))">
        <?php foreach ($capacities as $c): ?>
          <div class="field">
            <label for="cap-<?= (int) $c['id'] ?>"><?= F::h($c['label']) ?></label>
            <div class="input-unit"><input class="input input--num" type="text" inputmode="numeric" id="cap-<?= (int) $c['id'] ?>" name="items[<?= (int) $c['id'] ?>]" value="<?= F::h($items[(int) $c['id']] ?? '') ?>" data-cap="<?= (int) $c['id'] ?>"><span class="unit">本</span></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?= F::error($errors, 'items') ?>
    </fieldset>
    <?php endif; ?>
    <div class="field"><label for="note">備考</label><input class="input" type="text" id="note" name="note" value="<?= F::h($v('note', $session['note'] ?? '')) ?>"></div>
    <div class="btn-row">
      <button class="btn" type="submit" data-submit<?= $lot === null || $lot['total_ml'] === null ? ' disabled' : '' ?>><?= $isEdit ? '更新する' : '登録する' ?></button>
      <?php if ($isEdit): ?><a class="btn btn--secondary" href="<?= F::h(Url::to('books/bottling.php')) ?>">取消</a><?php endif; ?>
      <span class="small muted" data-submit-reason></span>
    </div>
  </form>

  <aside class="card card__body calc" data-calc-panel aria-live="polite">
    <?php if ($lot !== null && $lot['total_ml'] === null): ?>
      <form method="post" class="form">
        <?= F::csrfField() ?><input type="hidden" name="action" value="set_total"><input type="hidden" name="lot_id" value="<?= (int) $lot['id'] ?>">
        <div class="field">
          <label for="total_l">全液量（<?= F::h($lot['lot_code']) ?>）</label>
          <div class="input-unit"><input class="input input--num" type="text" inputmode="decimal" id="total_l" name="total_l" autofocus><span class="unit">L</span></div>
        </div>
        <button class="btn" type="submit">全液量を登録</button>
      </form>
    <?php else: ?>
      <h2>計算</h2>
      <dl>
        <dt>瓶の合計</dt><dd><span data-out="bottles">0</span> ml</dd>
        <dt>今回の液量</dt><dd><span data-out="session">0</span> ml</dd>
        <dt>試飲用</dt><dd class="big"><span data-out="sample">0</span> ml</dd>
        <dt>ロットの全液量</dt><dd><?= F::h(F::liters($lot['total_ml'] ?? null)) ?> L</dd>
        <dt>ロット残り</dt><dd><?= F::h(F::liters($remaining)) ?> L → <span data-out="after">—</span> L</dd>
      </dl>
      <div data-out="message" class="small"></div>
      <div>
        <h3 class="small">登録すると減る資材</h3>
        <ul class="small" data-out="consumption" style="padding-left:1.2em;margin:4px 0 0"></ul>
      </div>
    <?php endif; ?>
  </aside>
</div>

<?php elseif ($tab === 'movement'): ?>
<form method="post" class="card card__body form" data-guard novalidate>
  <?= F::csrfField() ?><input type="hidden" name="action" value="movement">
  <div class="field">
    <span class="label">種別 <span class="req">必須</span></span>
    <div class="segmented" role="radiogroup" aria-label="種別">
      <?php foreach (MovementService::KINDS as $k => $label): ?>
        <label><input type="radio" name="kind" value="<?= $k ?>"<?= F::checked($v('kind', 'sale') === $k) ?> data-kind><span><?= F::h($label) ?></span></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="form-grid">
    <div class="field"><label for="move_date">日付 <span class="req">必須</span></label><input class="input" type="date" id="move_date" name="move_date" value="<?= F::h($v('move_date', $today)) ?>"<?= F::invalid($errors, 'move_date') ?>></div>
    <div class="field"><label for="m_sku">SKU <span class="req">必須</span></label>
      <select class="select" id="m_sku" name="sku_id"><?php foreach ($skus as $s): ?><option value="<?= (int) $s['id'] ?>"<?= F::selected($s['id'], $v('sku_id')) ?>><?= F::h($s['code']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="m_cap">容量 <span class="req">必須</span></label>
      <select class="select" id="m_cap" name="capacity_id"><?php foreach ($capacities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= F::selected($c['id'], $v('capacity_id')) ?>><?= F::h($c['label']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="count">本数 <span class="req">必須</span></label><div class="input-unit"><input class="input input--num" type="text" inputmode="numeric" id="count" name="count" value="<?= F::h($v('count')) ?>"<?= F::invalid($errors, 'count') ?>><span class="unit">本</span></div><?= F::error($errors, 'count') ?></div>
    <div class="field" data-channel><label for="channel_id">販売先 <span class="req">販売のとき必須</span></label>
      <select class="select" id="channel_id" name="channel_id"<?= F::invalid($errors, 'channel_id') ?>><option value="">選んでください</option><?php foreach ($channels as $c): ?><option value="<?= (int) $c['id'] ?>"<?= F::selected($c['id'], $v('channel_id')) ?>><?= F::h($c['label']) ?></option><?php endforeach; ?></select>
      <?= F::error($errors, 'channel_id') ?></div>
    <div class="field"><label for="m_lot">ロット <span class="hint">任意</span></label>
      <select class="select" id="m_lot" name="lot_id"><option value="">—</option><?php foreach ($allLots as $l): ?><option value="<?= (int) $l['id'] ?>"<?= F::selected($l['id'], $v('lot_id')) ?>><?= F::h($l['lot_code']) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="form-grid">
    <div class="field"><label for="address">納品先住所 <span class="hint">任意</span></label><input class="input" type="text" id="address" name="address" value="<?= F::h($v('address')) ?>"></div>
    <div class="field"><label for="m_note">備考</label><input class="input" type="text" id="m_note" name="note" value="<?= F::h($v('note')) ?>"></div>
  </div>
  <div class="btn-row"><button class="btn" type="submit">出庫を登録する</button></div>
</form>

<?php else: ?>
<section class="card card--flush">
  <div class="card__head"><h2>試飲用の残り</h2><span class="small muted">詰口の端数。試飲で使った・処分したときに記録します</span></div>
  <?php if ($samples === []): ?>
    <div class="empty"><p>試飲用の残りはありません</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>詰口日</th><th>SKU</th><th>ロット</th><th class="num">残り (ml)</th><th>記録する</th></tr></thead>
    <tbody>
    <?php foreach ($samples as $s): ?>
      <tr>
        <td class="date"><?= F::h($s['bottling_date']) ?></td><td><?= F::code((string) $s['sku_code']) ?></td><td><?= F::code((string) $s['lot_code']) ?></td>
        <td class="num"><?= F::ml((int) $s['sample_left']) ?></td>
        <td>
          <form method="post" class="btn-row">
            <?= F::csrfField() ?><input type="hidden" name="action" value="sample"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>">
            <input class="input" type="date" name="use_date" value="<?= F::h($today) ?>" aria-label="日付" style="width:150px">
            <input class="input input--num" type="text" inputmode="numeric" name="ml" value="<?= (int) $s['sample_left'] ?>" aria-label="ml" style="width:90px">
            <select class="select" name="kind" aria-label="種別" style="width:100px"><option value="tasting">試飲</option><option value="disposal">処分</option></select>
            <button class="btn btn--secondary" type="submit">記録</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
