<?php
declare(strict_types=1);

namespace App\Http;

use App\Config;

final class Url
{
    /** base_path からの相対パス → URL（絶対パスを直書きしない。IMPLEMENTATION.md 8.1） @param array<string, scalar|null> $query */
    public static function to(string $path, array $query = []): string
    {
        $base = rtrim((string) Config::get('base_path'), '/') . '/';
        $url = $base . ltrim($path, '/');
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $url;
    }

    /** 今のページのパス（base_path 以降） */
    public static function current(): string
    {
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = rtrim((string) Config::get('base_path'), '/');
        return ltrim(substr($uri, strlen($base)), '/');
    }
}
