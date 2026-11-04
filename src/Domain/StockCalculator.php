<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 製品・酒（ロット）の在庫（CLAUDE.md 5章・6.1）。資材の在庫は ValuationEngine::stockAt。
 */
final class StockCalculator
{
    /** 製品の在庫 ＝ 詰口の本数 ＋ 返品 − 販売 − 試飲 − 廃棄 − 遺失 */
    public static function movementSign(string $kind): int
    {
        return match ($kind) {
            'return' => 1,
            'sale', 'tasting', 'disposal', 'loss' => -1,
            default => throw new \InvalidArgumentException("出庫の種別が不正です: {$kind}"),
        };
    }

    /**
     * @param list<array{sku_id:int, capacity_id:int, bottle_count:int}> $bottled
     * @param list<array{sku_id:int, capacity_id:int, kind:string, count:int}> $movements
     * @return array<int, array<int, int>> sku_id => capacity_id => 本数
     */
    public static function productStock(array $bottled, array $movements): array
    {
        $out = [];
        foreach ($bottled as $b) {
            $out[$b['sku_id']][$b['capacity_id']] = ($out[$b['sku_id']][$b['capacity_id']] ?? 0) + $b['bottle_count'];
        }
        foreach ($movements as $m) {
            $out[$m['sku_id']][$m['capacity_id']] = ($out[$m['sku_id']][$m['capacity_id']] ?? 0)
                + self::movementSign($m['kind']) * $m['count'];
        }
        return $out;
    }

    /**
     * ロットの残り ＝ 全液量 − 詰口済み（各回の session_ml の合計＝瓶の合計＋試飲用）
     * @return array{total_ml:?int, used_ml:int, bottles_ml:int, sample_ml:int, remaining_ml:?int}
     */
    public static function lotBalance(?int $totalMl, int $sessionsMl, int $bottlesMl): array
    {
        return [
            'total_ml' => $totalMl,
            'used_ml' => $sessionsMl,
            'bottles_ml' => $bottlesMl,
            'sample_ml' => $sessionsMl - $bottlesMl,
            'remaining_ml' => $totalMl === null ? null : $totalMl - $sessionsMl,
        ];
    }
}
