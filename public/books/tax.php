<?php
declare(strict_types=1);

// 酒税（月別）。納付済みにする操作はここだけ（CLAUDE.md 4章・6.6、DESIGN.md 4.10）
require __DIR__ . '/../_boot.php';

use App\Domain\ValidationError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Service\TaxService;
use App\View\View;

$req = Request::fromGlobals();
$self = Url::to('books/tax.php');
$editYm = $req->strOrNull('edit');
$find = static function (string $ym): array {
    foreach (TaxService::make()->months() as $m) {
        if ($m['ym'] === $ym) {
            return $m;
        }
    }
    return ['ym' => $ym, 'ml' => 0, 'raw' => 0, 'planned' => 0, 'due' => '', 'declared' => null, 'paid_date' => null, 'note' => null, 'status' => 'none', 'mismatch' => false, 'lots' => []];
};
$row = static fn (string $ym, bool $editing, array $errors = [], array $values = []) => View::fragment('books/_tax_row', ['m' => $find($ym), 'editing' => $editing, 'errors' => $errors, 'values' => $values, 'self' => $self]);

if ($req->isPost()) {
    Session::verifyCsrf($req);
    $ym = $req->str('ym');
    try {
        $declared = $req->str('declared_amount') === '' ? null : $req->scaled('declared_amount', 1, '申告額');
        TaxService::make()->record($ym, $declared, $req->dateOrNull('paid_date', '納付日'), $req->strOrNull('note'));
        if ($req->wantsFragment()) {
            Response::html($row($ym, false));
        }
        Session::flash('保存しました', 'toast');
        Response::redirect($self);
    } catch (ValidationError $e) {
        if ($req->wantsFragment()) {
            Response::html($row($ym, true, $e->fields(), $req->postAll()), 422);
        }
        Session::flash(implode(' / ', $e->fields()), 'danger');
        Response::redirect(Url::to('books/tax.php', ['edit' => $ym]));
    }
}
if ($editYm !== null && $req->wantsFragment()) {
    Response::html($row($editYm, true));
}

echo View::page('books/tax', [
    'title' => '酒税',
    'nav' => 'tax',
    'months' => TaxService::make()->months(),
    'editYm' => $editYm,
    'self' => $self,
]);
