<?php
declare(strict_types=1);

namespace App\Http;

use App\Config;

final class Response
{
    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * CSV（UTF-8 BOM・CRLF。DESIGN.md 7章）。同じ内容の控えを var/exports/ に保存する（IMPLEMENTATION.md 6.4）
     * @param list<list<string|int|float|null>> $rows 1行目は見出し
     */
    public static function csv(string $filename, array $rows): never
    {
        $body = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $body .= implode(',', array_map([self::class, 'csvCell'], $row)) . "\r\n";
        }
        $dir = (string) Config::get('export_dir');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $base = preg_replace('/\.csv$/', '', $filename);
        @file_put_contents($dir . '/' . $base . '_' . date('Ymd\THis') . '.csv', $body);

        header('Content-Type: text/csv; charset=utf-8');
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($filename));
        echo $body;
        exit;
    }

    public static function csvCell(string|int|float|null $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        // 式の混入防止: = + - @ で始まる文字列は ' を付ける
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v)) {
            $v = "'" . $v;
        }
        return '"' . str_replace('"', '""', $v) . '"';
    }
}
