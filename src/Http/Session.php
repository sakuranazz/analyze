<?php
declare(strict_types=1);

namespace App\Http;

use App\Config;

/** セッション・CSRF・フラッシュ。認証は Xserver の Basic 認証だけ（CLAUDE.md 2章） */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('analyze_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) Config::get('base_path'),
            'secure' => Config::isProd(),     // 手元の http では付けない
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        self::start();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(Request $req): void
    {
        $sent = $req->header('x-csrf-token') ?? $req->str('_csrf');
        if ($sent === '' || !hash_equals(self::csrfToken(), $sent)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo '送信の確認に失敗しました。画面を読み込み直してから、もう一度操作してください。';
            exit;
        }
    }

    public static function flash(string $message, string $type = 'ok'): void
    {
        self::start();
        $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
    }

    /** @return list<array{message:string, type:string}> */
    public static function takeFlash(): array
    {
        self::start();
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return is_array($f) ? $f : [];
    }

    /** 入力画面で「前回の値」を残すため（分析の連続入力など） */
    public static function remember(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['remember'][$key] = $value;
    }

    public static function recall(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION['remember'][$key] ?? $default;
    }
}
