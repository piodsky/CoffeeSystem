<?php
/**
 * Placeholder body for pages built in a later phase.
 *
 * @var array  $page
 * @var int    $phase
 * @var string $intro
 * @var array  $features
 */
?>
<div class="page-head">
    <div>
        <h1><?= e($page['title']) ?></h1>
        <p class="muted"><?= e($intro) ?></p>
    </div>
</div>

<section class="card coming-soon">
    <span class="coming-soon__icon"><?= icon($page['icon']) ?></span>
    <h2>Coming in Phase <?= (int) $phase ?></h2>
    <p class="muted">This page is protected and ready. Its features arrive in Phase <?= (int) $phase ?>:</p>
    <ul class="coming-soon__list">
        <?php foreach ($features as $feature): ?>
            <li><?= icon('check') ?> <?= e($feature) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
