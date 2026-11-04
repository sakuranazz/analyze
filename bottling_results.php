<?php
$pageTitle = '詰口台帳';
require_once __DIR__ . '/includes/header.php';

// 販売先の区分 → 報告区分。変えたいときはここを書き換える(旧システムと同じ対応)
const REPORT_WHOLESALER = ['tonya'];                      // 卸売業者
const REPORT_RETAILER   = ['saketen'];                    // 小売業者(酒販店)
const REPORT_CONSUMER   = ['gyoumuten', 'net', 'tenpo'];  // 小売販売(飲食店・ネット・店頭)
// 試飲・廃棄・返品は販売ではないので含めない。販売先が未設定または「その他」の販売は「区分なし」として別に表示する。

$typeLabels = ['bottling' => '詰口', 'sale' => '販売', 'tasting' => '試飲', 'disposal' => '廃棄', 'loss' => '遺失', 'return' => '返品'];
$inTypes = ['bottling', 'return'];

$sm = fiscalStartMonth($pdo);
$skuFilter = (int)($_GET['sku_id'] ?? 0);

$events = $pdo->query("SELECT e.*, s.code AS sku_code, s.name AS sku_name, c.label AS cap_label, c.ml,
        l.lot_code, ch.label AS channel, ch.category AS channel_cat
    FROM bottling_events e
    JOIN skus s ON s.id = e.sku_id JOIN capacities c ON c.id = e.capacity_id
    LEFT JOIN brewing_lots l ON l.id = e.lot_id LEFT JOIN sales_channels ch ON ch.id = e.channel_id
    ORDER BY e.entry_date, e.id")->fetchAll();

// 年度の選択肢
$years = [fiscalYearOf(date('Y-m-d'), $sm)];
foreach ($events as $e) $years[] = fiscalYearOf($e['entry_date'], $sm);
$years = range(min($years), max($years));
rsort($years);
$fy = (int)($_GET['fy'] ?? $years[0]);
if (!in_array($fy, $years, true)) $fy = $years[0];
[$fyStart, $fyEnd] = fiscalRange($fy, $sm);
$isCurrent = $fy === fiscalYearOf(date('Y-m-d'), $sm);

$zero = ['bottles' => 0.0, 'ml' => 0.0];
$sum = [];       // sku_id => 集計
$ledger = [];    // 年度内の台帳行(走行残高つき)
$running = [];   // "sku-cap" => 本数
$report = ['wholesaler' => $zero, 'retailer' => $zero, 'consumer' => $zero, 'unclassified' => $zero];

foreach ($events as $e) {
    $sid = (int)$e['sku_id'];
    $q = (float)$e['qty'];
    $ml = $q * (float)$e['ml'];
    $delta = in_array($e['event_type'], $inTypes, true) ? $q : -$q;
    $key = $sid . '-' . $e['capacity_id'];
    $running[$key] = ($running[$key] ?? 0) + $delta;

    $sum[$sid] ??= ['code' => $e['sku_code'], 'name' => $e['sku_name'],
        'carry' => $zero, 'bottled' => $zero, 'sale' => $zero, 'other_out' => $zero, 'end' => $zero];
    $d = ['bottles' => $delta, 'ml' => $delta * (float)$e['ml']];
    $add = function (array &$t, float $b, float $m) { $t['bottles'] += $b; $t['ml'] += $m; };

    if ($e['entry_date'] < $fyStart) $add($sum[$sid]['carry'], $d['bottles'], $d['ml']);
    if ($e['entry_date'] <= $fyEnd) $add($sum[$sid]['end'], $d['bottles'], $d['ml']);

    if ($e['entry_date'] >= $fyStart && $e['entry_date'] <= $fyEnd) {
        if ($e['event_type'] === 'bottling') $add($sum[$sid]['bottled'], $q, $ml);
        if ($e['event_type'] === 'sale') {
            $add($sum[$sid]['sale'], $q, $ml);
            $cat = (string)($e['channel_cat'] ?? '');
            $k = in_array($cat, REPORT_WHOLESALER, true) ? 'wholesaler'
               : (in_array($cat, REPORT_RETAILER, true) ? 'retailer'
               : (in_array($cat, REPORT_CONSUMER, true) ? 'consumer' : 'unclassified'));
            if (!$skuFilter || $skuFilter === $sid) $add($report[$k], $q, $ml);
        }
        if (in_array($e['event_type'], ['tasting', 'disposal', 'loss'], true)) $add($sum[$sid]['other_out'], $q, $ml);
        if (!$skuFilter || $skuFilter === $sid) $ledger[] = $e + ['balance' => $running[$key]];
    }
}
ksort($sum);
uasort($sum, fn($a, $b) => strcmp($a['code'], $b['code']));
$stockAll = $zero;
foreach ($sum as $sid => $r) {
    if ($skuFilter && $skuFilter !== $sid) continue;
    $stockAll['bottles'] += $r['end']['bottles'];
    $stockAll['ml'] += $r['end']['ml'];
}
$wholesale = ['bottles' => $report['wholesaler']['bottles'] + $report['retailer']['bottles'],
              'ml' => $report['wholesaler']['ml'] + $report['retailer']['ml']];
$ledger = array_reverse($ledger);

$litre = fn(array $q) => h(fmtQty(round($q['ml'] / 1000, 1))) . ' L<div class="muted">' . h(fmtQty($q['bottles'])) . '本</div>';
$skuList = $pdo->query('SELECT id, code, name FROM skus ORDER BY code')->fetchAll();
?>
<h1>詰口台帳</h1>
<div class="panel no-print">
    <form method="get" class="stack">
        <label>年度(<?= (int)$sm ?>月開始)</label>
        <select name="fy">
            <?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= h(fiscalYearLabel($y, $sm)) ?></option><?php endforeach; ?>
        </select>
        <label>SKU</label>
        <select name="sku_id">
            <option value="">すべて</option>
            <?php foreach ($skuList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $skuFilter === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['code'] . ($s['name'] ? ' ' . $s['name'] : '')) ?></option><?php endforeach; ?>
        </select>
        <p><button class="btn" type="submit">表示</button> <button class="btn sub" type="button" onclick="window.print()">印刷</button></p>
    </form>
</div>

<div class="panel">
    <h2><?= h(fiscalYearLabel($fy, $sm)) ?>の販売・在庫<?= $isCurrent ? '(進行中の年度)' : '' ?></h2>
    <table>
        <tr><th>卸売販売数量<div class="muted">卸売業者+小売業者</div></th><th>卸売業者</th><th>小売業者</th>
            <th>小売販売数量<div class="muted">飲食店・ネット・店頭</div></th><th>在庫数量<div class="muted"><?= $isCurrent ? '現在' : '期末' ?></div></th></tr>
        <tr>
            <td><?= $litre($wholesale) ?></td><td><?= $litre($report['wholesaler']) ?></td><td><?= $litre($report['retailer']) ?></td>
            <td><?= $litre($report['consumer']) ?></td><td><?= $litre($stockAll) ?></td>
        </tr>
    </table>
    <?php if ($report['unclassified']['bottles'] > 0): ?>
        <p class="muted">※ 販売先が未設定または「その他」の販売 <?= h(fmtQty($report['unclassified']['bottles'])) ?>本(<?= h(fmtQty(round($report['unclassified']['ml'] / 1000, 1))) ?>L)は、上の区分に含めていません。</p>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>年度別サマリ(SKU毎)</h2>
    <?php if (!$sum): ?><p class="muted">まだ記録がありません。<a href="bottling_input.php">詰口を入力</a>してください。</p><?php else: ?>
    <table>
        <tr><th>SKU</th><th>前年繰越</th><th>期間内の詰口</th><th>販売</th><th>試飲・廃棄・遺失</th><th>期末在庫</th></tr>
        <?php foreach ($sum as $sid => $r): if ($skuFilter && $skuFilter !== $sid) continue; ?>
        <tr>
            <td><?= h($r['code'] . ($r['name'] ? ' ' . $r['name'] : '')) ?></td>
            <td><?= $litre($r['carry']) ?></td><td><?= $litre($r['bottled']) ?></td><td><?= $litre($r['sale']) ?></td>
            <td><?= $litre($r['other_out']) ?></td><td><?= $litre($r['end']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>台帳(<?= count($ledger) ?>件・新しい順)</h2>
    <?php if (!$ledger): ?><p class="muted">この年度の記録はありません。</p><?php else: ?>
    <table>
        <tr><th>日付</th><th>種類</th><th>SKU</th><th>容量</th><th style="text-align:right">増減</th><th style="text-align:right">残(本)</th><th>ロット/販売先</th></tr>
        <?php foreach ($ledger as $e): $in = in_array($e['event_type'], $inTypes, true); ?>
        <tr>
            <td><?= h($e['entry_date']) ?></td>
            <td><?= h($typeLabels[$e['event_type']] ?? $e['event_type']) ?></td>
            <td><?= h($e['sku_code']) ?></td>
            <td><?= h($e['cap_label']) ?></td>
            <td style="text-align:right"><?= $in ? '+' : '−' ?><?= h(fmtQty($e['qty'])) ?></td>
            <td style="text-align:right;<?= $e['balance'] < 0 ? 'color:var(--neg)' : '' ?>"><?= h(fmtQty($e['balance'])) ?></td>
            <td><?= h($e['lot_code'] ?? $e['channel'] ?? '') ?><?php if ($e['note']): ?><div class="muted"><?= h($e['note']) ?></div><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
