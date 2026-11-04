<?php
declare(strict_types=1);

namespace App\Data;

use App\Db\Connection;

final class Lots
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    private const BASE = "SELECT l.*, lc.code AS liquor_code, lc.name AS liquor_name, t.code AS tank_code,
        COALESCE(b.sessions_ml, 0) AS used_ml, COALESCE(b.bottles_ml, 0) AS bottles_ml,
        COALESCE(b.sessions_ml, 0) - COALESCE(b.bottles_ml, 0) AS sample_ml,
        CASE WHEN l.total_ml IS NULL THEN NULL ELSE l.total_ml - COALESCE(b.sessions_ml, 0) END AS remaining_ml,
        (SELECT MAX(entry_date) FROM analysis_results a WHERE a.lot_id = l.id) AS last_analysis,
        EXISTS(SELECT 1 FROM processes p WHERE p.lot_id = l.id AND p.type = 'brew') AS has_brew
        FROM lots l
        JOIN lot_liquor_codes lc ON lc.id = l.liquor_code_id
        LEFT JOIN tank_codes t ON t.id = l.tank_code_id
        LEFT JOIN (SELECT lot_id, SUM(session_ml) AS sessions_ml, SUM(bottles_ml) AS bottles_ml FROM v_bottling_sessions GROUP BY lot_id) b ON b.lot_id = l.id";

    /**
     * @param 'active'|'closed'|'all' $status
     * @return list<array<string, mixed>>
     */
    public function list(string $status = 'active'): array
    {
        $where = match ($status) {
            'active' => "WHERE l.status = 'active'",
            'closed' => "WHERE l.status = 'closed'",
            default => '',
        };
        return $this->db->all(self::BASE . " {$where} ORDER BY l.status, l.year_month DESC, l.lot_code DESC");
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(self::BASE . ' WHERE l.id = ?', [$id]);
    }

    /** 分析入力の選択肢: 進行中だけ、直近に分析を入れたロットが先頭（CLAUDE.md 4章） @return list<array<string, mixed>> */
    public function forAnalysisInput(): array
    {
        return $this->db->all("SELECT l.id, l.lot_code, (SELECT MAX(a.id) FROM analysis_results a WHERE a.lot_id = l.id) AS last_entry
            FROM lots l WHERE l.status = 'active'
            ORDER BY last_entry IS NULL, last_entry DESC, l.lot_code DESC");
    }

    public function nextSequence(int $liquorId, ?int $tankId, string $yymm): int
    {
        return 1 + (int) $this->db->value(
            'SELECT COALESCE(MAX(sequence_number), 0) FROM lots WHERE liquor_code_id = ? AND COALESCE(tank_code_id, 0) = ? AND year_month = ?',
            [$liquorId, $tankId ?? 0, $yymm]
        );
    }

    /** 他の詰口の液量の合計（編集中のセッション自身は除く） */
    public function otherSessionsMl(int $lotId, ?int $exceptSessionId): int
    {
        return (int) $this->db->value(
            'SELECT COALESCE(SUM(session_ml), 0) FROM bottling_sessions WHERE lot_id = ? AND id <> ?',
            [$lotId, $exceptSessionId ?? 0]
        );
    }
}
