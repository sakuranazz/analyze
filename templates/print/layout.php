<?php
/** 印刷用画面の枠（DESIGN.md 7章）。ブラウザの印刷から PDF に保存する @var string $content @var string $title @var string $period */
use App\Config;
use App\Http\Url;
use App\View\Format as F;
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= F::h($title) ?></title>
<?= F::styles() ?>
<style>@media screen { body { background: #e9e6df; } .sheet { background: #fff; color: #000; max-width: 210mm; margin: 16px auto; padding: 15mm; box-shadow: 0 2px 12px rgb(0 0 0 / .15); } } :root { color-scheme: light; }</style>
<script type="module" src="<?= F::asset('js/print.js') ?>"></script>
</head>
<body class="print-page">
<div class="no-print" style="max-width:210mm;margin:16px auto 0;display:flex;gap:8px">
  <button class="btn" type="button" data-print>印刷する（PDFに保存）</button>
  <a class="btn btn--secondary" href="<?= F::h(Url::to('')) ?>" data-back>戻る</a>
</div>
<div class="sheet">
  <header class="print-head">
    <h1><?= F::h($title) ?></h1>
    <div class="meta"><?= F::h((string) Config::get('brewery_name')) ?><br>対象: <?= F::h($period) ?><br>出力日: <?= F::h(date('Y-m-d')) ?></div>
  </header>
  <?= $content ?>
</div>
</body>
</html>
