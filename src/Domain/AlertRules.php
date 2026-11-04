<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 要注意のルール。準備済みのデータ（配列）を受け取る関数の集まり。
 * 入口・サイドバーの件数・tools/check.php が同じ結果を使う。
 */
final class AlertRules
{
    /**
     * @param array<int, array{name:string, qty_milli:int}> $negativeMaterials
     * @param list<array{process_id:int, date:string, material:string}> $missingPrices
     * @param list<array{lot_code:string}> $lotsWithoutTotal
     * @param list<array{ym:string, due:string, planned:int}> $overdueTax
     * @param list<array{ym:string, planned:int, declared:int}> $mismatchTax
     * @param list<array{lot_code:string}> $emptyLots
     * @param list<array{process_id:int, message:string}> $makeWarnings
     * @return list<Alert>
     */
    public static function evaluate(
        array $negativeMaterials,
        array $missingPrices,
        array $lotsWithoutTotal,
        array $overdueTax,
        array $mismatchTax,
        array $emptyLots,
        array $makeWarnings,
    ): array {
        $a = [];
        foreach ($overdueTax as $t) {
            $a[] = new Alert('danger', '納付期限を過ぎた酒税', sprintf('%s分 · 期限 %s · ¥%s', self::ymJa($t['ym']), $t['due'], number_format($t['planned'])), 'books/tax.php', '酒税へ', 'tax');
        }
        if ($negativeMaterials !== []) {
            $names = implode('、', array_slice(array_column($negativeMaterials, 'name'), 0, 3));
            $a[] = new Alert('danger', 'マイナス在庫の資材', count($negativeMaterials) . "件 · {$names}", 'books/stock.php?tab=material&filter=negative', '在庫へ', 'stock', count($negativeMaterials));
        }
        if ($missingPrices !== []) {
            $first = $missingPrices[0];
            $a[] = new Alert('warn', '単価が未入力の仕入れ', count($missingPrices) . '件 · 原価0円で計算されています', 'input/process.php?id=' . $first['process_id'], '工程へ', 'process', count($missingPrices));
        }
        if ($lotsWithoutTotal !== []) {
            $a[] = new Alert('warn', '全液量が未入力のロット', implode('、', array_column($lotsWithoutTotal, 'lot_code')) . ' · 詰口の前に入力が必要です', 'books/sku_lots.php?filter=no_total', 'SKU・ロットへ', 'sku_lots', count($lotsWithoutTotal));
        }
        foreach ($mismatchTax as $t) {
            $a[] = new Alert('warn', '納付予定額と申告額が違う月', sprintf('%s分 · 予定 ¥%s / 申告 ¥%s', self::ymJa($t['ym']), number_format($t['planned']), number_format($t['declared'])), 'books/tax.php', '酒税へ', 'tax');
        }
        foreach ($makeWarnings as $w) {
            $a[] = new Alert('warn', 'できた量が0の製造工程', $w['message'], 'input/process.php?id=' . $w['process_id'], '工程へ', 'process');
        }
        if ($emptyLots !== []) {
            $a[] = new Alert('info', '残量0の進行中ロット', implode('、', array_column($emptyLots, 'lot_code')) . ' · 終了にしますか？', 'books/sku_lots.php', 'SKU・ロットへ', 'sku_lots', count($emptyLots));
        }
        return $a;
    }

    public static function ymJa(string $ym): string
    {
        return sprintf('%d年%d月', (int) substr($ym, 0, 4), (int) substr($ym, 5, 2));
    }
}
