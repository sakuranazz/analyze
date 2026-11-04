<?php
$pageTitle = 'パスワード変更';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $old = (string)($_POST['old'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify($old, (string)getSetting($pdo, 'password_hash'))) {
        flash('現在のパスワードが違います。', 'error');
    } elseif (strlen($new) < 8) {
        flash('新しいパスワードは8文字以上にしてください。', 'error');
    } elseif ($new !== (string)($_POST['new2'] ?? '')) {
        flash('確認用パスワードが一致しません。', 'error');
    } else {
        savePassword($pdo, $new);
        flash('パスワードを変更しました。');
    }
    redirect('change_password.php');
}
?>
<h1>パスワード変更</h1>
<div class="panel">
<form method="post" class="stack">
    <?= csrfField() ?>
    <label>現在のパスワード</label>
    <input type="password" name="old" required autocomplete="current-password">
    <label>新しいパスワード(8文字以上)</label>
    <input type="password" name="new" required autocomplete="new-password">
    <label>新しいパスワード(確認)</label>
    <input type="password" name="new2" required autocomplete="new-password">
    <p><button class="btn" type="submit">変更する</button></p>
</form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
