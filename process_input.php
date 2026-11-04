<?php
$pageTitle = '工程入力';
require_once __DIR__ . '/includes/header.php';

class InputError extends Exception {}

$types = [
    'seimai'  => '精米(玄米→白米)',
    'hatsuga' => '発芽処理(玄米→発芽玄米)',
    'seikiku' => '製麹(白米→米麹)',
    'jouzou'  => '醸造・仕込み(使用資材を消費)',
    'shiire'  => '仕入(入庫・金額つき)',
    'chosei'  => '手動調整(+/-)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM stock_movements WHERE process_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM process_events WHERE id = ?')->execute([$id]);
            $pdo->commit();
            flash('工程を削除し、在庫を元に戻しました。');
            redirect('process_input.php');
        }

        $date = (string)($_POST['entry_date'] ?? '');
        if (!validDate($date)) throw new InputError('日付が正しくありません。');
        $type = (string)($_POST['process_type'] ?? '');
        if (!isset($types[$type])) throw new InputError('工程の種類が正しくありません。');
        $note = trim((string)($_POST['note'] ?? '')) ?: null;

        $lotId = null;
        if (($_POST['lot_id'] ?? '') !== '') {
            $lotId = (int)$_POST['lot_id'];
            $st = $pdo->prepare('SELECT 1 FROM brewing_lots WHERE id = ?');
            $st->execute([$lotId]);
            if (!$st->fetchColumn()) throw new InputError('ロットが見つかりません。');
        }

        // 資材IDの検証(存在・有効・種別)
        $mat = function (string $field, ?array $kinds = null) use ($pdo): int {
            $id = (int)($_POST[$field] ?? 0);
            $st = $pdo->prepare('SELECT kind FROM materials WHERE id = ? AND is_active = 1');
            $st->execute([$id]);
            $kind = $st->fetchColumn();
            if ($kind === false || ($kinds && !in_array($kind, $kinds, true))) {
                throw new InputError('資材の選択が正しくありません。');
            }
            return $id;
        };
        $qty = function (string $field, bool $allowNegative = false): float {
            $raw = trim((string)($_POST[$field] ?? ''));
            if ($raw === '' || !is_numeric($raw)) throw new InputError('数量を数値で入力してください。');
            $v = (float)$raw;
            if ($v == 0 || (!$allowNegative && $v < 0)) throw new InputError('数量は' . ($allowNegative ? '0以外' : '0より大きい値') . 'で入力してください。');
            return $v;
        };

        // [資材ID, 数量(符号つき), 理由, 金額, 仕入先]
        $moves = [];
        $supplierId = null;
        switch ($type) {
            case 'seimai':
                $moves[] = [$mat('seimai_in', ['brown_rice']), -$qty('seimai_in_qty'), '精米(玄米消費)', null];
                $moves[] = [$mat('seimai_out', ['polished_rice']), $qty('seimai_out_qty'), '精米(白米生成)', null];
                break;
            case 'hatsuga':
                $moves[] = [$mat('hatsuga_in', ['brown_rice']), -$qty('hatsuga_in_qty'), '発芽処理(玄米消費)', null];
                $moves[] = [$mat('hatsuga_out', ['germinated_rice']), $qty('hatsuga_out_qty'), '発芽処理(発芽玄米生成)', null];
                break;
            case 'seikiku':
                $moves[] = [$mat('seikiku_in', ['polished_rice']), -$qty('seikiku_in_qty'), '製麹(白米消費)', null];
                $moves[] = [$mat('seikiku_out', ['koji']), $qty('seikiku_out_qty'), '製麹(麹生成)', null];
                if (($_POST['seikiku_seed'] ?? '') !== '' || trim((string)($_POST['seikiku_seed_qty'] ?? '')) !== '') {
                    $moves[] = [$mat('seikiku_seed', ['seed_koji']), -$qty('seikiku_seed_qty'), '製麹(種麹消費)', null];
                }
                break;
            case 'jouzou':
                foreach ($_POST['jouzou_material'] ?? [] as $i => $m) {
                    if ($m === '' && trim((string)($_POST['jouzou_qty'][$i] ?? '')) === '') continue;
                    $_POST['_m'] = $m; $_POST['_q'] = $_POST['jouzou_qty'][$i] ?? '';
                    $moves[] = [$mat('_m'), -$qty('_q'), '醸造仕込み(消費)', null];
                }
                if (!$moves) throw new InputError('使用した資材を1行以上入力してください。');
                break;
            case 'shiire':
                if (($_POST['supplier_id'] ?? '') !== '') {
                    $supplierId = (int)$_POST['supplier_id'];
                    $st = $pdo->prepare('SELECT 1 FROM suppliers WHERE id = ?');
                    $st->execute([$supplierId]);
                    if (!$st->fetchColumn()) throw new InputError('仕入先が見つかりません。');
                }
                foreach ($_POST['shiire_material'] ?? [] as $i => $m) {
                    $rawAmt = trim((string)($_POST['shiire_amount'][$i] ?? ''));
                    if ($m === '' && trim((string)($_POST['shiire_qty'][$i] ?? '')) === '' && $rawAmt === '') continue;
                    $_POST['_m'] = $m; $_POST['_q'] = $_POST['shiire_qty'][$i] ?? '';
                    if ($rawAmt !== '' && (!is_numeric($rawAmt) || (float)$rawAmt < 0)) throw new InputError('金額は0以上の数値で入力してください。');
                    $moves[] = [$mat('_m'), $qty('_q'), '仕入(入庫)', $rawAmt === '' ? null : (float)$rawAmt];
                }
                if (!$moves) throw new InputError('仕入れた資材を1行以上入力してください。');
                break;
            case 'chosei':
                $moves[] = [$mat('chosei_material'), $qty('chosei_qty', true), '手動調整', null];
                break;
        }

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO process_events (entry_date, process_type, lot_id, note) VALUES (?, ?, ?, ?)')
            ->execute([$date, $type, $lotId, $note]);
        $processId = (int)$pdo->lastInsertId();
        foreach ($moves as [$mid, $q, $reason, $amount]) {
            addMovement($pdo, $date, $mid, $q, $reason, $processId, $lotId, $amount, $supplierId, $note);
        }
        $pdo->commit();

        $noAmount = $type === 'shiire' && in_array(null, array_column($moves, 3), true);
        flash('工程を記録し、在庫を更新しました。');
        if ($noAmount) flash('金額が未入力の仕入があります。棚卸しの原価計算には金額が必要です(後から修正できます)。', 'error');
    } catch (InputError $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        flash('保存できませんでした。', 'error');
    }
    redirect('process_input.php');
}

