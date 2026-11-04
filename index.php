<?php
$pageTitle = 'トップ';
require_once __DIR__ . '/includes/header.php';
?>
<h1>醸造管理システム</h1>
<?php foreach ($navItems as $group): ?>
<div class="panel">
    <h2><?= h($group['label']) ?></h2>
    <ul>
    <?php foreach ($group['items'] as [$label, $href]): ?>
        <li><a href="<?= h($href) ?>"><?= h($label) ?></a><?php
            if (!file_exists(__DIR__ . '/' . $href)) echo ' <span class="muted">(準備中)</span>'; ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
