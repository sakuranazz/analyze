<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 酒税（CLAUDE.md 6.6）。月単位。対象月は詰口日の月。
 * 計算式: 液量(ml) × 税率(円/kL) ÷ 1,000,000 × 軽減% ÷ 100
 */
final class LiquorTax
{
    public function __construct(
        private int $ratePerKl = 100_000,
        private int $reducedPercent = 80,
        private int $dueMonths = 2,
    ) {
    }

    /** 1円未満切り捨ての税額（ロットごとの参考値にも使う） */
    public function raw(int $ml): int
    {
        if ($ml <= 0) {
            return 0;
        }
        return Num::mulDiv($ml, Num::mul($this->ratePerKl, $this->reducedPercent), 1_000_000 * 100, Rounding::Down);
    }

    /** 月の納付予定額: 月の合計液量で計算し、100円未満切り捨て */
    public function planned(int $monthMl): int
    {
        return intdiv($this->raw($monthMl), 100) * 100;
    }

    /** 納期限: 対象月の1日を起点に dueMonths か月後の末日（例: 2026-09 → 2026-11-30） */
    public function dueDate(string $ym): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            throw new \InvalidArgumentException("年月の形式が不正です: {$ym}");
        }
        $d = new \DateTimeImmutable($ym . '-01');
        return $d->modify("+{$this->dueMonths} month")->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * 月の状態
     * @return 'none'|'paid'|'overdue'|'unpaid'
     */
    public function status(int $planned, ?string $paidDate, string $due, string $today): string
    {
        if ($paidDate !== null && $paidDate !== '') {
            return 'paid';
        }
        if ($planned <= 0) {
            return 'none';
        }
        return $due < $today ? 'overdue' : 'unpaid';
    }
}
