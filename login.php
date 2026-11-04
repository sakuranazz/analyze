<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
startSecureSession();
header('X-Robots-Tag: noindex, nofollow');

$setup = !passwordIsSet($pdo);   // 初回: パスワード設定
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $pw = (string)($_POST['password'] ?? '');
    if ($setup) {
        if (strlen($pw) < 8) {
            $error = 'パスワードは8文字以上にしてください。';
        } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
            $error = '確認用パスワードが一致しません。';
        } else {
            savePassword($pdo, $pw);
            attemptLogin($pdo, $pw);
            redirect('index.php');
        }
    } elseif (attemptLogin($pdo, $pw)) {
        redirect('index.php');
    } else {
        sleep(1);
        $error = 'パスワードが違います。';
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ログイン | 醸造管理システム</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-box panel">
    <h1>醸造管理システム</h1>
    <?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <?= csrfField() ?>
        <?php if ($setup): ?>
            <p class="muted">初回です。ログイン用パスワードを設定してください(8文字以上)。</p>
        <?php endif; ?>
        <label for="password">パスワード</label>
        <input type="password" id="password" name="password" required autofocus autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>">
        <?php if ($setup): ?>
            <label for="password2">パスワード(確認)</label>
            <input type="password" id="password2" name="password2" required autocomplete="new-password">
        <?php endif; ?>
        <p><button class="btn" type="submit"><?= $setup ? '設定してログイン' : 'ログイン' ?></button></p>
    </form>
</div>
</body>
</html>
