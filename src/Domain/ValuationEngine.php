<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 最終仕入原価法による単価・棚卸し金額・ロット原価（CLAUDE.md 6.3、IMPLEMENTATION.md 6.2）。
 * DB を知らない純粋な計算。入力は全工程（効力日つき）と資材の情報。
 *
 * 規則の要点:
 * - 仕入れた資材の単価は「効力日 ≤ 基準日で (効力日, 工程id) が最大の仕入れ行の単価」。処理順に依存しない。
 * - 自家製（白米・発芽玄米・米麹）は製造工程を (効力日, 順位, 工程id) で並べて1回ずつ按分。
 *   製造工程の入力単価は「そこまでに計算済みの値」、それ以外の利用側は「その日の終わりの値」。
 * - 仕込み（brew）だけをロット原価に積む。詰口の自動消費（bottling）は積まない。
 * - 調整・棚卸し調整の単価は仕入単価を更新しない。仕入れが一度もない資材の評価にだけ使う。
 */
final class ValuationEngine
{
    /** @var array<int, list<array{date:string, id:int, price:?int}>> 資材 => 仕入れ（price は micro） */
    private array $purchases = [];
    /** @var array<int, list<array{date:string, id:int, price:int}>> 資材 => 調整で入った単価（micro） */
    private array $adjustPrices = [];
    /** @var array<int, list<array{date:string, rank:int, id:int, micro:int}>> 資材 => 製造の按分単価 */
    private array $made = [];
    /** @var list<array{process_id:int, message:string}> */
    private array $makeWarnings = [];

    /**
     * @param list<array{id:int, type:ProcessType, effective_date:string, lot_id:?int,
     *              lines:list<array{material_id:int, qty_milli:int, unit_price_micro:?int}>}> $processes
     * @param array<int, array{is_homemade:bool}> $materials
     */
    public function __construct(private array $processes, private array $materials)
    {
        usort($this->processes, static fn ($a, $b) => [$a['effective_date'], $a['id']] <=> [$b['effective_date'], $b['id']]);
        $this->indexPrices();
        $this->buildMadeCosts();
    }

    private function indexPrices(): void
    {
        foreach ($this->processes as $p) {
            foreach ($p['lines'] as $l) {
                if ($l['qty_milli'] <= 0) {
                    continue;
                }
                $m = $l['material_id'];
                if ($p['type'] === ProcessType::Purchase) {
                    $this->purchases[$m][] = ['date' => $p['effective_date'], 'id' => $p['id'], 'price' => $l['unit_price_micro']];
                } elseif (($p['type'] === ProcessType::Adjust || $p['type'] === ProcessType::Stocktake) && $l['unit_price_micro'] !== null) {
                    $this->adjustPrices[$m][] = ['date' => $p['effective_date'], 'id' => $p['id'], 'price' => $l['unit_price_micro']];
                }
            }
        }
    }

    private function buildMadeCosts(): void
    {
        $makes = array_values(array_filter($this->processes, static fn ($p) => $p['type']->isMake()));
        usort($makes, static fn ($a, $b) => [$a['effective_date'], $a['type']->makeRank(), $a['id']]
            <=> [$b['effective_date'], $b['type']->makeRank(), $b['id']]);
        foreach ($makes as $p) {
            $costMicro = 0;
            $producedMaterial = null;
            $producedQty = 0;
            foreach ($p['lines'] as $l) {
                if ($l['qty_milli'] < 0) {
                    // 計算済みの値だけで引く（同日の後の製造工程は含まない）
                    $price = $this->priceInfo($l['material_id'], $p['effective_date'])['micro'];
                    $costMicro += Num::amountMicro(-$l['qty_milli'], $price);
                } else {
                    $producedMaterial = $l['material_id'];
                    $producedQty += $l['qty_milli'];
                }
            }
            if ($producedMaterial === null || $producedQty <= 0) {
                $this->makeWarnings[] = ['process_id' => $p['id'], 'message' => 'できた量が0のため単価を計算できません'];
                continue;
            }
            // 按分単価（micro/単位）= 費用 micro × 1000 ÷ できた量 milli。麹の増量は費用を増やさない
            $unitMicro = Num::mulDiv($costMicro, 1000, $producedQty, Rounding::HalfUp);
            $this->made[$producedMaterial][] = [
                'date' => $p['effective_date'], 'rank' => $p['type']->makeRank(), 'id' => $p['id'], 'micro' => $unitMicro,
            ];
        }
    }

