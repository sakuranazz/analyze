<?php
$pageTitle = 'SKU・ロット一覧';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'sku_update') {
            $pdo->prepare('UPDATE skus SET name = ?, is_active = ? WHERE id = ?')
                ->execute([trim((string)($_POST['name'] ?? '')) ?: null, empty($_POST['is_active']) ? 0 : 1, $id]);
            flash('SKUを更新しました。');
        } elseif ($action === 'sku_delete') {
            $pdo->prepare('DELETE FROM skus WHERE id = ?')->execute([$id]);
            flash('SKUを削除しました。');
        } elseif ($action === 'lot_update') {
            $pdo->prepare('UPDATE brewing_lots SET note = ? WHERE id = ?')
                ->execute([trim((string)($_POST['note'] ?? '')) ?: null, $id]);
            flash('ロットのメモを更新しました。');
        } elseif ($action === 'lot_delete') {
            $pdo->prepare('DELETE FROM brewing_lots WHERE id = ?')->execute([$id]);
            flash('ロットを削除しました。');
        }
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'FOREIGN KEY') !== false) {
            flash('分析・工程・在庫などで使われているため削除できません。', 'error');
        } else {
            error_log($e->getMessage());
            flash('保存できませんでした。', 'error');
        }
    }
    redirect('sku_lot_list.php');
}

$skus = $pdo->query('SELECT * FROM skus ORDER BY code')->fetchAll();
$lots = $pdo->query('SELECT b.*, l.name AS liquor_name, t.name AS tank_name
    FROM brewing_lots b
    LEFT JOIN lot_liquor_codes l ON l.id = b.liquor_code_id
    LEFT JOIN tank_codes t ON t.id = b.tank_code_id
    ORDER BY b.year_month DESC, b.id DESC')->fetchAll();
?>
<h1>SKU・ロット一覧</h1>
<p><a class="btn sub small" href="codes.php">作番する</a></p>

<div class="panel">
    <h2>SKU(<?= count($skus) ?>件)</h2>
    <?php if (!$skus): ?><p class="muted">まだありません。</p><?php else: ?>
    <table>
        <tr><th>コード</th><th>商品名</th><th>使用中</th><th></th></tr>
        <?php foreach ($skus as $s): $fid = 'sku' . (int)$s['id']; ?>
        <tr>
            <td><?= h($s['code']) ?></td>
            <td><input type="text" name="name" form="<?= $fid ?>" value="<?= h($s['name']) ?>" maxlength="100"></td>
            <td><input type="checkbox" name="is_active" form="<?= $fid ?>" value="1" <?= $s['is_active'] ? 'checked' : '' ?>></td>
            <td>
                <form method="post" id="<?= $fid ?>" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn sub small" name="action" value="sku_update" type="submit">保存</button>
                    <button class="btn sub small" name="action" value="sku_delete" type="submit" onclick="return confirm('SKU「<?= h($s['code']) ?>」を削除しますか?');">削除</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>醸造ロット(<?= count($lots) ?>件)</h2>
    <?php if (!$lots): ?><p class="muted">まだありません。</p><?php else: ?>
    <table>
        <tr><th>ロットコード</th><th>酒類</th><th>タンク</th><th>メモ</th><th></th></tr>
        <?php foreach ($lots as $l): $fid = 'lot' . (int)$l['id']; ?>
        <tr>
            <td><?= h($l['lot_code']) ?></td>
            <td><?= h($l['liquor_name']) ?></td>
            <td><?= h($l['tank_name']) ?></td>
            <td><input type="text" name="note" form="<?= $fid ?>" value="<?= h($l['note']) ?>" maxlength="200"></td>
            <td>
                <form method="post" id="<?= $fid ?>" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <button class="btn sub small" name="action" value="lot_update" type="submit">保存</button>
                    <button class="btn sub small" name="action" value="lot_delete" type="submit" onclick="return confirm('ロット「<?= h($l['lot_code']) ?>」を削除しますか?');">削除</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
