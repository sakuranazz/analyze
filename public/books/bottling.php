<?php
declare(strict_types=1);

// 詰口情報一覧（台帳・年度別サマリ）。酒税は「酒税」画面へ分離（CLAUDE.md 9章）
require __DIR__ . '/../_boot.php';

use App\Data\Bottling;
use App\Data\Settings;
use App\Domain\DateUtil;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Service\BottlingService;
use App\Service\MovementService;
use App\Service\ReportService;
use App\View\View;

$req = Request::fromGlobals();
$startMonth = Settings::make()->int('liquor_year_start_month', 4);
$year = $req->int('year', DateUtil::liquorYearOf(DateUtil::today(), $startMonth));
[$from, $to] = DateUtil::liquorYearRange($year, $startMonth);

if ($req->isPost()) {
    Session::verifyCsrf($req);
    $id = $req->int('id');
    match ($req->str('action')) {
        'delete_session' => BottlingService::make()->delete($id),
        'delete_movement' => MovementService::make()->delete($id),
        'delete_sample_use' => MovementService::make()->deleteSampleUse($id),
        default => null,
    };
    Session::flash('削除しました（在庫は自動で再計算されます）');
    Response::redirect(Url::to('books/bottling.php', ['year' => $year]));
}

$first = Bottling::make()->ledger('0000-01-01', '9999-12-31');
$years = [];
foreach ($first as $r) {
    $years[DateUtil::liquorYearOf((string) $r['date'], $startMonth)] = true;
}
$years[DateUtil::liquorYearOf(DateUtil::today(), $startMonth)] = true;
krsort($years);

echo View::page('books/bottling', [
    'title' => '詰口情報一覧',
    'nav' => 'bottling',
    'year' => $year,
    'years' => array_keys($years),
    'from' => $from,
    'to' => $to,
    'rows' => Bottling::make()->ledger($from, $to),
    'summary' => ReportService::make()->yearSummary($from, min($to, DateUtil::today())),
    'current' => $to >= DateUtil::today(),
]);
