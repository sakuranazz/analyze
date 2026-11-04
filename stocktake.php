<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/valuation.php';

// 対象月(YYYY-MM)。月末時点で評価する
$ym = (string)($_GET['ym'] ?? $_POST['ym'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) $ym = date('Y-m');
$monthEnd = (new DateTime($ym . '-01'))->modify('last day of this month')->format('Y-m-d');

// CSV(税理士向け)は画面を出す前に返す
if (($_GET['csv'] ?? '') === '1') {
    requireLogin($pdo);
    $rows = valuationAt($pdo, $monthEnd, $ym, loadCostModel($pdo));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stocktake_' . str_replace('-', '', $ym) . '.csv"');
    header('X-Robots-Tag: noindex, nofollow');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['基準日', 'カテゴリ', '資材', '単位', '帳簿数量', '実数', '採用数量', '単価(円)', '金額(円)']);
    $total = 0;
    foreach ($rows as $r) {
        if (abs($r['use_qty']) < 0.0005 && $r['counted'] === null) continue;
        fputcsv($out, [$monthEnd, $r['category'], $r['name'], $r['unit'], fmtQty($r['book_qty']),
            $r['counted'] === null ? '' : fmtQty($r['counted']), fmtQty($r['use_qty']),
            $r['unit_cost'] === null ? '' : round($r['unit_cost'], 4), $r['value']]);
        $total += $r['value'];
    }
    fputcsv($out, [$monthEnd, '', '合計', '', '', '', '', '', $total]);
    fclose($out);
    exit;
}

