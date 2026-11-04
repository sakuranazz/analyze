<?php
/**
 * @var list<array<string, mixed>> $lots @var int $lotId @var string $date @var array<string, mixed> $values
 * @var array<string, string> $errors @var array<string, array{value:float, date:string}> $prev @var list<array<string, mixed>> $recent
 */
use App\Data\Analysis;
use App\Http\Url;
use App\View\Format as F;

$v = static fn (string $k) => F::h($values[$k] ?? '');
$fields = Analysis::FIELDS;
?>
<div data-page="analysis-input" hidden></div>
<header class="page-head"><h1>分析結果</h1></header>

<?php if ($lots === []): ?>
  <div class="alert alert--warn"><?= F::icon('warn') ?><div>進行中のロットがありません。<a href="<?= F::h(Url::to('master/index.php', ['tab' => 'lots'])) ?>">ロットを作番</a>してください。</div></div>
<?php else: ?>
<form method="post" class="form analysis-form" novalidate data-guard data-prev='<?= F::h(json_encode(array_map(static fn ($p) => $p['value'], $prev))) ?>'>
  <?= F::csrfField() ?>
  <div class="field">
    <label for="entry_date">日付 <span class="req">必須</span></label>
    <input class="input" type="date" id="entry_date" name="entry_date" value="<?= F::h($date) ?>" required<?= F::invalid($errors, 'entry_date') ?>>
    <?= F::error($errors, 'entry_date') ?>
  </div>
  <div class="field">
    <label for="lot_id">ロット <span class="req">必須</span> <span class="hint">進行中のみ。直近に入力したロットが先頭</span></label>
    <select class="select" id="lot_id" name="lot_id" required<?= F::invalid($errors, 'lot_id') ?> data-lot-select>
      <?php foreach ($lots as $l): ?>
        <option value="<?= (int) $l['id'] ?>"<?= F::selected($l['id'], $lotId) ?>><?= F::h($l['lot_code']) ?></option>
      <?php endforeach; ?>
    </select>
    <?= F::error($errors, 'lot_id') ?>
  </div>

  <div class="nums">
    <?php $first = true; foreach ($fields as $f => [$label, $unit]): ?>
      <div class="field">
        <label for="<?= $f ?>"><?= F::h($label) ?></label>
        <div class="input-unit">
          <?php if (in_array($f, Analysis::SIGNED, true)): ?>
            <button type="button" class="sign-toggle" data-sign-toggle="<?= $f ?>" aria-label="<?= F::h($label) ?>のプラス・マイナスを切り替える">±</button>
          <?php endif; ?>
          <input class="input input--big input--num" type="text" inputmode="decimal" enterkeyhint="next" autocomplete="off" id="<?= $f ?>" name="<?= $f ?>"
                 value="<?= $v($f) ?>" data-field="<?= $f ?>" data-decimal="<?= Analysis::DECIMALS ?>"<?= in_array($f, Analysis::SIGNED, true) ? ' data-signed' : '' ?>
                 <?= $first ? ' autofocus' : '' ?><?= F::invalid($errors, $f) ?>>
          <?php if ($unit !== ''): ?><span class="unit"><?= F::h($unit) ?></span><?php endif; ?>
        </div>
        <p class="field-note" data-prev-note="<?= $f ?>"><?= isset($prev[$f]) ? '前回 ' . F::h(F::num($prev[$f]['value'])) . '（' . F::h(substr($prev[$f]['date'], 5)) . '）' : '&nbsp;' ?></p>
        <?= F::error($errors, $f) ?>
      </div>
    <?php $first = false; endforeach; ?>
  </div>

  <div class="field">
    <label for="note">備考</label>
    <input class="input" type="text" id="note" name="note" value="<?= $v('note') ?>" placeholder="上槽、留添前 など">
  </div>

  <div class="sticky-submit"><button class="btn btn--big" type="submit">登録する</button></div>
</form>

<?php if ($recent !== []): ?>
<section class="card card--flush">
  <div class="card__head"><h2>このロットの最近の記録</h2><a class="small" href="<?= F::h(Url::to('books/analysis.php', ['lot' => [$lotId]])) ?>">一覧へ</a></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>日付</th><th class="num">アミノ酸</th><th class="num">酸度</th><th class="num">ボーメ</th><th class="num">アルコール</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td class="date"><?= F::h(substr((string) $r['entry_date'], 5)) ?></td>
        <td class="num"><?= F::num($r['amino_acid']) ?></td><td class="num"><?= F::num($r['acidity']) ?></td>
        <td class="num"><?= F::num($r['baume']) ?></td><td class="num"><?= F::num($r['alcohol']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php endif; ?>
