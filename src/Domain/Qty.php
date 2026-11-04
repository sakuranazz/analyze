<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 入力された数値の文字列 ⇔ スケール付き整数。浮動小数を一度も通らない。
 */
final class Qty
{
    /**
     * "1.34" → 1340（scale=1000）。全角数字・カンマ・空白・全角マイナスを正規化する。
     * @throws \InvalidArgumentException 形式が不正、または小数の桁が多すぎるとき
     */
    public static function parse(string $input, int $scale = 1000, string $label = '数量'): int
    {
        $digits = (int) round(log10($scale));
        $s = self::normalize($input);
        if ($s === '') {
            throw new \InvalidArgumentException("{$label}が空です");
        }
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $s, $m) !== 1) {
            throw new \InvalidArgumentException("{$label}は数字で入力してください");
        }
        $frac = $m[3] ?? '';
        if (strlen(rtrim($frac, '0')) > $digits) {
            throw new \InvalidArgumentException("{$label}は小数{$digits}桁までです");
        }
        $frac = str_pad(substr($frac, 0, $digits), $digits, '0');
        $intPart = ltrim($m[2], '0');
        if (strlen($intPart) > 15) {
            throw new \InvalidArgumentException("{$label}が大きすぎます");
        }
        $v = Num::mul((int) ($intPart === '' ? '0' : $intPart), $scale) + ($digits > 0 ? (int) $frac : 0);
        return $m[1] === '-' ? -$v : $v;
    }

    /** 空なら null */
    public static function parseOptional(?string $input, int $scale = 1000, string $label = '数量'): ?int
    {
        if ($input === null || self::normalize($input) === '') {
            return null;
        }
        return self::parse($input, $scale, $label);
    }

    public static function normalize(string $input): string
    {
        $s = mb_convert_kana($input, 'ns');
        $s = str_replace(['−', '－', 'ー', '‐', '―'], '-', $s);
        $s = str_replace(['，', ',', '、', ' ', '¥', '￥', '円'], '', $s);
        $s = str_replace(['．', '。', '｡'], '.', $s);   // 日本語キーボードの「。」も小数点として扱う
        return trim($s);
    }

    /** 1340 → "1.34"（不要な0は落とす。minDecimals までは残す） */
    public static function format(int $value, int $scale = 1000, int $minDecimals = 0): string
    {
        $digits = (int) round(log10($scale));
        $neg = $value < 0;
        $abs = abs($value);
        $int = intdiv($abs, $scale);
        $frac = $digits > 0 ? str_pad((string) ($abs % $scale), $digits, '0', STR_PAD_LEFT) : '';
        $frac = rtrim($frac, '0');
        if (strlen($frac) < $minDecimals) {
            $frac = str_pad($frac, $minDecimals, '0');
        }
        return ($neg ? '-' : '') . number_format($int) . ($frac !== '' ? '.' . $frac : '');
    }

    /** 入力欄に戻すための表記（カンマなし） */
    public static function plain(?int $value, int $scale = 1000): string
    {
        if ($value === null) {
            return '';
        }
        return str_replace(',', '', self::format($value, $scale));
    }
}
