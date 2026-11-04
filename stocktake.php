<?php
$pageTitle = '在庫・棚卸し';
require_once __DIR__ . '/includes/header.php';

// 基準日までの残高(台帳の合計)。金額評価(最終仕入原価法)と月次棚卸しは次の段階で追加する。
$asOf = (string)($_GET['as_of'] ?? date('Y-m-d'));
if (!validDate($asOf)) $asOf = date('Y-m-d');

$st = $pdo->prepare('SELECT m.id, m.name, m.unit, m.kind, c.name AS category,
        COALESCE(SUM(s.qty), 0) AS balance
    FROM materials m
    JOIN material_categories c ON c.id = m.category_id
    LEFT JOIN stock_movements s ON s.material_id = m.id AND s.entry_date <= ?
    WHERE m.is_active = 1
    GROUP BY m.id
    ORDER BY c.sort_order, c.id, m.id');
$st->execute([$asOf]);
$rows = $st->fetchAll();

$detailId = (int)($_GET['m'] ?? 0);
$detail = [];
$detailName = '';
if ($detailId > 0) {
    $d = $pdo->prepare('SELECT s.*, l.lot_code, sp.name AS supplier FROM stock_movements s
        LEFT JOIN brewing_lots l ON l.id = s.lot_id
        LEFT JOIN suppliers sp ON sp.id = s.supplier_id
        WHERE s.material_id = ? AND s.entry_date <= ? ORDER BY s.entry_date DESC, s.id DESC LIMIT 200');
    $d->execute([$detailId, $asOf]);
    $detail = $d->fetchAll();
    $n = $pdo->prepare('SELECT name, unit FROM materials WHERE id = ?');
    $n->execute([$detailId]);
    $detailName = $n->fetch() ?: ['name' => '', 'unit' => ''];
}
?>
<h1>在庫・棚卸し</h1>
<div class="panel">
    <form method="get" class="stack">
        <label>基準日(この日までの残高)</label>
        <input type="date" name="as_of" value="<?= h($asOf) ?>">
        <p><button class="btn" type="submit">表示</button></p>
    </form>
</div>

<div class="panel">
    <h2>資材の在庫(<?= h($asOf) ?> 時点)</h2>
    <?php if (!$rows): ?>
        <p class="muted">資材が未登録です。<a href="masters.php?t=materials">マスタ管理</a>から登録してください。</p>
    <?php else: ?>
    <table>
        <tr><th>カテゴリ</th><th>資材</th><th style="text-align:right">残高</th><th></th></tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= h($r['category']) ?></td>
            <td><?= h($r['name']) ?></td>
            <td style="text-align:right;<?= $r['balance'] < 0 ? 'color:var(--neg)' : '' ?>"><?= h(fmtQty($r['balance'])) ?> <?= h($r['unit']) ?></td>
            <td><a class="btn sub small" href="stocktake.php?as_of=<?= h($asOf) ?>&m=<?= (int)$r['id'] ?>">履歴</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<?php if ($detailId > 0): ?>
<div class="panel">
    <h2><?= h($detailName['name']) ?> の入出庫履歴(最新200件)</h2>
    <?php if (!$detail): ?><p class="muted">履歴がありません。</p><?php else: ?>
    <table>
        <tr><th>日付</th><th>内容</th><th style="text-align:right">増減</th><th style="text-align:right">金額</th><th>ロット</th><th>仕入先</th></tr>
        <?php foreach ($detail as $r): ?>
        <tr>
            <td><?= h($r['entry_date']) ?></td>
            <td><?= h($r['reason']) ?></td>
            <td style="text-align:right"><?= $r['qty'] > 0 ? '+' : '' ?><?= h(fmtQty($r['qty'])) ?> <?= h($detailName['unit']) ?></td>
            <td style="text-align:right"><?= $r['amount_yen'] !== null ? h(number_format($r['amount_yen'])) . '円' : '' ?></td>
            <td><?= h($r['lot_code'] ?? '') ?></td>
            <td><?= h($r['supplier'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