$lots = $pdo->query('SELECT id, lot_code FROM brewing_lots ORDER BY id DESC')->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY sort_order, id')->fetchAll();
$all = materialsByKind($pdo);

$opts = function (array $list, string $placeholder = '選択') {
    $o = '<option value="">' . h($placeholder) . '</option>';
    foreach ($list as $m) {
        $o .= '<option value="' . (int)$m['id'] . '">' . h($m['name']) . (isset($m['unit']) ? '(' . h($m['unit']) . ')' : '') . '</option>';
    }
    return $o;
};

// 直近の工程
$events = $pdo->query('SELECT e.*, l.lot_code FROM process_events e
    LEFT JOIN brewing_lots l ON l.id = e.lot_id ORDER BY e.entry_date DESC, e.id DESC LIMIT 30')->fetchAll();
$movesByEvent = [];
if ($events) {
    $ids = implode(',', array_map('intval', array_column($events, 'id')));
    $q = $pdo->query("SELECT s.process_id, s.qty, s.amount_yen, m.name, m.unit FROM stock_movements s
        JOIN materials m ON m.id = s.material_id WHERE s.process_id IN ($ids) ORDER BY s.id");
    foreach ($q as $r) $movesByEvent[$r['process_id']][] = $r;
}
?>
<h1>工程入力(在庫自動増減)</h1>
<p class="muted">工程を選んで入力すると、資材の在庫が自動で増減します。資材の種別はマスタ管理で設定します。</p>

<?php if (!$all): ?>
<div class="panel"><p>資材が登録されていません。先に <a href="masters.php?t=materials">マスタ管理</a> で資材(種別つき)を登録してください。</p></div>
<?php endif; ?>

<div class="panel">
<form method="post" id="processForm" class="stack">
    <?= csrfField() ?>
    <label>日付</label>
    <input type="date" name="entry_date" value="<?= h(date('Y-m-d')) ?>" required>
    <label>工程</label>
    <select name="process_type" id="processType" required>
        <?php foreach ($types as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
    </select>
    <label>醸造ロット(任意)</label>
    <select name="lot_id">
        <option value="">指定なし</option>
        <?php foreach ($lots as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['lot_code']) ?></option><?php endforeach; ?>
    </select>

    <div data-type="seimai" class="sec">
        <label>使用する玄米</label><select name="seimai_in"><?= $opts(materialsByKind($pdo, ['brown_rice'])) ?></select>
        <label>使用量</label><input type="number" step="any" name="seimai_in_qty">
        <label>出来る白米</label><select name="seimai_out"><?= $opts(materialsByKind($pdo, ['polished_rice'])) ?></select>
        <label>白米の出来高(精米歩合の実績に使います)</label><input type="number" step="any" name="seimai_out_qty">
    </div>
    <div data-type="hatsuga" class="sec">
        <label>使用する玄米</label><select name="hatsuga_in"><?= $opts(materialsByKind($pdo, ['brown_rice'])) ?></select>
        <label>使用量</label><input type="number" step="any" name="hatsuga_in_qty">
        <label>出来る発芽玄米</label><select name="hatsuga_out"><?= $opts(materialsByKind($pdo, ['germinated_rice'])) ?></select>
        <label>出来高</label><input type="number" step="any" name="hatsuga_out_qty">
    </div>
    <div data-type="seikiku" class="sec">
        <label>使用する白米</label><select name="seikiku_in"><?= $opts(materialsByKind($pdo, ['polished_rice'])) ?></select>
        <label>使用量</label><input type="number" step="any" name="seikiku_in_qty">
        <label>出来る麹</label><select name="seikiku_out"><?= $opts(materialsByKind($pdo, ['koji'])) ?></select>
        <label>出来高</label><input type="number" step="any" name="seikiku_out_qty">
        <label>使用する種麹(任意)</label><select name="seikiku_seed"><?= $opts(materialsByKind($pdo, ['seed_koji']), '使わない') ?></select>
        <label>種麹の使用量</label><input type="number" step="any" name="seikiku_seed_qty">
    </div>
    <div data-type="jouzou" class="sec">
        <label>使用した資材(最大5行)</label>
        <?php for ($i = 0; $i < 5; $i++): ?>
        <p><select name="jouzou_material[]"><?= $opts($all) ?></select>
           <input type="number" step="any" name="jouzou_qty[]" placeholder="使用量" style="max-width:140px"></p>
        <?php endfor; ?>
    </div>
    <div data-type="shiire" class="sec">
        <label>仕入先(任意)</label>
        <select name="supplier_id"><option value="">指定なし</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
        <label>仕入れた資材(最大5行。瓶・ラベルもここで入庫)</label>
        <?php for ($i = 0; $i < 5; $i++): ?>
        <p><select name="shiire_material[]"><?= $opts($all) ?></select>
           <input type="number" step="any" name="shiire_qty[]" placeholder="数量" style="max-width:120px">
           <input type="number" step="any" min="0" name="shiire_amount[]" placeholder="金額(円)" style="max-width:140px"></p>
        <?php endfor; ?>
    </div>
    <div data-type="chosei" class="sec">
        <label>調整する資材</label><select name="chosei_material"><?= $opts($all) ?></select>
        <label>増減量(増やすなら +、減らすなら −)</label><input type="number" step="any" name="chosei_qty">
    </div>

    <label>メモ</label>
    <input type="text" name="note" maxlength="200">
    <p><button class="btn" type="submit">記録して在庫を更新</button></p>
</form>
</div>

<div class="panel">
    <h2>直近の工程(30件)</h2>
    <?php if (!$events): ?><p class="muted">まだ記録がありません。</p><?php else: ?>
    <table>
        <tr><th>日付</th><th>工程</th><th>ロット</th><th>在庫の増減</th><th></th></tr>
        <?php foreach ($events as $e): ?>
        <tr>
            <td><?= h($e['entry_date']) ?></td>
            <td><?= h($types[$e['process_type']] ?? $e['process_type']) ?></td>
            <td><?= h($e['lot_code'] ?? '') ?></td>
            <td>
                <?php foreach ($movesByEvent[$e['id']] ?? [] as $mv): ?>
                    <div><?= h($mv['name']) ?> <?= $mv['qty'] > 0 ? '+' : '' ?><?= h(fmtQty($mv['qty'])) ?><?= h($mv['unit']) ?><?= $mv['amount_yen'] !== null ? ' / ' . h(number_format($mv['amount_yen'])) . '円' : '' ?></div>
                <?php endforeach; ?>
                <?php if ($e['note']): ?><div class="muted"><?= h($e['note']) ?></div><?php endif; ?>
            </td>
            <td>
                <form method="post" class="inline" onsubmit="return confirm('この工程を削除し、在庫を元に戻しますか?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                    <button class="btn sub small" type="submit">削除</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<script>
(function () {
    var sel = document.getElementById('processType');
    function sync() {
        document.querySelectorAll('.sec').forEach(function (el) {
            el.style.display = el.getAttribute('data-type') === sel.value ? '' : 'none';
        });
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
