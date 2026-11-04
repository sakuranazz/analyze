<?php
$pageTitle = 'マスタ管理';
require_once __DIR__ . '/includes/header.php';

// 項目定義: [列名, 表示名, 種別(text|number|select), 必須, 選択肢(select用: 配列 or ['ref'=>[表,値列,表示列]])]
$kinds = [
    'brown_rice' => '玄米', 'germinated_rice' => '発芽玄米', 'polished_rice' => '白米', 'koji' => '麹', 'lactic' => '乳酸',
    'yeast' => '酵母', 'seed_koji' => '種麹', 'bottle' => '瓶', 'label' => 'ラベル', 'other' => 'その他',
];
$channelCategories = [
    'tonya' => '問屋', 'saketen' => '酒店', 'gyoumuten' => '業務店', 'net' => 'ネット',
    'tenpo' => '店舗', 'tasting' => '試飲', 'disposal' => '廃棄', 'return' => '返品', 'other' => 'その他',
];
$defs = [
    'categories' => ['table' => 'material_categories', 'label' => '資材カテゴリ', 'order' => 'sort_order, id',
        'fields' => [['name', '名称', 'text', true]]],
    'materials' => ['table' => 'materials', 'label' => '資材', 'order' => 'category_id, id',
        'fields' => [
            ['category_id', 'カテゴリ', 'select', true, ['ref' => ['material_categories', 'id', 'name']]],
            ['name', '名称', 'text', true],
            ['unit', '単位', 'text', true],
            ['kind', '種別', 'select', true, $kinds],
            ['usage_per_ml', '詰口1mlあたり使用量', 'number', false],
        ]],
    'capacities' => ['table' => 'capacities', 'label' => '容量', 'order' => 'sort_order, ml',
        'fields' => [['label', '表示名(例: 720ml)', 'text', true], ['ml', 'ml', 'number', true], ['sort_order', '並び順', 'number', false]]],
    'suppliers' => ['table' => 'suppliers', 'label' => '仕入先', 'order' => 'sort_order, id',
        'fields' => [['name', '名称', 'text', true], ['address', '住所', 'text', false]]],
    'channels' => ['table' => 'sales_channels', 'label' => '販売先', 'order' => 'sort_order, id',
        'fields' => [['label', '名称', 'text', true], ['category', '区分', 'select', true, $channelCategories], ['address', '住所', 'text', false]]],
    'sku_liquor' => ['table' => 'sku_liquor_codes', 'label' => 'SKU酒類コード', 'order' => 'sort_order, id',
        'fields' => [['code', 'コード(例 SK)', 'text', true], ['name', '名称', 'text', true], ['description', '説明', 'text', false], ['sort_order', '並び順', 'number', false]]],
    'sku_ingredient' => ['table' => 'ingredient_codes', 'label' => 'SKU原料コード', 'order' => 'sort_order, id',
        'fields' => [['code', 'コード(例 ICH)', 'text', true], ['name', '名称', 'text', true], ['sort_order', '並び順', 'number', false]]],
    'sku_capacity' => ['table' => 'sku_capacity_codes', 'label' => 'SKU容量コード', 'order' => 'sort_order, id',
        'fields' => [['value', 'コード(例 720)', 'text', true], ['sort_order', '並び順', 'number', false]]],
    'lot_liquor' => ['table' => 'lot_liquor_codes', 'label' => 'ロット酒類コード', 'order' => 'sort_order, id',
        'fields' => [['code', 'コード(例 SK)', 'text', true], ['name', '名称', 'text', true], ['sort_order', '並び順', 'number', false]]],
    'tanks' => ['table' => 'tank_codes', 'label' => 'タンクコード', 'order' => 'sort_order, id',
        'fields' => [['code', 'コード(例 L1501)', 'text', true], ['name', '名称', 'text', true], ['sort_order', '並び順', 'number', false]]],
];

$t = $_GET['t'] ?? 'materials';
if (!isset($defs[$t])) $t = 'materials';
$def = $defs[$t];
$table = $def['table'];   // 固定の定義からのみ決まるため SQL に直接使える
$cols = array_column($def['fields'], 0);

function selectOptions(PDO $pdo, array $field): array {
    $opts = $field[4] ?? [];
    if (isset($opts['ref'])) {
        [$tbl, $val, $txt] = $opts['ref'];
        $opts = [];
        foreach ($pdo->query("SELECT $val AS v, $txt AS t FROM $tbl ORDER BY id") as $r) $opts[$r['v']] = $r['t'];
    }
    return $opts;
}

