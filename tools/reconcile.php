<?php
declare(strict_types=1);

/**
 * 旧DBと新DBの突合（CLAUDE.md 7.4、IMPLEMENTATION.md 9.3）
 *   php tools/reconcile.php --old=旧DBのコピー --new=新DB [--golden=tests/golden/old_values.json] [--out=reconcile.md]
 *
 * 一致して当然（違えば終了コード 1）: 資材別の在庫数量、SKU×容量の在庫本数、分析件数、ロット件数、酒税の記録額
 * 差が出て当然（情報）: 資材別の棚卸し金額（旧: 先入先出 → 新: 最終仕入原価法）。単価が1種類しかないのに差がある資材には ⚠
 * 旧の金額は golden（旧コードを実際に動かした値）を使う。
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db\Connection;
use App\Domain\DateUtil;
use App\Domain\Num;
use App\Domain\Qty;
use App\Ops\LegacyImport;
use App\Service\ReportService;

$opt = getopt('', ['old:', 'new:', 'golden::', 'out::']);
if (!isset($opt['old'], $opt['new'])) {
    fwrite(STDERR, "使い方: php tools/reconcile.php --old=旧DB --new=新DB [--golden=JSON] [--out=MD]\n");
    exit(1);
}
$old = LegacyImport::openOld((string) $opt['old']);
$new = Connection::open((string) $opt['new']);
$decisions = require APP_ROOT . '/config/migration_decisions.php';
$remap = $decisions['capacity_remap'] ?? [];
$report = new ReportService($new);

$fail = 0;
$md = "# 突合の結果\n\n- 実行: " . date('Y-m-d H:i') . "\n- 旧DB: {$opt['old']}\n- 新DB: {$opt['new']}\n";
$mark = static function (bool $ok) use (&$fail): string {
    if (!$ok) {
        $fail++;
    }
    return $ok ? '✓' : '✗';
};

// 1. 資材ごとの在庫数量
$md .= "\n## 1. 資材ごとの在庫数量（一致して当然）\n\n| 資材 | 旧 | 新 | |\n|---|---:|---:|---|\n";
$newStock = $report->engine()->stockAt('9999-12-31');
$newIds = array_column($new->all('SELECT id, name, unit FROM materials'), null, 'name');
foreach ($old->query('SELECT m.name, m.unit, COALESCE(SUM(l.change_amount), 0) AS q FROM materials m LEFT JOIN inventory_ledger l ON l.material_id = m.id GROUP BY m.id ORDER BY m.id') as $r) {
    $o = (int) round((float) $r['q'] * 1000);
    $n = $newStock[(int) ($newIds[$r['name']]['id'] ?? 0)] ?? 0;
    $md .= "| {$r['name']} | " . Qty::format($o) . " {$r['unit']} | " . Qty::format($n) . " {$r['unit']} | {$mark($o === $n)} |\n";
}

// 2. SKU×容量の在庫本数（旧の端数容量は決定済みの置き換えを当ててから比べる）
$md .= "\n## 2. SKU×容量の在庫本数（一致して当然。690→500ml、590→720ml、70ml→試飲用 の置き換え後）\n\n| SKU | 容量 | 旧 | 新 | |\n|---|---:|---:|---:|---|\n";
$oldProd = [];
foreach ($old->query('SELECT s.code, CAST(c.label AS INTEGER) AS ml, SUM(b.bottle_count + b.returns - b.sales_count - b.tasting - b.disposal - b.loss) AS n
    FROM bottling_entries b JOIN skus s ON s.id = b.sku_id JOIN bottling_capacities c ON c.id = b.capacity_id GROUP BY s.code, ml') as $r) {
    $ml = (int) $r['ml'];
    if (isset($remap[$ml]['sample'])) {
        continue;
    }
    $ml = $remap[$ml]['to_ml'] ?? $ml;
    $oldProd["{$r['code']}|{$ml}"] = ($oldProd["{$r['code']}|{$ml}"] ?? 0) + (int) round((float) $r['n']);
}
$p = $report->products('9999-12-31');
$capMl = array_column($p['capacities'], 'ml', 'id');
$newProd = [];
foreach ($p['skus'] as $s) {
    foreach ($p['stock'][(int) $s['id']] ?? [] as $capId => $n) {
        $newProd["{$s['code']}|{$capMl[$capId]}"] = $n;
    }
}
$keys = array_unique(array_merge(array_keys($oldProd), array_keys($newProd)));
sort($keys);
foreach ($keys as $k) {
    [$code, $ml] = explode('|', $k);
    $o = $oldProd[$k] ?? 0;
    $n = $newProd[$k] ?? 0;
    $md .= "| {$code} | {$ml}ml | {$o} | {$n} | {$mark($o === $n)} |\n";
}

// 3. 件数・酒税
$oA = (int) $old->query('SELECT COUNT(*) FROM analysis_results')->fetchColumn();
$nA = (int) $new->value('SELECT COUNT(*) FROM analysis_results');
$oL = (int) $old->query('SELECT COUNT(*) FROM brewing_lots')->fetchColumn();
$nL = (int) $new->value('SELECT COUNT(*) FROM lots WHERE lot_code <> ?', [LegacyImport::PLACEHOLDER_LOT]);
$oT = (int) round((float) $old->query('SELECT COALESCE(SUM(tax_amount), 0) FROM brewing_lots')->fetchColumn());
$nT = (int) $new->value('SELECT COALESCE(SUM(declared_amount), 0) FROM tax_months');
$ph = (int) $new->value('SELECT COUNT(*) FROM analysis_results a JOIN lots l ON l.id = a.lot_id WHERE l.lot_code = ?', [LegacyImport::PLACEHOLDER_LOT]);
$md .= "\n## 3. 件数・酒税（一致して当然）\n\n| 項目 | 旧 | 新 | |\n|---|---:|---:|---|\n";
$md .= "| 分析結果の件数 | {$oA} | {$nA} | {$mark($oA === $nA)} |\n";
$md .= "| ロットの件数 | {$oL} | {$nL} | {$mark($oL === $nL)} |\n";
$md .= '| 酒税の記録額の合計 | ' . number_format($oT) . ' | ' . number_format($nT) . " | {$mark($oT === $nT)} |\n";
if ($ph > 0) {
    $md .= "\n※ 仮のロット（未解決）に置いた分析結果が {$ph} 件あります。\n";
}

// 4. 棚卸し金額（差が出て当然）
$golden = is_string($opt['golden'] ?? null) && is_file($opt['golden']) ? json_decode((string) file_get_contents($opt['golden']), true) : null;
$md .= "\n## 4. 資材ごとの棚卸し金額（差が出て当然: 旧=先入先出、新=最終仕入原価法）\n";
if ($golden === null) {
    $md .= "\n旧システムの値（golden）がないため省略しました。`tools/golden_from_old.php` で作ってください。\n";
} else {
    $dates = array_keys($golden['dates']);
    $fiscal = DateUtil::lastFiscalEnd(end($dates));
    $show = array_values(array_unique(array_filter([$fiscal, end($dates)], static fn ($d) => isset($golden['dates'][$d]))));
    foreach ($show as $date) {
        $v = $report->engine()->valuationAt($date);
        $md .= "\n### 基準日 {$date}\n\n| 資材 | 数量（旧/新） | 旧 金額 | 新 金額 | 差 | 新の単価の出どころ | |\n|---|---|---:|---:|---:|---|---|\n";
        $oSum = 0.0;
        $nSum = 0;
        foreach ($new->all('SELECT id, name, unit FROM materials ORDER BY id') as $m) {
            $g = $golden['dates'][$date]['materials'][$m['name']] ?? ['qty' => 0, 'value' => 0];
            $row = $v['rows'][(int) $m['id']];
            $ov = (int) round((float) $g['value']);
            $nv = $row['amount_yen'];
            if ($ov === 0 && $nv === 0 && (float) $g['qty'] == 0.0 && $row['qty_milli'] === 0) {
                continue;
            }
            $oSum += (float) $g['value'];
            $nSum += $nv;
            $diff = $nv - $ov;
            // 単価が複数回ある資材（仕入単価の違う仕入れ、または製造が2回以上）は、評価方法の違いで差が出て当然
            $priceCount = (int) $new->value("SELECT COUNT(DISTINCT pl.unit_price_micro) FROM process_lines pl JOIN v_process_effective p ON p.id = pl.process_id
                WHERE pl.material_id = ? AND p.type = 'purchase' AND pl.qty_milli > 0 AND pl.unit_price_micro IS NOT NULL AND p.effective_date <= ?", [$m['id'], $date])
                + (int) $new->value("SELECT COUNT(*) FROM process_lines pl JOIN v_process_effective p ON p.id = pl.process_id
                WHERE pl.material_id = ? AND p.type IN ('polish','germinate','koji') AND pl.qty_milli > 0 AND p.effective_date <= ?", [$m['id'], $date]);
            $flag = abs($diff) <= 1 ? '' : ($priceCount > 1 ? '想定内（単価が複数回）' : '⚠ 要確認');
            $md .= "| {$m['name']} | " . rtrim(rtrim(number_format((float) $g['qty'], 3, '.', ''), '0'), '.') . ' / ' . Qty::format($row['qty_milli']) . " {$m['unit']} | "
                . number_format($ov) . ' | ' . number_format($nv) . ' | ' . ($diff > 0 ? '+' : '') . number_format($diff)
                . " | {$row['source']} | {$flag} |\n";
        }
        $md .= '| **合計** | | **' . number_format((int) round($oSum)) . '** | **' . number_format($nSum) . '** | ' . number_format($nSum - (int) round($oSum)) . " | | |\n";
    }
    $md .= "\n単価の出どころ: purchase＝最終仕入単価、made＝最後に作った工程の按分単価、purchase_missing＝単価未入力の仕入れ、none＝単価なし。\n";
    $md .= "旧は在庫がマイナス（不足）の資材を「直近の単価で仮払い出し」しています。新はマイナス在庫を最終単価でマイナス評価するため、差が出ます。\n";
}

$md .= "\n## 結果\n\n" . ($fail === 0 ? "**一致して当然の項目は、すべて一致しました。**\n" : "**一致して当然の項目で {$fail} 件の違いがあります。移行をやり直してください。**\n");
$out = is_string($opt['out'] ?? null) ? $opt['out'] : null;
if ($out !== null) {
    file_put_contents($out, $md);
    fwrite(STDOUT, "突合の結果: {$out}\n");
} else {
    fwrite(STDOUT, $md);
}
exit($fail === 0 ? 0 : 1);
