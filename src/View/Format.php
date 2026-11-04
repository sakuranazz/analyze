<?php
declare(strict_types=1);

namespace App\View;

use App\Domain\DateUtil;
use App\Domain\Num;
use App\Domain\Qty;
use App\Domain\Rounding;
use App\Http\Session;
use App\Http\Url;

/**
 * 表示用の整形（DESIGN.md 8章の ui.php に相当）。画面で number_format を直接呼ばない。
 * 計算はしない（Domain の仕事）。
 */
final class Format
{
    public static function h(mixed $s): string
    {
        return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function yen(?int $yen): string
    {
        return $yen === null ? '—' : '¥' . number_format($yen);
    }

    /** 数量（qty_milli）→ "1.34" */
    public static function qty(?int $milli): string
    {
        return $milli === null ? '—' : Qty::format($milli);
    }

    /** 単価（micro）→ "¥1,820.24"（小数は2桁、必要なら4桁） */
    public static function price(?int $micro): string
    {
        if ($micro === null) {
            return '—';
        }
        $cents = Num::divRound($micro, 10_000, Rounding::HalfUp);   // 0.01円単位
        if ($micro !== 0 && abs($micro) < 10_000_000) {   // ¥10 未満は小数4桁（乳酸 ¥1.1354/ml など）
            return '¥' . Qty::format(Num::divRound($micro, 100, Rounding::HalfUp), 10_000, 2);
        }
        return '¥' . Qty::format($cents, 100, 2);
    }

    /** 液量 ml → "92.70"（L、小数2桁） */
    public static function liters(?int $ml): string
    {
        return $ml === null ? '—' : Qty::format($ml, 1000, 2);
    }

    public static function ml(?int $ml): string
    {
        return $ml === null ? '—' : number_format($ml);
    }

    /** 分析値（REAL） */
    public static function num(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        $s = rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    public static function dateWithDay(string $date): string
    {
        return $date . '（' . DateUtil::weekdayJa($date) . '）';
    }

    public static function monthJa(string $ym): string
    {
        return sprintf('%d年%d月', (int) substr($ym, 0, 4), (int) substr($ym, 5, 2));
    }

    /** 公開フォルダの assets の場所。本番は公開フォルダ（_boot.php のある場所）、開発はリポジトリの public */
    private static function assetsDir(): string
    {
        return (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : APP_ROOT . '/public') . '/assets';
    }

    /**
     * assets 全体の版（いちばん新しいファイルの更新時刻）。
     * JS・CSS は中で別ファイルを読み込むため、1ファイルでも変わったら全部の URL を変えて、古いキャッシュを使わせない。
     */
    public static function assetVersion(): string
    {
        static $v = null;
        if ($v === null) {
            $max = 0;
            $dir = self::assetsDir();
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $max = max($max, (int) $f->getMTime());
                }
            }
            $v = (string) $max;
        }
        return $v;
    }

    public static function asset(string $path): string
    {
        return Url::to('assets/' . $path) . '?v=' . self::assetVersion();
    }

    /** CSS（@layer の順を固定し、各ファイルに版を付けて読み込む。main.css の @import だと版が付かないため） */
    public static function styles(): string
    {
        $files = ['reset', 'tokens', 'base', 'layout', 'components', 'pages', 'print'];
        $css = '@layer ' . implode(', ', $files) . ";\n";
        foreach ($files as $f) {
            $css .= '@import url("' . self::h(self::asset('css/' . $f . '.css')) . '") layer(' . $f . ");\n";
        }
        return "<style>\n" . $css . '</style>';
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::h(Session::csrfToken()) . '">';
    }

    /** 線画アイコン（currentColor）。名前は許可リスト */
    public static function icon(string $name, int $size = 18): string
    {
        $paths = [
            'analysis' => 'M3 17l5-6 4 4 8-9', 'bottle' => 'M9 3h6v4l2 3v11H7V10l2-3z', 'process' => 'M4 6h16M4 12h16M4 18h10',
            'chart' => 'M4 20V10M10 20V4M16 20v-7', 'book' => 'M5 4h14v16H5zM9 9h6M9 13h6', 'box' => 'M3 8l9-5 9 5v10l-9 5-9-5zM3 8l9 5 9-5',
            'grid' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z', 'sheet' => 'M6 3h12v18H6zM9 8h6M9 12h6M9 16h3',
            'tax' => 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 7v10M9 10h6', 'gear' => 'M12 8a4 4 0 100 8 4 4 0 000-8zM12 2v3M12 19v3M2 12h3M19 12h3',
            'warn' => 'M12 3l10 18H2zM12 10v5M12 18v.5', 'stop' => 'M12 3a9 9 0 100 18 9 9 0 000-18zM8 8l8 8', 'ok' => 'M5 12l5 5 9-10',
            'pencil' => 'M4 20l4-1 11-11-3-3L5 16z', 'arrow' => 'M5 12h14M13 6l6 6-6 6', 'plus' => 'M12 5v14M5 12h14', 'back' => 'M15 5l-7 7 7 7',
            'trash' => 'M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13', 'print' => 'M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z', 'download' => 'M12 4v11M7 10l5 5 5-5M5 20h14',
            'info' => 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 11v6M12 7.5v.5', 'menu' => 'M4 6h16M4 12h16M4 18h16', 'x' => 'M6 6l12 12M18 6L6 18',
            'dot' => 'M12 8a4 4 0 100 8 4 4 0 000-8z', 'circle' => 'M12 6a6 6 0 100 12 6 6 0 000-12z', 'home' => 'M4 11l8-7 8 7v9h-5v-6h-6v6H4z',
        ];
        $d = $paths[$name] ?? $paths['dot'];
        return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
    }

    /** バッジ（必ずアイコン＋文言。DESIGN.md 5章） */
    public static function badge(string $kind, string $text): string
    {
        $icon = ['ok' => 'ok', 'warn' => 'warn', 'danger' => 'stop', 'info' => 'info', 'muted' => 'circle', 'active' => 'dot'][$kind] ?? 'dot';
        return '<span class="badge badge--' . self::h($kind) . '">' . self::icon($icon, 13) . self::h($text) . '</span>';
    }

    public static function code(?string $code): string
    {
        if ($code === null || $code === '') {
            return '<span class="muted">—</span>';
        }
        return '<button type="button" class="chip" data-copy="' . self::h($code) . '" title="クリックでコピー">' . self::h($code) . '</button>';
    }

    /** 数値セル: マイナスは赤・ゼロは薄く */
    public static function numCell(string $text, int|float|null $value): string
    {
        $cls = $value === null ? 'muted' : ($value < 0 ? 'neg' : ($value == 0 ? 'zero' : ''));
        return '<td class="num ' . $cls . '">' . ($value !== null && $value < 0 ? '−' . self::h(ltrim($text, '-')) : self::h($text)) . '</td>';
    }

    /** @param array<string, string> $errors */
    public static function error(array $errors, string $field): string
    {
        return isset($errors[$field]) ? '<p class="field-error" id="err-' . self::h($field) . '">' . self::icon('warn', 14) . self::h($errors[$field]) . '</p>' : '';
    }

    /** @param array<string, string> $errors */
    public static function invalid(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . self::h($field) . '"' : '';
    }

    public static function selected(mixed $a, mixed $b): string
    {
        return (string) $a === (string) $b ? ' selected' : '';
    }

    public static function checked(bool $v): string
    {
        return $v ? ' checked' : '';
    }
}
