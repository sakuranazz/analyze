<?php
declare(strict_types=1);

namespace App\Service;

use App\Data\Bottling;
use App\Data\Lots;
use App\Data\Masters;
use App\Data\Processes;
use App\Db\Connection;
use App\Domain\Num;
use App\Domain\StockCalculator;
use App\Domain\ValuationEngine;

/**
 * 帳簿系の画面・CSV・印刷・tools/check.php が共通で使う読み取り（計算は Domain）。
 * 1リクエストの中では ValuationEngine を1回だけ作る。
 */
final class ReportService
{
    private ?ValuationEngine $engine = null;

    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    public function engine(): ValuationEngine
    {
        return $this->engine ??= new ValuationEngine(
            (new Processes($this->db))->engineInput(),
            (new Masters($this->db))->materialMap()
        );
    }

    /**
     * 資材の在庫と棚卸し金額（基準日）。分類ごとに並べる
     * @return array{rows:list<array<string, mixed>>, total_yen:int, negative:int, missing:int}
     */
    public function materials(string $date): array
    {
        $v = $this->engine()->valuationAt($date);
        $rows = [];
        $neg = 0;
        $missing = 0;
        foreach ((new Masters($this->db))->materials() as $m) {
            $id = (int) $m['id'];
            $r = $v['rows'][$id] ?? ['qty_milli' => 0, 'price_micro' => 0, 'source' => 'none', 'amount_yen' => 0, 'warnings' => []];
            if (!(bool) $m['is_active'] && $r['qty_milli'] === 0) {
                continue;
            }
            $neg += $r['qty_milli'] < 0 ? 1 : 0;
            $missing += in_array('単価未入力', $r['warnings'], true) ? 1 : 0;
            $rows[] = $m + $r;
        }
        return ['rows' => $rows, 'total_yen' => $v['total_yen'], 'negative' => $neg, 'missing' => $missing];
    }

    /**
     * 製品の在庫（SKU×容量）
     * @return array{skus:list<array<string, mixed>>, capacities:list<array<string, mixed>>, stock:array<int, array<int, int>>}
     */
    public function products(string $date): array
    {
        $b = new Bottling($this->db);
        $stock = StockCalculator::productStock($b->bottledUntil($date), $b->movementsUntil($date));
        $m = new Masters($this->db);
        $skus = array_values(array_filter($m->skus(false), static fn ($s) => (bool) $s['is_active'] || isset($stock[(int) $s['id']])));
        $usedCaps = [];
        foreach ($stock as $byCap) {
            foreach ($byCap as $capId => $_) {
                $usedCaps[$capId] = true;
            }
        }
        $caps = array_values(array_filter($m->capacities(false), static fn ($c) => (bool) $c['is_active'] || isset($usedCaps[(int) $c['id']])));
        return ['skus' => $skus, 'capacities' => $caps, 'stock' => $stock];
    }

    /**
     * ロットごとの残量・原価（1ml原価・1本あたり原価の材料）
     * @return list<array<string, mixed>>
     */
    public function lots(string $status = 'active'): array
    {
        $costs = $this->engine()->lotCosts();
        $out = [];
        foreach ((new Lots($this->db))->list($status) as $l) {
            $c = $costs[(int) $l['id']] ?? ['cost_micro' => 0, 'cost_yen' => 0, 'used' => [], 'missing_price' => false];
            $total = $l['total_ml'] === null ? null : (int) $l['total_ml'];
            $l['cost_yen'] = $c['cost_yen'];
            $l['cost_micro'] = $c['cost_micro'];
            $l['missing_price'] = $c['missing_price'];
            $l['used_materials'] = $c['used'];
            $l['per_ml_micro'] = ValuationEngine::perMlMicro($c['cost_micro'], $total);
            $out[] = $l;
        }
        return $out;
    }

