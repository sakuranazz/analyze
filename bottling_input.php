<?php
$pageTitle = '詰口・出荷入力';
require_once __DIR__ . '/includes/header.php';

class InputError extends Exception {}

$outTypes = ['sale' => '販売', 'tasting' => '試飲', 'disposal' => '廃棄', 'loss' => '遺失', 'return' => '返品(入庫扱い)'];
$typeLabels = ['bottling' => '詰口'] + $outTypes;

/** SKU×容量の現在庫(本数) */
function bottleStock(PDO $pdo, int $skuId, int $capId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN event_type IN ('bottling','return') THEN qty ELSE -qty END), 0)
        FROM bottling_events WHERE sku_id = ? AND capacity_id = ?");
    $st->execute([$skuId, $capId]);
    return (float)$st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'delete') {
            $batch = (int)($_POST['batch_id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM stock_movements WHERE bottling_event_id IN (SELECT id FROM bottling_events WHERE batch_id = ?)')->execute([$batch]);
            $pdo->prepare('DELETE FROM bottling_events WHERE batch_id = ?')->execute([$batch]);
            $pdo->commit();
            flash('削除しました(瓶・ラベルの在庫も元に戻しました)。');
            redirect('bottling_input.php');
        }

        $date = (string)($_POST['entry_date'] ?? '');
        if (!validDate($date)) throw new InputError('日付が正しくありません。');
        $note = trim((string)($_POST['note'] ?? '')) ?: null;

        $skuId = (int)($_POST['sku_id'] ?? 0);
        $st = $pdo->prepare('SELECT 1 FROM skus WHERE id = ?');
        $st->execute([$skuId]);
        if (!$st->fetchColumn()) throw new InputError('SKUを選択してください。');

        $caps = [];   // id => [label, ml]
        foreach ($pdo->query('SELECT id, label, ml FROM capacities') as $c) $caps[(int)$c['id']] = [$c['label'], (float)$c['ml']];

        $positive = function ($raw, string $name): float {
            $raw = trim((string)$raw);
            if ($raw === '' || !is_numeric($raw) || (float)$raw <= 0) throw new InputError($name . 'は0より大きい数値で入力してください。');
            return (float)$raw;
        };

        $warnings = [];

        if ($action === 'bottling') {
            $lotId = (int)($_POST['lot_id'] ?? 0);
            $st = $pdo->prepare('SELECT 1 FROM brewing_lots WHERE id = ?');
            $st->execute([$lotId]);
            if (!$st->fetchColumn()) throw new InputError('醸造ロットを選択してください(酒税の計算に使います)。');

            $totalMl = $positive($_POST['total_ml'] ?? '', '総容量(ml)');
            $items = []; $usedMl = 0.0;
            foreach ($_POST['cap_id'] ?? [] as $i => $cid) {
                $rawCnt = trim((string)($_POST['cap_count'][$i] ?? ''));
                if ($cid === '' && $rawCnt === '') continue;
                $cid = (int)$cid;
                if (!isset($caps[$cid])) throw new InputError('容量の選択が正しくありません。');
                $cnt = $positive($rawCnt, '本数');
                $items[] = [$cid, $cnt];
                $usedMl += $cnt * $caps[$cid][1];
            }
            if (!$items) throw new InputError('容量ごとの本数を1行以上入力してください。');
            if ($usedMl > $totalMl + 0.0001) {
                throw new InputError('容量×本数の合計(' . fmtQty($usedMl) . 'ml)が総容量(' . fmtQty($totalMl) . 'ml)を超えています。');
            }

            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO bottling_events (entry_date, event_type, lot_id, sku_id, capacity_id, qty, note)
                VALUES (?, 'bottling', ?, ?, ?, ?, ?)");
            $batch = null; $bottleTotal = 0.0; $missing = [];

            $bottleQ = $pdo->prepare("SELECT id FROM materials WHERE kind = 'bottle' AND is_active = 1 AND capacity_id = ? LIMIT 1");
            $labelQ = $pdo->prepare("SELECT id FROM materials WHERE kind = 'label' AND is_active = 1 AND sku_id = ? LIMIT 1");

            foreach ($items as [$cid, $cnt]) {
                $ins->execute([$date, $lotId, $skuId, $cid, $cnt, $note]);
                $eid = (int)$pdo->lastInsertId();
                if ($batch === null) { $batch = $eid; }
                $pdo->prepare('UPDATE bottling_events SET batch_id = ? WHERE id = ?')->execute([$batch, $eid]);
                $bottleTotal += $cnt;
                $bottleQ->execute([$cid]);
                $mid = $bottleQ->fetchColumn();
                if ($mid) addMovement($pdo, $date, (int)$mid, -$cnt, '詰口(瓶)', null, $lotId, null, null, null, $eid);
                else $missing[$caps[$cid][0] . 'の瓶'] = true;
            }
            $labelQ->execute([$skuId]);
            $labelMat = $labelQ->fetchColumn();
            if ($labelMat) addMovement($pdo, $date, (int)$labelMat, -$bottleTotal, '詰口(ラベル)', null, $lotId, null, null, null, $batch);
            else $missing['このSKUのラベル'] = true;

            // 残りは「試飲用」として、残りmlと同じ容量で1本登録(なければ容量を自動追加)
            $leftoverMsg = '';
            $leftover = round($totalMl - $usedMl, 2);
            if ($leftover > 0.0001) {
                $label = fmtQty($leftover) . 'ml';
                $q = $pdo->prepare('SELECT id FROM capacities WHERE ml = ?');
                $q->execute([$leftover]);
                $lcid = $q->fetchColumn();
                if (!$lcid) {
                    $pdo->prepare('INSERT INTO capacities (label, ml, sort_order) VALUES (?, ?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM capacities))')
                        ->execute([$label, $leftover]);
                    $lcid = (int)$pdo->lastInsertId();
                }
                $lnote = ($note ? $note . '／' : '') . '試飲用(残り' . $label . ')';
                $ins->execute([$date, $lotId, $skuId, (int)$lcid, 1, $lnote]);
                $leid = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE bottling_events SET batch_id = ? WHERE id = ?')->execute([$batch, $leid]);
                $leftoverMsg = '残り ' . $label . ' は試飲用として登録しました。';
                if (!empty($_POST['leftover_consume'])) {
                    $pdo->prepare("INSERT INTO bottling_events (entry_date, event_type, lot_id, sku_id, capacity_id, qty, note, batch_id)
                        VALUES (?, 'tasting', ?, ?, ?, 1, '試飲で消費', ?)")->execute([$date, $lotId, $skuId, (int)$lcid, $batch]);
                    $leftoverMsg .= '試飲で消費したものとして在庫から減らしました。';
                }
            }
            $pdo->commit();
            flash('詰口を登録しました。' . $leftoverMsg);
            if ($missing) flash(implode('、', array_keys($missing)) . 'の資材が未登録のため、その在庫は減らしていません(マスタ管理で資材に容量/SKUを設定してください)。', 'error');

        } elseif ($action === 'outbound') {
            $type = (string)($_POST['event_type'] ?? '');
            if (!isset($outTypes[$type])) throw new InputError('種類が正しくありません。');
            $cid = (int)($_POST['capacity_id'] ?? 0);
            if (!isset($caps[$cid])) throw new InputError('容量を選択してください。');
            $qty = $positive($_POST['qty'] ?? '', '本数');

            $channelId = null;
            if (($_POST['channel_id'] ?? '') !== '') {
                $channelId = (int)$_POST['channel_id'];
                $st = $pdo->prepare('SELECT 1 FROM sales_channels WHERE id = ?');
                $st->execute([$channelId]);
                if (!$st->fetchColumn()) throw new InputError('販売先が正しくありません。');
            }
            $address = trim((string)($_POST['address'] ?? '')) ?: null;
            if ($address === null && $channelId) {
                $st = $pdo->prepare('SELECT address FROM sales_channels WHERE id = ?');
                $st->execute([$channelId]);
                $address = $st->fetchColumn() ?: null;
            }

            $before = bottleStock($pdo, $skuId, $cid);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO bottling_events (entry_date, event_type, sku_id, capacity_id, qty, channel_id, address, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$date, $type, $skuId, $cid, $qty, $channelId, $address, $note]);
            $eid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE bottling_events SET batch_id = ? WHERE id = ?')->execute([$eid, $eid]);
            $pdo->commit();
            flash($outTypes[$type] . 'を登録しました。');
            if ($type !== 'return' && $qty > $before + 0.0001) {
                flash('在庫(' . fmtQty($before) . '本)を超えています。入力を確認してください。', 'error');
            }
        } else {
            throw new InputError('操作が正しくありません。');
        }
    } catch (InputError $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        flash('保存できませんでした。', 'error');
    }
    redirect('bottling_input.php');
}

