<?php
declare(strict_types=1);

/**
 * 画面を見るための最小限のマスタを、空のDBに入れる（旧データを移行するときは使わない）
 *   php tools/migrate.php && php tools/seed_demo.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db\Connection;
use App\Service\LotService;

$db = Connection::shared();
if ((int) $db->value('SELECT COUNT(*) FROM materials') > 0) {
    fwrite(STDOUT, "すでにデータがあるため何もしません\n");
    exit(0);
}
$db->transaction(function () use ($db): void {
    $cat = static fn (string $n) => (int) $db->value('SELECT id FROM material_categories WHERE name = ?', [$n]);
    $caps = [];
    foreach ([450, 500, 720, 1800] as $i => $ml) {
        $caps[$ml] = $db->insert('capacities', ['ml' => $ml, 'label' => "{$ml}ml", 'sort_order' => $i]);
    }
    foreach ([['原料米', '玄米', 'kg', 0], ['原料米', '白米', 'kg', 1], ['原料米', '発芽玄米', 'kg', 1], ['製麹関連', '米麹', 'kg', 1],
        ['製麹関連', '種麹', 'g', 0], ['副原料', '桃', 'kg', 0], ['醸造関連', '乳酸', 'ml', 0], ['醸造関連', '酵母', 'g', 0]] as $i => [$c, $n, $u, $h]) {
        $db->insert('materials', ['category_id' => $cat($c), 'name' => $n, 'unit' => $u, 'is_homemade' => $h, 'sort_order' => $i]);
    }
    $db->insert('materials', ['category_id' => $cat('瓶'), 'name' => '瓶 450ml', 'unit' => '本', 'capacity_id' => $caps[450]]);
    $liquor = $db->insert('lot_liquor_codes', ['code' => 'FR', 'name' => 'フルーツ澄酒']);
    $sku = $db->insert('sku_liquor_codes', ['code' => 'FR', 'name' => 'ふわり']);
    $ing = $db->insert('ingredient_codes', ['code' => 'MOM', 'name' => '桃']);
    $db->insert('skus', ['code' => 'FR-MOM_450', 'name' => '（例）ふわり 桃 450ml', 'sku_liquor_code_id' => $sku, 'ingredient_code_id' => $ing,
        'capacity_id' => $caps[450], 'lot_liquor_code_id' => $liquor]);
    foreach ([['卸し(問屋)', 'wholesale_distributor'], ['卸し(酒販店)', 'wholesale_retailer'], ['小売(ネット)', 'retail'], ['小売(店頭)', 'retail']] as $i => [$l, $c]) {
        $db->insert('sales_channels', ['label' => $l, 'category' => $c, 'sort_order' => $i]);
    }
    (new LotService($db))->create($liquor, null, date('ym'), null, '（例）');
});
fwrite(STDOUT, "最小限のマスタを入れました\n");
