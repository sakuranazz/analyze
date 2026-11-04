<?php
$pageTitle = 'SKU・ロット作番';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'generate_sku') {
            $liquor = $pdo->prepare('SELECT code FROM sku_liquor_codes WHERE id = ?');
            $liquor->execute([(int)($_POST['liquor_id'] ?? 0)]);
            $liquorCode = $liquor->fetchColumn();

            $cap = $pdo->prepare('SELECT value FROM sku_capacity_codes WHERE id = ?');
            $cap->execute([(int)($_POST['capacity_id'] ?? 0)]);
            $capValue = $cap->fetchColumn();

            $ingCode = null;
            if (($_POST['ingredient_id'] ?? '') !== '') {
                $ing = $pdo->prepare('SELECT code FROM ingredient_codes WHERE id = ?');
                $ing->execute([(int)$_POST['ingredient_id']]);
                $ingCode = $ing->fetchColumn();
                if ($ingCode === false) throw new RuntimeException('原料コードが正しくありません。');
            }
            if ($liquorCode === false || $capValue === false) throw new RuntimeException('酒類コードと容量コードを選択してください。');

            // 形式: 酒類[-原料]_容量  例: FR-ICH_720
            $code = $liquorCode . ($ingCode ? '-' . $ingCode : '') . '_' . $capValue;
            $name = trim((string)($_POST['name'] ?? '')) ?: null;
            $pdo->prepare('INSERT INTO skus (code, name) VALUES (?, ?)')->execute([$code, $name]);
            flash("SKU「{$code}」を作番しました。");

        } elseif ($action === 'generate_lot') {
            $liquor = $pdo->prepare('SELECT code FROM lot_liquor_codes WHERE id = ?');
            $liquor->execute([(int)($_POST['liquor_id'] ?? 0)]);
            $liquorCode = $liquor->fetchColumn();
            if ($liquorCode === false) throw new RuntimeException('酒類コードを選択してください。');

            $tankId = null; $tankCode = null;
            if (($_POST['tank_id'] ?? '') !== '') {
                $tankId = (int)$_POST['tank_id'];
                $tk = $pdo->prepare('SELECT code FROM tank_codes WHERE id = ?');
                $tk->execute([$tankId]);
                $tankCode = $tk->fetchColumn();
                if ($tankCode === false) throw new RuntimeException('タンクコードが正しくありません。');
            }

            $ym = trim((string)($_POST['year_month'] ?? ''));
            if (!preg_match('/^\d{2}(0[1-9]|1[0-2])$/', $ym)) throw new RuntimeException('年月は4桁(例: 2611 = 2026年11月)で入力してください。');

            // 回数: 空欄なら同じ 酒類+タンク+年月 の次の番号
            $seqRaw = trim((string)($_POST['sequence'] ?? ''));
            if ($seqRaw === '') {
                $q = $pdo->prepare('SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM brewing_lots
                    WHERE year_month = ? AND liquor_code_id = ? AND tank_code_id IS ?');
                $q->execute([$ym, (int)$_POST['liquor_id'], $tankId]);
                $seq = (int)$q->fetchColumn();
            } elseif (ctype_digit($seqRaw) && (int)$seqRaw >= 1 && (int)$seqRaw <= 99) {
                $seq = (int)$seqRaw;
            } else {
                throw new RuntimeException('回数は1〜99の数字で入力してください。');
            }

            // 形式: 酒類[_タンク]-年月_回数(2桁)  例: DU_L201-2608_01
            $lotCode = $liquorCode . ($tankCode ? '_' . $tankCode : '') . '-' . $ym . '_' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
            $note = trim((string)($_POST['note'] ?? '')) ?: null;
            $pdo->prepare('INSERT INTO brewing_lots (lot_code, liquor_code_id, tank_code_id, year_month, sequence_number, note)
                VALUES (?, ?, ?, ?, ?, ?)')->execute([$lotCode, (int)$_POST['liquor_id'], $tankId, $ym, $seq, $note]);
            flash("醸造ロット「{$lotCode}」を作番しました。");
        }
    } catch (PDOException $e) {   // PDOException は RuntimeException の子なので先に捕まえる
        if (stripos($e->getMessage(), 'UNIQUE') !== false) flash('同じコードがすでにあります。組み合わせまたは回数を変えてください。', 'error');
        else { error_log($e->getMessage()); flash('保存できませんでした。', 'error'); }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('codes.php');
}

$skuLiquor = $pdo->query('SELECT id, code, name FROM sku_liquor_codes ORDER BY sort_order, id')->fetchAll();
$ingredients = $pdo->query('SELECT id, code, name FROM ingredient_codes ORDER BY sort_order, id')->fetchAll();
$skuCaps = $pdo->query('SELECT id, value FROM sku_capacity_codes ORDER BY sort_order, id')->fetchAll();
$lotLiquor = $pdo->query('SELECT id, code, name FROM lot_liquor_codes ORDER BY sort_order, id')->fetchAll();
$tanks = $pdo->query('SELECT id, code, name FROM tank_codes ORDER BY sort_order, id')->fetchAll();
?>
<h1>SKU・醸造ロット 作番</h1>
<p class="muted">コードはマスタ管理で編集できます(SKU酒類・原料・容量、ロット酒類・タンク)。作番したものは <a href="sku_lot_list.php">一覧</a> で確認します。</p>

<div class="panel">
    <h2>SKUを作番する</h2>
    <p class="muted">形式: 酒類コード-原料コード_容量コード(例: FR-ICH_720)</p>
    <form method="post" class="stack" id="skuForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="generate_sku">
        <label>酒類コード *</label>
        <select name="liquor_id" required>
            <?php foreach ($skuLiquor as $r): ?><option value="<?= (int)$r['id'] ?>" data-code="<?= h($r['code']) ?>"><?= h($r['code']) ?>(<?= h($r['name']) ?>)</option><?php endforeach; ?>
        </select>
        <label>原料コード(任意)</label>
        <select name="ingredient_id">
            <option value="" data-code="">なし</option>
            <?php foreach ($ingredients as $r): ?><option value="<?= (int)$r['id'] ?>" data-code="<?= h($r['code']) ?>"><?= h($r['code']) ?>(<?= h($r['name']) ?>)</option><?php endforeach; ?>
        </select>
        <label>容量コード *</label>
        <select name="capacity_id" required>
            <?php foreach ($skuCaps as $r): ?><option value="<?= (int)$r['id'] ?>" data-code="<?= h($r['value']) ?>"><?= h($r['value']) ?></option><?php endforeach; ?>
        </select>
        <label>商品名(任意)</label>
        <input type="text" name="name" maxlength="100">
        <p>作番されるコード: <strong id="skuPreview">-</strong></p>
        <p><button class="btn" type="submit">SKUを作番</button></p>
    </form>
</div>

<div class="panel">
    <h2>醸造ロットを作番する</h2>
    <p class="muted">形式: 酒類コード_タンクコード-年月_回数(例: DU_L201-2608_01)。タンクなしなら 酒類コード-年月_回数(例: SK-2610_01)。</p>
    <form method="post" class="stack" id="lotForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="generate_lot">
        <label>酒類コード *</label>
        <select name="liquor_id" required>
            <?php foreach ($lotLiquor as $r): ?><option value="<?= (int)$r['id'] ?>" data-code="<?= h($r['code']) ?>"><?= h($r['code']) ?>(<?= h($r['name']) ?>)</option><?php endforeach; ?>
        </select>
        <label>タンクコード(任意)</label>
        <select name="tank_id">
            <option value="" data-code="">なし</option>
            <?php foreach ($tanks as $r): ?><option value="<?= (int)$r['id'] ?>" data-code="<?= h($r['code']) ?>"><?= h($r['code']) ?>(<?= h($r['name']) ?>)</option><?php endforeach; ?>
        </select>
        <label>年月 *(西暦下2桁+月。例: 2611)</label>
        <input type="text" name="year_month" value="<?= h(date('ym')) ?>" pattern="\d{4}" maxlength="4" inputmode="numeric" required>
        <label>回数(空欄なら自動で次の番号)</label>
        <input type="number" name="sequence" min="1" max="99">
        <label>メモ(任意)</label>
        <input type="text" name="note" maxlength="200">
        <p>作番されるコード: <strong id="lotPreview">-</strong> <span class="muted" id="lotSeqNote"></span></p>
        <p><button class="btn" type="submit">ロットを作番</button></p>
    </form>
</div>

<script>
(function () {
    function code(sel) { var o = sel.options[sel.selectedIndex]; return o ? (o.getAttribute('data-code') || '') : ''; }
    var sf = document.getElementById('skuForm');
    function skuUpdate() {
        var l = code(sf.liquor_id), i = code(sf.ingredient_id), c = code(sf.capacity_id);
        document.getElementById('skuPreview').textContent = (l && c) ? l + (i ? '-' + i : '') + '_' + c : '-';
    }
    var lf = document.getElementById('lotForm');
    function lotUpdate() {
        var l = code(lf.liquor_id), t = code(lf.tank_id), ym = lf.year_month.value, s = lf.sequence.value;
        var seq = s ? ('0' + s).slice(-2) : 'NN';
        document.getElementById('lotPreview').textContent = l ? l + (t ? '_' + t : '') + '-' + ym + '_' + seq : '-';
        document.getElementById('lotSeqNote').textContent = s ? '' : '(NN は保存時に自動採番)';
    }
    sf.addEventListener('change', skuUpdate); lf.addEventListener('input', lotUpdate); lf.addEventListener('change', lotUpdate);
    skuUpdate(); lotUpdate();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
