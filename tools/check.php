<?php
declare(strict_types=1);

/**
 * 不変条件と要注意の確認（反映後のスモークテスト、日々の確認用）
 *   php tools/check.php            問題があれば一覧を出して終了コード 1（「情報」だけなら 0）
 *   php tools/check.php --quiet    問題があるときだけ出力（cron 向け）
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db\Connection;
use App\Service\AlertService;

$quiet = in_array('--quiet', $argv, true);
$db = Connection::shared();
$problems = [];

// DB の不変条件（CHECK・外部キーで守っているものの再確認）
if (($r = $db->value('PRAGMA integrity_check')) !== 'ok') {
    $problems[] = "整合性検査: {$r}";
}
if (($fk = $db->all('PRAGMA foreign_key_check')) !== []) {
    $problems[] = '外部キーの不整合: ' . count($fk) . ' 件';
}
$orphanAuto = (int) $db->value("SELECT COUNT(*) FROM processes WHERE type = 'bottling' AND source_session_id NOT IN (SELECT id FROM bottling_sessions)");
if ($orphanAuto > 0) {
    $problems[] = "詰口のない自動消費の工程: {$orphanAuto} 件";
}
$overSample = (int) $db->value('SELECT COUNT(*) FROM v_bottling_sessions WHERE sample_ml < 0 OR sample_used_ml > sample_ml');
if ($overSample > 0) {
    $problems[] = "試飲用の残りがマイナスの詰口: {$overSample} 件";
}

$lines = [];
foreach (AlertService::make()->all() as $a) {
    $lines[] = sprintf('[%s] %s — %s', ['danger' => '要対応', 'warn' => '注意', 'info' => '情報'][$a->severity], $a->title, $a->detail);
    if ($a->severity !== 'info') {
        $problems[] = $a->title;
    }
}
if (!$quiet || $problems !== []) {
    fwrite(STDOUT, $problems === [] ? "問題はありません\n" : "確認が必要な点があります\n");
    foreach ($lines as $l) {
        fwrite(STDOUT, "  {$l}\n");
    }
}
exit($problems === [] ? 0 : 1);
