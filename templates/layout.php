<?php
/**
 * 全ページ共通の枠（DESIGN.md 3章）。
 * @var string $content
 * @var string $title
 * @var string $nav   現在地のキー
 * @var bool|null $narrow  スマホ優先の入力画面（分析結果入力）
 */
use App\Config;
use App\Http\Session;
use App\Http\Url;
use App\Service\AlertService;
use App\View\Format as F;

$nav ??= '';
$narrow ??= false;
$counts = AlertService::make()->navCounts();
$flash = Session::takeFlash();
$groups = [
    '日々の入力' => [
        ['input/analysis.php', 'analysis_input', 'analysis', '分析結果'],
        ['input/bottling.php', 'bottling_input', 'bottle', '詰口・出庫'],
        ['input/process.php', 'process', 'process', '工程'],
    ],
    '帳簿・集計' => [
        ['books/analysis.php', 'analysis', 'chart', '分析結果一覧'],
        ['books/bottling.php', 'bottling', 'book', '詰口情報一覧'],
        ['books/stock.php', 'stock', 'box', '在庫'],
        ['books/sku_lots.php', 'sku_lots', 'grid', 'SKU・ロット'],
        ['books/valuation.php', 'valuation', 'sheet', '棚卸し金額'],
        ['books/tax.php', 'tax', 'tax', '酒税'],
    ],
    '' => [
        ['master/index.php', 'master', 'gear', 'マスタ管理'],
    ],
];
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= F::h(Session::csrfToken()) ?>">
<title><?= F::h($title ?? '') ?> | 醸造管理システム</title>
<link rel="icon" href="<?= F::asset('favicon.svg') ?>" type="image/svg+xml">
<?= F::styles() ?>
<script type="module" src="<?= F::asset('js/app.js') ?>"></script>
</head>
<body class="<?= $narrow ? 'is-narrow' : '' ?>">
<a class="skip" href="#main">本文へ移動</a>
<header class="appbar">
  <button type="button" class="appbar__menu" aria-label="メニュー" aria-controls="sidebar" aria-expanded="false" data-menu><?= F::icon('menu', 22) ?></button>
  <a class="appbar__title" href="<?= F::h(Url::to('')) ?>">醸造管理システム</a>
  <?php if (!Config::isProd()): ?><span class="appbar__env">開発</span><?php endif; ?>
</header>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <?php foreach ($groups as $label => $items): ?>
      <nav class="sidebar__group" aria-label="<?= F::h($label ?: 'マスタ') ?>">
        <?php if ($label !== ''): ?><div class="sidebar__label"><?= F::h($label) ?></div><?php else: ?><hr class="sidebar__rule"><?php endif; ?>
        <?php foreach ($items as [$href, $key, $icon, $text]): ?>
          <a class="sidebar__item<?= $nav === $key ? ' is-current' : '' ?>" href="<?= F::h(Url::to($href)) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>>
            <?= F::icon($icon) ?><span><?= F::h($text) ?></span>
            <?php if (!empty($counts[$key])): ?><span class="count" aria-label="要注意 <?= (int) $counts[$key] ?> 件"><?= (int) $counts[$key] ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endforeach; ?>
  </aside>
  <main class="main" id="main">
    <?php foreach ($flash as $f): ?>
      <?php if ($f['type'] === 'toast'): ?><div data-toast="<?= F::h($f['message']) ?>" hidden></div><noscript><div class="alert alert--ok"><?= F::h($f['message']) ?></div></noscript><?php continue; endif; ?>
      <div class="alert alert--<?= F::h($f['type']) ?>" role="status"><?= F::icon($f['type'] === 'ok' ? 'ok' : ($f['type'] === 'danger' ? 'stop' : 'warn')) ?><div><?= F::h($f['message']) ?></div></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
<div class="toast" role="status" aria-live="polite" hidden></div>
<dialog class="dialog" id="confirm-dialog">
  <form method="dialog">
    <h2 class="dialog__title"></h2>
    <div class="dialog__body"></div>
    <div class="dialog__actions">
      <button value="cancel" class="btn btn--secondary" autofocus>取消</button>
      <button value="ok" class="btn btn--danger">実行する</button>
    </div>
  </form>
</dialog>
</body>
</html>
