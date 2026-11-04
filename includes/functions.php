<?php
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function getSetting(PDO $pdo, string $key, $default = null) {
    $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function setSetting(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare('INSERT INTO settings(key, value) VALUES(?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $st->execute([$key, $value]);
}

function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'][] = [$type, $msg];
}

function renderFlash(): string {
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $out .= '<div class="flash ' . h($type) . '">' . h($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">';
}

function checkCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('不正なリクエストです(CSRF)。画面を開き直してください。');
    }
}

function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}
