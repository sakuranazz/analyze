<?php
declare(strict_types=1);

/**
 * 復元の確認（IMPLEMENTATION.md 10.3）。復元先は必ず別ファイルにつくり、本番の差し替えは手で行う
 *   php tools/restore.php --from=バックアップ --to=復元先        別ファイルに復元して中身を確かめる
 *   php tools/restore.php --verify                              最新の月次バックアップを一時ファイルに復元して確かめる（月1回）
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Config;
use App\Db\Connection;

$opt = getopt('', ['from:', 'to:', 'verify']);
if (isset($opt['verify'])) {
    $files = glob(rtrim((string) Config::get('backup_dir'), '/') . '/{monthly,daily}/analyze-*.db', GLOB_BRACE) ?: [];
    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    if ($files === []) {
        fwrite(STDOUT, "バックアップが見つかりません\n");
        exit(1);
    }
    $opt['from'] = $files[0];
    $opt['to'] = sys_get_temp_dir() . '/analyze-restore-check-' . getmypid() . '.db';
}
if (!isset($opt['from'], $opt['to'])) {
    fwrite(STDERR, "使い方: php tools/restore.php --from=FILE --to=FILE | --verify\n");
    exit(1);
}
$from = (string) $opt['from'];
$to = (string) $opt['to'];
if (realpath($to) !== false && realpath($to) === realpath((string) Config::get('db_path'))) {
    fwrite(STDERR, "本番のDBへは直接復元しません。別のファイルに復元してから、手で差し替えてください。\n");
    exit(1);
}
if (is_file($to)) {
    fwrite(STDERR, "復元先がすでにあります: {$to}\n");
    exit(1);
}
copy($from, $to);
$db = Connection::open($to);
$ok = $db->value('PRAGMA integrity_check');
fwrite(STDOUT, "復元元: {$from}\n復元先: {$to}\n整合性: {$ok}\n");
foreach (['processes', 'process_lines', 'lots', 'analysis_results', 'bottling_sessions', 'product_movements', 'tax_months'] as $t) {
    fwrite(STDOUT, sprintf("  %-20s %d 件\n", $t, (int) $db->value("SELECT COUNT(*) FROM {$t}")));
}
fwrite(STDOUT, '最新の工程日: ' . $db->value('SELECT MAX(process_date) FROM processes') . "\n");
if (isset($opt['verify'])) {
    unlink($to);
}
exit($ok === 'ok' ? 0 : 1);
