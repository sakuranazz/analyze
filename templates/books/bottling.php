<?php
/** @var int $year @var list<int> $years @var string $from @var string $to @var list<array> $rows @var list<array> $summary @var bool $current */
use App\Http\Url;
use App\Service\MovementService;
use App\View\Format as F;

$kindLabel = MovementService::KINDS + ['tasting_sample' => '試飲（試飲用）', 'disposal_sample' => '処分（試飲用）'];
?>
<header class="page-head">
  <div><h1>詰口情報一覧</h1><p class="page-head__sub">酒類の年度 <?= $year ?>年度（<?= F::h($from) ?>〜<?= F::h($to) ?>）</p></div>
  <div class="page-head__actions">
    <a class="btn btn--secondary" href="<?= F::h(Url::to('print/bottling.php', ['year' => $year])) ?>"><?= F::icon('print', 16) ?>印刷用画面</a>
    <a class="btn" href="<?= F::h(Url::to('input/bottling.php')) ?>"><?= F::icon('plus', 16) ?>詰口・出庫を入力</a>
  </div>
</header>
<nav class="filter-chips" aria-label="年度">
  <?php foreach ($years as $y): ?><a href="<?= F::h(Url::to('books/bottling.php', ['year' => $y])) ?>"<?= $y === $year ? ' aria-current="true"' : '' ?>><?= $y ?>年度</a><?php endforeach; ?>
</nav>

<section class="card card--flush">
  <div class="card__head"><h2>年度別サマリ（SKU毎）</h2><?php if ($current): ?><span class="small muted">現在進行中の年度です。期末在庫は今日時点の数量です</span><?php endif; ?></div>
  <?php if ($summary === []): ?><div class="empty"><p>この年度の記録はありません</p></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>SKU</th><th class="num">前年繰越 (本)</th><th class="num">期間内詰口 (本)</th><th class="num">卸売業者 (本)</th><th class="num">小売業者 (本)</th><th class="num">小売販売 (本)</th><th class="num">期末在庫 (本)</th></tr></thead>
    <tbody>
    <?php $un = 0; foreach ($summary as $s): $un += $s['uncategorized']; ?>
      <tr><td><?= F::code((string) $s['sku_code']) ?> <span class="small muted"><?= F::h($s['sku_name']) ?></span></td>
        <?= F::numCell((string) $s['carry'], $s['carry']) ?><?= F::numCell((string) $s['bottled'], $s['bottled']) ?>
        <?= F::numCell((string) $s['wholesale_distributor'], $s['wholesale_distributor']) ?><?= F::numCell((string) $s['wholesale_retailer'], $s['wholesale_retailer']) ?>
        <?= F::numCell((string) $s['retail'], $s['retail']) ?><?= F::numCell((string) $s['end'], $s['end']) ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card__body small muted">卸売業者＝卸し(問屋)、小売業者＝卸し(酒販店)、小売販売＝卸し(業務店)・小売(ネット)・小売(店頭)。試飲・廃棄・遺失・返品は含めません。<?= $un > 0 ? '販売先が未設定または「その他」の販売 ' . $un . ' 本は数量に含めていません。' : '' ?></div>
  <?php endif; ?>
</section>

<section class="card card--flush">
  <div class="card__head"><h2>台帳</h2><span class="small muted"><?= count($rows) ?> 行</span></div>
  <?php if ($rows === []): ?><div class="empty"><p>まだ記録がありません</p><a class="btn" href="<?= F::h(Url::to('input/bottling.php')) ?>">詰口を入力する</a></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>日付</th><th>区分</th><th>SKU</th><th class="num">容量 (ml)</th><th class="num">本数・量</th><th>ロット</th><th>販売先・住所</th><th>備考</th><th><span class="sr-only">操作</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        [$label, $badge] = match ($r['row_kind']) {
            'bottling' => ['詰口', 'active'],
            'sample' => ['試飲用', 'muted'],
            'movement' => [MovementService::KINDS[$r['kind']] ?? $r['kind'], $r['kind'] === 'sale' ? 'ok' : ($r['kind'] === 'return' ? 'active' : 'warn')],
            default => [$r['kind'] === 'tasting' ? '試飲用を試飲' : '試飲用を処分', 'muted'],
        }; ?>
      <tr>
        <td class="date"><?= F::h($r['date']) ?></td>
        <td><?= F::badge($badge, $label) ?></td>
        <td><?= F::code((string) $r['sku_code']) ?></td>
        <td class="num"><?= $r['ml'] === null ? '' : F::ml((int) $r['ml']) ?></td>
        <td class="num"><?= match ($r['row_kind']) { 'sample' => F::ml((int) $r['sample_ml']) . ' ml', 'sample_use' => F::ml((int) $r['count']) . ' ml', default => (int) $r['count'] . ' 本' } ?></td>
        <td><?= F::code($r['lot_code']) ?></td>
        <td class="small"><?= F::h(trim(($r['channel'] ?? '') . ' ' . ($r['address'] ?? ''))) ?></td>
        <td class="small"><?= F::h($r['note']) ?></td>
        <td class="actions">
          <?php if ($r['row_kind'] === 'bottling'): ?>
            <a class="btn btn--icon" href="<?= F::h(Url::to('input/bottling.php', ['id' => $r['session_id']])) ?>" aria-label="この詰口を直す"><?= F::icon('pencil', 16) ?></a>
          <?php endif; ?>
          <?php if ($r['row_kind'] !== 'sample'):
              $act = ['bottling' => 'delete_session', 'movement' => 'delete_movement', 'sample_use' => 'delete_sample_use'][$r['row_kind']];
              $what = $r['row_kind'] === 'bottling' ? 'この詰口（同じ回の全容量・試飲用・瓶とラベルの自動消費を含む）' : 'この行'; ?>
            <form method="post" style="display:inline" data-confirm="<?= F::h($r['date'] . ' ' . $r['sku_code'] . ' の' . $what . 'を削除します。') ?>" data-confirm-title="削除の確認" data-confirm-ok="削除する">
              <?= F::csrfField() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn--icon" type="submit" aria-label="削除"><?= F::icon('trash', 16) ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
