<?php
// SQLite 接続。初回はスキーマを自動作成する。
$dbPath = getenv('ANALYZE_DB') ?: __DIR__ . '/../data/analyze.db';
$isNew = !file_exists($dbPath);

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA busy_timeout = 5000');

if ($isNew) {
    $pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));
}
