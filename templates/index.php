<?php
/** @var string $today @var list<App\Domain\Alert> $alerts @var list<array<string, mixed>> $lots @var array<string, ?string> $last */
use App\Http\Url;
use App\View\Format as F;

$hour = (int) date('G');
$greet = $hour < 11 ? 'おはようございます' : ($hour < 18 ? 'こんにちは' : 'おつかれさまです');
$tiles = [
    ['input/analysis.php', 'analysis', '分析結果を入れる', $last['analysis']],
    ['input/bottling.php', 'bottle', '詰口・出庫', $last['bottling']],
    ['input/process.php', 'process', '工程', $last['process']],
];
$important = array_filter($alerts, static fn ($a) => $a->severity !== 'info');
?>
<header class="page-head">
  <div>
    <p class="muted"><?= F::h($greet) ?></p>
    <h1><?= F::h(F::dateWithDay($today)) ?></h1>
  </div>
</header>

<section aria-labelledby="h-today">
  <h2 id="h-today" class="sr-only">今日の入力</h2>
  <div class="today">
    <?php foreach ($tiles as [$href, $icon, $label, $date]): ?>
      <a href="<?= F::h(Url::to($href)) ?>">
        <span class="ico"><?= F::icon($icon, 24) ?></span>
        <span><strong><?= F::h($label) ?></strong><br><small>最後の入力 <?= F::h($date ?? 'まだありません') ?></small></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="card card--flush" aria-labelledby="h-attention">
  <div class="card__head"><h2 id="h-attention">要注意</h2><span class="muted small"><?= count($important) ?>件</span></div>
  <?php if ($alerts === []): ?>
    <div class="card__body"><div class="alert alert--ok"><?= F::icon('ok') ?><div>問題はありません</div></div></div>
  <?php else: ?>
    <ul class="attention">
      <?php foreach ($alerts as $a): ?>
        <li class="is-<?= F::h($a->severity) ?>">
          <span class="ico"><?= F::icon($a->severity === 'danger' ? 'stop' : ($a->severity === 'warn' ? 'warn' : 'info')) ?></span>
          <span class="txt"><strong><?= F::h($a->title) ?></strong><span><?= F::h($a->detail) ?></span></span>
          <a href="<?= F::h(Url::to($a->link)) ?>"><?= F::h($a->linkLabel) ?><?= F::icon('arrow', 16) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card card--flush" aria-labelledby="h-lots">
  <div class="card__head"><h2 id="h-lots">進行中のロット</h2><a class="small" href="<?= F::h(Url::to('books/sku_lots.php')) ?>">すべて見る</a></div>
  <?php if ($lots === []): ?>
    <div class="empty"><p>進行中のロットはありません</p><a class="btn" href="<?= F::h(Url::to('master/index.php', ['tab' => 'lots'])) ?>">ロットを作番する</a></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">ロット</th><th scope="col">酒類</th><th scope="col" class="num">全液量 (L)</th><th scope="col" class="num">残り (L)</th><th scope="col">詰口済み</th><th scope="col">最終分析</th><th scope="col"><span class="sr-only">操作</span></th></tr></thead>
      <tbody>
      <?php foreach ($lots as $l):
          $total = $l['total_ml'] === null ? null : (int) $l['total_ml'];
          $pct = $total ? min(100, (int) round((int) $l['bottles_ml'] / $total * 100)) : 0;
          $spct = $total ? min(100 - $pct, (int) round((int) $l['sample_ml'] / $total * 100)) : 0; ?>
        <tr>
          <td><?= F::code((string) $l['lot_code']) ?></td>
          <td><?= F::h($l['liquor_name']) ?></td>
          <td class="num"><?= $total === null ? ((bool) $l['has_brew'] ? F::badge('warn', '未入力') : '<span class="muted">—</span>') : F::h(F::liters($total)) ?></td>
          <?= F::numCell($l['remaining_ml'] === null ? '—' : F::liters((int) $l['remaining_ml']), $l['remaining_ml'] === null ? null : (int) $l['remaining_ml']) ?>
          <td><?php if ($total): ?><div class="bar" title="詰口済み <?= $pct ?>%"><span class="bar__done" style="width:<?= $pct ?>%"></span><span class="bar__sample" style="width:<?= $spct ?>%"></span></div><?php endif; ?></td>
          <td class="date"><?= F::h($l['last_analysis'] ?? '—') ?></td>
          <td class="actions"><a class="btn btn--secondary" href="<?= F::h(Url::to('input/analysis.php', ['lot_id' => $l['id']])) ?>">分析を入れる</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
