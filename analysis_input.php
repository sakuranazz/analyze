<?php
$pageTitle = '分析結果入力';
require_once __DIR__ . '/includes/header.php';

// 分析項目: [列名, 表示名, 単位/補足]
$fields = [
    ['amino_acid', 'アミノ酸度', ''],
    ['acidity', '酸度', ''],
    ['baume', 'ボーメ(日本酒度)', ''],
    ['alcohol', 'アルコール度数', '%'],
    ['product_temp', '品温', '℃'],
    ['room_temp', '室温', '℃'],
];
$cols = array_column($fields, 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $back = $id ? 'analysis_input.php?edit=' . $id : 'analysis_input.php';
    try {
        $date = (string)($_POST['entry_date'] ?? '');
        if (!validDate($date)) throw new RuntimeException('日付が正しくありません。');
        $lotId = (int)($_POST['lot_id'] ?? 0);
        $st = $pdo->prepare('SELECT 1 FROM brewing_lots WHERE id = ?');
        $st->execute([$lotId]);
        if (!$st->fetchColumn()) throw new RuntimeException('ロットを選択してください。');

        $vals = []; $any = false;
        foreach ($fields as [$col, $label]) {
            $raw = str_replace(',', '.', trim((string)($_POST[$col] ?? '')));   // スマホでカンマ入力されても受け付ける
            if ($raw === '') { $vals[$col] = null; continue; }
            if (!is_numeric($raw)) throw new RuntimeException("「{$label}」は数値で入力してください。");
            $vals[$col] = (float)$raw;
            $any = true;
        }
        if (!$any) throw new RuntimeException('分析値を1つ以上入力してください。');
        $note = trim((string)($_POST['note'] ?? '')) ?: null;

        if ($id) {
            $set = implode(', ', array_map(fn($c) => "$c = :$c", $cols));
            $pdo->prepare("UPDATE analysis_results SET entry_date = :d, lot_id = :l, $set, note = :n WHERE id = :id")
                ->execute(['d' => $date, 'l' => $lotId, 'n' => $note, 'id' => $id] + $vals);
            flash('分析結果を更新しました。');
            $back = 'analysis_results.php?lot_id=' . $lotId;
        } else {
            $pdo->prepare('INSERT INTO analysis_results (entry_date, lot_id, ' . implode(', ', $cols) . ', note)
                VALUES (:d, :l, :' . implode(', :', $cols) . ', :n)')
                ->execute(['d' => $date, 'l' => $lotId, 'n' => $note] + $vals);
            $_SESSION['last_analysis_lot'] = $lotId;   // 次回はこのロットを最初から選んでおく
            flash('分析結果を登録しました。');
            $back = 'analysis_input.php';
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash('保存できませんでした。', 'error');
    }
    redirect($back);
}

$edit = [];
if (($editId = (int)($_GET['edit'] ?? 0)) > 0) {
    $st = $pdo->prepare('SELECT * FROM analysis_results WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: [];
}
$lots = $pdo->query('SELECT id, lot_code FROM brewing_lots ORDER BY year_month DESC, id DESC')->fetchAll();
$selLot = (int)($edit['lot_id'] ?? $_SESSION['last_analysis_lot']
    ?? $pdo->query('SELECT lot_id FROM analysis_results WHERE lot_id IS NOT NULL ORDER BY entry_date DESC, id DESC LIMIT 1')->fetchColumn());

$recent = [];
if ($selLot && !$edit) {
    $st = $pdo->prepare('SELECT * FROM analysis_results WHERE lot_id = ? ORDER BY entry_date DESC, id DESC LIMIT 5');
    $st->execute([$selLot]);
    $recent = $st->fetchAll();
}
?>
<h1><?= $edit ? '分析結果の修正' : '分析結果入力' ?></h1>

<?php if (!$lots): ?>
<div class="panel"><p>ロットがありません。先に <a href="codes.php">ロットを作番</a> してください。</p></div>
<?php endif; ?>

<div class="panel">
<form method="post" class="stack mobile-form">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>日付</label>
    <input type="date" name="entry_date" value="<?= h($edit['entry_date'] ?? date('Y-m-d')) ?>" required>
    <label>ロット</label>
    <select name="lot_id" required>
        <option value="">選択してください</option>
        <?php foreach ($lots as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === $selLot ? 'selected' : '' ?>><?= h($l['lot_code']) ?></option><?php endforeach; ?>
    </select>
    <div class="grid2">
        <?php foreach ($fields as [$col, $label, $unit]): ?>
        <div>
            <label><?= h($label) ?><?= $unit ? '(' . h($unit) . ')' : '' ?></label>
            <input type="text" inputmode="decimal" name="<?= h($col) ?>" value="<?= isset($edit[$col]) ? h(fmtQty($edit[$col])) : '' ?>" autocomplete="off">
        </div>
        <?php endforeach; ?>
    </div>
    <label>メモ</label>
    <input type="text" name="note" value="<?= h($edit['note'] ?? '') ?>" maxlength="200">
    <p><button class="btn wide" type="submit"><?= $edit ? '更新する' : '登録する' ?></button>
        <?php if ($edit): ?><a class="btn sub" href="analysis_results.php?lot_id=<?= (int)$edit['lot_id'] ?>">戻る</a><?php endif; ?></p>
</form>
</div>

<?php if ($recent): ?>
<div class="panel">
    <h2>このロットの直近5件 <a class="btn sub small" href="analysis_results.php?lot_id=<?= $selLot ?>">一覧・グラフ</a></h2>
    <div class="scroll-x">
    <table>
        <tr><th>日付</th><?php foreach ($fields as [, $label]): ?><th><?= h($label) ?></th><?php endforeach; ?></tr>
        <?php foreach ($recent as $r): ?>
        <tr><td><?= h($r['entry_date']) ?></td><?php foreach ($cols as $c): ?><td><?= $r[$c] === null ? '' : h(fmtQty($r[$c])) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
