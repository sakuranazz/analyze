<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * SKU・ロットのコード作番（CLAUDE.md 6.8）。画面のプレビューと登録で同じ関数を使う。
 * - SKU:  酒類コード[-原料コード]_容量ml       例: FR-MOM_450
 * - ロット: 酒類コード[_タンクコード]-YYMM_連番2桁  例: FR-2609_01、FR_L201-2604_01
 */
final class CodeGenerator
{
    public static function sku(string $liquor, ?string $ingredient, int $ml): string
    {
        self::assertCode($liquor, '酒類コード');
        $s = $liquor;
        if ($ingredient !== null && $ingredient !== '') {
            self::assertCode($ingredient, '原料コード');
            $s .= '-' . $ingredient;
        }
        return $s . '_' . $ml;
    }

    public static function lot(string $liquor, ?string $tank, string $yymm, int $seq): string
    {
        self::assertCode($liquor, '酒類コード');
        if (preg_match('/^\d{4}$/', $yymm) !== 1) {
            throw new \InvalidArgumentException('年月は YYMM の4桁で入力してください（例: 2611）');
        }
        $month = (int) substr($yymm, 2, 2);
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('年月の月が不正です');
        }
        if ($seq < 1 || $seq > 99) {
            throw new \InvalidArgumentException('回数は1〜99です');
        }
        $s = $liquor;
        if ($tank !== null && $tank !== '') {
            self::assertCode($tank, 'タンクコード');
            $s .= '_' . $tank;
        }
        return sprintf('%s-%s_%02d', $s, $yymm, $seq);
    }

    /** @return array{liquor:string, tank:?string, yymm:string, seq:int}|null */
    public static function parseLot(string $code): ?array
    {
        if (preg_match('/^([A-Z0-9]+)(?:_([A-Z0-9]+))?-(\d{4})_(\d{2})$/', $code, $m) !== 1) {
            return null;
        }
        return ['liquor' => $m[1], 'tank' => $m[2] !== '' ? $m[2] : null, 'yymm' => $m[3], 'seq' => (int) $m[4]];
    }

    /** 日付（YYYY-MM-DD）→ YYMM */
    public static function yymmFromDate(string $date): string
    {
        return substr($date, 2, 2) . substr($date, 5, 2);
    }

    private static function assertCode(string $code, string $label): void
    {
        if (preg_match('/^[A-Z0-9]+$/', $code) !== 1) {
            throw new \InvalidArgumentException("{$label}は英大文字と数字だけにしてください");
        }
    }
}
