<?php
/**
 * Tabs across the Settings pages.
 *
 * @var string $settingsTab 'company' | 'users'
 */
$tabs = [
    'company' => ['Company & Receipt', 'receipt', 'pages/settings.php'],
    'users'   => ['Users', 'user', 'pages/users.php'],
];
?>
<nav class="settings-tabs" aria-label="Settings sections">
    <?php foreach ($tabs as $key => [$label, $tabIcon, $path]): ?>
        <a href="<?= e(url($path)) ?>" class="settings-tab<?= $settingsTab === $key ? ' is-active' : '' ?>"<?= $settingsTab === $key ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>
