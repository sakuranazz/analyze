<?php
/** @var array<string, mixed> $r @var bool $editing @var array<string, string> $errors @var array<string, mixed> $values @var string $self */
use App\Data\Analysis;
use App\Http\Url;
use App\View\Format as F;

$id = (int) $r['id'];
$formId = 'f-ana-' . $id;
$val = static fn (string $k) => F::h($values[$k] ?? ($r[$k] === null ? '' : F::num($r[$k])));
if (!$editing): ?>
<tr data-row-id="ana-<?= $id ?>">
  <td class="date"><?= F::h($r['entry_date']) ?></td>
  <td><?= F::code((string) $r['lot_code']) ?></td>
  <?php foreach (array_keys(Analysis::FIELDS) as $f): ?><td class="num"><?= F::num($r[$f]) ?></td><?php endforeach; ?>
  <td><?= F::h($r['note']) ?></td>
  <td class="actions">
    <a class="btn btn--icon" data-edit href="<?= F::h($self . (str_contains($self, '?') ? '&' : '?') . 'edit=' . $id) ?>" aria-label="編集"><?= F::icon('pencil', 16) ?></a>
  </td>
</tr>
<?php else: ?>
<tr class="is-editing" data-row-id="ana-<?= $id ?>">
  <td><input class="input" type="date" name="entry_date" form="<?= $formId ?>" value="<?= F::h($values['entry_date'] ?? $r['entry_date']) ?>" aria-label="日付"<?= F::invalid($errors, 'entry_date') ?>></td>
  <td><?= F::code((string) $r['lot_code']) ?><input type="hidden" name="lot_id" form="<?= $formId ?>" value="<?= (int) $r['lot_id'] ?>"></td>
  <?php foreach (Analysis::FIELDS as $f => [$label]): ?>
    <td><input class="input input--num" type="text" inputmode="decimal" data-decimal="<?= Analysis::DECIMALS ?>"<?= in_array($f, Analysis::SIGNED, true) ? ' data-signed' : '' ?> name="<?= $f ?>" form="<?= $formId ?>" value="<?= $val($f) ?>" aria-label="<?= F::h($label) ?>" size="5"<?= F::invalid($errors, $f) ?>></td>
  <?php endforeach; ?>
  <td><input class="input" type="text" name="note" form="<?= $formId ?>" value="<?= F::h($values['note'] ?? $r['note']) ?>" aria-label="備考"></td>
  <td class="actions">
    <form id="<?= $formId ?>" method="post" action="<?= F::h($self) ?>" data-inline="ana-<?= $id ?>" class="btn-row">
      <?= F::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn--secondary" href="<?= F::h($self) ?>" data-cancel>取消</a>
    </form>
    <form method="post" action="<?= F::h($self) ?>" data-confirm="<?= F::h($r['entry_date'] . ' の ' . $r['lot_code'] . ' の分析結果を削除します。') ?>" data-confirm-title="削除の確認" data-confirm-ok="削除する">
      <?= F::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete">
      <button class="btn btn--text" type="submit"><?= F::icon('trash', 16) ?>削除</button>
    </form>
  </td>
</tr>
<?php if ($errors !== []): ?>
<tr class="row-error" data-row-extra="ana-<?= $id ?>"><td colspan="10"><?= F::icon('warn', 14) ?> <?= F::h(implode(' / ', $errors)) ?></td></tr>
<?php endif; endif; ?>
