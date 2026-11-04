<?php
declare(strict_types=1);

// 在庫（資材／製品／酒のタブ）。台帳の行は一覧上で開いて見る（CLAUDE.md 4章、DESIGN.md 4.7）
require __DIR__ . '/../_boot.php';

use App\Data\Processes;
use App\Domain\DateUtil;
use App\Http\Request;
use App\Service\ReportService;
use App\View\View;

$req = Request::fromGlobals();
$tab = $req->choice('tab', ['material', 'product', 'liquor'], 'material');
$filter = $req->choice('filter', ['all', 'negative', 'missing'], 'all');
$today = DateUtil::today();
$report = ReportService::make();
$materials = $report->materials($today);
$ledgers = [];
if ($tab === 'material') {
    foreach ($materials['rows'] as $m) {
        $ledgers[(int) $m['id']] = Processes::make()->ledgerFor((int) $m['id'], 30);
    }
}

echo View::page('books/stock', [
    'title' => '在庫',
    'nav' => 'stock',
    'tab' => $tab,
    'filter' => $filter,
    'today' => $today,
    'materials' => $materials,
    'ledgers' => $ledgers,
    'products' => $tab === 'product' ? $report->products($today) : null,
    'lots' => $tab === 'liquor' ? array_values(array_filter($report->lots('all'), static fn ($l) => $l['lot_code'] !== App\Ops\LegacyImport::PLACEHOLDER_LOT)) : [],
]);
