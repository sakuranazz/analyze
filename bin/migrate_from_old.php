<?php
// 旧 analyze.nnts.jp(sakegura.db)から新DBへ移行する。旧DBは読み取りのみ。
//   php bin/migrate_from_old.php <旧DB> <新DB> [--force]
// 新DBが既にあれば止まる(--force で作り直し)。ID はできるだけ旧DBのまま引き継ぐ。
// 最後に旧DBと新DBの数量を突き合わせて結果を表示する。

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
[$oldPath, $newPath] = [$argv[1] ?? '', $argv[2] ?? ''];
$force = in_array('--force', $argv, true);
if (!$oldPath || !$newPath) { fwrite(STDERR, "使い方: php bin/migrate_from_old.php <旧DB> <新DB> [--force]\n"); exit(1); }
if (!is_file($oldPath)) { fwrite(STDERR, "旧DBが見つかりません: $oldPath\n"); exit(1); }
if (file_exists($newPath)) {
    if (!$force) { fwrite(STDERR, "新DBが既にあります: $newPath(作り直すなら --force)\n"); exit(1); }
    foreach (['', '-wal', '-shm'] as $sfx) @unlink($newPath . $sfx);
}

$old = new PDO('sqlite:file:' . $oldPath . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$new = new PDO('sqlite:' . $newPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$new->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));
$new->exec('PRAGMA foreign_keys = OFF');   // 旧データの参照切れで止まらないよう、移行中だけ無効

$oldCols = fn(string $t) => array_column($old->query("PRAGMA table_info($t)")->fetchAll(), 'name');
$hasTable = fn(string $t) => (bool)$old->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $old->quote($t))->fetchColumn();
$count = [];

/** 列名を合わせてそのまま写す */
function copyTable(PDO $old, PDO $new, string $from, string $to, array $map, array &$count): void {
    $cols = array_keys($map);
    $ins = $new->prepare("INSERT INTO $to (" . implode(',', $map) . ') VALUES (' . implode(',', array_fill(0, count($map), '?')) . ')');
    $n = 0;
    foreach ($old->query("SELECT * FROM $from ORDER BY id") as $r) {
        $ins->execute(array_map(fn($c) => $r[$c] ?? null, $cols));
        $n++;
    }
    $count[$to] = $n;
}

$new->beginTransaction();

// ===== 設定 =====
$set = $new->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
foreach ($old->query('SELECT setting_key, setting_value FROM app_settings') as $r) {
    if ($r['setting_key'] === 'admin_password_hash' && preg_match('/^\$(2y|argon2)/', (string)$r['setting_value'])) {
        $set->execute(['password_hash', $r['setting_value']]);      // 今のパスワードのまま使える
    } elseif (in_array($r['setting_key'], ['liquor_tax_rate_per_kl', 'liquor_tax_reduction_percent'], true)) {
        $set->execute([$r['setting_key'], $r['setting_value']]);
    }
}

// ===== マスタ =====
copyTable($old, $new, 'material_categories', 'material_categories', ['id' => 'id', 'name' => 'name', 'sort_order' => 'sort_order'], $count);

$kindOf = function (string $name): string {
    if ($name === '玄米') return 'brown_rice';
    if ($name === '白米') return 'polished_rice';
    if ($name === '発芽玄米') return 'germinated_rice';
    if ($name === '米麹') return 'koji';
    if (mb_strpos($name, '種麹') === 0) return 'seed_koji';
    if (mb_strpos($name, '乳酸') !== false) return 'lactic';
    if (mb_strpos($name, '酵母') !== false) return 'yeast';
    return 'other';
};
$ins = $new->prepare('INSERT INTO materials (id, category_id, name, unit, kind, is_active) VALUES (?, ?, ?, ?, ?, ?)');
$count['materials'] = 0;
foreach ($old->query('SELECT * FROM materials ORDER BY id') as $r) {
    $ins->execute([$r['id'], $r['category_id'], $r['name'], $r['unit'], $kindOf($r['name']), $r['is_active']]);
    $count['materials']++;
}

copyTable($old, $new, 'suppliers', 'suppliers', ['id' => 'id', 'name' => 'name', 'address' => 'address', 'sort_order' => 'sort_order'], $count);

