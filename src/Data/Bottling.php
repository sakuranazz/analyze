<?php
declare(strict_types=1);

namespace App\Data;

use App\Db\Connection;

/** 詰口・出庫・試飲用 */
final class Bottling
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /** @return array<string, mixed>|null */
    public function findSession(int $id): ?array
    {
        $s = $this->db->one('SELECT s.*, k.code AS sku_code, k.name AS sku_name, l.lot_code FROM v_bottling_sessions s
            JOIN skus k ON k.id = s.sku_id JOIN lots l ON l.id = s.lot_id WHERE s.id = ?', [$id]);
        if ($s === null) {
            return null;
        }
        $s['items'] = $this->db->all('SELECT i.*, c.ml FROM bottling_items i JOIN capacities c ON c.id = i.capacity_id WHERE i.session_id = ? ORDER BY c.ml', [$id]);
        return $s;
    }

    /**
     * 詰口・出庫の台帳（日付順に新しい順）。期間は日付で絞る
     * @return list<array<string, mixed>>
     */
    public function ledger(string $from, string $to): array
    {
        return $this->db->all("SELECT * FROM (
            SELECT 'bottling' AS row_kind, s.id, s.bottling_date AS date, k.code AS sku_code, c.ml, i.bottle_count AS count,
                   NULL AS kind, NULL AS channel, NULL AS address, l.lot_code, s.note, s.session_ml, s.sample_ml, s.id AS session_id
            FROM v_bottling_sessions s JOIN bottling_items i ON i.session_id = s.id JOIN capacities c ON c.id = i.capacity_id
            JOIN skus k ON k.id = s.sku_id JOIN lots l ON l.id = s.lot_id
            WHERE s.bottling_date BETWEEN :f AND :t
            UNION ALL
            SELECT 'sample' AS row_kind, s.id, s.bottling_date, k.code, NULL, NULL, NULL, NULL, NULL, l.lot_code, s.note, s.session_ml, s.sample_ml, s.id
            FROM v_bottling_sessions s JOIN skus k ON k.id = s.sku_id JOIN lots l ON l.id = s.lot_id
            WHERE s.sample_ml > 0 AND s.bottling_date BETWEEN :f AND :t
            UNION ALL
            SELECT 'movement', m.id, m.move_date, k.code, c.ml, m.count, m.kind, ch.label, m.address, l.lot_code, m.note, NULL, NULL, NULL
            FROM product_movements m JOIN skus k ON k.id = m.sku_id JOIN capacities c ON c.id = m.capacity_id
            LEFT JOIN sales_channels ch ON ch.id = m.channel_id LEFT JOIN lots l ON l.id = m.lot_id
            WHERE m.move_date BETWEEN :f AND :t
            UNION ALL
            SELECT 'sample_use', u.id, u.use_date, k.code, NULL, u.ml, u.kind, NULL, NULL, l.lot_code, u.note, NULL, NULL, u.session_id
            FROM sample_ml_uses u JOIN bottling_sessions s ON s.id = u.session_id JOIN skus k ON k.id = s.sku_id JOIN lots l ON l.id = s.lot_id
            WHERE u.use_date BETWEEN :f AND :t
            ) ORDER BY date DESC, row_kind, id DESC", ['f' => $from, 't' => $to]);
    }

    /** @return list<array{sku_id:int, capacity_id:int, bottle_count:int}> 基準日までの詰口本数 */
    public function bottledUntil(string $date): array
    {
        return array_map(static fn ($r) => ['sku_id' => (int) $r['sku_id'], 'capacity_id' => (int) $r['capacity_id'], 'bottle_count' => (int) $r['n']],
            $this->db->all('SELECT s.sku_id, i.capacity_id, SUM(i.bottle_count) AS n FROM bottling_items i
                JOIN bottling_sessions s ON s.id = i.session_id WHERE s.bottling_date <= ? GROUP BY s.sku_id, i.capacity_id', [$date]));
    }

    /** @return list<array{sku_id:int, capacity_id:int, kind:string, count:int, category:?string, date:string}> */
    public function movementsUntil(string $date, string $from = '0000-00-00'): array
    {
        return array_map(static fn ($r) => [
            'sku_id' => (int) $r['sku_id'], 'capacity_id' => (int) $r['capacity_id'], 'kind' => (string) $r['kind'],
            'count' => (int) $r['count'], 'category' => $r['category'] === null ? null : (string) $r['category'], 'date' => (string) $r['move_date'],
        ], $this->db->all('SELECT m.sku_id, m.capacity_id, m.kind, m.count, m.move_date, ch.category FROM product_movements m
            LEFT JOIN sales_channels ch ON ch.id = m.channel_id WHERE m.move_date BETWEEN ? AND ?', [$from, $date]));
    }

    /** @return list<array<string, mixed>> 試飲用が残っている詰口 */
    public function openSamples(): array
    {
        return $this->db->all('SELECT s.id, s.bottling_date, s.sample_ml, s.sample_used_ml, s.sample_ml - s.sample_used_ml AS sample_left,
                k.code AS sku_code, l.lot_code FROM v_bottling_sessions s JOIN skus k ON k.id = s.sku_id JOIN lots l ON l.id = s.lot_id
            WHERE s.sample_ml - s.sample_used_ml > 0 ORDER BY s.bottling_date DESC');
    }

    /** @return array<string, int> YYYY-MM => その月の詰口量（session_ml の合計、詰口日の月） */
    public function monthlyMl(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT substr(bottling_date, 1, 7) AS ym, SUM(session_ml) AS ml FROM bottling_sessions GROUP BY ym ORDER BY ym") as $r) {
            $out[(string) $r['ym']] = (int) $r['ml'];
        }
        return $out;
    }

    /** @return list<array{lot_id:int, lot_code:string, ym:string, ml:int}> ロット×月の詰口量（参考値の計算用） */
    public function monthlyMlByLot(): array
    {
        return array_map(static fn ($r) => ['lot_id' => (int) $r['lot_id'], 'lot_code' => (string) $r['lot_code'], 'ym' => (string) $r['ym'], 'ml' => (int) $r['ml']],
            $this->db->all('SELECT s.lot_id, l.lot_code, substr(s.bottling_date, 1, 7) AS ym, SUM(s.session_ml) AS ml
                FROM bottling_sessions s JOIN lots l ON l.id = s.lot_id GROUP BY s.lot_id, ym ORDER BY ym, l.lot_code'));
    }

    public function lastDate(): ?string
    {
        $v = $this->db->value('SELECT MAX(d) FROM (SELECT MAX(bottling_date) AS d FROM bottling_sessions UNION ALL SELECT MAX(move_date) FROM product_movements)');
        return $v === null ? null : (string) $v;
    }
}
