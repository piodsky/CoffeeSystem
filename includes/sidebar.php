<?php
/**
 * Sidebar — items come from config/menu.php and are hidden when the
 * user's role is not allowed (the page itself also enforces this).
 *
 * @var string $activeKey
 */
?>
<aside class="sidebar" id="sidebar">
    <nav class="sidebar__nav" aria-label="Main menu">
        <?php foreach (config('menu', []) as $key => $item): ?>
            <?php if (!Auth::hasRole(...$item['roles'])) continue; ?>
            <a href="<?= e(url($item['url'])) ?>"
               class="nav-link<?= $key === $activeKey ? ' is-active' : '' ?>"
               <?= $key === $activeKey ? 'aria-current="page"' : '' ?>>
                <?= icon($item['icon']) ?>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__bottom">
        <a class="scan-card" href="<?= e(url('pages/pos.php')) ?>" data-scan-trigger>
            <?= icon('barcode') ?>
            <span>
                <strong>Scan Barcode</strong>
                <small>Use scanner or type</small>
            </span>
            <kbd>F2</kbd>
        </a>

        <div class="sidebar__footer">
            <strong>EXECOM Logistics</strong>
            <small>Inventory <span>•</span> Sales <span>•</span> Distribution</small>
            <small class="sidebar__version">v<?= e(config('app.version')) ?></small>
        </div>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-toggle hidden></div>