$pageTitle = '在庫・棚卸し';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $back = 'stocktake.php?ym=' . urlencode($ym);
    try {
        if ($action === 'save_counts') {
            $n = 0;
            $pdo->beginTransaction();
            $up = $pdo->prepare('INSERT INTO stocktakes (year_month, material_id, counted_qty) VALUES (?, ?, ?)
                ON CONFLICT(year_month, material_id) DO UPDATE SET counted_qty = excluded.counted_qty');
            $del = $pdo->prepare('DELETE FROM stocktakes WHERE year_month = ? AND material_id = ?');
            foreach ($_POST['counted'] ?? [] as $mid => $raw) {
                $raw = trim((string)$raw);
                if ($raw === '') { $del->execute([$ym, (int)$mid]); continue; }
                if (!is_numeric($raw)) throw new RuntimeException('実数は数値で入力してください。');
                $up->execute([$ym, (int)$mid, (float)$raw]);
                $n++;
            }
            $pdo->commit();
            flash("実数を保存しました({$n}件)。");
        } elseif ($action === 'apply_adjust') {
            $rows = valuationAt($pdo, $monthEnd, $ym, loadCostModel($pdo));
            $n = 0;
            $pdo->beginTransaction();
            foreach ($rows as $r) {
                if ($r['counted'] !== null && abs($r['diff']) > 0.0005) {
                    addMovement($pdo, $monthEnd, (int)$r['id'], $r['diff'], '棚卸調整', null, null, null, null, $ym . ' の棚卸し');
                    $n++;
                }
            }
            $pdo->commit();
            flash($n ? "{$n}件の差を帳簿に調整として反映しました。" : '反映する差はありません。');
        } elseif ($action === 'update_amount') {
            $id = (int)($_POST['id'] ?? 0);
            $raw = trim((string)($_POST['amount'] ?? ''));
            if ($raw !== '' && (!is_numeric($raw) || (float)$raw < 0)) throw new RuntimeException('金額は0以上の数値で入力してください。');
            $st = $pdo->prepare("UPDATE stock_movements SET amount_yen = ? WHERE id = ? AND reason = '仕入(入庫)'");
            $st->execute([$raw === '' ? null : (float)$raw, $id]);
            flash('仕入金額を更新しました。単価と棚卸し金額に反映されます。');
            $back .= '&m=' . (int)($_POST['m'] ?? 0);
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        flash('保存できませんでした。', 'error');
    }
    redirect($back);
}

$model = loadCostModel($pdo);
$rows = valuationAt($pdo, $monthEnd, $ym, $model);

$total = 0; $byCat = []; $warnNoUnit = []; $hasDiff = false;
foreach ($rows as $r) {
    $total += $r['value'];
    $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + $r['value'];
    if ($r['no_unit']) $warnNoUnit[] = $r['name'];
    if ($r['counted'] !== null && abs($r['diff']) > 0.0005) $hasDiff = true;
}

// 直近の決算月(設定 closing_month、既定8月)
$cm = (int)getSetting($pdo, 'closing_month', 8);
$closings = [];
$y = (int)date('Y');
for ($i = 0; $i < 4; $i++) {
    $cand = sprintf('%04d-%02d', $y - $i, $cm);
    if ($cand <= date('Y-m')) $closings[] = $cand;
}

$detailId = (int)($_GET['m'] ?? 0);
$detail = []; $detailMat = null;
if ($detailId > 0) {
    $d = $pdo->prepare('SELECT s.*, l.lot_code, sp.name AS supplier FROM stock_movements s
        LEFT JOIN brewing_lots l ON l.id = s.lot_id LEFT JOIN suppliers sp ON sp.id = s.supplier_id
        WHERE s.material_id = ? AND s.entry_date <= ? ORDER BY s.entry_date DESC, s.id DESC LIMIT 200');
    $d->execute([$detailId, $monthEnd]);
    $detail = $d->fetchAll();
    $n = $pdo->prepare('SELECT name, unit FROM materials WHERE id = ?');
    $n->execute([$detailId]);
    $detailMat = $n->fetch() ?: ['name' => '', 'unit' => ''];
}
?>
<h1>在庫・棚卸し</h1>
<p class="muted">月末時点の在庫を、最終仕入原価法で評価します(単価 = その時点の最後の仕入単価。白米・麹は精米・製麹の実績から算出)。目視で数えた実数を入れると、実数で評価します。</p>

<div class="panel no-print">
    <form method="get" class="stack">
        <label>対象月(月末で評価)</label>
        <input type="month" name="ym" value="<?= h($ym) ?>" required>
        <p>
            <button class="btn" type="submit">表示</button>
            <?php foreach ($closings as $c): ?><a class="btn sub small" href="stocktake.php?ym=<?= h($c) ?>"><?= h(str_replace('-', '年', $c)) ?>月(決算)</a> <?php endforeach; ?>
        </p>
    </form>
</div>

<div class="panel">
    <h2><?= h($monthEnd) ?> 時点の棚卸し
        <span class="no-print">
            <a class="btn sub small" href="stocktake.php?ym=<?= h($ym) ?>&csv=1">CSV</a>
            <button class="btn sub small" type="button" onclick="window.print()">印刷</button>
        </span>
    </h2>
    <?php if (!$rows): ?>
        <p class="muted">資材がありません。<a href="masters.php?t=materials">マスタ管理</a>から登録してください。</p>
    <?php else: ?>
    <p>評価額の合計: <strong style="font-size:1.2rem"><?= h(number_format($total)) ?>円</strong>
        <?php foreach ($byCat as $cat => $v): ?><span class="muted"> / <?= h($cat) ?> <?= h(number_format($v)) ?>円</span><?php endforeach; ?></p>
    <?php if ($warnNoUnit): ?>
        <div class="flash error">単価が決まらない在庫があります(評価額0円): <?= h(implode('、', $warnNoUnit)) ?>。仕入の金額が未入力の可能性があります。「履歴」から金額を入れてください。</div>
    <?php endif; ?>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="ym" value="<?= h($ym) ?>">
        <table>
            <tr><th>カテゴリ</th><th>資材</th><th style="text-align:right">帳簿数量</th><th class="no-print">実数(目視)</th>
                <th style="text-align:right">差</th><th style="text-align:right">単価</th><th style="text-align:right">金額</th><th class="no-print"></th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= h($r['category']) ?></td>
                <td><?= h($r['name']) ?></td>
                <td style="text-align:right;<?= $r['negative'] ? 'color:var(--neg)' : '' ?>"><?= h(fmtQty($r['book_qty'])) ?> <?= h($r['unit']) ?></td>
                <td class="no-print"><input type="number" step="any" name="counted[<?= (int)$r['id'] ?>]" value="<?= $r['counted'] === null ? '' : h(fmtQty($r['counted'])) ?>" style="max-width:120px"></td>
                <td style="text-align:right"><?= $r['diff'] === null ? '' : h(($r['diff'] > 0 ? '+' : '') . fmtQty($r['diff'])) ?></td>
                <td style="text-align:right"><?= $r['unit_cost'] === null ? '<span class="muted">未設定</span>' : h(number_format($r['unit_cost'], 2)) . '円' ?></td>
                <td style="text-align:right"><?= h(number_format($r['value'])) ?>円</td>
                <td class="no-print"><a class="btn sub small" href="stocktake.php?ym=<?= h($ym) ?>&m=<?= (int)$r['id'] ?>">履歴</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p class="no-print">
            <button class="btn" type="submit" name="action" value="save_counts">実数を保存</button>
            <?php if ($hasDiff): ?>
            <button class="btn sub" type="submit" name="action" value="apply_adjust" onclick="return confirm('実数との差を、月末日付の調整として帳簿に反映します。よろしいですか?');">実数との差を帳簿に反映</button>
            <?php endif; ?>
        </p>
    </form>
    <?php endif; ?>
</div>

<?php if ($detailMat): ?>
<div class="panel">
    <h2><?= h($detailMat['name']) ?> の入出庫履歴(最新200件)</h2>
    <?php if (!$detail): ?><p class="muted">履歴がありません。</p><?php else: ?>
    <table>
        <tr><th>日付</th><th>内容</th><th style="text-align:right">増減</th><th>金額(円)</th><th>ロット</th><th>仕入先</th></tr>
        <?php foreach ($detail as $r): ?>
        <tr>
            <td><?= h($r['entry_date']) ?></td>
            <td><?= h($r['reason']) ?></td>
            <td style="text-align:right"><?= $r['qty'] > 0 ? '+' : '' ?><?= h(fmtQty($r['qty'])) ?> <?= h($detailMat['unit']) ?></td>
            <td>
                <?php if ($r['reason'] === '仕入(入庫)'): ?>
                <form method="post" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_amount">
                    <input type="hidden" name="ym" value="<?= h($ym) ?>">
                    <input type="hidden" name="m" value="<?= (int)$detailId ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="number" step="any" min="0" name="amount" value="<?= $r['amount_yen'] === null ? '' : h(fmtQty($r['amount_yen'])) ?>" placeholder="未入力" style="max-width:130px">
                    <button class="btn sub small" type="submit">修正</button>
                </form>
                <?php endif; ?>
            </td>
            <td><?= h($r['lot_code'] ?? '') ?></td>
            <td><?= h($r['supplier'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
