<?php
declare(strict_types=1);

namespace App\Service;

use App\Data\Lots;
use App\Data\Masters;
use App\Db\Connection;
use App\Domain\CodeGenerator;
use App\Domain\ValidationError;

final class LotService
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /** ロットの作番。回数を省略すると次の番号を自動で取る（BEGIN IMMEDIATE の中で取るので二重取りしない） */
    public function create(int $liquorId, ?int $tankId, string $yymm, ?int $seq, ?string $note): int
    {
        $m = new Masters($this->db);
        $liquor = $m->find('lot_liquor_codes', $liquorId);
        $tank = $tankId === null ? null : $m->find('tank_codes', $tankId);
        if ($liquor === null) {
            throw ValidationError::one('liquor_code_id', '酒類コードを選んでください');
        }
        return $this->db->transaction(function () use ($liquorId, $tankId, $yymm, $seq, $note, $liquor, $tank): int {
            $seq ??= (new Lots($this->db))->nextSequence($liquorId, $tankId, $yymm);
            try {
                $code = CodeGenerator::lot((string) $liquor['code'], $tank === null ? null : (string) $tank['code'], $yymm, $seq);
            } catch (\InvalidArgumentException $e) {
                throw ValidationError::one('year_month', $e->getMessage());
            }
            if ($this->db->value('SELECT 1 FROM lots WHERE lot_code = ?', [$code]) !== null) {
                throw ValidationError::one('sequence_number', "ロット {$code} はすでにあります");
            }
            return $this->db->insert('lots', [
                'lot_code' => $code, 'liquor_code_id' => $liquorId, 'tank_code_id' => $tankId,
                'year_month' => $yymm, 'sequence_number' => $seq, 'note' => $note,
            ]);
        });
    }

    public function setTotalMl(int $id, ?int $ml): void
    {
        if ($ml !== null && $ml <= 0) {
            throw ValidationError::one('total_ml', '全液量は0より大きい値にしてください');
        }
        $this->db->transaction(fn () => $this->db->update('lots', $id, ['total_ml' => $ml]));
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'closed'], true)) {
            throw ValidationError::one('status', '状態が不正です');
        }
        $this->db->transaction(fn () => $this->db->update('lots', $id, ['status' => $status]));
    }

    public function setNote(int $id, ?string $note): void
    {
        $this->db->transaction(fn () => $this->db->update('lots', $id, ['note' => $note]));
    }
}
