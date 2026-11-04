<?php
declare(strict_types=1);

/**
 * サーバーの環境確認（IMPLEMENTATION.md 段階0）。Xserver で1回実行して、結果を貼ってください
 *   php tools/env_check.php
 * DB には触れない（一時ファイルだけを使う）。
 */

$ok = true;
$line = static function (string $label, bool $pass, string $detail) use (&$ok): void {
    $ok = $ok && $pass;
    fwrite(STDOUT, sprintf("%s %-28s %s\n", $pass ? '✓' : '✗', $label, $detail));
};

$line('PHP のバージョン', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION . '（8.2 以上が必要）');
$line('PHP の実行ファイル', true, PHP_BINARY . '（cron にはこのパスを書く）');
foreach (['pdo_sqlite', 'mbstring', 'json', 'sodium'] as $ext) {
    $line("拡張 {$ext}", extension_loaded($ext) || $ext === 'sodium', extension_loaded($ext) ? 'あり' : ($ext === 'sodium' ? 'なし（外部バックアップの暗号化にだけ使う）' : 'なし'));
}
$tmp = sys_get_temp_dir() . '/analyze-envcheck-' . getmypid();
try {
    $pdo = new PDO('sqlite:' . $tmp . '.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $v = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
    $line('SQLite のバージョン', version_compare($v, '3.27.0', '>='), $v . '（VACUUM INTO に 3.27 以上が必要）');
    $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $pdo->exec('BEGIN IMMEDIATE');
    $pdo->exec("INSERT INTO t (v) VALUES ('x')");
    $pdo->exec('SAVEPOINT sp1');
    $pdo->exec('ROLLBACK TO sp1');
    $pdo->exec('RELEASE sp1');
    $pdo->exec('COMMIT');
    $line('BEGIN IMMEDIATE / SAVEPOINT', true, '動作します');
    $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp . '-copy.db'));
    $line('VACUUM INTO', is_file($tmp . '-copy.db'), 'バックアップに使えます');
    $line('式インデックス・部分インデックス', (bool) $pdo->exec('CREATE UNIQUE INDEX ux ON t(COALESCE(v, 0)) WHERE id > 0') || true, '使えます');
    $jm = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();
    $line('journal_mode', true, $jm);
} catch (Throwable $e) {
    $line('SQLite', false, $e->getMessage());
} finally {
    @unlink($tmp . '.db');
    @unlink($tmp . '-copy.db');
}
$line('タイムゾーン', true, date_default_timezone_get() . '（アプリは Asia/Tokyo に固定します）');
$line('一時ディレクトリ', is_writable(sys_get_temp_dir()), sys_get_temp_dir());
fwrite(STDOUT, $ok ? "\nこのサーバーで動かせます。\n" : "\n✗ の項目を確認してください。\n");
exit($ok ? 0 : 1);
