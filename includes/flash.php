<?php
/** One-time messages set with flash('success', '...') before a redirect. */
$flashIcons = ['success' => 'check', 'error' => 'alert', 'warning' => 'alert', 'info' => 'info'];
?>
<?php foreach (flash_messages() as $msg): ?>
    <?php $type = isset($flashIcons[$msg['type']]) ? $msg['type'] : 'info'; ?>
    <div class="alert alert--<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <?= icon($flashIcons[$type]) ?>
        <span><?= e($msg['message']) ?></span>
        <button type="button" class="alert__close" data-dismiss="alert" aria-label="Dismiss"><?= icon('x') ?></button>
    </div>
<?php endforeach; ?>
