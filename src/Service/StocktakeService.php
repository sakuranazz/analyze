<?php
declare(strict_types=1);

namespace App\Service;

use App\Db\Connection;
use App\Domain\ProcessType;
use App\Domain\ValidationError;

/** 目視棚卸しの実数を入れると、差を stocktake 工程として記録する（CLAUDE.md 6.5） */
final class StocktakeService
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /**
     * @param array<int, int> $actuals 資材id => 実数 qty_milli
     * @return list<array{material_id:int, name:string, unit:string, book:int, actual:int, diff:int}>
     */
    public function preview(string $date, array $actuals): array
    {
        $report = new ReportService($this->db);
        $stock = $report->engine()->stockAt($date);
        $names = [];
        foreach ($this->db->all('SELECT id, name, unit FROM materials') as $m) {
            $names[(int) $m['id']] = $m;
        }
        $out = [];
        foreach ($actuals as $mid => $actual) {
            if (!isset($names[$mid])) {
                continue;
            }
            $book = $stock[$mid] ?? 0;
            if ($actual !== $book) {
                $out[] = ['material_id' => $mid, 'name' => (string) $names[$mid]['name'], 'unit' => (string) $names[$mid]['unit'],
                    'book' => $book, 'actual' => $actual, 'diff' => $actual - $book];
            }
        }
        return $out;
    }

    /** @param array<int, int> $actuals */
    public function commit(string $date, array $actuals, ?string $note): ?int
    {
        $diffs = $this->preview($date, $actuals);
        if ($diffs === []) {
            throw ValidationError::one('actuals', '帳簿と実数の差がありません');
        }
        return (new ProcessService($this->db))->save(null, [
            'type' => ProcessType::Stocktake, 'process_date' => $date, 'purchase_date' => null, 'lot_id' => null,
            'partner_name' => null, 'partner_address' => null, 'note' => $note ?? '目視棚卸しの差異',
            'lines' => array_map(static fn ($d) => ['material_id' => $d['material_id'], 'qty_milli' => $d['diff'], 'unit_price_micro' => null], $diffs),
        ], true);
    }
}
