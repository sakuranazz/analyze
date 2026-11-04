<?php
declare(strict_types=1);

// 工程入力（精米・発芽・製麹・醸造仕込み・仕入・手動調整）。工程を1件のまとまりとして登録・修正する（CLAUDE.md 5章、DESIGN.md 4.4）
require __DIR__ . '/../_boot.php';

use App\Data\Lots;
use App\Data\Masters;
use App\Data\Processes;
use App\Domain\DateUtil;
use App\Domain\Num;
use App\Domain\ProcessType;
use App\Domain\Qty;
use App\Domain\ValidationError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Service\ProcessService;
use App\Service\ReportService;
use App\View\View;

$req = Request::fromGlobals();
$id = $req->intOrNull('id');
$errors = [];
$values = [];

/** 明細行の入力 → 保存用。数量は正で入力し、向き（使った／入った）で符号を付ける */
$parseLines = static function (Request $req): array {
    $out = [];
    $errors = [];
    foreach ($req->arr('lines') as $i => $l) {
        $mat = (int) ($l['material_id'] ?? 0);
        $qtyS = trim((string) ($l['qty'] ?? ''));
        if ($mat === 0 && $qtyS === '') {
            continue;
        }
        try {
            $qty = Qty::parse($qtyS, 1000, '数量');
            if ($qty <= 0) {
                throw new InvalidArgumentException('数量は0より大きい値で入力し、「使った／入った」で向きを選んでください');
            }
            $qty = ($l['dir'] ?? '-') === '+' ? $qty : -$qty;
            $price = null;
            $cp = trim((string) ($l['container_price'] ?? ''));
            $cq = trim((string) ($l['container_qty'] ?? ''));
            if ($cp !== '' || $cq !== '') {
                // 容器単位の仕入れ: 1本の価格 ÷ 1本の容量（CLAUDE.md 5章）
                $price = Num::unitPriceFromContainer(Qty::parse($cp, 1_000_000, '容器の価格'), Qty::parse($cq, 1000, '容器の容量'));
            } elseif (trim((string) ($l['price'] ?? '')) !== '') {
                $price = Qty::parse((string) $l['price'], 1_000_000, '単価');
            }
            $out[] = ['material_id' => $mat, 'qty_milli' => $qty, 'unit_price_micro' => $qty > 0 ? $price : null];
        } catch (InvalidArgumentException $e) {
            $errors["lines.{$i}"] = ($i + 1) . '行目: ' . $e->getMessage();
        }
    }
    if ($errors !== []) {
        throw new ValidationError($errors);
    }
    return $out;
};

if ($req->isPost()) {
    Session::verifyCsrf($req);
    try {
        if ($req->str('action') === 'delete' && $id !== null) {
            ProcessService::make()->delete($id);
            Session::flash('工程を削除しました');
            Response::redirect(Url::to('input/process.php'));
        }
        $type = ProcessType::tryFrom($req->str('type')) ?? throw ValidationError::one('type', '種別を選んでください');
        $newId = ProcessService::make()->save($id, [
            'type' => $type,
            'process_date' => $req->date('process_date', '工程日'),
            'purchase_date' => $type === ProcessType::Purchase ? $req->dateOrNull('purchase_date', '仕入日') : null,
            'lot_id' => $req->intOrNull('lot_id'),
            'partner_name' => $req->strOrNull('partner_name'),
            'partner_address' => $req->strOrNull('partner_address'),
            'note' => $req->strOrNull('note'),
            'lines' => $parseLines($req),
        ]);
        Session::flash(($id ? '工程を更新しました' : '工程を登録しました') . '（在庫と原価は自動で再計算されます）', 'toast');
        Response::redirect(Url::to('input/process.php', ['type' => $type->value, 'done' => $newId]));
    } catch (ValidationError $e) {
        $errors = $e->fields();
        $values = $req->postAll();
    }
}

$process = $id !== null ? Processes::make()->find($id) : null;
$type = $values['type'] ?? $process['type'] ?? $req->choice('type', array_map(static fn ($t) => $t->value, ProcessType::inputTypes()), 'purchase');
$lots = Lots::make()->list('active');
if ($process !== null && $process['lot_id'] !== null && !in_array((int) $process['lot_id'], array_map('intval', array_column($lots, 'id')), true)) {
    $lots[] = Lots::make()->find((int) $process['lot_id']);
}
$report = ReportService::make();

echo View::page('input/process', [
    'title' => '工程入力',
    'nav' => 'process',
    'process' => $process,
    'type' => (string) $type,
    'values' => $values,
    'errors' => $errors,
    'materials' => Masters::make()->materials(true),
    'suppliers' => Masters::make()->suppliers(),
    'lots' => $lots,
    'today' => DateUtil::today(),
    'recent' => Processes::make()->recent(40),
    'lotCost' => $process !== null && $process['type'] === 'brew' && $process['lot_id'] !== null
        ? (array_values(array_filter($report->lots('all'), static fn ($l) => (int) $l['id'] === (int) $process['lot_id']))[0] ?? null) : null,
]);