    /**
     * 基準日の単価（micro）と出どころ。
     * @return array{micro:int, source:string} source: made | purchase | purchase_missing | adjust | none
     */
    public function priceInfo(int $materialId, string $date): array
    {
        $made = null;
        if ($this->materials[$materialId]['is_homemade'] ?? false) {
            foreach ($this->made[$materialId] ?? [] as $e) {
                if ($e['date'] <= $date) {
                    $made = $e;
                }
            }
        }
        $last = null;
        foreach ($this->purchases[$materialId] ?? [] as $e) {
            if ($e['date'] <= $date && ($last === null || [$e['date'], $e['id']] >= [$last['date'], $last['id']])) {
                $last = $e;
            }
        }
        // 自家製の品目を仕入れることもある（白米・米麹）。製造と仕入れのうち「新しく入ってきた方」の単価にする
        if ($made !== null && ($last === null || [$made['date'], $made['id']] > [$last['date'], $last['id']])) {
            return ['micro' => $made['micro'], 'source' => 'made'];
        }
        if ($last !== null) {
            return $last['price'] === null
                ? ['micro' => 0, 'source' => 'purchase_missing']
                : ['micro' => $last['price'], 'source' => 'purchase'];
        }
        $last = null;
        foreach ($this->adjustPrices[$materialId] ?? [] as $e) {
            if ($e['date'] <= $date && ($last === null || [$e['date'], $e['id']] >= [$last['date'], $last['id']])) {
                $last = $e;
            }
        }
        if ($last !== null) {
            return ['micro' => $last['price'], 'source' => 'adjust'];
        }
        return ['micro' => 0, 'source' => 'none'];
    }

    /** @return array<int, int> 資材 => 基準日（効力日 ≤ 基準日）の在庫 qty_milli */
    public function stockAt(string $date): array
    {
        $stock = [];
        foreach ($this->processes as $p) {
            if ($p['effective_date'] > $date) {
                continue;
            }
            foreach ($p['lines'] as $l) {
                $stock[$l['material_id']] = ($stock[$l['material_id']] ?? 0) + $l['qty_milli'];
            }
        }
        return $stock;
    }

    /**
     * 棚卸し金額。資材ごとに円へ丸め、合計は丸めた値の和。
     * @return array{rows: array<int, array{material_id:int, qty_milli:int, price_micro:int, source:string, amount_yen:int, warnings:list<string>}>, total_yen:int}
     */
    public function valuationAt(string $date): array
    {
        $rows = [];
        $total = 0;
        $stock = $this->stockAt($date);
        foreach (array_keys($this->materials) as $m) {
            $qty = $stock[$m] ?? 0;
            $info = $this->priceInfo($m, $date);
            $amount = Num::roundYen(Num::amountMicro($qty, $info['micro']));
            $warnings = [];
            if ($qty < 0) {
                $warnings[] = 'マイナス在庫';
            }
            if ($qty !== 0 && ($info['source'] === 'purchase_missing' || $info['source'] === 'none')) {
                $warnings[] = '単価未入力';
            }
            $rows[$m] = [
                'material_id' => $m, 'qty_milli' => $qty, 'price_micro' => $info['micro'],
                'source' => $info['source'], 'amount_yen' => $amount, 'warnings' => $warnings,
            ];
            $total += $amount;
        }
        return ['rows' => $rows, 'total_yen' => $total];
    }

    /**
     * 仕込みで使った原料を、使った日の単価でロットに積む（micro のまま積み、ロット合計で円へ）。
     * @return array<int, array{cost_micro:int, cost_yen:int, used:array<int,int>, missing_price:bool}>
     */
    public function lotCosts(): array
    {
        $out = [];
        foreach ($this->processes as $p) {
            if ($p['type'] !== ProcessType::Brew || $p['lot_id'] === null) {
                continue;
            }
            $lot = $p['lot_id'];
            $out[$lot] ??= ['cost_micro' => 0, 'cost_yen' => 0, 'used' => [], 'missing_price' => false];
            foreach ($p['lines'] as $l) {
                if ($l['qty_milli'] >= 0) {
                    continue;
                }
                $used = -$l['qty_milli'];
                $info = $this->priceInfo($l['material_id'], $p['effective_date']);
                $out[$lot]['cost_micro'] += Num::amountMicro($used, $info['micro']);
                $out[$lot]['used'][$l['material_id']] = ($out[$lot]['used'][$l['material_id']] ?? 0) + $used;
                if ($info['source'] === 'purchase_missing' || $info['source'] === 'none') {
                    $out[$lot]['missing_price'] = true;
                }
            }
        }
        foreach ($out as $lot => $v) {
            $out[$lot]['cost_yen'] = Num::roundYen($v['cost_micro']);
        }
        return $out;
    }

    /** @return list<array{process_id:int, message:string}> */
    public function makeWarnings(): array
    {
        return $this->makeWarnings;
    }

    /** ロットの1ml原価（micro/ml）。全液量が未入力なら null */
    public static function perMlMicro(int $lotCostMicro, ?int $totalMl): ?int
    {
        if ($totalMl === null || $totalMl <= 0) {
            return null;
        }
        return Num::divRound($lotCostMicro, $totalMl, Rounding::HalfUp);
    }

    /**
     * 1本あたり原価（円）= 1ml原価 × 容量 ＋ 瓶 ＋ ラベル × 枚数
     * @param list<array{price_micro:int, qty_per_bottle_milli:int}> $perBottleMaterials 瓶・ラベル
     */
    public static function perBottleYen(int $perMlMicro, int $ml, array $perBottleMaterials): int
    {
        $micro = Num::mul($perMlMicro, $ml);
        foreach ($perBottleMaterials as $m) {
            $micro += Num::amountMicro($m['qty_per_bottle_milli'], $m['price_micro']);
        }
        return Num::roundYen($micro);
    }
}