// 容量: ラベル(例 "720ml")から ml を取り出す
$ins = $new->prepare('INSERT INTO capacities (id, label, ml, sort_order) VALUES (?, ?, ?, ?)');
$count['capacities'] = 0;
foreach ($old->query('SELECT * FROM bottling_capacities ORDER BY id') as $r) {
    $ins->execute([$r['id'], $r['label'], (float)$r['label'], $r['sort_order']]);
    $count['capacities']++;
}

copyTable($old, $new, 'sales_channels', 'sales_channels', ['id' => 'id', 'label' => 'label', 'category' => 'category', 'address' => 'address', 'sort_order' => 'sort_order'], $count);
copyTable($old, $new, 'ingredient_codes', 'ingredient_codes', ['id' => 'id', 'code' => 'code', 'name' => 'name', 'sort_order' => 'sort_order'], $count);
copyTable($old, $new, 'sku_liquor_codes', 'sku_liquor_codes', ['id' => 'id', 'code' => 'code', 'name' => 'name', 'description' => 'description', 'sort_order' => 'sort_order'], $count);
copyTable($old, $new, 'sku_capacity_codes', 'sku_capacity_codes', ['id' => 'id', 'value' => 'value', 'sort_order' => 'sort_order'], $count);
copyTable($old, $new, 'lot_liquor_codes', 'lot_liquor_codes', ['id' => 'id', 'code' => 'code', 'name' => 'name', 'sort_order' => 'sort_order'], $count);
copyTable($old, $new, 'tank_codes', 'tank_codes', ['id' => 'id', 'code' => 'code', 'name' => 'name', 'sort_order' => 'sort_order'], $count);
copyTable($old, $new, 'skus', 'skus', ['id' => 'id', 'code' => 'code', 'name' => 'name', 'is_active' => 'is_active'], $count);   // JANコードは持ち込まない
copyTable($old, $new, 'brewing_lots', 'brewing_lots', ['id' => 'id', 'lot_code' => 'lot_code', 'liquor_code_id' => 'liquor_code_id',
    'tank_code_id' => 'tank_code_id', 'year_month' => 'year_month', 'sequence_number' => 'sequence_number', 'note' => 'note', 'created_at' => 'created_at'], $count);

// 酒税: 旧はロットごとに「納付済」を持っていた → 月の全ロットが納付済なら、その月を納付済にする
$taxNote = [];
if (in_array('tax_paid', $oldCols('brewing_lots'), true)) {
    $q = $old->query('SELECT year_month, COUNT(*) AS n, SUM(tax_paid) AS paid, SUM(tax_amount) AS amt FROM brewing_lots GROUP BY year_month');
    $ins = $new->prepare('INSERT INTO tax_payments (year_month, paid, paid_amount, paid_date) VALUES (?, 1, ?, NULL)');
    foreach ($q as $r) {
        if ((int)$r['paid'] > 0 && (int)$r['paid'] === (int)$r['n']) $ins->execute([$r['year_month'], (int)round($r['amt'])]);
        elseif ((int)$r['paid'] > 0) $taxNote[] = "{$r['year_month']}: 一部のロットだけ納付済だったため未納として移行";
        elseif ((float)$r['amt'] > 0) $taxNote[] = "{$r['year_month']}: 旧システムに記録額 " . number_format($r['amt']) . "円(未納)。新システムでは詰口量から自動計算";
    }
}

// ===== 日々のデータ =====
copyTable($old, $new, 'analysis_results', 'analysis_results', ['id' => 'id', 'entry_date' => 'entry_date', 'lot_id' => 'lot_id', 'sku_id' => 'sku_id',
    'amino_acid' => 'amino_acid', 'acidity' => 'acidity', 'baume' => 'baume', 'alcohol' => 'alcohol',
    'product_temp' => 'product_temp', 'room_temp' => 'room_temp', 'note' => 'note', 'created_at' => 'created_at'], $count);
copyTable($old, $new, 'process_events', 'process_events', ['id' => 'id', 'entry_date' => 'entry_date', 'process_type' => 'process_type',
    'lot_id' => 'lot_id', 'note' => 'note', 'created_at' => 'created_at'], $count);

