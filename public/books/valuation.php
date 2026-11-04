<?php
declare(strict_types=1);

// 棚卸し金額（月末・決算日別、最終仕入原価法。CLAUDE.md 6.3・6.5、DESIGN.md 4.9）
require __DIR__ . '/../_boot.php';

use App\Data\Settings;
use App\Db\Connection;
use App\Domain\DateUtil;
use App\Domain\Qty;
use App\Domain\ValidationError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Url;
use App\Ops\LegacyImport;
use App\Service\ReportService;
use App\Service\StocktakeService;
use App\View\View;

$req = Request::fromGlobals();
$today = DateUtil::today();
$first = (string) (Connection::shared()->value('SELECT MIN(effective_date) FROM v_process_effective') ?? $today);
$fiscalEnd = Settings::make()->fiscalYearEndMonth();
$dates = DateUtil::monthEnds($first, $today);
if ($dates !== [] && $dates[0] > $today) {
    $dates[0] = $today;
}
$date = $req->str('date');
if (!in_array($date, $dates, true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $date = $dates[1] ?? $dates[0] ?? $today;     // 既定は前月末
}
$errors = [];
$preview = null;
$actuals = [];

if ($req->isPost()) {
    Session::verifyCsrf($req);
    try {
        foreach ($req->arr('actual') as $mid => $v) {
            if (trim((string) $v) !== '') {
                $actuals[(int) $mid] = Qty::parse((string) $v, 1000, '実数');
            }
        }
        if ($actuals === []) {
            throw ValidationError::one('actual', '実数を1つ以上入力してください');
        }
        if ($req->str('action') === 'commit') {
            StocktakeService::make()->commit($date, $actuals, $req->strOrNull('note'));
            Session::flash("{$date} の棚卸し差異を記録しました（棚卸し調整の工程）");
            Response::redirect(Url::to('books/valuation.php', ['date' => $date]));
        }
        $preview = StocktakeService::make()->preview($date, $actuals);
        if ($preview === []) {
            Session::flash('帳簿と実数の差はありません', 'ok');
            Response::redirect(Url::to('books/valuation.php', ['date' => $date]));
        }
    } catch (ValidationError $e) {
        $errors = $e->fields();
    } catch (InvalidArgumentException $e) {
        $errors = ['actual' => $e->getMessage()];
    }
}

$report = ReportService::make();
$lots = array_values(array_filter($report->lots('all'), static fn ($l) => $l['cost_yen'] > 0 && $l['lot_code'] !== LegacyImport::PLACEHOLDER_LOT));

echo View::page('books/valuation', [
    'title' => '棚卸し金額',
    'nav' => 'valuation',
    'date' => $date,
    'dates' => $dates,
    'fiscalEnd' => $fiscalEnd,
    'materials' => $report->materials($date),
    'errors' => $errors,
    'preview' => $preview,
    'actuals' => $actuals,
    'lots' => $lots,
    'perBottle' => $req->intOrNull('lot') !== null ? $report->perBottle($req->int('lot'), $date) : [],
    'lotSel' => $req->intOrNull('lot'),
]);
