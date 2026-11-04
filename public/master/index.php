<?php
declare(strict_types=1);

// マスタ管理（1画面に集約。CLAUDE.md 4章、DESIGN.md 4.11）
require __DIR__ . '/../_boot.php';

use App\Data\Lots;
use App\Data\Masters;
use App\Domain\CodeGenerator;
use App\Domain\Qty;
use App\Domain\ValidationError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Service\LotService;
use App\Service\MasterService;
use App\View\View;

$req = Request::fromGlobals();
$m = Masters::make();
$opts = static fn (array $rows, string $label = 'name') => array_column(array_map(static fn ($r) => ['id' => $r['id'], 'l' => ($r['code'] ?? null) ? $r['code'] . '（' . $r[$label] . '）' : $r[$label]], $rows), 'l', 'id');
$capOpts = array_column($m->capacities(false), 'label', 'id');
$categoryLabels = ['wholesale_distributor' => '卸売業者（問屋）', 'wholesale_retailer' => '小売業者（酒販店）', 'retail' => '小売販売（業務店・ネット・店頭）', 'other' => 'その他（集計に含めない）'];

/** 画面の定義: 表 => [見出し, 列 => [ラベル, 種類, 選択肢]] */
$tabs = [
    'materials' => ['資材', 'materials', [
        'name' => ['品名', 'text'], 'category_id' => ['分類', 'select', array_column($m->rows('material_categories'), 'name', 'id')],
        'unit' => ['単位', 'text'], 'is_homemade' => ['自家製', 'bool'], 'capacity_id' => ['瓶の容量', 'select', $capOpts],
        'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool'],
    ]],
    'material_categories' => ['資材の分類', 'material_categories', ['name' => ['分類名', 'text'], 'sort_order' => ['並び順', 'int']]],
    'skus' => ['SKU', 'skus', [
        'code' => ['SKUコード', 'code'], 'name' => ['商品名', 'text'], 'jan_code' => ['JAN', 'text'],
        'lot_liquor_code_id' => ['ロットの酒類', 'select', $opts($m->codes('lot_liquor_codes', false))],
        'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool'],
    ]],
    'lots' => ['ロット作番', null, []],
    'lot_liquor_codes' => ['酒類コード（ロット）', 'lot_liquor_codes', ['code' => ['コード', 'code'], 'name' => ['名前', 'text'], 'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool']]],
    'sku_liquor_codes' => ['酒類コード（SKU）', 'sku_liquor_codes', ['code' => ['コード', 'code'], 'name' => ['名前', 'text'], 'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool']]],
    'ingredient_codes' => ['原料コード', 'ingredient_codes', ['code' => ['コード', 'code'], 'name' => ['名前', 'text'], 'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool']]],
    'tank_codes' => ['タンクコード', 'tank_codes', ['code' => ['コード', 'code'], 'name' => ['名前', 'text'], 'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool']]],
    'capacities' => ['容量', 'capacities', ['ml' => ['容量 (ml)', 'int'], 'label' => ['表示名', 'text'], 'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool']]],
    'sales_channels' => ['販売先', 'sales_channels', ['label' => ['販売先', 'text'], 'category' => ['販売区分', 'select', $categoryLabels], 'address' => ['住所', 'text'], 'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool']]],
    'suppliers' => ['仕入先', 'suppliers', ['name' => ['仕入先', 'text'], 'address' => ['住所', 'text'], 'sort_order' => ['並び順', 'int'], 'is_active' => ['表示', 'bool']]],
];
$tab = $req->choice('tab', array_keys($tabs), 'materials');
$table = $tabs[$tab][1];
$cols = $tabs[$tab][2];
$showHidden = $req->str('hidden') === '1';
$self = Url::to('master/index.php', ['tab' => $tab, 'hidden' => $showHidden ? '1' : null]);
$editId = $req->intOrNull('edit');

$rowHtml = static function (array $r, bool $editing, array $errors = [], array $values = []) use ($cols, $self, $table): string {
    return View::fragment('master/_row', ['r' => $r, 'cols' => $cols, 'editing' => $editing, 'errors' => $errors, 'values' => $values, 'self' => $self, 'table' => $table]);
};

