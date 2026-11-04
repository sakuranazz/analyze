<?php
declare(strict_types=1);

// 既定の設定。手元・本番の差分は config.local.php（.gitignore）に書く。
// パスワード・鍵・接続先の情報はここにも config.local.php にも書かない（CLAUDE.md 0-3）。
return [
    // dev | prod。prod のとき Secure Cookie・HSTS・エラー非表示
    'env' => 'dev',
    // SQLite ファイル。本番は公開ディレクトリとデプロイ転送先の外に置く
    'db_path' => APP_ROOT . '/var/data/analyze.db',
    'backup_dir' => APP_ROOT . '/var/backup',
    'log_dir' => APP_ROOT . '/var/log',
    'export_dir' => APP_ROOT . '/var/exports',
    // 公開URLのパス（サブパスに置く場合は '/analyze/' など）
    'base_path' => '/',
    'timezone' => 'Asia/Tokyo',
    // 金額の丸め: 'down'（円未満切り捨て）| 'half_up'（四捨五入）。IMPLEMENTATION.md 前提 #5
    'yen_rounding' => 'down',
    // 蔵名（印刷用の見出し）
    'brewery_name' => 'にっこにこ太陽酒造',
];