function collectValues(PDO $pdo, array $def): ?array {
    $vals = [];
    foreach ($def['fields'] as $f) {
        [$col, $label, $type, $required] = $f;
        $raw = trim((string)($_POST[$col] ?? ''));
        if ($raw === '') {
            if ($required) { flash("「{$label}」を入力してください。", 'error'); return null; }
            $vals[$col] = null;
            continue;
        }
        if ($type === 'number') {
            if (!is_numeric($raw)) { flash("「{$label}」は数値で入力してください。", 'error'); return null; }
            $raw = $raw + 0;
        } elseif ($type === 'select') {
            if (!array_key_exists($raw, selectOptions($pdo, $f))) { flash("「{$label}」の選択が不正です。", 'error'); return null; }
        }
        $vals[$col] = $raw;
    }
    return $vals;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            $vals = collectValues($pdo, $def);
            if ($vals !== null) {
                if ($id > 0) {
                    $set = implode(', ', array_map(fn($c) => "$c = :$c", $cols));
                    $st = $pdo->prepare("UPDATE $table SET $set WHERE id = :id");
                    $st->execute($vals + ['id' => $id]);
                    flash('更新しました。');
                } else {
                    $ph = implode(', ', array_map(fn($c) => ":$c", $cols));
                    $st = $pdo->prepare("INSERT INTO $table (" . implode(', ', $cols) . ") VALUES ($ph)");
                    $st->execute($vals);
                    flash('追加しました。');
                }
            } else {
                $_SESSION['form_error'] = true;
            }
        } elseif ($action === 'delete' && $id > 0) {
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            flash('削除しました。');
        }
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'UNIQUE') !== false) flash('同じ名称がすでにあります。', 'error');
        elseif (stripos($msg, 'FOREIGN KEY') !== false) flash('他のデータで使われているため削除できません。', 'error');
        else { error_log($msg); flash('保存できませんでした。', 'error'); }
    }
    redirect('masters.php?t=' . urlencode($t));
}

$editId = (int)($_GET['edit'] ?? 0);
$editRow = [];
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$editId]);
    $editRow = $st->fetch() ?: [];
}
$rows = $pdo->query("SELECT * FROM $table ORDER BY {$def['order']}")->fetchAll();
?>
<h1>マスタ管理</h1>
<div class="tabs">
    <?php foreach ($defs as $key => $d): ?>
        <a href="masters.php?t=<?= h($key) ?>" class="<?= $key === $t ? 'active' : '' ?>"><?= h($d['label']) ?></a>
    <?php endforeach; ?>
</div>

<div class="panel">
    <h2><?= h($def['label']) ?>を<?= $editRow ? '編集' : '追加' ?></h2>
    <form method="post" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
        <?php foreach ($def['fields'] as $f): [$col, $label, $type, $required] = $f; $cur = $editRow[$col] ?? ''; ?>
            <label><?= h($label) ?><?= $required ? ' *' : '' ?></label>
            <?php if ($type === 'select'): ?>
                <select name="<?= h($col) ?>" <?= $required ? 'required' : '' ?>>
                    <option value="">選択してください</option>
                    <?php foreach (selectOptions($pdo, $f) as $v => $txt): ?>
                        <option value="<?= h($v) ?>" <?= (string)$cur === (string)$v ? 'selected' : '' ?>><?= h($txt) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="<?= $type === 'number' ? 'number' : 'text' ?>" <?= $type === 'number' ? 'step="any"' : '' ?>
                    name="<?= h($col) ?>" value="<?= h($cur) ?>" <?= $required ? 'required' : '' ?>>
            <?php endif; ?>
        <?php endforeach; ?>
        <p>
            <button class="btn" type="submit"><?= $editRow ? '更新' : '追加' ?></button>
            <?php if ($editRow): ?><a class="btn sub" href="masters.php?t=<?= h($t) ?>">キャンセル</a><?php endif; ?>
        </p>
    </form>
</div>

<div class="panel">
    <h2><?= h($def['label']) ?>一覧(<?= count($rows) ?>件)</h2>
    <?php if (!$rows): ?>
        <p class="muted">まだ登録がありません。</p>
    <?php else: ?>
    <table>
        <tr>
            <?php foreach ($def['fields'] as $f): ?><th><?= h($f[1]) ?></th><?php endforeach; ?>
            <th></th>
        </tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <?php foreach ($def['fields'] as $f):
                $v = $r[$f[0]] ?? '';
                if ($f[2] === 'select') { $o = selectOptions($pdo, $f); $v = $o[$v] ?? $v; } ?>
                <td><?= h($v) ?></td>
            <?php endforeach; ?>
            <td>
                <a class="btn sub small" href="masters.php?t=<?= h($t) ?>&edit=<?= (int)$r['id'] ?>">編集</a>
                <form method="post" class="inline" onsubmit="return confirm('削除しますか?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn sub small" type="submit">削除</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
