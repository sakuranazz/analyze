<?php
declare(strict_types=1);

namespace App\Service;

use App\Data\Bottling;
use App\Data\Settings;
use App\Db\Connection;
use App\Domain\DateUtil;
use App\Domain\ValidationError;

/** 酒税（月単位）。納付済みにする操作はここだけ（CLAUDE.md 4章） */
final class TaxService
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /**
     * 月ごとの一覧（新しい順）
     * @return list<array{ym:string, ml:int, raw:int, planned:int, due:string, declared:?int, paid_date:?string, note:?string,
     *                    status:string, mismatch:bool, lots:list<array{lot_code:string, ml:int, ref:int}>}>
     */
    public function months(?string $today = null): array
    {
        $today ??= DateUtil::today();
        $tax = (new Settings($this->db))->liquorTax();
        $b = new Bottling($this->db);
        $ml = $b->monthlyMl();
        $recorded = [];
        foreach ($this->db->all('SELECT * FROM tax_months') as $r) {
            $recorded[(string) $r['ym']] = $r;
        }
        $byLot = [];
        foreach ($b->monthlyMlByLot() as $r) {
            $byLot[$r['ym']][] = ['lot_code' => $r['lot_code'], 'ml' => $r['ml'], 'ref' => $tax->raw($r['ml'])];
        }
        $yms = array_unique(array_merge(array_keys($ml), array_keys($recorded)));
        rsort($yms);
        $out = [];
        foreach ($yms as $ym) {
            $m = $ml[$ym] ?? 0;
            $planned = $tax->planned($m);
            $rec = $recorded[$ym] ?? null;
            $declared = $rec === null || $rec['declared_amount'] === null ? null : (int) $rec['declared_amount'];
            $paid = $rec['paid_date'] ?? null;
            $due = $tax->dueDate($ym);
            $out[] = [
                'ym' => $ym, 'ml' => $m, 'raw' => $tax->raw($m), 'planned' => $planned, 'due' => $due,
                'declared' => $declared, 'paid_date' => $paid === null ? null : (string) $paid, 'note' => $rec['note'] ?? null,
                'status' => $tax->status($planned, $paid === null ? null : (string) $paid, $due, $today),
                'mismatch' => $declared !== null && $declared !== $planned,
                'lots' => $byLot[$ym] ?? [],
            ];
        }
        return $out;
    }

    public function record(string $ym, ?int $declared, ?string $paidDate, ?string $note): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            throw ValidationError::one('ym', '対象月が不正です');
        }
        if ($declared !== null && $declared < 0) {
            throw ValidationError::one('declared_amount', '申告額が不正です');
        }
        $this->db->transaction(fn () => $this->db->run(
            "INSERT INTO tax_months (ym, declared_amount, paid_date, note) VALUES (?, ?, ?, ?)
             ON CONFLICT(ym) DO UPDATE SET declared_amount = excluded.declared_amount, paid_date = excluded.paid_date,
               note = excluded.note, updated_at = strftime('%Y-%m-%dT%H:%M:%S','now','localtime')",
            [$ym, $declared, $paidDate, $note]
        ));
    }
}
