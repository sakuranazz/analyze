<?php
declare(strict_types=1);

// 使い方: php tools/migrate.php [--no-backup]
// 既存のDBに適用するときは、適用の前に自動でバックアップを取る（IMPLEMENTATION.md 5.2）。

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Config;
use App\Db\Connection;
use App\Db\Migrator;
use App\Ops\Backup;

$path = (string) Config::get('db_path');
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0700, true);
}

if (is_file($path) && filesize($path) > 0 && !in_array('--no-backup', $argv, true)) {
    $file = (new Backup(Connection::open($path), (string) Config::get('backup_dir')))->run('pre-migrate');
    fwrite(STDOUT, "バックアップ: {$file}\n");
}

$done = (new Migrator(Connection::open($path), APP_ROOT . '/migrations'))->migrate();
fwrite(STDOUT, $done === [] ? "適用するマイグレーションはありません\n" : '適用: ' . implode(', ', $done) . "\n");