// 在庫台帳: 仕入の相手先は仕入先マスタに結びつける(なければ追加)。それ以外の相手先はメモに残す
$reasonMap = [
    '精米(原料消費)' => '精米(玄米消費)', '発芽処理(原料消費)' => '発芽処理(玄米消費)', '製麹(米麹生成)' => '製麹(麹生成)',
];
$supplierIds = [];
foreach ($new->query('SELECT id, name FROM suppliers') as $s) $supplierIds[$s['name']] = (int)$s['id'];
$addSupplier = $new->prepare('INSERT INTO suppliers (name, address) VALUES (?, ?)');
$ins = $new->prepare('INSERT INTO stock_movements (id, entry_date, material_id, qty, reason, amount_yen, process_id, lot_id, supplier_id, note, created_at)
    VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)');
$count['stock_movements'] = 0; $newSuppliers = [];
foreach ($old->query('SELECT * FROM inventory_ledger ORDER BY id') as $r) {
    $supplierId = null;
    $note = $r['note'] !== '' ? $r['note'] : null;
    $partner = trim((string)($r['partner_name'] ?? ''));
    if ($partner !== '') {
        if ($r['reason'] === '仕入(入庫)') {
            if (!isset($supplierIds[$partner])) {
                $addSupplier->execute([$partner, $r['partner_address'] ?: null]);
                $supplierIds[$partner] = (int)$new->lastInsertId();
                $newSuppliers[] = $partner;
            }
            $supplierId = $supplierIds[$partner];
        } else {
            $note = trim(($note ?? '') . ' 相手先: ' . $partner . ($r['partner_address'] ? '(' . $r['partner_address'] . ')' : ''));
        }
    }
    $ins->execute([$r['id'], $r['entry_date'], $r['material_id'], $r['change_amount'], $reasonMap[$r['reason']] ?? $r['reason'],
        $r['process_id'], $r['lot_id'], $supplierId, $note, $r['created_at']]);
    $count['stock_movements']++;
}

// 詰口: 旧は1行に詰口・販売・試飲などが混在 → 種類ごとの行に分ける(同じ旧行は同じ batch)
$ins = $new->prepare('INSERT INTO bottling_events (entry_date, event_type, lot_id, sku_id, capacity_id, qty, channel_id, address, note, created_at, batch_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$setBatch = $new->prepare('UPDATE bottling_events SET batch_id = ? WHERE id = ?');
$count['bottling_events'] = 0; $noLot = 0; $skipped = 0;
$fields = ['bottle_count' => 'bottling', 'sales_count' => 'sale', 'tasting' => 'tasting', 'disposal' => 'disposal', 'loss' => 'loss', 'returns' => 'return'];
foreach ($old->query('SELECT * FROM bottling_entries ORDER BY entry_date, id') as $r) {
    if (!$r['sku_id'] || !$r['capacity_id']) { $skipped++; continue; }
    $batch = null;
    foreach ($fields as $col => $type) {
        $q = (float)($r[$col] ?? 0);
        if ($q == 0) continue;
        if ($q < 0) { $type = in_array($type, ['bottling', 'return'], true) ? 'disposal' : 'return'; $q = -$q; }   // 負の数は逆向きの出入りとして扱う
        $isOut = in_array($type, ['sale', 'return'], true);
        $ins->execute([$r['entry_date'], $type, $type === 'bottling' ? $r['lot_id'] : null, $r['sku_id'], $r['capacity_id'], $q,
            $isOut ? $r['channel_id'] : null, $isOut && $r['address'] !== '' ? $r['address'] : null, $r['note'] !== '' ? $r['note'] : null, $r['created_at'], $batch]);
        $id = (int)$new->lastInsertId();
        if ($batch === null) { $batch = $id; $setBatch->execute([$batch, $id]); }
        if ($type === 'bottling' && !$r['lot_id']) $noLot++;
        $count['bottling_events']++;
    }
}

// 旧データで既に削除されたマスタを指している任意項目は空にする(行そのものは残す)
$dangling = [];
foreach ([
    ['analysis_results', 'sku_id', 'skus'], ['analysis_results', 'lot_id', 'brewing_lots'],
    ['process_events', 'lot_id', 'brewing_lots'], ['stock_movements', 'lot_id', 'brewing_lots'],
    ['bottling_events', 'lot_id', 'brewing_lots'], ['bottling_events', 'channel_id', 'sales_channels'],
] as [$t, $col, $parent]) {
    $n = $new->exec("UPDATE $t SET $col = NULL WHERE $col IS NOT NULL AND $col NOT IN (SELECT id FROM $parent)");
    if ($n) $dangling[] = "$t.$col: {$n}件(削除済みの $parent を指していたため空にしました)";
}

$new->commit();
$new->exec('PRAGMA foreign_keys = ON');

// ===== 突き合わせ =====
$fail = 0;
$check = function (string $label, array $a, array $b) use (&$fail) {
    $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
    $bad = [];
    foreach ($keys as $k) if (abs(($a[$k] ?? 0) - ($b[$k] ?? 0)) > 0.0001) $bad[] = "$k: 旧 " . ($a[$k] ?? 0) . " / 新 " . ($b[$k] ?? 0);
    echo ($bad ? '× ' : '○ ') . $label . ($bad ? "\n    " . implode("\n    ", $bad) : '(' . count($keys) . '件一致)') . "\n";
    if ($bad) $fail++;
};
$pairs = fn(PDO $db, string $sql) => array_map('floatval', $db->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR));

