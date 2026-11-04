<?php
declare(strict_types=1);

// 詰口・出庫入力（CLAUDE.md 4章・6.1・6.2、DESIGN.md 4.3）
require __DIR__ . '/../_boot.php';

use App\Data\Bottling;
use App\Data\Lots;
use App\Data\Masters;
use App\Domain\DateUtil;
use App\Domain\ValidationError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Service\BottlingService;
use App\Service\LotService;
use App\Service\MovementService;
use App\View\View;

$req = Request::fromGlobals();
$tab = $req->choice('tab', ['bottling', 'movement', 'sample'], 'bottling');
$editId = $req->intOrNull('id');
$errors = [];
$values = [];

if ($req->isPost()) {
    Session::verifyCsrf($req);
    $action = $req->str('action');
    try {
        switch ($action) {
            case 'set_total':
                LotService::make()->setTotalMl($req->int('lot_id'), $req->scaled('total_l', 1000, '全液量'));
                Session::flash('全液量を登録しました', 'toast');
                Response::redirect(Url::to('input/bottling.php', ['lot_id' => $req->int('lot_id'), 'id' => $editId]));
            case 'bottling':
                $items = [];
                foreach ($req->arr('items') as $capId => $n) {
                    $n = trim((string) $n);
                    if ($n !== '') {
                        if (preg_match('/^\d+$/', App\Domain\Qty::normalize($n)) !== 1) {
                            throw ValidationError::one('items', '本数は整数で入力してください');
                        }
                        $items[(int) $capId] = (int) App\Domain\Qty::normalize($n);
                    }
                }
                $r = BottlingService::make()->save($editId, [
                    'bottling_date' => $req->date('bottling_date'),
                    'sku_id' => $req->int('sku_id'),
                    'lot_id' => $req->int('lot_id'),
                    'session_ml' => $req->int('session_ml'),
                    'items' => $items,
                    'note' => $req->strOrNull('note'),
                ]);
                foreach ($r['warnings'] as $w) {
                    Session::flash($w, 'warn');
                }
                Session::flash(($editId ? '詰口を更新しました' : '詰口を登録しました') . ($r['sample_ml'] > 0 ? "（試飲用 {$r['sample_ml']}ml）" : ''), 'toast');
                Response::redirect(Url::to('books/bottling.php'));
            case 'movement':
                $w = MovementService::make()->add([
                    'move_date' => $req->date('move_date'),
                    'sku_id' => $req->int('sku_id'),
                    'capacity_id' => $req->int('capacity_id'),
                    'kind' => $req->str('kind'),
                    'count' => $req->int('count'),
                    'channel_id' => $req->str('kind') === 'sale' ? $req->intOrNull('channel_id') : null,
                    'address' => $req->strOrNull('address'),
                    'lot_id' => $req->intOrNull('lot_id'),
                    'note' => $req->strOrNull('note'),
                ]);
                foreach ($w as $m) {
                    Session::flash($m, 'warn');
                }
                Session::flash('出庫を登録しました', 'toast');
                Response::redirect(Url::to('input/bottling.php', ['tab' => 'movement']));
            case 'sample':
                MovementService::make()->useSample($req->int('session_id'), $req->date('use_date'), $req->int('ml'), $req->str('kind'), $req->strOrNull('note'));
                Session::flash('試飲用の残りを記録しました', 'toast');
                Response::redirect(Url::to('input/bottling.php', ['tab' => 'sample']));
        }
    } catch (ValidationError $e) {
        $errors = $e->fields();
        $values = $req->postAll();
        $tab = in_array($action, ['movement', 'sample'], true) ? $action : 'bottling';
    }
}

$m = Masters::make();
$session = $editId !== null ? Bottling::make()->findSession($editId) : null;
$activeLots = Lots::make()->list('active');
if ($session !== null && !in_array((int) $session['lot_id'], array_map('intval', array_column($activeLots, 'id')), true)) {
    $activeLots[] = Lots::make()->find((int) $session['lot_id']);
}
$lotId = (int) ($values['lot_id'] ?? $session['lot_id'] ?? $req->int('lot_id', (int) ($activeLots[0]['id'] ?? 0)));
if (!in_array($lotId, array_map('intval', array_column($activeLots, 'id')), true)) {
    $lotId = (int) ($activeLots[0]['id'] ?? 0);    // 進行中でない・存在しないロットは選べない
}
$lot = $lotId > 0 ? Lots::make()->find($lotId) : null;
$otherMl = $lot ? Lots::make()->otherSessionsMl($lotId, $editId) : 0;

echo View::page('input/bottling', [
    'title' => '詰口・出庫',
    'nav' => 'bottling_input',
    'tab' => $tab,
    'errors' => $errors,
    'values' => $values,
    'session' => $session,
    'skus' => $m->skus(),
    'capacities' => $m->capacities(),
    'channels' => $m->channels(),
    'lots' => $activeLots,
    'allLots' => Lots::make()->list('all'),
    'lot' => $lot,
    'otherMl' => $otherMl,
    'today' => DateUtil::today(),
    'bottleByCap' => $m->bottleMaterialByCapacity(),
    'labelsBySku' => $m->allSkuBottleMaterials(),
    'materialNames' => array_map(static fn ($x) => $x['name'], $m->materialMap()),
    'samples' => Bottling::make()->openSamples(),
]);
