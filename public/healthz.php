<?php
declare(strict_types=1);

// 反映後の確認用。DB に1回触れて ok を返すだけ（情報は出さない）
require __DIR__ . '/_boot.php';

App\Db\Connection::shared()->value('SELECT 1');
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
