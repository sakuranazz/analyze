<?php
/** @var array $m @var bool $editing @var array<string,string> $errors @var array $values @var string $self */
use App\View\Format as F;

$ym = (string) $m['ym'];
$status = match ($m['status']) {
    'paid' => F::badge('ok', '納付済'), 'overdue' => F::badge('danger', '期限超過'), 'unpaid' => F::badge('warn', '未納'), default => F::badge('muted', '納付対象なし'),
};
$formId = 'f-tax-' . $ym;
?>
<tr data-row-id="tax-<?= F::h($ym) ?>"<?= $editing ? ' class="is-editing"' : '' ?>>
  <td class="nowrap"><?= F::h(F::monthJa($ym)) ?></td>
  <td class="num"><?= F::liters((int) $m['ml']) ?></td>
  <td class="num"><?= F::yen((int) $m['raw']) ?></td>
  <td class="num"><strong><?= F::yen((int) $m['planned']) ?></strong></td>
  <td class="date"><?= F::h($m['due']) ?></td>
  <?php if ($editing): ?>
    <td><div class="input-unit"><input class="input input--num" type="text" inputmode="numeric" name="declared_amount" form="<?= F::h($formId) ?>" value="<?= F::h($values['declared_amount'] ?? ($m['declared'] ?? $m['planned'])) ?>" aria-label="申告額" size="7"<?= F::invalid($errors, 'declared_amount') ?>><span class="unit">円</span></div></td>
    <td><input class="input" type="date" name="paid_date" form="<?= F::h($formId) ?>" value="<?= F::h($values['paid_date'] ?? $m['paid_date']) ?>" aria-label="納付日"<?= F::invalid($errors, 'paid_date') ?>></td>
    <td><?= $status ?></td>
    <td><input class="input" type="text" name="note" form="<?= F::h($formId) ?>" value="<?= F::h($values['note'] ?? $m['note']) ?>" aria-label="備考"></td>
    <td class="actions"><form id="<?= F::h($formId) ?>" method="post" action="<?= F::h($self) ?>" data-inline="tax-<?= F::h($ym) ?>" class="btn-row"
        data-confirm="<?= F::h(F::monthJa($ym) . 'の申告額・納付日を保存します。納付日を入れると「納付済」になります。') ?>" data-confirm-title="酒税の記録" data-confirm-ok="保存する">
      <?= F::csrfField() ?><input type="hidden" name="ym" value="<?= F::h($ym) ?>"><button class="btn" type="submit">保存</button><a class="btn btn--secondary" href="<?= F::h($self) ?>" data-cancel>取消</a></form></td>
  <?php else: ?>
    <td class="num"><?= $m['declared'] === null ? '<span class="muted">—</span>' : F::yen((int) $m['declared']) ?><?= $m['mismatch'] ? '<br>' . F::badge('warn', '差 ' . F::yen((int) $m['declared'] - (int) $m['planned'])) : '' ?></td>
    <td class="date"><?= F::h($m['paid_date'] ?? '—') ?></td>
    <td><?= $status ?></td>
    <td class="small note"><?= F::h($m['note']) ?><?php if ($m['lots'] !== []): ?><br><span class="muted">参考: <?= F::h(implode('、', array_map(static fn ($l) => $l['lot_code'] . ' ' . number_format($l['ml'] / 1000, 2) . 'L ¥' . number_format($l['ref']), $m['lots']))) ?></span><?php endif; ?></td>
    <td class="actions"><a class="btn <?= $m['status'] === 'paid' ? 'btn--icon' : 'btn--secondary' ?>" data-edit href="<?= F::h($self . '?edit=' . $ym) ?>" aria-label="<?= F::h(F::monthJa($ym)) ?>の申告額・納付日を記録"><?= $m['status'] === 'paid' ? F::icon('pencil', 16) : '記録する' ?></a></td>
  <?php endif; ?>
</tr>
<?php if ($errors !== []): ?><tr class="row-error" data-row-extra="tax-<?= F::h($ym) ?>"><td colspan="10"><?= F::h(implode(' / ', $errors)) ?></td></tr><?php endif; ?>
