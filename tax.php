<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tax.php';

// CSV(税理士向け)は画面を出す前に返す
if (($_GET['csv'] ?? '') === '1') {
    requireLogin($pdo);
    $rows = monthTaxRows($pdo);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="liquor_tax_' . date('Ymd') . '.csv"');
    header('X-Robots-Tag: noindex, nofollow');
    echo "\xEF\xBB\xBF";   // Excel で文字化けしないための BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['年月', 'ロット', '詰口本数', '詰口量(L)', '軽減前の税額(円)', '納付予定額(円)', '納付期限', '納付状況', '納付日', '納付額(円)']);
    foreach ($rows as $m) {
        fputcsv($out, [
            $m['label'], implode(' / ', array_column($m['lots'], 'lot_code')),
            fmtQty($m['bottles']), fmtQty(round($m['ml'] / 1000, 2)),
            $m['calc_tax'], $m['due_amount'], $m['due_date'] ?? '',
            $m['paid'] ? '納付済' : '未納', $m['paid_date'] ?? '', $m['paid_amount'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = '酒税';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $ym = (string)($_POST['ym'] ?? '');
    try {
        if ($action === 'save_rate') {
            $rate = $_POST['rate'] ?? '';
            $pct = $_POST['pct'] ?? '';
            if (!ctype_digit((string)$rate) || (int)$rate < 1 || !is_numeric($pct) || (float)$pct <= 0 || (float)$pct > 100) {
                flash('酒税率は1以上の整数、軽減税率は0より大きく100以下の数値で入力してください。', 'error');
            } else {
                setSetting($pdo, 'liquor_tax_rate_per_kl', (string)(int)$rate);
                setSetting($pdo, 'liquor_tax_reduction_percent', (string)(float)$pct);
                flash('酒税率と軽減税率を保存しました。');
            }
        } elseif ($action === 'mark_paid' || $action === 'mark_unpaid') {
            $target = null;
            foreach (monthTaxRows($pdo) as $m) if ($m['year_month'] === $ym) $target = $m;
            if (!$target) {
                flash('対象の月が見つかりません。', 'error');
            } elseif ($action === 'mark_paid') {
                $date = (string)($_POST['paid_date'] ?? '');
                $amtRaw = trim((string)($_POST['paid_amount'] ?? ''));
                if (!validDate($date)) {
                    flash('納付日が正しくありません。', 'error');
                } elseif ($amtRaw !== '' && (!ctype_digit($amtRaw))) {
                    flash('納付額は0以上の整数で入力してください。', 'error');
                } else {
                    $amt = $amtRaw === '' ? $target['due_amount'] : (int)$amtRaw;   // 空欄なら納付予定額
                    $pdo->prepare('INSERT INTO tax_payments (year_month, paid, paid_amount, paid_date) VALUES (?, 1, ?, ?)
                        ON CONFLICT(year_month) DO UPDATE SET paid = 1, paid_amount = excluded.paid_amount, paid_date = excluded.paid_date')
                        ->execute([$ym, $amt, $date]);
                    flash($target['label'] . 'を納付済にしました。');
                }
            } else {
                $pdo->prepare('DELETE FROM tax_payments WHERE year_month = ?')->execute([$ym]);
                flash($target['label'] . 'を未納に戻しました。');
            }
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash('保存できませんでした。', 'error');
    }
    redirect('tax.php');
}

$rows = monthTaxRows($pdo);
$rate = taxRate($pdo);
$pct = taxReductionPct($pdo);
$unpaidTotal = 0;
foreach ($rows as $m) if (!$m['paid']) $unpaidTotal += $m['due_amount'];
?>
<h1>酒税(月別)</h1>
<p class="muted">同じ年月のロットの詰口量を合計して計算します。酒税 = 詰口量(kL) × 税率 → 軽減税率を掛け → 100円未満切り捨て。納付期限は年月の翌々月末です。目安の計算なので、申告額は必ず確認してください。</p>

<div class="panel no-print">
    <h2>税率の設定</h2>
    <form method="post" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_rate">
        <label>酒税率(円/kL)</label>
        <input type="number" name="rate" min="1" step="1" value="<?= (int)$rate ?>" required>
        <label>軽減税率(税額の何%か。軽減なしは100)</label>
        <input type="number" name="pct" min="0.01" max="100" step="any" value="<?= h(fmtQty($pct)) ?>" required>
        <p><button class="btn" type="submit">保存</button></p>
    </form>
</div>

<div class="panel">
    <h2>月別一覧
        <span class="no-print">
            <a class="btn sub small" href="tax.php?csv=1">CSVでダウンロード</a>
            <button class="btn sub small" type="button" onclick="window.print()">印刷</button>
        </span>
    </h2>
    <?php if (!$rows): ?>
        <p class="muted">詰口の記録がまだありません。<a href="bottling_input.php">詰口を入力</a>すると、ロットの年月ごとに集計されます。</p>
    <?php else: ?>
    <p>未納の納付予定額の合計: <strong><?= h(number_format($unpaidTotal)) ?>円</strong></p>
    <table>
        <tr><th>年月</th><th>ロット</th><th style="text-align:right">詰口量</th><th style="text-align:right">軽減前</th>
            <th style="text-align:right">納付予定額</th><th>納付期限</th><th>状況</th><th class="no-print"></th></tr>
        <?php foreach ($rows as $m): ?>
        <tr>
            <td><?= h($m['label']) ?></td>
            <td><?php foreach ($m['lots'] as $l): ?><div><?= h($l['lot_code']) ?> <span class="muted"><?= h(fmtQty(round($l['ml'] / 1000, 2))) ?>L / <?= h(fmtQty($l['bottles'])) ?>本</span></div><?php endforeach; ?></td>
            <td style="text-align:right"><?= h(fmtQty(round($m['ml'] / 1000, 2))) ?> L<div class="muted"><?= h(fmtQty($m['bottles'])) ?>本</div></td>
            <td style="text-align:right"><?= h(number_format($m['calc_tax'])) ?>円</td>
            <td style="text-align:right"><strong><?= h(number_format($m['due_amount'])) ?>円</strong></td>
            <td><?= h($m['due_date'] ?? '-') ?></td>
            <td>
                <?php if ($m['paid']): ?>
                    <span style="color:var(--pos)">納付済</span>
                    <div class="muted"><?= h($m['paid_date']) ?> / <?= h(number_format((int)$m['paid_amount'])) ?>円</div>
                <?php elseif ($m['overdue']): ?>
                    <span style="color:var(--neg)">期限超過</span>
                <?php else: ?>未納<?php endif; ?>
            </td>
            <td class="no-print">
                <?php if ($m['paid']): ?>
                <form method="post" class="inline" onsubmit="return confirm('未納に戻しますか?');">
                    <?= csrfField() ?><input type="hidden" name="action" value="mark_unpaid"><input type="hidden" name="ym" value="<?= h($m['year_month']) ?>">
                    <button class="btn sub small" type="submit">未納に戻す</button>
                </form>
                <?php else: ?>
                <form method="post">
                    <?= csrfField() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="ym" value="<?= h($m['year_month']) ?>">
                    <input type="date" name="paid_date" value="<?= h(date('Y-m-d')) ?>" required style="max-width:150px">
                    <input type="number" name="paid_amount" min="0" step="1" placeholder="納付額(空欄=予定額)" style="max-width:170px">
                    <button class="btn small" type="submit">納付済にする</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
