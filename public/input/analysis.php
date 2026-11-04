<?php
declare(strict_types=1);

// 分析結果入力（スマホ最優先。CLAUDE.md 4章、DESIGN.md 4.2）
// 登録後は日付とロットを残して数値だけ空にし、続けて入力できる
require __DIR__ . '/../_boot.php';

use App\Data\Analysis;
use App\Data\Lots;
use App\Domain\DateUtil;
use App\Domain\ValidationError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Service\AnalysisService;
use App\View\Format as F;
use App\View\View;

$req = Request::fromGlobals();
$errors = [];
$values = [];
if ($req->isPost()) {
    Session::verifyCsrf($req);
    try {
        $svc = AnalysisService::make();
        $row = $svc->parse($req->postAll());
        $outliers = $svc->outliers($row);
        $svc->save(null, $req->postAll());
        Session::remember('analysis.date', $row['entry_date']);
        Session::remember('analysis.lot', $row['lot_id']);
        $parts = [];
        foreach (Analysis::FIELDS as $f => [$label]) {
            if ($row[$f] !== null) {
                $parts[] = $label . ' ' . F::num($row[$f]);
            }
        }
        Session::flash('登録しました' . ($parts !== [] ? '（' . implode('、', array_slice($parts, 0, 2)) . (count($parts) > 2 ? ' ほか' : '') . '）' : ''), 'toast');
        if ($outliers !== []) {
            Session::flash('前回と大きく違う値があります: ' . implode(' / ', $outliers), 'warn');
        }
        Response::redirect(Url::to('input/analysis.php'));
    } catch (ValidationError $e) {
        $errors = $e->fields();
        $values = $req->postAll();
    }
}

$lots = Lots::make()->forAnalysisInput();
$lotId = (int) ($values['lot_id'] ?? $req->int('lot_id', (int) Session::recall('analysis.lot', 0)));
if (!in_array($lotId, array_map('intval', array_column($lots, 'id')), true)) {
    $lotId = (int) ($lots[0]['id'] ?? 0);
}
$prev = $lotId > 0 ? Analysis::make()->previousValues($lotId) : [];
$recent = $lotId > 0 ? array_slice(Analysis::make()->list([$lotId]), 0, 5) : [];

echo View::page('input/analysis', [
    'title' => '分析結果入力',
    'nav' => 'analysis_input',
    'narrow' => true,
    'lots' => $lots,
    'lotId' => $lotId,
    'date' => (string) ($values['entry_date'] ?? Session::recall('analysis.date', DateUtil::today())),
    'values' => $values,
    'errors' => $errors,
    'prev' => $prev,
    'recent' => $recent,
]);
