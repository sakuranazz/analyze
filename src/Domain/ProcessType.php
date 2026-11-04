<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 工程の種別（CLAUDE.md 5章 ＋ bottling）。文字列で分岐せず、この enum の match で分ける（CLAUDE.md 0-7）。
 */
enum ProcessType: string
{
    case Polish = 'polish';
    case Germinate = 'germinate';
    case Koji = 'koji';
    case Brew = 'brew';
    case Purchase = 'purchase';
    case Adjust = 'adjust';
    case Stocktake = 'stocktake';
    case Bottling = 'bottling';

    public function label(): string
    {
        return match ($this) {
            self::Polish => '精米',
            self::Germinate => '発芽',
            self::Koji => '製麹',
            self::Brew => '醸造仕込み',
            self::Purchase => '仕入',
            self::Adjust => '手動調整',
            self::Stocktake => '棚卸し調整',
            self::Bottling => '詰口（自動）',
        };
    }

    /** 自家製の品目を作る工程か */
    public function isMake(): bool
    {
        return match ($this) {
            self::Polish, self::Germinate, self::Koji => true,
            default => false,
        };
    }

    /** 同じ日の製造工程の順位（精米・発芽 → 製麹） */
    public function makeRank(): int
    {
        return match ($this) {
            self::Polish, self::Germinate => 0,
            self::Koji => 1,
            default => 9,
        };
    }

    /** 工程入力の画面で選べる種別（棚卸し調整・詰口は専用の操作から作る） */
    public static function inputTypes(): array
    {
        return [self::Polish, self::Germinate, self::Koji, self::Brew, self::Purchase, self::Adjust];
    }

    /**
     * 行の規則（IMPLEMENTATION.md 6.2a）。
     * @param list<array{material_id:int, qty_milli:int, unit_price_micro:?int}> $lines
     * @param array<int, array{is_homemade:bool, name:string}> $materials
     * @return array<string, string> 項目 => メッセージ（空なら正しい）
     */
    public function validateLines(array $lines, array $materials): array
    {
        $errors = [];
        if ($lines === []) {
            return ['lines' => '明細を1行以上入力してください'];
        }
        $plus = array_values(array_filter($lines, static fn ($l) => $l['qty_milli'] > 0));
        $minus = array_values(array_filter($lines, static fn ($l) => $l['qty_milli'] < 0));
        foreach ($lines as $i => $l) {
            if ($l['qty_milli'] === 0) {
                $errors["lines.{$i}.qty"] = '数量が0です';
            }
            if (abs($l['qty_milli']) > Num::MAX_QTY_MILLI) {
                $errors["lines.{$i}.qty"] = '数量が大きすぎます';
            }
            if ($l['unit_price_micro'] !== null && ($l['unit_price_micro'] < 0 || $l['unit_price_micro'] > Num::MAX_PRICE_MICRO)) {
                $errors["lines.{$i}.price"] = '単価が範囲外です';
            }
            if (!isset($materials[$l['material_id']])) {
                $errors["lines.{$i}.material"] = '資材を選んでください';
            }
        }
        $all = fn (callable $p) => count(array_filter($lines, $p)) === count($lines);
        switch ($this) {
            case self::Polish:
            case self::Germinate:
            case self::Koji:
                if (count($minus) < 1) {
                    $errors['lines'] = '使った原料（マイナスの行）を1行以上入力してください';
                } elseif (count($plus) !== 1) {
                    $errors['lines'] = 'できた品目（プラスの行）をちょうど1行入力してください';
                } elseif (!($materials[$plus[0]['material_id']]['is_homemade'] ?? false)) {
                    $errors['lines'] = 'できた品目は「自家製」の資材にしてください（マスタで設定）';
                }
                break;
            case self::Brew:
            case self::Bottling:
                if (!$all(static fn ($l) => $l['qty_milli'] < 0)) {
                    $errors['lines'] = 'この工程は使った量（マイナス）だけを入力します';
                }
                break;
            case self::Purchase:
                if (!$all(static fn ($l) => $l['qty_milli'] > 0)) {
                    $errors['lines'] = '仕入れは増えた量（プラス）だけを入力します';
                }
                break;
            case self::Adjust:
            case self::Stocktake:
                break;
        }
        return $errors;
    }
}
