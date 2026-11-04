<?php
declare(strict_types=1);

/**
 * 旧DBから、テスト用の SQLite（tests/fixtures/old_sample.db）を作る（IMPLEMENTATION.md 9.4）
 * 住所・相手先の名前・パスワードハッシュを消す。数量・金額・日付は残す（突合のテストに要る）
 *   php tools/make_fixture.php --old=旧DBのコピー [--out=tests/fixtures/old_sample.db]
 */

$opt = getopt('', ['old:', 'out::']);
if (!isset($opt['old'])) {
    fwrite(STDERR, "使い方: php tools/make_fixture.php --old=旧DB [--out=FILE]\n");
    exit(1);
}
$out = is_string($opt['out'] ?? null) ? $opt['out'] : dirname(__DIR__) . '/tests/fixtures/old_sample.db';
@unlink($out);
copy((string) $opt['old'], $out);
$pdo = new PDO('sqlite:' . $out, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("UPDATE inventory_ledger SET partner_name = CASE WHEN partner_name IS NULL THEN NULL ELSE '仕入先' || id END, partner_address = NULL, note = NULL");
$pdo->exec("UPDATE suppliers SET name = '仕入先' || id, address = NULL");
$pdo->exec('UPDATE sales_channels SET address = NULL');
$pdo->exec('UPDATE bottling_entries SET address = NULL');
$pdo->exec("DELETE FROM app_settings WHERE setting_key LIKE '%password%' OR setting_key LIKE '%hash%'");
$pdo->exec('VACUUM');
fwrite(STDOUT, "テスト用DB: {$out}\n");