$check('資材ごとの在庫残高',
    $pairs($old, 'SELECT material_id, ROUND(SUM(change_amount), 6) FROM inventory_ledger GROUP BY material_id'),
    $pairs($new, 'SELECT material_id, ROUND(SUM(qty), 6) FROM stock_movements GROUP BY material_id'));
$check('SKU×容量ごとの酒の在庫(本数)',
    $pairs($old, "SELECT sku_id || '-' || capacity_id, SUM(bottle_count + returns - sales_count - tasting - disposal - loss) FROM bottling_entries WHERE sku_id AND capacity_id GROUP BY sku_id, capacity_id"),
    $pairs($new, "SELECT sku_id || '-' || capacity_id, SUM(CASE WHEN event_type IN ('bottling','return') THEN qty ELSE -qty END) FROM bottling_events GROUP BY sku_id, capacity_id"));
$check('ロットごとの詰口量(ml)',
    $pairs($old, 'SELECT b.lot_id, SUM(b.bottle_count * CAST(c.label AS REAL)) FROM bottling_entries b JOIN bottling_capacities c ON c.id = b.capacity_id WHERE b.lot_id IS NOT NULL GROUP BY b.lot_id'),
    $pairs($new, "SELECT e.lot_id, SUM(e.qty * c.ml) FROM bottling_events e JOIN capacities c ON c.id = e.capacity_id WHERE e.event_type = 'bottling' AND e.lot_id IS NOT NULL GROUP BY e.lot_id"));
$check('分析結果の件数', ['n' => (float)$old->query('SELECT COUNT(*) FROM analysis_results')->fetchColumn()], ['n' => (float)$new->query('SELECT COUNT(*) FROM analysis_results')->fetchColumn()]);

echo "\n移行した件数:\n";
foreach ($count as $t => $n) echo "  $t: $n\n";
if ($newSuppliers) echo "仕入先マスタに追加: " . implode('、', $newSuppliers) . "\n";
if ($noLot) echo "注意: ロット未設定の詰口が {$noLot} 件あります(酒税の月別一覧には含まれません。旧システムでは納付済扱い)。\n";
if ($skipped) echo "注意: SKUまたは容量が空の詰口 {$skipped} 行は移行していません。\n";
foreach ($taxNote as $t) echo "酒税: $t\n";
foreach ($dangling as $d) echo "参照の修正: $d\n";
echo "注意: 旧システムには仕入金額がないため、棚卸しの金額評価には各資材の最新の仕入金額の入力が必要です(在庫・棚卸し → 履歴)。\n";
$fk = $new->query('PRAGMA foreign_key_check')->fetchAll();
if ($fk) { echo "× 参照切れ: " . count($fk) . "件\n"; $fail++; }
echo $fail ? "\n突き合わせで差があります。内容を確認してください。\n" : "\n突き合わせはすべて一致しました。\n";
exit($fail ? 2 : 0);
