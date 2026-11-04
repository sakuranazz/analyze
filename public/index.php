<?php
declare(strict_types=1);

// 入口: 今日の入力へのボタンと、要注意の通知（DESIGN.md 4.1）
require __DIR__ . '/_boot.php';

use App\Data\Analysis;
use App\Data\Bottling;
use App\Data\Processes;
use App\Domain\DateUtil;
use App\Service\AlertService;
use App\Service\ReportService;
use App\View\View;

$today = DateUtil::today();
$lots = array_values(array_filter(ReportService::make()->lots('active'), static fn ($l) => $l['lot_code'] !== App\Ops\LegacyImport::PLACEHOLDER_LOT));

echo View::page('index', [
    'title' => '入口',
    'nav' => '',
    'today' => $today,
    'alerts' => AlertService::make()->all(),
    'lots' => $lots,
    'last' => [
        'analysis' => Analysis::make()->lastDate(),
        'bottling' => Bottling::make()->lastDate(),
        'process' => Processes::make()->lastInputDate(),
    ],
]);
