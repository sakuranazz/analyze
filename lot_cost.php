<?php
$pageTitle = 'ロット原価';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/valuation.php';

$lots = lotCostRows($pdo, loadCostModel($pdo));
$lots = array_values(array_filter($lots, fn($l) => $l['has_cost'] || $l['ml'] > 0));
?>
<h1>ロット原価(1ml当たり)</h1>
<p class="muted">醸造仕込みで使った原料と、詰口で減った瓶・ラベル・使用量換算の資材を、そのロットの詰口総量(試飲用を含む)で割ります。単価は最終仕入原価法(白米・麹は精米・製麹の実績)です。完成酒の評価は第2段階です。</p>

<div class="panel">
    <?php if (!$lots): ?>
        <p class="muted">原価を計算できるロットがありません。工程入力でロットを指定し、詰口を登録してください。</p>
    <?php else: ?>
    <table>
        <tr><th>ロット</th><th style="text-align:right">詰口総量</th><th style="text-align:right">原料費</th><th style="text-align:right">瓶・ラベル</th>
            <th style="text-align:right">原価合計</th><th style="text-align:right">1ml当たり</th><th style="text-align:right">720ml換算</th></tr>
        <?php foreach ($lots as $l): ?>
        <tr>
            <td><?= h($l['lot_code']) ?>
                <?php if ($l['unknown']): ?><div style="color:var(--neg);font-size:.8rem">単価不明: <?= h(implode('、', $l['unknown'])) ?></div><?php endif; ?></td>
            <td style="text-align:right"><?= h(fmtQty(round($l['ml'] / 1000, 2))) ?> L<div class="muted"><?= h(fmtQty($l['bottles'])) ?>本</div></td>
            <td style="text-align:right"><?= h(number_format($l['raw'])) ?>円</td>
            <td style="text-align:right"><?= h(number_format($l['pack'])) ?>円</td>
            <td style="text-align:right"><strong><?= h(number_format($l['total'])) ?>円</strong></td>
            <td style="text-align:right"><?= $l['per_ml'] === null ? '<span class="muted">詰口なし</span>' : h(number_format($l['per_ml'], 3)) . '円' ?></td>
            <td style="text-align:right"><?= $l['per_ml'] === null ? '' : h(number_format($l['per_ml'] * 720)) . '円' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
