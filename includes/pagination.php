<?php
/**
 * Pager under a list.
 *
 * @var array  $pg       from paginate()
 * @var string $pgPath   e.g. 'pages/inventory.php'
 * @var array  $pgQuery  current filters (without 'page')
 */
$from = $pg['total'] === 0 ? 0 : $pg['offset'] + 1;
$to   = min($pg['total'], $pg['offset'] + $pg['per_page']);
$pageUrl = static fn (int $n): string => url($pgPath) . '?' . http_build_query(array_merge($pgQuery, ['page' => $n]));

// Page numbers to show: first, last, and two either side of the current page.
$numbers = [];
for ($n = 1; $n <= $pg['pages']; $n++) {
    if ($n === 1 || $n === $pg['pages'] || abs($n - $pg['page']) <= 2) {
        $numbers[] = $n;
    }
}
?>
<nav class="pager" aria-label="Pagination">
    <span class="pager__info">Showing <?= (int) $from ?>–<?= (int) $to ?> of <?= (int) $pg['total'] ?></span>
    <?php if ($pg['pages'] > 1): ?>
        <div class="pager__links">
            <?php if ($pg['page'] > 1): ?>
                <a href="<?= e($pageUrl($pg['page'] - 1)) ?>" rel="prev">‹ Prev</a>
            <?php endif; ?>
            <?php $prev = 0; foreach ($numbers as $n): ?>
                <?php if ($n - $prev > 1): ?><span class="pager__gap">…</span><?php endif; ?>
                <?php if ($n === $pg['page']): ?>
                    <span class="is-current" aria-current="page"><?= $n ?></span>
                <?php else: ?>
                    <a href="<?= e($pageUrl($n)) ?>"><?= $n ?></a>
                <?php endif; ?>
            <?php $prev = $n; endforeach; ?>
            <?php if ($pg['page'] < $pg['pages']): ?>
                <a href="<?= e($pageUrl($pg['page'] + 1)) ?>" rel="next">Next ›</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</nav>
