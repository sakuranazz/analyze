<?php
declare(strict_types=1);

// 酒税の集計 CSV（税理士向け。UTF-8 BOM。控えを var/exports/ に保存）
require __DIR__ . '/../_boot.php';

use App\Http\Response;
use App\Service\TaxService;

$labels = ['paid' => '納付済', 'overdue' => '期限超過', 'unpaid' => '未納', 'none' => '納付対象なし'];
$rows = [['対象月', '詰口量（ml）', '計算酒税（円）', '納付予定額（円）', '納期限', '申告額（円）', '納付日', '状態', '備考']];
foreach (array_reverse(TaxService::make()->months()) as $m) {
    $rows[] = [$m['ym'], $m['ml'], $m['raw'], $m['planned'], $m['due'], $m['declared'], $m['paid_date'], $labels[$m['status']], $m['note']];
}
Response::csv('酒税集計_' . date('Y-m-d') . '.csv', $rows);
