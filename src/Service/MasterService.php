<?php
declare(strict_types=1);

namespace App\Service;

use App\Data\Masters;
use App\Db\Connection;
use App\Domain\Qty;
use App\Domain\ValidationError;

/** マスタの追加・修正・非表示・削除。使用中は削除せず非表示（CLAUDE.md 4章） */
final class MasterService
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /** @param array<string, mixed> $values 画面から来た値（許可された列だけ使う） */
    public function save(string $table, ?int $id, array $values): int
    {
        $allowed = Masters::TABLES[$table] ?? throw ValidationError::one('table', '不明な表です');
        $row = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $values)) {
                continue;
            }
            $row[$col] = $this->normalize($col, $values[$col]);
        }
        $this->validate($table, $row, $id);
        return $this->db->transaction(function () use ($table, $id, $row): int {
            if ($id === null) {
                return $this->db->insert($table, $row);
            }
            if ($table === 'material_categories') {
                $this->db->run('UPDATE material_categories SET name = ?, sort_order = ? WHERE id = ?', [$row['name'] ?? '', $row['sort_order'] ?? 0, $id]);
                return $id;
            }
            $this->db->update($table, $id, $row);
            return $id;
        });
    }

    /** @return 'deleted'|'hidden' */
    public function remove(string $table, int $id): string
    {
        $m = new Masters($this->db);
        if ($m->isReferenced($table, $id)) {
            if (!in_array('is_active', Masters::TABLES[$table], true)) {
                throw ValidationError::one('id', '使用中のため削除できません');
            }
            $this->db->transaction(fn () => $this->db->update($table, $id, ['is_active' => 0]));
            return 'hidden';
        }
        $this->db->transaction(fn () => $this->db->run("DELETE FROM {$table} WHERE id = ?", [$id]));
        return 'deleted';
    }

    public function restore(string $table, int $id): void
    {
        $this->db->transaction(fn () => $this->db->update($table, $id, ['is_active' => 1]));
    }

    /** SKU に1本あたりの資材（ラベル等）を結びつける。枚数0で外す */
    public function setSkuBottleMaterial(int $skuId, int $materialId, int $qtyPerBottleMilli): void
    {
        $this->db->transaction(function () use ($skuId, $materialId, $qtyPerBottleMilli): void {
            if ($qtyPerBottleMilli <= 0) {
                $this->db->run('DELETE FROM sku_bottle_materials WHERE sku_id = ? AND material_id = ?', [$skuId, $materialId]);
                return;
            }
            $this->db->run('INSERT INTO sku_bottle_materials (sku_id, material_id, qty_per_bottle_milli) VALUES (?, ?, ?)
                ON CONFLICT(sku_id, material_id) DO UPDATE SET qty_per_bottle_milli = excluded.qty_per_bottle_milli',
                [$skuId, $materialId, $qtyPerBottleMilli]);
        });
    }

    private function normalize(string $col, mixed $v): mixed
    {
        $s = is_string($v) ? trim($v) : $v;
        return match (true) {
            in_array($col, ['is_active', 'is_homemade'], true) => (int) (bool) $s,
            in_array($col, ['sort_order', 'ml'], true) => ($s === '' || $s === null) ? 0 : (int) Qty::normalize((string) $s),
            str_ends_with($col, '_id') => ($s === '' || $s === null) ? null : (int) $s,
            in_array($col, ['code'], true) => strtoupper((string) $s),
            default => $s === '' ? null : $s,
        };
    }

    /** @param array<string, mixed> $row */
    private function validate(string $table, array $row, ?int $id): void
    {
        $e = [];
        foreach (['name', 'label', 'code', 'unit'] as $req) {
            if (array_key_exists($req, $row) && ($row[$req] === null || $row[$req] === '')) {
                $e[$req] = '入力してください';
            }
        }
        if (isset($row['code']) && in_array($table, ['lot_liquor_codes', 'sku_liquor_codes', 'ingredient_codes', 'tank_codes'], true)
            && preg_match('/^[A-Z0-9]+$/', (string) $row['code']) !== 1) {
            $e['code'] = 'コードは英大文字と数字だけにしてください';
        }
        if ($table === 'capacities' && isset($row['ml']) && $row['ml'] <= 0) {
            $e['ml'] = '容量は1以上です';
        }
        if ($table === 'materials' && $id === null && !isset($row['category_id'])) {
            $e['category_id'] = '分類を選んでください';
        }
        foreach (['code', 'ml', 'name'] as $uniq) {
            if (!isset($row[$uniq]) || !in_array($table, ['capacities', 'skus', 'lot_liquor_codes', 'sku_liquor_codes', 'ingredient_codes', 'tank_codes', 'material_categories'], true)) {
                continue;
            }
            if ($uniq === 'name' && $table !== 'material_categories') {
                continue;
            }
            $dup = $this->db->value("SELECT id FROM {$table} WHERE {$uniq} = ? AND id <> ?", [$row[$uniq], $id ?? 0]);
            if ($dup !== null) {
                $e[$uniq] = '同じ値がすでにあります';
            }
        }
        if ($e !== []) {
            throw new ValidationError($e);
        }
    }
}
