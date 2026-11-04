<?php
declare(strict_types=1);

// 全ページが最初に読むファイル。
// 本番では _app_root.php（アプリ本体の場所を返す。Git に入れない）を公開フォルダに置く。なければリポジトリの配置（public の1つ上）。

$root = is_file(__DIR__ . '/_app_root.php') ? (string) require __DIR__ . '/_app_root.php' : dirname(__DIR__);

// 本体が見つからないときは、Fatal error ではなく直し方を出す
if (!is_file($root . '/src/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $here = htmlspecialchars(__DIR__, ENT_QUOTES);
    $tried = htmlspecialchars($root, ENT_QUOTES);
    echo <<<HTML
<!doctype html><meta charset="utf-8"><title>設定が必要です</title>
<div style="font-family:sans-serif;padding:2rem;max-width:720px;line-height:1.7">
<h1>アプリ本体の場所が分かりません</h1>
<p>探した場所: <code>{$tried}/src/bootstrap.php</code></p>
<p>アプリ本体（src・templates・migrations・tools・config・var）を<strong>公開フォルダの外</strong>に置き、
この公開フォルダ（<code>{$here}</code>）に <code>_app_root.php</code> を作って、その場所を書いてください。</p>
<pre style="background:#f3ece4;padding:1rem">&lt;?php
return '/home/ユーザー名/…/analyze-app';</pre>
</div>
HTML;
    exit;
}

define('APP_ROOT', $root);
define('PUBLIC_ROOT', __DIR__);   // 公開フォルダ（assets の版を調べるのに使う）
require APP_ROOT . '/src/bootstrap.php';

// 反映中は 503（IMPLEMENTATION.md 11.3）
if (is_file(APP_ROOT . '/var/maintenance.flag')) {
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>反映中</title><p style="font-family:sans-serif;padding:2rem">ただいま更新中です。1分ほどしてから読み込み直してください。</p>';
    exit;
}

// DB がまだ無い・空のときは、勝手に空のDBを作らずに直し方を出す
$dbPath = (string) App\Config::get('db_path');
if (!is_file($dbPath) || filesize($dbPath) === 0) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>DBがありません</title><div style="font-family:sans-serif;padding:2rem;line-height:1.7">'
        . '<h1>データベースがありません</h1><p>設定の場所: <code>' . htmlspecialchars($dbPath, ENT_QUOTES) . '</code></p>'
        . '<p>移行したDBファイル（analyze.db）をこの場所に置くか、SSH で <code>php tools/migrate.php</code> を実行してください。'
        . '置き場所は <code>config/config.local.php</code> の <code>db_path</code> で変えられます。</p></div>';
    exit;
}

App\Http\Headers::apply();

// 想定外のエラーはログに書き、画面には ID だけ出す（IMPLEMENTATION.md 8.5）
set_exception_handler(static function (Throwable $e): void {
    $id = bin2hex(random_bytes(4));
    $dir = (string) App\Config::get('log_dir');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($dir . '/app-' . date('Y-m') . '.log', sprintf(
        "[%s] %s %s %s\n%s: %s\n%s\n\n",
        date('c'), $id, $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '', get_class($e), $e->getMessage(), $e->getTraceAsString()
    ), FILE_APPEND);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $detail = App\Config::isProd() ? '' : '<pre style="white-space:pre-wrap">' . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine(), ENT_QUOTES) . '</pre>';
    echo '<!doctype html><meta charset="utf-8"><title>エラー</title><div style="font-family:sans-serif;padding:2rem"><h1>エラーが起きました</h1><p>問い合わせ用ID: ' . $id . '</p><p><a href="/">入口へ戻る</a></p>' . $detail . '</div>';
});

ini_set('display_errors', App\Config::isProd() ? '0' : '1');
