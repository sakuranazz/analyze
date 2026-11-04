<?php
// 認証が必要なページの共通ヘッダ。使う前に $pageTitle を設定する。
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
requireLogin($pdo);
header('X-Robots-Tag: noindex, nofollow');
$current = basename($_SERVER['SCRIPT_NAME']);

$navItems = [
    ['label' => '日々の入力', 'items' => [
        ['分析結果入力', 'analysis_input.php'],
        ['工程入力', 'process_input.php'],
        ['詰口・出荷入力', 'bottling_input.php'],
        ['SKU・ロット作番', 'codes.php'],
    ]],
    ['label' => '帳簿・集計', 'items' => [
        ['分析結果一覧', 'analysis_results.php'],
        ['在庫・棚卸し', 'stocktake.php'],
        ['詰口台帳', 'bottling_results.php'],
        ['ロット原価', 'lot_cost.php'],
        ['SKU・ロット一覧', 'sku_lot_list.php'],
        ['酒税', 'tax.php'],
    ]],
    ['label' => '設定', 'items' => [
        ['マスタ管理', 'masters.php'],
        ['パスワード変更', 'change_password.php'],
    ]],
];
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($pageTitle ?? '醸造管理システム') ?> | 醸造管理システム</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
    <a href="index.php" class="brand">醸造管理システム</a>
    <nav>
        <?php foreach ($navItems as $group): ?>
            <div class="nav-group">
                <span class="nav-label"><?= h($group['label']) ?></span>
                <?php foreach ($group['items'] as [$label, $href]): ?>
                    <a href="<?= h($href) ?>" class="<?= $current === $href ? 'active' : '' ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <a href="logout.php" class="logout">ログアウト</a>
    </nav>
</header>
<main class="container">
<?= renderFlash() ?>
