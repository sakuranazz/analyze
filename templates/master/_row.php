<?php
/** @var array $r @var array $cols @var bool $editing @var array<string,string> $errors @var array $values @var string $self @var string $table */
use App\View\Format as F;

$id = (int) $r['id'];
$formId = "f-{$table}-{$id}";
$hidden = isset($r['is_active']) && (int) $r['is_active'] === 0;
?>
<tr data-row-id="<?= F::h($table . '-' . $id) ?>" class="<?= $editing ? 'is-editing' : '' ?>"<?= $hidden && !$editing ? ' style="color:var(--text-muted)"' : '' ?>>
  <?php foreach ($cols as $c => $def):
      [$label, $type] = $def;
      $val = $values[$c] ?? $r[$c] ?? null; ?>
    <?php if (!$editing): ?>
      <td class="<?= $type === 'int' ? 'num' : '' ?>"><?php
        echo match ($type) {
            'bool' => (int) $val === 1 ? F::icon('ok', 16) : '<span class="muted">—</span>',
            'select' => F::h($def[2][$val] ?? ($val === null || $val === '' ? '—' : $val)),
            'code' => F::code((string) $val),
            default => F::h($val),
        }; ?></td>
    <?php else: ?>
      <td><?php if ($type === 'bool'): ?>
          <input type="hidden" name="<?= $c ?>" value="0" form="<?= $formId ?>"><input type="checkbox" name="<?= $c ?>" value="1" form="<?= $formId ?>" aria-label="<?= F::h($label) ?>"<?= F::checked((int) $val === 1) ?>>
        <?php elseif ($type === 'select'): ?>
          <select class="select" name="<?= $c ?>" form="<?= $formId ?>" aria-label="<?= F::h($label) ?>"><option value="">—</option><?php foreach ($def[2] as $k => $l): ?><option value="<?= F::h($k) ?>"<?= F::selected($k, $val) ?>><?= F::h($l) ?></option><?php endforeach; ?></select>
        <?php else: ?>
          <input class="input<?= $type === 'int' ? ' input--num' : '' ?>" type="text" name="<?= $c ?>" form="<?= $formId ?>" value="<?= F::h($val) ?>" aria-label="<?= F::h($label) ?>"<?= $type === 'int' ? ' inputmode="numeric" size="5"' : '' ?><?= F::invalid($errors, $c) ?>>
        <?php endif; ?></td>
    <?php endif; ?>
  <?php endforeach; ?>
  <td class="actions">
    <?php if ($editing): ?>
      <form id="<?= $formId ?>" method="post" action="<?= F::h($self) ?>" data-inline="<?= F::h($table . '-' . $id) ?>" class="btn-row" style="flex-wrap:nowrap">
        <?= F::csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn" type="submit">保存</button><a class="btn btn--secondary" href="<?= F::h($self) ?>" data-cancel>取消</a>
      </form>
    <?php else: ?>
      <div class="btn-row" style="flex-wrap:nowrap;justify-content:flex-end">
        <a class="btn btn--icon" data-edit href="<?= F::h($self . (str_contains($self, '?') ? '&' : '?') . 'edit=' . $id) ?>" aria-label="編集"><?= F::icon('pencil', 16) ?></a>
        <?php if ($hidden): ?>
          <form method="post" action="<?= F::h($self) ?>"><?= F::csrfField() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--text" type="submit">表示に戻す</button></form>
        <?php else: ?>
          <form method="post" action="<?= F::h($self) ?>" data-confirm="「<?= F::h($r['name'] ?? $r['label'] ?? $r['code'] ?? $id) ?>」を削除します。使用中のときは削除せず非表示にします。" data-confirm-title="削除の確認" data-confirm-ok="削除する">
            <?= F::csrfField() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--icon" type="submit" aria-label="削除"><?= F::icon('trash', 16) ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </td>
</tr>
<?php if ($errors !== []): ?><tr class="row-error" data-row-extra="<?= F::h($table . '-' . $id) ?>"><td colspan="<?= count($cols) + 1 ?>"><?= F::h(implode(' / ', $errors)) ?></td></tr><?php endif; ?>
