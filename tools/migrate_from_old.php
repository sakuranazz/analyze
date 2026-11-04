<?php
declare(strict_types=1);

/**
 * 旧DB → 新DB の移行（CLAUDE.md 7章）
 *   php tools/migrate_from_old.php --old=旧DBのコピー --out=新DB [--report=report.md] [--allow-unresolved]
 * - 旧DBは読み取り専用で開く。--out は毎回作り直す（既存なら削除）。
 * - 判断が要る項目は config/migration_decisions.php。未回答があると止まる（終了コード 2）。
 *   --allow-unresolved（リハーサル）は未回答を仮のロットに置いて最後まで取り込む。
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db\Connection;
use App\Db\Migrator;
use App\Ops\LegacyImport;

$opt = getopt('', ['old:', 'out:', 'report::', 'allow-unresolved']);
if (!isset($opt['old'], $opt['out'])) {
    fwrite(STDERR, "使い方: php tools/migrate_from_old.php --old=旧DB --out=新DB [--report=report.md] [--allow-unresolved]\n");
    exit(1);
}
$old = (string) $opt['old'];
$out = (string) $opt['out'];
if (realpath($old) !== false && realpath($old) === realpath($out)) {
    fwrite(STDERR, "旧DBと新DBが同じファイルです\n");
    exit(1);
}
if (is_file($out)) {
    unlink($out);
}
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0700, true);
}
$db = Connection::open($out);
(new Migrator($db, APP_ROOT . '/migrations'))->migrate();

$import = new LegacyImport(LegacyImport::openOld($old), $db, require APP_ROOT . '/config/migration_decisions.php', isset($opt['allow-unresolved']));
$ok = $import->run();
$md = $import->markdown();
$reportPath = is_string($opt['report'] ?? null) ? $opt['report'] : null;
if ($reportPath !== null) {
    file_put_contents($reportPath, $md);
    fwrite(STDOUT, "報告: {$reportPath}\n");
} else {
    fwrite(STDOUT, $md);
}
if (!$ok) {
    unlink($out);
    fwrite(STDERR, '未解決が ' . count($import->unresolved) . " 件あるため、取り込みを止めました（--allow-unresolved でリハーサルできます）\n");
    exit(2);
}
fwrite(STDOUT, "新DB: {$out}\n");
exit($import->unresolved === [] ? 0 : 2);
