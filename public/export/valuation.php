<?php
declare(strict_types=1);

// 月次の棚卸し表 CSV（税理士向け。UTF-8 BOM。控えを var/exports/ に保存）
require __DIR__ . '/../_boot.php';

use App\Domain\DateUtil;
use App\Domain\Qty;
use App\Http\Request;
use App\Http\Response;
use App\Service\ReportService;

$req = Request::fromGlobals();
$date = $req->str('date');
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : DateUtil::today();
$v = ReportService::make()->materials($date);

$rows = [['基準日', '分類', '品名', '数量', '単位', '単価（円）', '金額（円）', '注記']];
foreach ($v['rows'] as $m) {
    if ($m['qty_milli'] === 0) {
        continue;
    }
    $rows[] = [$date, $m['category_name'], $m['name'], str_replace(',', '', Qty::format($m['qty_milli'])), $m['unit'],
        str_replace(',', '', Qty::format($m['price_micro'], 1_000_000)), $m['amount_yen'], implode('、', $m['warnings'])];
}
$rows[] = [$date, '', '合計', '', '', '', $v['total_yen'], '最終仕入原価法・円未満切り捨て'];
Response::csv("棚卸し表_{$date}.csv", $rows);
