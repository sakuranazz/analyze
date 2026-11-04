<?php
declare(strict_types=1);

// 分析結果一覧・推移グラフ（DESIGN.md 4.12）。一覧の中でインライン編集する
require __DIR__ . '/../_boot.php';

use App\Data\Analysis;
use App\Data\Lots;
use App\Domain\ValidationError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Service\AnalysisService;
use App\View\View;

$req = Request::fromGlobals();
$lotIds = array_values(array_filter(array_map('intval', $req->arr('lot')), static fn ($i) => $i > 0));
$from = $req->strOrNull('from');
$to = $req->strOrNull('to');
$editId = $req->intOrNull('edit');
$self = Url::to('books/analysis.php', ['lot' => $lotIds ?: null, 'from' => $from, 'to' => $to]);

$row = static function (int $id, bool $editing, array $errors = [], array $values = []) use ($self): string {
    return View::fragment('books/_analysis_row', ['r' => Analysis::make()->find($id), 'editing' => $editing, 'errors' => $errors, 'values' => $values, 'self' => $self]);
};

if ($req->isPost()) {
    Session::verifyCsrf($req);
    $id = $req->int('id');
    if ($req->str('action') === 'delete') {
        AnalysisService::make()->delete($id);
        Session::flash('分析結果を削除しました');
        Response::redirect($self);
    }
    try {
        AnalysisService::make()->save($id, $req->postAll());
        if ($req->wantsFragment()) {
            Response::html($row($id, false));
        }
        Session::flash('保存しました', 'toast');
        Response::redirect($self);
    } catch (ValidationError $e) {
        if ($req->wantsFragment()) {
            Response::html($row($id, true, $e->fields(), $req->postAll()), 422);
        }
        Session::flash(implode(' / ', $e->fields()), 'danger');
        Response::redirect(Url::to('books/analysis.php', ['lot' => $lotIds ?: null, 'from' => $from, 'to' => $to, 'edit' => $id]));
    }
}
if ($editId !== null && $req->wantsFragment()) {
    Response::html($row($editId, true));
}

$rows = Analysis::make()->list($lotIds, $from, $to);
echo View::page('books/analysis', [
    'title' => '分析結果一覧',
    'nav' => 'analysis',
    'lots' => Lots::make()->list('all'),
    'lotIds' => $lotIds,
    'from' => $from,
    'to' => $to,
    'rows' => $rows,
    'editId' => $editId,
    'self' => $self,
    'chart' => array_reverse($rows),
]);
