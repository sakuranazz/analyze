<?php
declare(strict_types=1);

// 棚卸し金額の印刷用画面（税理士向け）
require __DIR__ . '/../_boot.php';

use App\Domain\DateUtil;
use App\Http\Request;
use App\Service\ReportService;
use App\View\View;

$req = Request::fromGlobals();
$date = $req->str('date');
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : DateUtil::today();

echo View::printPage('print/valuation', [
    'title' => '棚卸し表（原材料・瓶・ラベル）',
    'period' => "基準日 {$date}（最終仕入原価法）",
    'materials' => ReportService::make()->materials($date),
]);
