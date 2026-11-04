<?php
/** @var array $l @var bool $editing @var array<string,string> $errors @var string $self @var string $tax */
use App\Domain\Qty;
use App\View\Format as F;

$id = (int) $l['id'];
$total = $l['total_ml'] === null ? null : (int) $l['total_ml'];
$remaining = $l['remaining_ml'] === null ? null : (int) $l['remaining_ml'];
$done = $total ? min(100, round((int) $l['bottles_ml'] / $total * 100, 1)) : 0;
$sample = $total ? min(100 - $done, round((int) $l['sample_ml'] / $total * 100, 1)) : 0;
$taxBadge = match ($tax) {
    'paid' => F::badge('ok', '納付済'), 'overdue' => F::badge('danger', '期限超過'), 'unpaid' => F::badge('warn', '未納'), default => '<span class="muted">—</span>',
};
$formId = 'f-lot-' . $id;
?>
<tr data-row-id="lot-<?= $id ?>"<?= $editing ? ' class="is-editing"' : '' ?>>
  <td><?= F::code((string) $l['lot_code']) ?></td>
  <td><?= F::h($l['liquor_name']) ?></td>
  <td><?= $l['status'] === 'active' ? F::badge('active', '進行中') : F::badge('muted', '終了') ?></td>
  <?php if ($editing): ?>
    <td><div class="input-unit"><input class="input input--num" type="text" inputmode="decimal" name="total_l" form="<?= $formId ?>" value="<?= F::h($total === null ? '' : Qty::plain($total)) ?>" aria-label="全液量 (L)" size="7" autofocus<?= F::invalid($errors, 'total_l') ?>><span class="unit">L</span></div></td>
  <?php else: ?>
    <td class="num"><?= $total === null ? ((bool) $l['has_brew'] ? F::badge('warn', '未入力') : '<span class="muted">—</span>') : F::liters($total) ?></td>
  <?php endif; ?>
  <td class="num"><?= F::liters((int) $l['used_ml']) ?></td>
  <?= F::numCell($remaining === null ? '—' : F::liters($remaining), $remaining) ?>
  <td><?php if ($total): ?><div class="bar"><span class="bar__done" style="width:<?= $done ?>%"></span><span class="bar__sample" style="width:<?= $sample ?>%"></span></div><?php endif; ?></td>
  <td class="num"><?= $l['per_ml_micro'] === null ? '<span class="muted">—</span>' : F::h(F::price((int) $l['per_ml_micro'])) ?><?= $l['missing_price'] ? ' ' . F::badge('warn', '単価未入力含む') : '' ?></td>
  <td><?= $taxBadge ?></td>
  <?php if ($editing): ?>
    <td><input class="input" type="text" name="note" form="<?= $formId ?>" value="<?= F::h($l['note']) ?>" aria-label="備考"></td>
    <td class="actions"><form id="<?= $formId ?>" method="post" action="<?= F::h($self) ?>" data-inline="lot-<?= $id ?>" class="btn-row">
      <?= F::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn" type="submit">保存</button><a class="btn btn--secondary" href="<?= F::h($self) ?>" data-cancel>取消</a></form></td>
  <?php else: ?>
    <td class="small"><?= F::h($l['note']) ?></td>
    <td class="actions"><div class="btn-row" style="flex-wrap:nowrap;justify-content:flex-end">
      <?php if ($l['status'] === 'active' && $remaining !== null && $remaining <= 0): ?>
        <form method="post" action="<?= F::h($self) ?>"><?= F::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="closed"><button class="btn btn--secondary" type="submit">終了にしますか？</button></form>
      <?php endif; ?>
      <a class="btn btn--icon" data-edit href="<?= F::h($self . '&edit=' . $id) ?>" aria-label="全液量・備考を直す"><?= F::icon('pencil', 16) ?></a>
      <form method="post" action="<?= F::h($self) ?>"><?= F::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= $l['status'] === 'active' ? 'closed' : 'active' ?>">
        <button class="btn btn--text" type="submit"><?= $l['status'] === 'active' ? '終了にする' : '進行中に戻す' ?></button></form>
    </div></td>
  <?php endif; ?>
</tr>
<?php if ($errors !== []): ?><tr class="row-error" data-row-extra="lot-<?= $id ?>"><td colspan="11"><?= F::h(implode(' / ', $errors)) ?></td></tr><?php endif; ?>
