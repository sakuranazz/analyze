<?php
declare(strict_types=1);

namespace App\Service;

use App\Data\Lots;
use App\Data\Masters;
use App\Db\Connection;
use App\Domain\BottlingPlanner;
use App\Domain\ProcessType;
use App\Domain\ValidationError;

/**
 * 詰口: セッション・容量ごとの本数・瓶とラベルの自動消費（type='bottling' の工程）を1つのまとまりで保存する。
 * 試飲用（端数）は保存せず計算する（v_bottling_sessions）。
 */
final class BottlingService
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /**
     * @param array{bottling_date:string, sku_id:int, lot_id:int, session_ml:int, items:array<int,int>, note:?string} $in
     *        items: 容量id => 本数
     * @return array{id:int, warnings:list<string>, sample_ml:int}
     */
    public function save(?int $id, array $in): array
    {
        $masters = new Masters($this->db);
        $lot = (new Lots($this->db))->find($in['lot_id']);
        if ($lot === null) {
            throw ValidationError::one('lot_id', 'ロットを選んでください');
        }
        $sku = $masters->find('skus', $in['sku_id']);
        if ($sku === null) {
            throw ValidationError::one('sku_id', 'SKUを選んでください');
        }
        $caps = [];
        foreach ($masters->capacities(false) as $c) {
            $caps[(int) $c['id']] = (int) $c['ml'];
        }
        $items = [];
        foreach ($in['items'] as $capId => $count) {
            if ($count > 0) {
                if (!isset($caps[$capId])) {
                    throw ValidationError::one('items', '容量が不正です');
                }
                $items[] = ['capacity_id' => $capId, 'ml' => $caps[$capId], 'count' => $count];
            }
        }
        $plan = BottlingPlanner::plan(
            $in['session_ml'],
            $items,
            $lot['total_ml'] === null ? null : (int) $lot['total_ml'],
            (new Lots($this->db))->otherSessionsMl($in['lot_id'], $id),
            $masters->bottleMaterialByCapacity(),
            $masters->skuBottleMaterials($in['sku_id']),
        );
        if ($plan['errors'] !== []) {
            throw new ValidationError($plan['errors']);
        }
        $newId = $this->db->transaction(function () use ($id, $in, $items, $plan): int {
            $row = ['bottling_date' => $in['bottling_date'], 'sku_id' => $in['sku_id'], 'lot_id' => $in['lot_id'],
                'session_ml' => $in['session_ml'], 'note' => $in['note']];
            if ($id === null) {
                $id = $this->db->insert('bottling_sessions', $row);
            } else {
                if ($this->db->value('SELECT 1 FROM bottling_sessions WHERE id = ?', [$id]) === null) {
                    throw ValidationError::one('id', '詰口が見つかりません');
                }
                $used = (int) $this->db->value('SELECT COALESCE(SUM(ml), 0) FROM sample_ml_uses WHERE session_id = ?', [$id]);
                if ($used > $plan['sample_ml']) {
                    throw ValidationError::one('session_ml', sprintf('試飲用はすでに %dml 使っています。試飲用が %dml より少なくなる変更はできません', $used, $used));
                }
                $this->db->update('bottling_sessions', $id, $row);
                $this->db->run('DELETE FROM bottling_items WHERE session_id = ?', [$id]);
                $this->db->run('DELETE FROM processes WHERE source_session_id = ?', [$id]);
            }
            foreach ($items as $it) {
                $this->db->insert('bottling_items', ['session_id' => $id, 'capacity_id' => $it['capacity_id'], 'bottle_count' => $it['count']]);
            }
            if ($plan['consumption'] !== []) {
                $pid = $this->db->insert('processes', [
                    'process_date' => $in['bottling_date'], 'type' => ProcessType::Bottling->value, 'lot_id' => $in['lot_id'],
                    'source_session_id' => $id, 'note' => '詰口による瓶・ラベルの自動消費',
                ]);
                $i = 0;
                foreach ($plan['consumption'] as $materialId => $qty) {
                    $this->db->insert('process_lines', ['process_id' => $pid, 'material_id' => $materialId, 'qty_milli' => $qty, 'sort_order' => $i++]);
                }
            }
            return $id;
        });
        return ['id' => $newId, 'warnings' => $plan['warnings'], 'sample_ml' => $plan['sample_ml']];
    }

    /** 削除。items・試飲用の記録・自動消費の工程は CASCADE で消える */
    public function delete(int $id): void
    {
        $this->db->transaction(fn () => $this->db->run('DELETE FROM bottling_sessions WHERE id = ?', [$id]));
    }
}
