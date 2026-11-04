<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 詰口の計算（CLAUDE.md 6.1・6.2、IMPLEMENTATION.md 6.5）。
 * 半端な量は試飲用。瓶の合計が今回の液量を超えたら登録不可。
 */
final class BottlingPlanner
{
    /**
     * @param list<array{capacity_id:int, ml:int, count:int}> $items
     * @param array<int, int> $bottleMaterialByCapacity 容量id => 瓶の資材id
     * @param list<array{material_id:int, qty_per_bottle_milli:int}> $perBottleMaterials SKUに結びつくラベル等
     * @return array{bottles_ml:int, sample_ml:int, errors:array<string,string>, warnings:list<string>,
     *               consumption:array<int,int>}
     */
    public static function plan(
        int $sessionMl,
        array $items,
        ?int $lotTotalMl,
        int $otherSessionsMl,
        array $bottleMaterialByCapacity,
        array $perBottleMaterials,
    ): array {
        $errors = [];
        $warnings = [];
        $bottlesMl = 0;
        $bottles = 0;
        $consumption = [];

        if ($lotTotalMl === null) {
            $errors['lot_id'] = 'このロットは全液量が未入力です。先に全液量を入力してください';
        }
        if ($sessionMl <= 0) {
            $errors['session_ml'] = '今回の液量を入力してください';
        }
        foreach ($items as $it) {
            if ($it['count'] <= 0) {
                continue;
            }
            $bottlesMl += Num::mul($it['ml'], $it['count']);
            $bottles += $it['count'];
            $mat = $bottleMaterialByCapacity[$it['capacity_id']] ?? null;
            if ($mat === null) {
                $warnings[] = "{$it['ml']}ml の瓶の資材がマスタに未登録です（瓶の在庫は減りません）";
            } else {
                $consumption[$mat] = ($consumption[$mat] ?? 0) - Num::mul($it['count'], 1000);
            }
        }
        if ($bottles === 0) {
            $errors['items'] = '容量ごとの本数を1本以上入力してください';
        }
        if ($bottlesMl > $sessionMl) {
            $errors['items'] = sprintf('瓶の合計（%sml）が今回の液量（%sml）を超えています', number_format($bottlesMl), number_format($sessionMl));
        }
        foreach ($perBottleMaterials as $m) {
            $consumption[$m['material_id']] = ($consumption[$m['material_id']] ?? 0) - Num::mul($bottles, $m['qty_per_bottle_milli']);
        }
        if ($lotTotalMl !== null && $otherSessionsMl + $sessionMl > $lotTotalMl) {
            $warnings[] = sprintf(
                'このロットの詰口の合計（%sml）が全液量（%sml）を超えます',
                number_format($otherSessionsMl + $sessionMl),
                number_format($lotTotalMl)
            );
        }
        $consumption = array_filter($consumption, static fn ($q) => $q !== 0);
        return [
            'bottles_ml' => $bottlesMl,
            'sample_ml' => max(0, $sessionMl - $bottlesMl),
            'errors' => $errors,
            'warnings' => $warnings,
            'consumption' => $consumption,
        ];
    }
}
