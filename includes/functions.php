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

// ===== 在庫台帳 =====
/** 種別(materials.kind)で資材を取得。$kinds は配列。null なら全資材 */
function materialsByKind(PDO $pdo, ?array $kinds = null): array {
    $sql = 'SELECT id, name, unit, kind FROM materials WHERE is_active = 1';
    $args = [];
    if ($kinds) {
        $sql .= ' AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
        $args = $kinds;
    }
    $st = $pdo->prepare($sql . ' ORDER BY category_id, id');
    $st->execute($args);
    return $st->fetchAll();
}

/** 在庫台帳に1行追加(正=入庫 負=出庫)。トランザクションは呼び出し側で管理 */
function addMovement(PDO $pdo, string $date, int $materialId, float $qty, string $reason,
    ?int $processId = null, ?int $lotId = null, ?float $amountYen = null, ?int $supplierId = null, ?string $note = null,
    ?int $bottlingEventId = null): void {
    $st = $pdo->prepare('INSERT INTO stock_movements
        (entry_date, material_id, qty, reason, amount_yen, process_id, lot_id, supplier_id, note, bottling_event_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$date, $materialId, $qty, $reason, $amountYen, $processId, $lotId, $supplierId, $note, $bottlingEventId]);
}

/** 数量の表示用整形(浮動小数の誤差を丸め、末尾の0を除く) */
function fmtQty($v): string {
    $s = rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

function validDate(string $d): bool {
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// ===== 年度(酒類の報告用。開始月は設定 fiscal_start_month、既定4月) =====
function fiscalStartMonth(PDO $pdo): int {
    $m = (int)getSetting($pdo, 'fiscal_start_month', 4);
    return ($m >= 1 && $m <= 12) ? $m : 4;
}

/** 日付が属する年度の開始年 */
function fiscalYearOf(string $date, int $startMonth): int {
    $y = (int)substr($date, 0, 4);
    return (int)substr($date, 5, 2) >= $startMonth ? $y : $y - 1;
}

/** 年度開始年 → [開始日, 終了日] */
function fiscalRange(int $startYear, int $startMonth): array {
    $start = new DateTime(sprintf('%04d-%02d-01', $startYear, $startMonth));
    $end = (clone $start)->modify('+1 year')->modify('-1 day');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function fiscalYearLabel(int $startYear, int $startMonth): string {
    [$s, $e] = fiscalRange($startYear, $startMonth);
    return $startMonth === 1 ? "{$startYear}年" : "{$startYear}年度(" . (int)substr($s, 5, 2) . '月〜' . (int)substr($e, 0, 4) . '年' . (int)substr($e, 5, 2) . '月)';
}
