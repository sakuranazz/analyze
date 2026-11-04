<?php
declare(strict_types=1);

// 分析結果一覧の印刷用画面（現行の analysis_results_pdf.php に当たる）
require __DIR__ . '/../_boot.php';

use App\Data\Analysis;
use App\Http\Request;
use App\View\View;

$req = Request::fromGlobals();
$lotIds = array_values(array_filter(array_map('intval', $req->arr('lot')), static fn ($i) => $i > 0));
$rows = Analysis::make()->list($lotIds, $req->strOrNull('from'), $req->strOrNull('to'), 'ASC');
$lots = array_values(array_unique(array_column($rows, 'lot_code')));

echo View::printPage('print/analysis', [
    'title' => '分析結果一覧',
    'period' => ($lots === [] ? 'すべてのロット' : implode('、', $lots)) . ($req->strOrNull('from') || $req->strOrNull('to') ? '　' . $req->str('from') . '〜' . $req->str('to') : ''),
    'rows' => $rows,
]);
