<?php
$pageTitle = '分析結果一覧';
require_once __DIR__ . '/includes/header.php';

$fields = [
    'amino_acid' => 'アミノ酸度', 'acidity' => '酸度', 'baume' => 'ボーメ(日本酒度)',
    'alcohol' => 'アルコール度数(%)', 'product_temp' => '品温(℃)', 'room_temp' => '室温(℃)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $pdo->prepare('DELETE FROM analysis_results WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('分析結果を削除しました。');
    }
    $lot = (int)($_POST['lot_id'] ?? 0);
    redirect('analysis_results.php' . ($lot ? '?lot_id=' . $lot : ''));
}

$lotFilter = (int)($_GET['lot_id'] ?? 0);
$lots = $pdo->query('SELECT l.id, l.lot_code, COUNT(a.id) AS n FROM brewing_lots l
    LEFT JOIN analysis_results a ON a.lot_id = l.id GROUP BY l.id ORDER BY l.year_month DESC, l.id DESC')->fetchAll();

$sql = 'SELECT a.*, l.lot_code FROM analysis_results a LEFT JOIN brewing_lots l ON l.id = a.lot_id';
$params = [];
if ($lotFilter) { $sql .= ' WHERE a.lot_id = ?'; $params[] = $lotFilter; }
$st = $pdo->prepare($sql . ' ORDER BY a.entry_date DESC, a.id DESC');
$st->execute($params);
$rows = $st->fetchAll();
$lotCode = '';
foreach ($lots as $l) if ((int)$l['id'] === $lotFilter) $lotCode = $l['lot_code'];

// グラフ用(古い順)
$chart = array_reverse($rows);
$series = fn(string $c) => array_map(fn($r) => $r[$c] === null ? null : (float)$r[$c], $chart);
?>
<h1>分析結果一覧<?= $lotCode ? ' — ' . h($lotCode) : '' ?></h1>

<div class="panel no-print">
    <form method="get" class="stack">
        <label>ロットで絞り込み</label>
        <select name="lot_id" onchange="this.form.submit()">
            <option value="">すべてのロット</option>
            <?php foreach ($lots as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === $lotFilter ? 'selected' : '' ?>><?= h($l['lot_code']) ?>(<?= (int)$l['n'] ?>件)</option><?php endforeach; ?>
        </select>
        <p><a class="btn" href="analysis_input.php">入力する</a> <button class="btn sub" type="button" onclick="window.print()">印刷・PDF保存</button></p>
    </form>
</div>

<?php if ($lotFilter && count($rows) > 0): ?>
<div class="panel">
    <h2>推移グラフ(成分)</h2>
    <canvas id="chartMain" height="110"></canvas>
</div>
<div class="panel">
    <h2>推移グラフ(温度)</h2>
    <canvas id="chartTemp" height="80"></canvas>
</div>
<script src="assets/js/chart.umd.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var labels = <?= json_encode(array_column($chart, 'entry_date')) ?>;
    var css = getComputedStyle(document.documentElement);
    function ds(label, data, color, axis) {
        return { label: label, data: data, borderColor: color, backgroundColor: color, spanGaps: true, tension: 0.2, yAxisID: axis || 'y' };
    }
    new Chart(document.getElementById('chartMain'), {
        type: 'line',
        data: { labels: labels, datasets: [
            ds('アミノ酸度', <?= json_encode($series('amino_acid')) ?>, '#2e7d32'),
            ds('酸度', <?= json_encode($series('acidity')) ?>, '#1565c0'),
            ds('ボーメ', <?= json_encode($series('baume')) ?>, '#6a1b9a'),
            ds('アルコール度数(%)', <?= json_encode($series('alcohol')) ?>, css.getPropertyValue('--accent').trim() || '#b5443c', 'y2')
        ]},
        options: { interaction: { mode: 'index', intersect: false },
            scales: { y: { title: { display: true, text: 'アミノ酸度・酸度・ボーメ' } },
                      y2: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'アルコール(%)' } } } }
    });
    new Chart(document.getElementById('chartTemp'), {
        type: 'line',
        data: { labels: labels, datasets: [
            ds('品温(℃)', <?= json_encode($series('product_temp')) ?>, '#b5443c'),
            ds('室温(℃)', <?= json_encode($series('room_temp')) ?>, '#7a7267')
        ]},
        options: { interaction: { mode: 'index', intersect: false } }
    });
})();
</script>
<?php elseif (!$lotFilter && $rows): ?>
<p class="muted no-print">ロットを1つ選ぶと、そのロットの推移グラフが表示されます。</p>
<?php endif; ?>

<div class="panel">
    <h2><?= count($rows) ?>件</h2>
    <?php if (!$rows): ?><p class="muted">分析結果がありません。</p><?php else: ?>
    <div class="scroll-x">
    <table>
        <tr><th>日付</th><?php if (!$lotFilter): ?><th>ロット</th><?php endif; ?>
            <?php foreach ($fields as $label): ?><th><?= h($label) ?></th><?php endforeach; ?><th>メモ</th><th class="no-print"></th></tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= h($r['entry_date']) ?></td>
            <?php if (!$lotFilter): ?><td><?= h($r['lot_code'] ?? '') ?></td><?php endif; ?>
            <?php foreach ($fields as $c => $label): ?><td><?= $r[$c] === null ? '' : h(fmtQty($r[$c])) ?></td><?php endforeach; ?>
            <td><?= h($r['note']) ?></td>
            <td class="no-print">
                <a class="btn sub small" href="analysis_input.php?edit=<?= (int)$r['id'] ?>">修正</a>
                <form method="post" class="inline" onsubmit="return confirm('削除しますか?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="lot_id" value="<?= $lotFilter ?>">
                    <button class="btn sub small" type="submit">削除</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