$skus = $pdo->query('SELECT id, code, name FROM skus WHERE is_active = 1 ORDER BY code')->fetchAll();
$lots = $pdo->query('SELECT id, lot_code FROM brewing_lots ORDER BY year_month DESC, id DESC')->fetchAll();
$capList = $pdo->query('SELECT id, label FROM capacities ORDER BY sort_order, ml')->fetchAll();
$channels = $pdo->query('SELECT id, label FROM sales_channels ORDER BY sort_order, id')->fetchAll();

$stock = $pdo->query("SELECT s.code, s.name, c.label, c.ml,
        SUM(CASE WHEN e.event_type IN ('bottling','return') THEN e.qty ELSE -e.qty END) AS bal
    FROM bottling_events e JOIN skus s ON s.id = e.sku_id JOIN capacities c ON c.id = e.capacity_id
    GROUP BY e.sku_id, e.capacity_id HAVING ABS(bal) > 0.0001 ORDER BY s.code, c.ml DESC")->fetchAll();

$recent = $pdo->query("SELECT e.*, s.code AS sku_code, c.label AS cap_label, l.lot_code, ch.label AS channel
    FROM bottling_events e
    JOIN skus s ON s.id = e.sku_id JOIN capacities c ON c.id = e.capacity_id
    LEFT JOIN brewing_lots l ON l.id = e.lot_id LEFT JOIN sales_channels ch ON ch.id = e.channel_id
    ORDER BY e.entry_date DESC, e.id DESC LIMIT 60")->fetchAll();

$skuOpts = function () use ($skus) {
    $o = '<option value="">選択してください</option>';
    foreach ($skus as $s) $o .= '<option value="' . (int)$s['id'] . '">' . h($s['code'] . ($s['name'] ? ' ' . $s['name'] : '')) . '</option>';
    return $o;
};
$capOpts = function (string $ph = '容量') use ($capList) {
    $o = '<option value="">' . h($ph) . '</option>';
    foreach ($capList as $c) $o .= '<option value="' . (int)$c['id'] . '">' . h($c['label']) . '</option>';
    return $o;
};
?>
<h1>詰口・出荷入力</h1>

<?php if (!$skus || !$capList): ?>
<div class="panel"><p>先に <a href="codes.php">SKUを作番</a>し、<a href="masters.php?t=capacities">容量</a>を登録してください。</p></div>
<?php endif; ?>

<div class="panel">
    <h2>詰口(入庫)</h2>
    <p class="muted">総容量から容量ごとの本数を引いた残りは、試飲用として自動登録します。瓶(容量ごと)とラベル(SKUごと)の在庫も自動で減ります。</p>
    <form method="post" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bottling">
        <label>日付</label><input type="date" name="entry_date" value="<?= h(date('Y-m-d')) ?>" required>
        <label>SKU *</label><select name="sku_id" required><?= $skuOpts() ?></select>
        <label>醸造ロット *</label>
        <select name="lot_id" required>
            <option value="">選択してください</option>
            <?php foreach ($lots as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['lot_code']) ?></option><?php endforeach; ?>
        </select>
        <label>総容量(ml) *</label><input type="number" step="any" min="0" name="total_ml" required>
        <label>容量ごとの本数(最大6行)</label>
        <?php for ($i = 0; $i < 6; $i++): ?>
        <p><select name="cap_id[]"><?= $capOpts() ?></select>
           <input type="number" step="any" min="0" name="cap_count[]" placeholder="本数" style="max-width:120px"></p>
        <?php endfor; ?>
        <label><input type="checkbox" name="leftover_consume" value="1"> 残りの試飲用は試飲で使い切った(在庫から減らす)</label>
        <label>メモ</label><input type="text" name="note" maxlength="200">
        <p><button class="btn" type="submit">詰口を登録</button></p>
    </form>
</div>

<div class="panel">
    <h2>出庫(販売・試飲・廃棄・遺失・返品)</h2>
    <form method="post" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="outbound">
        <label>日付</label><input type="date" name="entry_date" value="<?= h(date('Y-m-d')) ?>" required>
        <label>種類</label>
        <select name="event_type" required>
            <?php foreach ($outTypes as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select>
        <label>SKU *</label><select name="sku_id" required><?= $skuOpts() ?></select>
        <label>容量 *</label><select name="capacity_id" required><?= $capOpts('選択してください') ?></select>
        <label>本数 *</label><input type="number" step="any" min="0" name="qty" required>
        <label>販売先(販売・返品のとき)</label>
        <select name="channel_id">
            <option value="">指定なし</option>
            <?php foreach ($channels as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['label']) ?></option><?php endforeach; ?>
        </select>
        <label>納品先住所(空欄なら販売先の住所)</label><input type="text" name="address" maxlength="200">
        <label>メモ</label><input type="text" name="note" maxlength="200">
        <p><button class="btn" type="submit">出庫を登録</button></p>
    </form>
</div>

<div class="panel">
    <h2>現在庫(SKU×容量)</h2>
    <?php if (!$stock): ?><p class="muted">在庫はありません。</p><?php else: ?>
    <table>
        <tr><th>SKU</th><th>容量</th><th style="text-align:right">本数</th><th style="text-align:right">ml換算</th></tr>
        <?php foreach ($stock as $r): ?>
        <tr><td><?= h($r['code'] . ($r['name'] ? ' ' . $r['name'] : '')) ?></td><td><?= h($r['label']) ?></td>
            <td style="text-align:right;<?= $r['bal'] < 0 ? 'color:var(--neg)' : '' ?>"><?= h(fmtQty($r['bal'])) ?></td>
            <td style="text-align:right"><?= h(number_format($r['bal'] * $r['ml'])) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>直近の入力(60件)</h2>
    <?php if (!$recent): ?><p class="muted">まだ記録がありません。</p><?php else: ?>
    <table>
        <tr><th>日付</th><th>種類</th><th>SKU</th><th>容量</th><th style="text-align:right">本数</th><th>ロット/販売先</th><th></th></tr>
        <?php foreach ($recent as $e): ?>
        <tr>
            <td><?= h($e['entry_date']) ?></td>
            <td><?= h($typeLabels[$e['event_type']] ?? $e['event_type']) ?></td>
            <td><?= h($e['sku_code']) ?></td>
            <td><?= h($e['cap_label']) ?></td>
            <td style="text-align:right"><?= h(fmtQty($e['qty'])) ?></td>
            <td><?= h($e['lot_code'] ?? $e['channel'] ?? '') ?><?php if ($e['note']): ?><div class="muted"><?= h($e['note']) ?></div><?php endif; ?></td>
            <td>
                <form method="post" class="inline" onsubmit="return confirm('この入力(同時に登録された行を含む)を削除しますか?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="batch_id" value="<?= (int)$e['batch_id'] ?>">
                    <button class="btn sub small" type="submit">削除</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
