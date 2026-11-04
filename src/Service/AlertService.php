<?php
declare(strict_types=1);

namespace App\Service;

use App\Data\Lots;
use App\Data\Processes;
use App\Db\Connection;
use App\Domain\Alert;
use App\Domain\AlertRules;
use App\Domain\DateUtil;

/** 入口・サイドバーの件数・tools/check.php が共有する「要注意」 */
final class AlertService
{
    /** @var list<Alert>|null */
    private static ?array $cache = null;

    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /** @return list<Alert> */
    public function all(?string $today = null): array
    {
        if ($today === null && self::$cache !== null) {
            return self::$cache;
        }
        $today ??= DateUtil::today();
        $report = new ReportService($this->db);
        $mats = $report->materials($today);
        $negative = [];
        foreach ($mats['rows'] as $r) {
            if ($r['qty_milli'] < 0) {
                $negative[(int) $r['id']] = ['name' => (string) $r['name'], 'qty_milli' => $r['qty_milli']];
            }
        }
        $lots = (new Lots($this->db))->list('active');
        $noTotal = array_values(array_map(static fn ($l) => ['lot_code' => (string) $l['lot_code']],
            array_filter($lots, static fn ($l) => $l['total_ml'] === null && (int) $l['used_ml'] === 0 && self::hasBrew($l))));
        $noTotalBottled = array_values(array_map(static fn ($l) => ['lot_code' => (string) $l['lot_code']],
            array_filter($lots, static fn ($l) => $l['total_ml'] === null && (int) $l['used_ml'] > 0)));
        $empty = array_values(array_map(static fn ($l) => ['lot_code' => (string) $l['lot_code']],
            array_filter($lots, static fn ($l) => $l['remaining_ml'] !== null && (int) $l['remaining_ml'] <= 0)));
        $overdue = [];
        $mismatch = [];
        foreach ((new TaxService($this->db))->months($today) as $m) {
            if ($m['status'] === 'overdue') {
                $overdue[] = ['ym' => $m['ym'], 'due' => $m['due'], 'planned' => $m['planned']];
            }
            if ($m['mismatch']) {
                $mismatch[] = ['ym' => $m['ym'], 'planned' => $m['planned'], 'declared' => (int) $m['declared']];
            }
        }
        $alerts = AlertRules::evaluate(
            array_values($negative),
            (new Processes($this->db))->missingPrices(),
            array_merge($noTotalBottled, $noTotal),
            $overdue,
            $mismatch,
            $empty,
            $report->engine()->makeWarnings(),
        );
        return self::$cache = $alerts;
    }

    /** 仕込みのあるロットだけ「全液量未入力」の対象にする（作番しただけのロットは出さない） */
    private static function hasBrew(array $lot): bool
    {
        return (bool) ($lot['has_brew'] ?? true);
    }

    /** @return array<string, int> navKey => 件数 */
    public function navCounts(): array
    {
        $out = [];
        foreach ($this->all() as $a) {
            if ($a->severity === 'info') {
                continue;
            }
            $out[$a->navKey] = ($out[$a->navKey] ?? 0) + $a->count;
        }
        return $out;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
