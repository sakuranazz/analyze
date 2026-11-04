<?php
declare(strict_types=1);

namespace App\Service;

use App\Data\Masters;
use App\Db\Connection;
use App\Domain\ProcessType;
use App\Domain\ValidationError;

/**
 * 工程を「1件のまとまり」として登録・置き換え・削除する（CLAUDE.md 5章）。
 * 在庫と原価は派生なので、保存のあとに再計算の処理は要らない。
 */
final class ProcessService
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /**
     * @param array{type:ProcessType, process_date:string, purchase_date:?string, lot_id:?int, partner_name:?string,
     *              partner_address:?string, note:?string, lines:list<array{material_id:int, qty_milli:int, unit_price_micro:?int}>} $in
     */
    public function save(?int $id, array $in, bool $allowSpecialTypes = false): int
    {
        $type = $in['type'];
        if (!$allowSpecialTypes && !in_array($type, ProcessType::inputTypes(), true)) {
            throw ValidationError::one('type', 'この種別は工程入力からは作れません');
        }
        $errors = $type->validateLines($in['lines'], (new Masters($this->db))->materialMap());
        if ($type === ProcessType::Brew && $in['lot_id'] === null) {
            $errors['lot_id'] = '醸造仕込みはロットの指定が必須です';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return $this->db->transaction(function () use ($id, $in, $type): int {
            $row = [
                'process_date' => $in['process_date'],
                'type' => $type->value,
                'lot_id' => $in['lot_id'],
                'partner_name' => $in['partner_name'] ?? null,
                'partner_address' => $in['partner_address'] ?? null,
                'purchase_date' => $type === ProcessType::Purchase ? ($in['purchase_date'] ?? null) : null,
                'note' => $in['note'] ?? null,
            ];
            if ($id === null) {
                $id = $this->db->insert('processes', $row);
            } else {
                $old = $this->db->one('SELECT type FROM processes WHERE id = ?', [$id]);
                if ($old === null) {
                    throw ValidationError::one('id', '工程が見つかりません');
                }
                if (in_array($old['type'], ['bottling', 'stocktake'], true) && $old['type'] !== $type->value) {
                    throw ValidationError::one('type', '詰口・棚卸し調整の工程は、その画面から直してください');
                }
                $this->db->update('processes', $id, $row);
                $this->db->run('DELETE FROM process_lines WHERE process_id = ?', [$id]);
            }
            foreach ($in['lines'] as $i => $l) {
                $this->db->insert('process_lines', [
                    'process_id' => $id, 'material_id' => $l['material_id'], 'qty_milli' => $l['qty_milli'],
                    'unit_price_micro' => $l['unit_price_micro'], 'sort_order' => $i,
                ]);
            }
            return $id;
        });
    }

    public function delete(int $id): void
    {
        $type = $this->db->value('SELECT type FROM processes WHERE id = ?', [$id]);
        if ($type === 'bottling') {
            throw ValidationError::one('id', '詰口の自動消費は、詰口を削除すると消えます');
        }
        $this->db->transaction(fn () => $this->db->run('DELETE FROM processes WHERE id = ?', [$id]));
    }
}