if ($req->isPost()) {
    Session::verifyCsrf($req);
    $action = $req->str('action');
    $id = $req->intOrNull('id');
    try {
        switch ($action) {
            case 'create_lot':
                $newId = LotService::make()->create($req->int('liquor_code_id'), $req->intOrNull('tank_code_id'), $req->str('year_month'), $req->intOrNull('sequence_number'), $req->strOrNull('note'));
                Session::flash('ロット ' . Lots::make()->find($newId)['lot_code'] . ' を作番しました');
                Response::redirect(Url::to('master/index.php', ['tab' => 'lots']));
            case 'create_sku':
                $liq = $m->find('sku_liquor_codes', $req->int('sku_liquor_code_id'));
                $ing = $req->intOrNull('ingredient_code_id') === null ? null : $m->find('ingredient_codes', $req->int('ingredient_code_id'));
                $cap = $m->find('capacities', $req->int('capacity_id'));
                if ($liq === null || $cap === null) {
                    throw ValidationError::one('sku_liquor_code_id', '酒類コードと容量を選んでください');
                }
                $code = CodeGenerator::sku((string) $liq['code'], $ing['code'] ?? null, (int) $cap['ml']);
                MasterService::make()->save('skus', null, [
                    'code' => $code, 'name' => $req->str('name'), 'jan_code' => $req->str('jan_code'),
                    'sku_liquor_code_id' => $liq['id'], 'ingredient_code_id' => $ing['id'] ?? null, 'capacity_id' => $cap['id'],
                    'lot_liquor_code_id' => $req->str('lot_liquor_code_id'),
                ]);
                Session::flash("SKU {$code} を作番しました");
                Response::redirect(Url::to('master/index.php', ['tab' => 'skus']));
            case 'sku_material':
                MasterService::make()->setSkuBottleMaterial($req->int('sku_id'), $req->int('material_id'), $req->str('qty') === '' ? 0 : $req->scaled('qty', 1000, '枚数'));
                Session::flash('1本あたりの資材を保存しました', 'toast');
                Response::redirect(Url::to('master/index.php', ['tab' => 'skus']));
            case 'remove':
                $r = MasterService::make()->remove((string) $table, (int) $id);
                Session::flash($r === 'hidden' ? '使用中のため削除せず、非表示にしました' : '削除しました');
                Response::redirect($self);
            case 'restore':
                MasterService::make()->restore((string) $table, (int) $id);
                Session::flash('表示に戻しました', 'toast');
                Response::redirect($self);
            default:
                $values = [];
                foreach ($cols as $c => [$label, $type]) {
                    $values[$c] = $type === 'bool' ? ($req->str($c) === '1' ? 1 : 0) : $req->str($c);
                }
                $savedId = MasterService::make()->save((string) $table, $id, $values);
                if ($req->wantsFragment()) {
                    Response::html($rowHtml($m->find((string) $table, $savedId), false));
                }
                Session::flash($id === null ? '追加しました' : '保存しました', 'toast');
                Response::redirect($self);
        }
    } catch (ValidationError|InvalidArgumentException $e) {
        $fields = $e instanceof ValidationError ? $e->fields() : ['_' => $e->getMessage()];
        if ($req->wantsFragment() && $id !== null) {
            Response::html($rowHtml($m->find((string) $table, $id), true, $fields, $req->postAll()), 422);
        }
        Session::flash(implode(' / ', $fields), 'danger');
        Response::redirect($self);
    }
}
if ($editId !== null && $req->wantsFragment() && $table !== null) {
    Response::html($rowHtml($m->find($table, $editId), true));
}

$rows = $table === null ? [] : array_values(array_filter($m->rows($table), static fn ($r) => $showHidden || !isset($r['is_active']) || (int) $r['is_active'] === 1));
echo View::page('master/index', [
    'title' => 'マスタ管理',
    'nav' => 'master',
    'tabs' => $tabs,
    'tab' => $tab,
    'table' => $table,
    'cols' => $cols,
    'rows' => $rows,
    'editId' => $editId,
    'self' => $self,
    'showHidden' => $showHidden,
    'm' => $m,
    'lots' => $tab === 'lots' ? Lots::make()->list('all') : [],
    'skuMaterials' => $tab === 'skus' ? $m->allSkuBottleMaterials() : [],
    'yymm' => date('ym'),
]);
