<?php
// 原価の計算(最終仕入原価法)。台帳(stock_movements)を日付順にたどって、資材ごとの「その時点の単価」を決める。
//  - 仕入(金額つき)      : 単価 = 金額 ÷ 数量                  (最後の仕入単価で上書き)
//  - 精米・発芽・製麹      : 出来た資材の単価 = 使った資材の原価合計 ÷ 出来高
//                          (白米 = 玄米の原価 ÷ 白米の出来高、麹 = 白米の原価 + 種麹の費用 ÷ 麹の出来高)
//  - 消費(醸造・詰口など)  : その時点の単価で原価を算出
// 台帳や仕入金額を直すと、次に開いたときに全体が再計算される。

function loadCostModel(PDO $pdo): array {
    $rows = $pdo->query("SELECT s.id, s.entry_date, s.material_id, s.qty, s.reason, s.amount_yen,
            s.process_id, s.lot_id, s.bottling_event_id, p.process_type
        FROM stock_movements s LEFT JOIN process_events p ON p.id = s.process_id
        ORDER BY s.entry_date, s.id")->fetchAll();

    $byProcess = [];
    foreach ($rows as $r) if ($r['process_id']) $byProcess[$r['process_id']][] = $r;

    $unit = [];      // material_id => 現在の単価(null=不明)
    $hist = [];      // material_id => [[日付, 台帳id, 単価], ...] 時系列
    $consume = [];   // 台帳id(消費行) => 消費時の単価(null=不明)
    $setUnit = function (int $mid, string $date, int $id, ?float $u) use (&$unit, &$hist) {
        $unit[$mid] = $u;
        $hist[$mid][] = [$date, $id, $u];
    };

    $done = [];
    foreach ($rows as $r) {
        $mid = (int)$r['material_id'];
        $pid = $r['process_id'];

        if ($pid && in_array($r['process_type'], ['seimai', 'hatsuga', 'seikiku'], true)) {
            if (isset($done[$pid])) continue;          // 工程は最初の行で全体をまとめて処理
            $done[$pid] = true;
            $cost = 0.0;
            foreach ($byProcess[$pid] as $m) {
                if ($m['qty'] < 0) {
                    $u = $unit[(int)$m['material_id']] ?? null;
                    $consume[$m['id']] = $u;
                    $cost += -$m['qty'] * ($u ?? 0.0);
                }
            }
            foreach ($byProcess[$pid] as $m) {
                if ($m['qty'] > 0) $setUnit((int)$m['material_id'], $m['entry_date'], (int)$m['id'], $cost > 0 ? $cost / $m['qty'] : null);
            }
            continue;
        }

        if ($r['qty'] > 0) {
            if ($r['reason'] === '仕入(入庫)' && $r['amount_yen'] !== null) {
                $setUnit($mid, $r['entry_date'], (int)$r['id'], (float)$r['amount_yen'] / (float)$r['qty']);
            }
        } elseif ($r['qty'] < 0) {
            $consume[$r['id']] = $unit[$mid] ?? null;
        }
    }
    return ['hist' => $hist, 'consume' => $consume, 'rows' => $rows];
}

/** 指定日(含む)時点の最終単価。まだ単価が決まらなければ null */
function unitCostAt(array $model, int $materialId, string $date): ?float {
    foreach (array_reverse($model['hist'][$materialId] ?? []) as [$d, , $u]) {
        if ($d <= $date) return $u;
    }
    return null;
}

/** 月末(または指定日)時点の棚卸し評価。$ym は 'YYYY-MM'(実数の読み込みに使う) */
function valuationAt(PDO $pdo, string $asOf, string $ym, array $model): array {
    $st = $pdo->prepare('SELECT m.id, m.name, m.unit, m.kind, c.name AS category,
            COALESCE(SUM(s.qty), 0) AS book_qty
        FROM materials m
        JOIN material_categories c ON c.id = m.category_id
        LEFT JOIN stock_movements s ON s.material_id = m.id AND s.entry_date <= ?
        WHERE m.is_active = 1 OR EXISTS (SELECT 1 FROM stock_movements x WHERE x.material_id = m.id)
        GROUP BY m.id ORDER BY c.sort_order, c.id, m.id');
    $st->execute([$asOf]);
    $rows = $st->fetchAll();

    $counts = [];
    $cs = $pdo->prepare('SELECT material_id, counted_qty FROM stocktakes WHERE year_month = ?');
    $cs->execute([$ym]);
    foreach ($cs as $r) $counts[(int)$r['material_id']] = (float)$r['counted_qty'];

    foreach ($rows as &$r) {
        $mid = (int)$r['id'];
        $r['book_qty'] = round((float)$r['book_qty'], 6);
        $r['counted'] = $counts[$mid] ?? null;
        $r['use_qty'] = $r['counted'] ?? $r['book_qty'];           // 実数があれば実数を採用
        $r['diff'] = $r['counted'] === null ? null : round($r['counted'] - $r['book_qty'], 6);
        $r['unit_cost'] = unitCostAt($model, $mid, $asOf);
        $r['value'] = ($r['unit_cost'] !== null && $r['use_qty'] > 0) ? (int)round($r['use_qty'] * $r['unit_cost']) : 0;
        $r['no_unit'] = $r['unit_cost'] === null && $r['use_qty'] > 0;   // 単価が決まらない在庫
        $r['negative'] = $r['use_qty'] < -0.0005;
    }
    unset($r);
    return $rows;
}

/** ロット別の原価。醸造仕込みの消費 + 詰口で減った資材(瓶・ラベル・使用量換算)を、そのロットの詰口総量で割る */
function lotCostRows(PDO $pdo, array $model): array {
    $lots = $pdo->query("SELECT l.id, l.lot_code, l.year_month,
            COALESCE((SELECT SUM(e.qty * c.ml) FROM bottling_events e JOIN capacities c ON c.id = e.capacity_id
                      WHERE e.lot_id = l.id AND e.event_type = 'bottling'), 0) AS ml,
            COALESCE((SELECT SUM(e.qty) FROM bottling_events e WHERE e.lot_id = l.id AND e.event_type = 'bottling'), 0) AS bottles
        FROM brewing_lots l ORDER BY l.year_month DESC, l.id DESC")->fetchAll();

    $mats = [];
    foreach ($pdo->query('SELECT id, name, kind FROM materials') as $m) $mats[(int)$m['id']] = $m;

    $acc = [];   // lot_id => [raw, pack, unknown[]]
    foreach ($model['rows'] as $r) {
        if (!$r['lot_id'] || $r['qty'] >= 0) continue;
        // 精米・発芽・製麹の途中消費は、麹や白米の原価に含まれるので数えない(二重計上の防止)
        $isFinal = $r['bottling_event_id'] !== null || $r['process_type'] === 'jouzou';
        if (!$isFinal) continue;
        $lid = (int)$r['lot_id'];
        $mid = (int)$r['material_id'];
        $u = $model['consume'][$r['id']] ?? null;
        $acc[$lid] ??= ['raw' => 0.0, 'pack' => 0.0, 'unknown' => []];
        if ($u === null) { $acc[$lid]['unknown'][$mats[$mid]['name'] ?? '?'] = true; continue; }
        $cost = -$r['qty'] * $u;
        $isPack = in_array($mats[$mid]['kind'] ?? '', ['bottle', 'label'], true);
        $acc[$lid][$isPack ? 'pack' : 'raw'] += $cost;
    }

    foreach ($lots as &$l) {
        $a = $acc[(int)$l['id']] ?? ['raw' => 0.0, 'pack' => 0.0, 'unknown' => []];
        $l['raw'] = (int)round($a['raw']);
        $l['pack'] = (int)round($a['pack']);
        $l['total'] = $l['raw'] + $l['pack'];
        $l['unknown'] = array_keys($a['unknown']);
        $l['per_ml'] = $l['ml'] > 0 ? $l['total'] / $l['ml'] : null;
        $l['has_cost'] = $l['total'] > 0 || $l['unknown'];
    }
    unset($l);
    return $lots;
}
