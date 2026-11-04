<?php
declare(strict_types=1);

namespace App\Domain;

use App\Config;

/**
 * 整数計算（IMPLEMENTATION.md 4章）。
 * - 数量 qty_milli（単位の1/1000）、単価 price_micro（円/単位×10^6。旧データの小数6桁の単価をそのまま保つ）
 * - 金額も micro で積み、円にするのは決めた単位で1回だけ
 * - PHP の整数は溢れると黙って float になるため、掛け算は必ずここを通し、int でなければ例外
 */
final class Num
{
    // 数量 × 単価(micro) ≤ 10^8 × 10^10 = 10^18 ＜ 9.2×10^18（溢れない）
    public const MAX_QTY_MILLI = 100_000_000;        // 10万単位（乳酸 20,000ml などの ml 単位の資材も入る）
    public const MAX_PRICE_MICRO = 10_000_000_000;   // ¥1万/単位
    public const MICRO = 1_000_000;

    public static function mul(int $a, int $b): int
    {
        $r = $a * $b;
        if (!is_int($r)) {
            throw new \OverflowException("整数の範囲を超えました（{$a} × {$b}）");
        }
        return $r;
    }

    /** a × b ÷ div を、途中で丸めずに計算して指定の方法で丸める */
    public static function mulDiv(int $a, int $b, int $div, Rounding $r = Rounding::HalfUp): int
    {
        if ($div === 0) {
            throw new \DivisionByZeroError('0 で割ろうとしました');
        }
        return self::divRound(self::mul($a, $b), $div, $r);
    }

    public static function divRound(int $num, int $div, Rounding $r = Rounding::HalfUp): int
    {
        if ($div === 0) {
            throw new \DivisionByZeroError('0 で割ろうとしました');
        }
        if ($div < 0) {
            $num = -$num;
            $div = -$div;
        }
        $q = intdiv($num, $div);          // 0 方向へ切り捨て
        $rem = $num - $q * $div;
        if ($rem === 0) {
            return $q;
        }
        return match ($r) {
            Rounding::Down => $q,
            Rounding::Floor => $num < 0 ? $q - 1 : $q,
            Rounding::HalfUp => (2 * abs($rem) >= $div) ? $q + ($num < 0 ? -1 : 1) : $q,
        };
    }

    /** 円（整数）→ micro */
    public static function yenToMicro(int $yen): int
    {
        return self::mul($yen, self::MICRO);
    }

    /** 容器単位の仕入れ: 1本の価格（micro）÷ 1本の容量（milli）→ 単価（micro/単位） */
    public static function unitPriceFromContainer(int $containerPriceMicro, int $containerQtyMilli): int
    {
        if ($containerQtyMilli <= 0) {
            throw new \InvalidArgumentException('容器の容量は0より大きい値にしてください');
        }
        return self::mulDiv($containerPriceMicro, 1000, $containerQtyMilli, Rounding::HalfUp);
    }

    /** 数量 × 単価 → 金額（micro）。qty_milli × price_micro ÷ 1000 */
    public static function amountMicro(int $qtyMilli, int $priceMicro): int
    {
        return self::mulDiv($qtyMilli, $priceMicro, 1000, Rounding::HalfUp);
    }

    /** micro 円 → 円。丸め方は設定（既定は円未満切り捨て） */
    public static function roundYen(int $micro, ?Rounding $r = null): int
    {
        $r ??= self::configuredRounding();
        return self::divRound($micro, self::MICRO, $r);
    }

    public static function configuredRounding(): Rounding
    {
        try {
            $v = Config::get('yen_rounding');
        } catch (\RuntimeException) {
            $v = 'down';
        }
        return $v === 'half_up' ? Rounding::HalfUp : Rounding::Down;
    }
}
