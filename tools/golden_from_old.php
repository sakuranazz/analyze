<?php
declare(strict_types=1);

/**
 * 旧システムの棚卸し計算（includes/valuation.php の computeInventoryValuation）を、旧DBのコピーに対して
 * 実際に動かし、基準日ごとの資材の数量・金額を JSON に保存する（IMPLEMENTATION.md 9.3）。
 * 同じ人が旧ロジックを書き直して比べるのではなく、旧コードそのものの値を突合の基準にする。
 *
 *   php tools/golden_from_old.php --old-code=旧コードのフォルダ --old-db=旧DBのコピー --out=tests/golden/old_values.json [--dates=2026-08-31,2026-10-31]
 *
 * 旧コードはこのプロセスでだけ読み込む（新システムのクラスとは混ぜない）。
 */

$opt = getopt('', ['old-code:', 'old-db:', 'out:', 'dates::']);
if (!isset($opt['old-code'], $opt['old-db'], $opt['out'])) {
    fwrite(STDERR, "使い方: php tools/golden_from_old.php --old-code=DIR --old-db=DB --out=JSON [--dates=YYYY-MM-DD,...]\n");
    exit(1);
}
$valuation = rtrim((string) $opt['old-code'], '/') . '/includes/valuation.php';
if (!is_file($valuation)) {
    fwrite(STDERR, "旧コードに includes/valuation.php がありません: {$valuation}\n");
    exit(1);
}
require $valuation;

$pdo = new PDO('sqlite:file:' . $opt['old-db'] . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA query_only = ON');

if (isset($opt['dates']) && is_string($opt['dates']) && $opt['dates'] !== '') {
    $dates = explode(',', $opt['dates']);
} else {
    // 最初の記録の月から今日まで、各月末 ＋ 今日
    $first = (string) $pdo->query('SELECT MIN(entry_date) FROM inventory_ledger')->fetchColumn();
    $d = new DateTimeImmutable(substr($first, 0, 7) . '-01');
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $dates = [];
    while ($d->format('Y-m-d') <= $today) {
        $end = $d->modify('last day of this month')->format('Y-m-d');
        $dates[] = min($end, $today);
        $d = $d->modify('+1 month');
    }
    $dates[] = $today;
}

$names = array_column($pdo->query('SELECT id, name FROM materials')->fetchAll(), 'name', 'id');
$result = computeInventoryValuation($pdo, $dates);
$out = ['generated_at' => date('c'), 'source' => 'includes/valuation.php computeInventoryValuation（旧・先入先出）', 'dates' => []];
foreach ($result['snapshots'] as $date => $snap) {
    $mats = [];
    foreach ($snap['materials'] as $mid => $m) {
        $mats[$names[$mid] ?? (string) $mid] = ['qty' => round((float) $m['qty'], 6), 'value' => round((float) $m['value'], 4)];
    }
    $out['dates'][$date] = ['materials' => $mats, 'raw_total' => round((float) $snap['rawTotal'], 4)];
}
if (!is_dir(dirname((string) $opt['out']))) {
    mkdir(dirname((string) $opt['out']), 0755, true);
}
file_put_contents((string) $opt['out'], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, '基準日 ' . count($out['dates']) . " 件を保存: {$opt['out']}\n");
