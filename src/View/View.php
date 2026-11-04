<?php
declare(strict_types=1);

namespace App\View;

/** テンプレート描画（templates/）。テンプレートでは Format::h() でエスケープする */
final class View
{
    /** ページ全体（layout.php で包む） @param array<string, mixed> $vars */
    public static function page(string $template, array $vars = []): string
    {
        $content = self::fragment($template, $vars);
        return self::fragment('layout', $vars + ['content' => $content]);
    }

    /** 印刷用画面（templates/print/layout.php で包む） @param array<string, mixed> $vars */
    public static function printPage(string $template, array $vars = []): string
    {
        $content = self::fragment($template, $vars);
        return self::fragment('print/layout', $vars + ['content' => $content]);
    }

    /** 部品だけ（インライン編集の行の断片など） @param array<string, mixed> $vars */
    public static function fragment(string $template, array $vars = []): string
    {
        $file = APP_ROOT . '/templates/' . $template . '.php';
        if (preg_match('#^[a-z0-9_/]+$#', $template) !== 1 || !is_file($file)) {
            throw new \RuntimeException("テンプレートがありません: {$template}");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
