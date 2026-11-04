<?php
declare(strict_types=1);

namespace App\Http;

use App\Config;

final class Headers
{
    public static function apply(): void
    {
        header('X-Robots-Tag: noindex, nofollow');           // CLAUDE.md 2章
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        // スクリプトは厳格に（外部CDNなし）。スタイルはバー幅などの style="" のため 'unsafe-inline' を許す（IMPLEMENTATION.md 8.3）
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (Config::isProd()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
