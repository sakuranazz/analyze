<?php
declare(strict_types=1);

namespace App\Data;

use App\Db\Connection;

/** マスタの読み取り（SQL はこの層だけ） */
final class Masters
{
    /** マスタ管理で扱う表と、編集してよい列（許可リスト） */
    public const TABLES = [
        'material_categories' => ['name', 'sort_order'],
        'materials' => ['category_id', 'name', 'unit', 'is_homemade', 'capacity_id', 'sort_order', 'is_active'],
        'capacities' => ['ml', 'label', 'sort_order', 'is_active'],
        'skus' => ['code', 'name', 'jan_code', 'sku_liquor_code_id', 'ingredient_code_id', 'capacity_id', 'lot_liquor_code_id', 'sort_order', 'is_active'],
        'lot_liquor_codes' => ['code', 'name', 'sort_order', 'is_active'],
        'sku_liquor_codes' => ['code', 'name', 'sort_order', 'is_active'],
        'ingredient_codes' => ['code', 'name', 'sort_order', 'is_active'],
        'tank_codes' => ['code', 'name', 'sort_order', 'is_active'],
        'sales_channels' => ['label', 'category', 'address', 'sort_order', 'is_active'],
        'suppliers' => ['name', 'address', 'sort_order', 'is_active'],
    ];

    /** 使用中の判定: 表 => [参照している表.列, …] */
    private const REFERENCES = [
        'material_categories' => ['materials.category_id'],
        'materials' => ['process_lines.material_id', 'sku_bottle_materials.material_id'],
        'capacities' => ['bottling_items.capacity_id', 'product_movements.capacity_id', 'materials.capacity_id', 'skus.capacity_id'],
        'skus' => ['bottling_sessions.sku_id', 'product_movements.sku_id'],
        'lot_liquor_codes' => ['lots.liquor_code_id', 'skus.lot_liquor_code_id'],
        'sku_liquor_codes' => ['skus.sku_liquor_code_id'],
        'ingredient_codes' => ['skus.ingredient_code_id'],
        'tank_codes' => ['lots.tank_code_id'],
        'sales_channels' => ['product_movements.channel_id'],
        'suppliers' => [],
    ];

    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $table, bool $includeHidden = true): array
    {
        if (!isset(self::TABLES[$table])) {
            throw new \InvalidArgumentException('不明な表です');
        }
        $order = match ($table) {
            'capacities' => 'sort_order, ml',
            'materials' => 'category_id, sort_order, id',
            default => 'sort_order, id',
        };
        $where = ($includeHidden || !in_array('is_active', self::TABLES[$table], true)) ? '' : 'WHERE is_active = 1';
        return $this->db->all("SELECT * FROM {$table} {$where} ORDER BY {$order}");
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, int $id): ?array
    {
        if (!isset(self::TABLES[$table])) {
            throw new \InvalidArgumentException('不明な表です');
        }
        return $this->db->one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    }

    public function isReferenced(string $table, int $id): bool
    {
        foreach (self::REFERENCES[$table] ?? [] as $ref) {
            [$t, $c] = explode('.', $ref);
            if ($this->db->value("SELECT 1 FROM {$t} WHERE {$c} = ? LIMIT 1", [$id]) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string, mixed>> 分類名つき */
    public function materials(bool $activeOnly = false): array
    {
        $w = $activeOnly ? 'WHERE m.is_active = 1' : '';
        return $this->db->all("SELECT m.*, c.name AS category_name, c.sort_order AS category_sort, cap.ml AS capacity_ml
            FROM materials m JOIN material_categories c ON c.id = m.category_id
            LEFT JOIN capacities cap ON cap.id = m.capacity_id
            {$w} ORDER BY c.sort_order, m.sort_order, m.id");
    }

    /** @return array<int, array{is_homemade:bool, name:string, unit:string}> ValuationEngine・検証用 */
    public function materialMap(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, name, unit, is_homemade FROM materials') as $r) {
            $out[(int) $r['id']] = ['is_homemade' => (bool) $r['is_homemade'], 'name' => (string) $r['name'], 'unit' => (string) $r['unit']];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function capacities(bool $activeOnly = true): array
    {
        return $this->db->all('SELECT * FROM capacities ' . ($activeOnly ? 'WHERE is_active = 1 ' : '') . 'ORDER BY ml');
    }

    /** @return list<array<string, mixed>> */
    public function skus(bool $activeOnly = true): array
    {
        return $this->db->all('SELECT s.*, c.ml AS capacity_ml FROM skus s LEFT JOIN capacities c ON c.id = s.capacity_id '
            . ($activeOnly ? 'WHERE s.is_active = 1 ' : '') . 'ORDER BY s.sort_order, s.code');
    }

    /** @return list<array<string, mixed>> */
    public function codes(string $table, bool $activeOnly = true): array
    {
        if (!in_array($table, ['lot_liquor_codes', 'sku_liquor_codes', 'ingredient_codes', 'tank_codes'], true)) {
            throw new \InvalidArgumentException('不明なコード表です');
        }
        return $this->db->all("SELECT * FROM {$table} " . ($activeOnly ? 'WHERE is_active = 1 ' : '') . 'ORDER BY sort_order, code');
    }

    /** @return list<array<string, mixed>> */
    public function channels(bool $activeOnly = true): array
    {
        return $this->db->all('SELECT * FROM sales_channels ' . ($activeOnly ? 'WHERE is_active = 1 ' : '') . 'ORDER BY sort_order, id');
    }

    /** @return list<array<string, mixed>> */
    public function suppliers(): array
    {
        return $this->db->all('SELECT * FROM suppliers WHERE is_active = 1 ORDER BY sort_order, id');
    }

    /** @return array<int, int> 容量id => 瓶の資材id */
    public function bottleMaterialByCapacity(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, capacity_id FROM materials WHERE capacity_id IS NOT NULL AND is_active = 1') as $r) {
            $out[(int) $r['capacity_id']] = (int) $r['id'];
        }
        return $out;
    }

    /** @return list<array{material_id:int, qty_per_bottle_milli:int, name:string}> */
    public function skuBottleMaterials(int $skuId): array
    {
        return array_map(static fn ($r) => [
            'material_id' => (int) $r['material_id'], 'qty_per_bottle_milli' => (int) $r['qty_per_bottle_milli'], 'name' => (string) $r['name'],
        ], $this->db->all('SELECT b.material_id, b.qty_per_bottle_milli, m.name FROM sku_bottle_materials b
            JOIN materials m ON m.id = b.material_id WHERE b.sku_id = ? ORDER BY m.sort_order, m.id', [$skuId]));
    }

    /** @return array<int, list<array{material_id:int, qty_per_bottle_milli:int, name:string}>> 全SKU分 */
    public function allSkuBottleMaterials(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT b.sku_id, b.material_id, b.qty_per_bottle_milli, m.name FROM sku_bottle_materials b
            JOIN materials m ON m.id = b.material_id ORDER BY m.sort_order, m.id') as $r) {
            $out[(int) $r['sku_id']][] = ['material_id' => (int) $r['material_id'], 'qty_per_bottle_milli' => (int) $r['qty_per_bottle_milli'], 'name' => (string) $r['name']];
        }
        return $out;
    }
}
