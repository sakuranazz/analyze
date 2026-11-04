<?php
declare(strict_types=1);

/**
 * バックアップ（cron から毎日）。正常時は何も出力しない（Xserver の cron は出力があるときだけメールするため）
 *   php tools/backup.php                    日次（30日分）＋月次（無期限）
 *   php tools/backup.php --label pre-deploy 反映前などの都度（manual/、消さない）
 * cron の例（PHP のパスは Xserver のパネルで確認）:
 *   15 3 * * * /usr/bin/php8.3 /home/ACCOUNT/analyze-app/tools/backup.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Config;
use App\Db\Connection;
use App\Ops\Backup;

$opt = getopt('', ['label:', 'verbose']);
try {
    $file = (new Backup(Connection::open((string) Config::get('db_path')), (string) Config::get('backup_dir')))->run($opt['label'] ?? null);
    if (isset($opt['verbose']) || isset($opt['label'])) {
        fwrite(STDOUT, "バックアップ: {$file}\n");
    }
} catch (Throwable $e) {
    $dir = (string) Config::get('log_dir');
    @mkdir($dir, 0700, true);
    @file_put_contents($dir . '/backup-' . date('Y-m') . '.log', '[' . date('c') . '] ' . $e->getMessage() . "\n", FILE_APPEND);
    fwrite(STDOUT, 'バックアップに失敗しました: ' . $e->getMessage() . "\n");
    exit(1);
}
