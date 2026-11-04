<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 日付は YYYY-MM-DD のテキスト（CLAUDE.md 5章）。年度は保存せず、範囲を返す（CLAUDE.md 6.7）。
 */
final class DateUtil
{
    public static function parse(string $s, string $label = '日付'): string
    {
        $s = trim(mb_convert_kana($s, 'n'));
        $s = str_replace('/', '-', $s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException("{$label}は 2026-11-03 の形で正しい日付を入力してください");
        }
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    public static function monthEnd(string $ym): string
    {
        return (new \DateTimeImmutable($ym . '-01'))->modify('last day of this month')->format('Y-m-d');
    }

    public static function ym(string $date): string
    {
        return substr($date, 0, 7);
    }

    /** 酒類の年度（4/1〜翌3/31）。年度は開始年で表す */
    public static function liquorYearOf(string $date, int $startMonth = 4): int
    {
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);
        return $m >= $startMonth ? $y : $y - 1;
    }

    /** @return array{0:string, 1:string} 酒類の年度の開始日・終了日 */
    public static function liquorYearRange(int $year, int $startMonth = 4): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth));
        return [$start->format('Y-m-d'), $start->modify('+1 year -1 day')->format('Y-m-d')];
    }

    /** 決算日（例: 8月末）。基準日以前で直近の決算日 */
    public static function lastFiscalEnd(string $date, int $endMonth = 8): string
    {
        $y = (int) substr($date, 0, 4);
        $candidate = self::monthEnd(sprintf('%04d-%02d', $y, $endMonth));
        return $candidate <= $date ? $candidate : self::monthEnd(sprintf('%04d-%02d', $y - 1, $endMonth));
    }

    /** @return list<string> from〜to の各月末（新しい順） */
    public static function monthEnds(string $from, string $to): array
    {
        $out = [];
        $d = new \DateTimeImmutable(substr($to, 0, 7) . '-01');
        $stop = substr($from, 0, 7);
        while ($d->format('Y-m') >= $stop) {
            $out[] = $d->modify('last day of this month')->format('Y-m-d');
            $d = $d->modify('-1 month');
        }
        return $out;
    }

    public static function weekdayJa(string $date): string
    {
        return ['日', '月', '火', '水', '木', '金', '土'][(int) (new \DateTimeImmutable($date))->format('w')];
    }
}