    /**
     * 1本あたりの原価（酒の分＋瓶＋ラベル）。SKU×容量ごと、指定ロットで計算
     * @return list<array{sku_code:string, ml:int, yen:?int, detail:string}>
     */
    public function perBottle(int $lotId, string $date): array
    {
        $lot = null;
        foreach ($this->lots('all') as $l) {
            if ((int) $l['id'] === $lotId) {
                $lot = $l;
            }
        }
        if ($lot === null || $lot['per_ml_micro'] === null) {
            return [];
        }
        $m = new Masters($this->db);
        $bottleByCap = $m->bottleMaterialByCapacity();
        $labels = $m->allSkuBottleMaterials();
        $out = [];
        $rows = $this->db->all('SELECT DISTINCT s.sku_id, k.code, i.capacity_id, c.ml FROM bottling_sessions s
            JOIN bottling_items i ON i.session_id = s.id JOIN skus k ON k.id = s.sku_id JOIN capacities c ON c.id = i.capacity_id
            WHERE s.lot_id = ? ORDER BY k.code, c.ml', [$lotId]);
        foreach ($rows as $r) {
            $mats = [];
            if (isset($bottleByCap[(int) $r['capacity_id']])) {
                $mats[] = ['price_micro' => $this->engine()->priceInfo($bottleByCap[(int) $r['capacity_id']], $date)['micro'], 'qty_per_bottle_milli' => 1000];
            }
            foreach ($labels[(int) $r['sku_id']] ?? [] as $lb) {
                $mats[] = ['price_micro' => $this->engine()->priceInfo($lb['material_id'], $date)['micro'], 'qty_per_bottle_milli' => $lb['qty_per_bottle_milli']];
            }
            $out[] = [
                'sku_code' => (string) $r['code'], 'ml' => (int) $r['ml'],
                'yen' => ValuationEngine::perBottleYen((int) $lot['per_ml_micro'], (int) $r['ml'], $mats),
                'liquor_yen' => Num::roundYen(Num::mul((int) $lot['per_ml_micro'], (int) $r['ml'])),
                'materials' => count($mats),
            ];
        }
        return $out;
    }

    /**
     * 製品の年度別サマリ（CLAUDE.md 6.7）。販売区分はコード（sales_channels.category）
     * @return list<array<string, mixed>>
     */
    public function yearSummary(string $from, string $to): array
    {
        $b = new Bottling($this->db);
        $before = date('Y-m-d', strtotime($from . ' -1 day'));
        $carry = StockCalculator::productStock($b->bottledUntil($before), $b->movementsUntil($before));
        $end = StockCalculator::productStock($b->bottledUntil($to), $b->movementsUntil($to));
        $bottledIn = [];
        foreach ($this->db->all('SELECT s.sku_id, SUM(i.bottle_count) AS n, SUM(i.bottle_count * c.ml) AS ml FROM bottling_items i
            JOIN bottling_sessions s ON s.id = i.session_id JOIN capacities c ON c.id = i.capacity_id
            WHERE s.bottling_date BETWEEN ? AND ? GROUP BY s.sku_id', [$from, $to]) as $r) {
            $bottledIn[(int) $r['sku_id']] = ['n' => (int) $r['n'], 'ml' => (int) $r['ml']];
        }
        $sales = [];
        $uncategorized = [];
        foreach ($b->movementsUntil($to, $from) as $mv) {
            if ($mv['kind'] !== 'sale') {
                continue;   // 試飲・廃棄・遺失・返品は含めない
            }
            $cat = $mv['category'] ?? 'other';
            if ($cat === 'other') {
                $uncategorized[$mv['sku_id']] = ($uncategorized[$mv['sku_id']] ?? 0) + $mv['count'];
                continue;
            }
            $sales[$mv['sku_id']][$cat] = ($sales[$mv['sku_id']][$cat] ?? 0) + $mv['count'];
        }
        $out = [];
        foreach ((new Masters($this->db))->skus(false) as $s) {
            $id = (int) $s['id'];
            $sum = static fn (array $x) => array_sum($x[$id] ?? []);
            $row = [
                'sku_code' => $s['code'], 'sku_name' => $s['name'],
                'carry' => $sum($carry), 'bottled' => $bottledIn[$id]['n'] ?? 0, 'bottled_ml' => $bottledIn[$id]['ml'] ?? 0,
                'wholesale_distributor' => $sales[$id]['wholesale_distributor'] ?? 0,
                'wholesale_retailer' => $sales[$id]['wholesale_retailer'] ?? 0,
                'retail' => $sales[$id]['retail'] ?? 0,
                'uncategorized' => $uncategorized[$id] ?? 0,
                'end' => $sum($end),
            ];
            if ($row['carry'] || $row['bottled'] || $row['end'] || $row['retail'] || $row['wholesale_distributor'] || $row['wholesale_retailer'] || $row['uncategorized']) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
