<?php
// ログインは1つ。パスワードは settings.password_hash に保存。
function startSecureSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function passwordIsSet(PDO $pdo): bool {
    return getSetting($pdo, 'password_hash') !== null;
}

function requireLogin(PDO $pdo): void {
    startSecureSession();
    if (!passwordIsSet($pdo) || empty($_SESSION['logged_in'])) {
        redirect('login.php');
    }
}

function attemptLogin(PDO $pdo, string $password): bool {
    $hash = getSetting($pdo, 'password_hash');
    if ($hash === null || !password_verify($password, $hash)) return false;
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    return true;
}

function savePassword(PDO $pdo, string $password): void {
    setSetting($pdo, 'password_hash', password_hash($password, PASSWORD_DEFAULT));
}
