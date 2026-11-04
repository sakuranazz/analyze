<?php
/**
 * @var list<array<string, mixed>> $lots @var list<int> $lotIds @var ?string $from @var ?string $to
 * @var list<array<string, mixed>> $rows @var ?int $editId @var string $self @var list<array<string, mixed>> $chart
 */
use App\Data\Analysis;
use App\Http\Url;
use App\View\Format as F;
use App\View\View;

$series = [];
foreach ($chart as $r) {
    $series[$r['lot_code']][] = ['d' => $r['entry_date']] + array_intersect_key($r, Analysis::FIELDS);
}
?>
<div data-page="analysis-chart" hidden></div>
<header class="page-head">
  <div><h1>分析結果一覧</h1><p class="page-head__sub"><?= count($rows) ?> 件</p></div>
  <div class="page-head__actions">
    <a class="btn btn--secondary" href="<?= F::h(Url::to('print/analysis.php', ['lot' => $lotIds ?: null, 'from' => $from, 'to' => $to])) ?>"><?= F::icon('print', 16) ?>印刷用画面</a>
    <a class="btn" href="<?= F::h(Url::to('input/analysis.php')) ?>"><?= F::icon('plus', 16) ?>分析結果を入れる</a>
  </div>
</header>

<form class="card card__body form-grid filters" method="get">
  <div class="field">
    <label for="lot">ロット <span class="hint">複数可（⌘/Ctrl＋クリック）</span></label>
    <select class="select" id="lot" name="lot[]" multiple size="3" style="height:auto">
      <?php foreach ($lots as $l): ?>
        <option value="<?= (int) $l['id'] ?>"<?= in_array((int) $l['id'], $lotIds, true) ? ' selected' : '' ?>><?= F::h($l['lot_code']) ?><?= $l['status'] === 'closed' ? '（終了）' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label for="from">期間（から）</label><input class="input" type="date" id="from" name="from" value="<?= F::h($from) ?>"></div>
  <div class="field"><label for="to">期間（まで）</label><input class="input" type="date" id="to" name="to" value="<?= F::h($to) ?>"></div>
  <div class="field" style="justify-content:flex-end"><div class="btn-row"><button class="btn" type="submit">絞り込む</button><a class="btn btn--text" href="<?= F::h(Url::to('books/analysis.php')) ?>">解除</a></div></div>
</form>

<?php if ($rows !== []): ?>
<section aria-label="推移グラフ">
  <script type="application/json" id="chart-data"><?= json_encode(['series' => $series, 'fields' => array_map(static fn ($x) => ['label' => $x[0], 'unit' => $x[1]], Analysis::FIELDS)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <div class="grid-charts" id="charts"></div>
  <?php if (count($series) > 6): ?><p class="small muted">ロットが多いので、グラフを見るときはロットを絞り込んでください。</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card card--flush">
  <?php if ($rows === []): ?>
    <div class="empty"><p>まだ記録がありません</p><a class="btn" href="<?= F::h(Url::to('input/analysis.php')) ?>">分析結果を入れる</a></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <caption class="sr-only">分析結果</caption>
    <thead><tr><th scope="col">日付</th><th scope="col">ロット</th>
      <?php foreach (Analysis::FIELDS as [$label, $unit]): ?><th scope="col" class="num"><?= F::h($label) ?><?= $unit !== '' ? ' (' . F::h($unit) . ')' : '' ?></th><?php endforeach; ?>
      <th scope="col">備考</th><th scope="col"><span class="sr-only">操作</span></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?= View::fragment('books/_analysis_row', ['r' => $r, 'editing' => $editId === (int) $r['id'], 'errors' => [], 'values' => [], 'self' => $self]) ?>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
