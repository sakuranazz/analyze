<?php
declare(strict_types=1);

namespace App\Data;

use App\Db\Connection;

final class Analysis
{
    /** 測定値の小数の桁数（小数第1位まで） */
    public const DECIMALS = 1;
    /** マイナスがありうる項目（スマホの数字キーボードにはマイナスがないため、±ボタンを出す） */
    public const SIGNED = ['baume', 'product_temp', 'room_temp'];

    public const FIELDS = [
        'amino_acid' => ['アミノ酸度', ''],
        'acidity' => ['酸度', ''],
        'baume' => ['ボーメ', 'Bx'],
        'alcohol' => ['アルコール', '%'],
        'product_temp' => ['品温', '℃'],
        'room_temp' => ['室温', '℃'],
    ];

    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /**
     * @param list<int> $lotIds 空なら全部
     * @return list<array<string, mixed>>
     */
    public function list(array $lotIds = [], ?string $from = null, ?string $to = null, string $order = 'DESC'): array
    {
        $w = [];
        $p = [];
        if ($lotIds !== []) {
            $w[] = 'a.lot_id IN (' . implode(',', array_fill(0, count($lotIds), '?')) . ')';
            array_push($p, ...$lotIds);
        }
        if ($from !== null) {
            $w[] = 'a.entry_date >= ?';
            $p[] = $from;
        }
        if ($to !== null) {
            $w[] = 'a.entry_date <= ?';
            $p[] = $to;
        }
        $order = $order === 'ASC' ? 'ASC' : 'DESC';
        return $this->db->all('SELECT a.*, l.lot_code FROM analysis_results a JOIN lots l ON l.id = a.lot_id '
            . ($w === [] ? '' : 'WHERE ' . implode(' AND ', $w)) . " ORDER BY a.entry_date {$order}, a.id {$order}", $p);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT a.*, l.lot_code FROM analysis_results a JOIN lots l ON l.id = a.lot_id WHERE a.id = ?', [$id]);
    }

    /** そのロットの直近の値（項目ごとに、値が入っている最新） @return array<string, array{value:float, date:string}> */
    public function previousValues(int $lotId, ?int $exceptId = null): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $f) {
            $r = $this->db->one("SELECT {$f} AS v, entry_date FROM analysis_results WHERE lot_id = ? AND {$f} IS NOT NULL AND id <> ?
                ORDER BY entry_date DESC, id DESC LIMIT 1", [$lotId, $exceptId ?? 0]);
            if ($r !== null) {
                $out[$f] = ['value' => (float) $r['v'], 'date' => (string) $r['entry_date']];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        return $this->db->insert('analysis_results', $row);
    }

    /** @param array<string, mixed> $row */
    public function update(int $id, array $row): void
    {
        $this->db->update('analysis_results', $id, $row);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM analysis_results WHERE id = ?', [$id]);
    }

    public function lastDate(): ?string
    {
        $v = $this->db->value('SELECT MAX(entry_date) FROM analysis_results');
        return $v === null ? null : (string) $v;
    }
}
