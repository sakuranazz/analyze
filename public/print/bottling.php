<?php
declare(strict_types=1);

// 詰口情報一覧の印刷用画面（現行の bottling_results_pdf.php に当たる）
require __DIR__ . '/../_boot.php';

use App\Data\Bottling;
use App\Data\Settings;
use App\Domain\DateUtil;
use App\Http\Request;
use App\Service\ReportService;
use App\View\View;

$req = Request::fromGlobals();
$start = Settings::make()->int('liquor_year_start_month', 4);
$year = $req->int('year', DateUtil::liquorYearOf(DateUtil::today(), $start));
[$from, $to] = DateUtil::liquorYearRange($year, $start);

echo View::printPage('print/bottling', [
    'title' => '詰口情報一覧',
    'period' => "{$year}年度（{$from}〜{$to}）",
    'rows' => array_reverse(Bottling::make()->ledger($from, $to)),
    'summary' => ReportService::make()->yearSummary($from, min($to, DateUtil::today())),
]);
