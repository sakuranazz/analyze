<?php
declare(strict_types=1);

// 全ての入口（public/_boot.php、tools/*.php、tests）が最初に読むファイル。

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// オートローダ: App\Foo\Bar → src/Foo/Bar.php（Composer の vendor/ に依存しない）
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Config::load();
date_default_timezone_set(App\Config::get('timezone'));
mb_internal_encoding('UTF-8');

// 警告も例外にする（黙って続行しない）
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
