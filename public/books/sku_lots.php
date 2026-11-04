<?php
declare(strict_types=1);

// SKU・ロット一覧（各SKUの現在庫、各ロットの残量・酒税の納付状況。DESIGN.md 4.8）
require __DIR__ . '/../_boot.php';

use App\Data\Bottling;
use App\Domain\DateUtil;
use App\Domain\ValidationError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Ops\LegacyImport;
use App\Service\LotService;
use App\Service\ReportService;
use App\Service\TaxService;
use App\View\View;

$req = Request::fromGlobals();
$filter = $req->choice('filter', ['active', 'closed', 'all', 'no_total'], 'active');
$self = Url::to('books/sku_lots.php', ['filter' => $filter]);
$editId = $req->intOrNull('edit');

/** ロットごとの酒税の状態（詰口した月の状態をまとめる） */
$taxByLot = static function (): array {
    $months = [];
    foreach (TaxService::make()->months() as $m) {
        $months[$m['ym']] = $m['status'];
    }
    $out = [];
    foreach (Bottling::make()->monthlyMlByLot() as $r) {
        $out[$r['lot_id']][] = $months[$r['ym']] ?? 'none';
    }
    return array_map(static function (array $st): string {
        foreach (['overdue', 'unpaid', 'paid'] as $s) {
            if (in_array($s, $st, true)) {
                return $s;
            }
        }
        return 'none';
    }, $out);
};
$lotRows = static function () use ($filter): array {
    $rows = ReportService::make()->lots($filter === 'no_total' ? 'active' : $filter);
    $rows = array_values(array_filter($rows, static fn ($l) => $l['lot_code'] !== LegacyImport::PLACEHOLDER_LOT || $filter === 'all'));
    return $filter === 'no_total' ? array_values(array_filter($rows, static fn ($l) => $l['total_ml'] === null)) : $rows;
};
$row = static function (int $id, bool $editing, array $errors = []) use ($self, $taxByLot): string {
    $l = array_values(array_filter(ReportService::make()->lots('all'), static fn ($x) => (int) $x['id'] === $id))[0];
    return View::fragment('books/_lot_row', ['l' => $l, 'editing' => $editing, 'errors' => $errors, 'self' => $self, 'tax' => $taxByLot()[$id] ?? 'none']);
};

if ($req->isPost()) {
    Session::verifyCsrf($req);
    $id = $req->int('id');
    try {
        switch ($req->str('action')) {
            case 'status':
                LotService::make()->setStatus($id, $req->choice('status', ['active', 'closed'], 'active'));
                Session::flash($req->str('status') === 'closed' ? 'ロットを終了にしました' : 'ロットを進行中に戻しました', 'toast');
                Response::redirect($self);
            default:
                $l = $req->str('total_l');
                LotService::make()->setTotalMl($id, $l === '' ? null : $req->scaled('total_l', 1000, '全液量'));
                LotService::make()->setNote($id, $req->strOrNull('note'));
                if ($req->wantsFragment()) {
                    Response::html($row($id, false));
                }
                Session::flash('保存しました', 'toast');
                Response::redirect($self);
        }
    } catch (ValidationError $e) {
        if ($req->wantsFragment()) {
            Response::html($row($id, true, $e->fields()), 422);
        }
        Session::flash(implode(' / ', $e->fields()), 'danger');
        Response::redirect($self);
    }
}
if ($editId !== null && $req->wantsFragment()) {
    Response::html($row($editId, true));
}

$report = ReportService::make();
$products = $report->products(DateUtil::today());

echo View::page('books/sku_lots', [
    'title' => 'SKU・ロット一覧',
    'nav' => 'sku_lots',
    'filter' => $filter,
    'lots' => $lotRows(),
    'tax' => $taxByLot(),
    'editId' => $editId,
    'self' => $self,
    'products' => $products,
]);
