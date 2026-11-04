<?php
declare(strict_types=1);

namespace App\Data;

use App\Db\Connection;
use App\Domain\ProcessType;

final class Processes
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /**
     * ValuationEngine に渡す全工程（効力日つき）
     * @return list<array{id:int, type:ProcessType, effective_date:string, lot_id:?int,
     *              lines:list<array{material_id:int, qty_milli:int, unit_price_micro:?int}>}>
     */
    public function engineInput(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, type, effective_date, lot_id FROM v_process_effective ORDER BY id') as $p) {
            $out[(int) $p['id']] = [
                'id' => (int) $p['id'], 'type' => ProcessType::from((string) $p['type']),
                'effective_date' => (string) $p['effective_date'],
                'lot_id' => $p['lot_id'] === null ? null : (int) $p['lot_id'], 'lines' => [],
            ];
        }
        foreach ($this->db->all('SELECT process_id, material_id, qty_milli, unit_price_micro FROM process_lines ORDER BY process_id, sort_order, id') as $l) {
            $pid = (int) $l['process_id'];
            if (isset($out[$pid])) {
                $out[$pid]['lines'][] = [
                    'material_id' => (int) $l['material_id'], 'qty_milli' => (int) $l['qty_milli'],
                    'unit_price_micro' => $l['unit_price_micro'] === null ? null : (int) $l['unit_price_micro'],
                ];
            }
        }
        return array_values($out);
    }

    /** @return array<string, mixed>|null 工程と行 */
    public function find(int $id): ?array
    {
        $p = $this->db->one('SELECT p.*, l.lot_code FROM v_process_effective p LEFT JOIN lots l ON l.id = p.lot_id WHERE p.id = ?', [$id]);
        if ($p === null) {
            return null;
        }
        $p['lines'] = $this->db->all('SELECT pl.*, m.name AS material_name, m.unit FROM process_lines pl
            JOIN materials m ON m.id = pl.material_id WHERE pl.process_id = ? ORDER BY pl.sort_order, pl.id', [$id]);
        return $p;
    }

    /**
     * 工程の一覧（新しい順）
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50, ?string $type = null): array
    {
        $params = [];
        $w = '';
        if ($type !== null) {
            $w = 'WHERE p.type = ?';
            $params[] = $type;
        }
        $params[] = $limit;
        return $this->db->all("SELECT p.*, l.lot_code,
            (SELECT group_concat(m.name || ' ' || CASE WHEN pl.qty_milli > 0 THEN '+' ELSE '' END || printf('%g', pl.qty_milli / 1000.0) || m.unit, '、')
               FROM process_lines pl JOIN materials m ON m.id = pl.material_id WHERE pl.process_id = p.id) AS summary,
            (SELECT COUNT(*) FROM process_lines pl WHERE pl.process_id = p.id AND p.type IN ('purchase') AND pl.unit_price_micro IS NULL) AS missing_prices
            FROM v_process_effective p LEFT JOIN lots l ON l.id = p.lot_id {$w}
            ORDER BY p.effective_date DESC, p.id DESC LIMIT ?", $params);
    }

    /**
     * 資材ごとの台帳（在庫の一覧で行を開いたときに見る）
     * @return list<array<string, mixed>>
     */
    public function ledgerFor(int $materialId, int $limit = 200): array
    {
        return $this->db->all('SELECT p.id AS process_id, p.type, p.effective_date, p.partner_name, l.lot_code, pl.qty_milli, pl.unit_price_micro, p.note
            FROM process_lines pl JOIN v_process_effective p ON p.id = pl.process_id LEFT JOIN lots l ON l.id = p.lot_id
            WHERE pl.material_id = ? ORDER BY p.effective_date DESC, p.id DESC LIMIT ?', [$materialId, $limit]);
    }

    /** @return list<array{process_id:int, date:string, material:string}> 単価が空の仕入れ行 */
    public function missingPrices(): array
    {
        return array_map(static fn ($r) => ['process_id' => (int) $r['process_id'], 'date' => (string) $r['effective_date'], 'material' => (string) $r['name']],
            $this->db->all("SELECT p.id AS process_id, p.effective_date, m.name FROM process_lines pl
                JOIN v_process_effective p ON p.id = pl.process_id JOIN materials m ON m.id = pl.material_id
                WHERE p.type = 'purchase' AND pl.unit_price_micro IS NULL ORDER BY p.effective_date DESC"));
    }

    public function lastInputDate(?string $type = null): ?string
    {
        $v = $type === null
            ? $this->db->value("SELECT MAX(process_date) FROM processes WHERE type NOT IN ('bottling','stocktake')")
            : $this->db->value('SELECT MAX(process_date) FROM processes WHERE type = ?', [$type]);
        return $v === null ? null : (string) $v;
    }
}
