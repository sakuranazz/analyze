<?php
// 酒税の計算。酒税 = 詰口量(kL) × 税率(円/kL)。
// 同じ年月のロットの詰口量を合計してから、税額 → 軽減税率 → 100円未満切り捨て の順に計算する。
const TAX_PAY_UNIT = 100;   // 納付税額の端数処理: 100円未満切り捨て

function taxRate(PDO $pdo): int {
    $v = getSetting($pdo, 'liquor_tax_rate_per_kl');
    return ($v !== null && is_numeric($v) && (float)$v > 0) ? (int)$v : 100000;
}

function taxReductionPct(PDO $pdo): float {
    $v = getSetting($pdo, 'liquor_tax_reduction_percent');
    return ($v !== null && is_numeric($v) && (float)$v > 0 && (float)$v <= 100) ? (float)$v : 80.0;
}

/** 軽減前の税額(1円未満切り捨て)。ml → kL は 1,000,000 で割る */
function calcTaxFromMl(float $ml, int $rate): int {
    return (int)floor(round($ml * $rate / 1000000, 4));
}

/** 軽減税率を掛けた納付予定額(100円未満切り捨て) */
function calcReducedTaxFromMl(float $ml, int $rate, float $pct): int {
    $reduced = round($ml * $rate / 1000000 * $pct / 100, 4);
    return (int)(floor($reduced / TAX_PAY_UNIT) * TAX_PAY_UNIT);
}

/** 納付期限: 年月(YYMM)の翌々月末。例 2609 → 2026-11-30。解釈できなければ null */
function taxDueDate(string $yearMonth): ?string {
    if (!preg_match('/^(\d{2})(0[1-9]|1[0-2])$/', $yearMonth, $m)) return null;
    $d = new DateTime(sprintf('%04d-%02d-01', 2000 + (int)$m[1], (int)$m[2]));
    return $d->modify('+2 months')->modify('last day of this month')->format('Y-m-d');
}

function yearMonthLabel(string $ym): string {
    return preg_match('/^(\d{2})(\d{2})$/', $ym, $m) ? (2000 + (int)$m[1]) . '年' . (int)$m[2] . '月' : $ym;
}

/** 月別の酒税一覧(新しい月が先頭)。各月に所属ロットと納付記録を含む */
function monthTaxRows(PDO $pdo): array {
    $rate = taxRate($pdo);
    $pct = taxReductionPct($pdo);

    $lots = $pdo->query("SELECT l.lot_code, l.year_month, SUM(e.qty) AS bottles, SUM(e.qty * c.ml) AS ml
        FROM brewing_lots l
        JOIN bottling_events e ON e.lot_id = l.id AND e.event_type = 'bottling'
        JOIN capacities c ON c.id = e.capacity_id
        GROUP BY l.id ORDER BY l.lot_code")->fetchAll();

    $months = [];
    foreach ($lots as $l) {
        $k = $l['year_month'];
        $months[$k] ??= ['year_month' => $k, 'lots' => [], 'bottles' => 0.0, 'ml' => 0.0];
        $months[$k]['lots'][] = ['lot_code' => $l['lot_code'], 'bottles' => (float)$l['bottles'], 'ml' => (float)$l['ml']];
        $months[$k]['bottles'] += (float)$l['bottles'];
        $months[$k]['ml'] += (float)$l['ml'];
    }

    $paid = [];
    foreach ($pdo->query('SELECT * FROM tax_payments') as $r) $paid[$r['year_month']] = $r;

    krsort($months, SORT_STRING);
    $today = date('Y-m-d');
    foreach ($months as $k => &$m) {
        $m['label'] = yearMonthLabel($k);
        $m['calc_tax'] = calcTaxFromMl($m['ml'], $rate);
        $m['due_amount'] = calcReducedTaxFromMl($m['ml'], $rate, $pct);
        $m['due_date'] = taxDueDate($k);
        $p = $paid[$k] ?? null;
        $m['paid'] = $p && $p['paid'] ? 1 : 0;
        $m['paid_amount'] = $p['paid_amount'] ?? null;
        $m['paid_date'] = $p['paid_date'] ?? null;
        $m['overdue'] = !$m['paid'] && $m['due_date'] !== null && $m['due_date'] < $today && $m['due_amount'] > 0;
    }
    unset($m);
    return array_values($months);
}
