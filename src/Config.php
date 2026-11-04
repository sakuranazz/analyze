<?php
declare(strict_types=1);

namespace App;

final class Config
{
    /** @var array<string, mixed> */
    private static array $values = [];

    public static function load(): void
    {
        $values = require APP_ROOT . '/config/config.php';
        $local = APP_ROOT . '/config/config.local.php';
        if (is_file($local)) {
            $values = array_replace($values, require $local);
        }
        // テストなどから上書きできるように環境変数も見る
        $db = getenv('ANALYZE_DB_PATH');
        if (is_string($db) && $db !== '') {
            $values['db_path'] = $db;
        }
        self::$values = $values;
    }

    public static function get(string $key): mixed
    {
        if (!array_key_exists($key, self::$values)) {
            throw new \RuntimeException("設定 {$key} がありません");
        }
        return self::$values[$key];
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }

    public static function isProd(): bool
    {
        return self::get('env') === 'prod';
    }
}
