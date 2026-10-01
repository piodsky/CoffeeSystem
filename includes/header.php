<?php
/**
 * Page layout — top. Expects $page from require_page().
 * Optional: $pageStyles = ['css/pos.css'] for page-specific CSS.
 *
 * @var array $page
 */
$user      = Auth::user();
$activeKey = $page['key'] ?? '';
$roleName  = config('app.roles')[$user['role']] ?? ucfirst($user['role']);
$now       = new DateTimeImmutable();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($page['title'] ?? 'POS') ?> · <?= e(config('app.name')) ?> POS</title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php foreach ($pageStyles ?? [] as $style): ?>
        <link rel="stylesheet" href="<?= e(asset($style)) ?>">
    <?php endforeach; ?>
</head>
<body data-base-url="<?= e(base_path()) ?>" data-page="<?= e($activeKey) ?>">

<header class="topbar">
    <button class="topbar__toggle" type="button" data-sidebar-toggle aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
        <?= icon('menu') ?>
    </button>

    <a class="brand" href="<?= e(url('pages/pos.php')) ?>">
        <img class="brand__logo" src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="46" height="46">
        <span class="brand__text">
            <strong>EXECOM</strong>
            <small>LOGISTICS</small>
        </span>
    </a>

    <div class="topbar__title">
        <strong>POS SYSTEM</strong>
        <small>Fast <span>•</span> Secure <span>•</span> Reliable</small>
    </div>

    <form class="topbar__search" action="<?= e(url('pages/pos.php')) ?>" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" id="globalSearch" maxlength="100" autocomplete="off"
               placeholder="Search for product, barcode or item name..."
               aria-label="Search products"
               value="<?= e(input_string($_GET, 'q', 100)) ?>">
    </form>

    <div class="topbar__meta">
        <div class="meta-item">
            <?= icon('calendar') ?>
            <span><small>Date</small><strong data-clock-date><?= e($now->format('M j, Y')) ?></strong></span>
        </div>
        <div class="meta-item">
            <?= icon('clock') ?>
            <span><small>Time</small><strong data-clock-time><?= e($now->format('g:i:s A')) ?></strong></span>
        </div>
    </div>

    <details class="user-menu">
        <summary class="user-menu__trigger">
            <span class="avatar"><?= icon('user') ?></span>
            <span class="user-menu__name">
                <strong><?= e($user['username']) ?></strong>
                <small><?= e($roleName) ?></small>
            </span>
            <?= icon('chevron-down', 'user-menu__caret') ?>
        </summary>
        <div class="user-menu__panel">
            <div class="user-menu__head">
                <strong><?= e($user['full_name']) ?></strong>
                <small>@<?= e($user['username']) ?> · <?= e($roleName) ?></small>
            </div>
            <a href="<?= e(url('pages/account.php')) ?>" id="myAccountLink"><?= icon('lock') ?> Change Password</a>
            <?php if (Auth::hasRole('admin')): ?>
                <a href="<?= e(url('pages/settings.php')) ?>"><?= icon('settings') ?> Settings</a>
                <a href="<?= e(url('pages/users.php')) ?>"><?= icon('user') ?> Users</a>
            <?php endif; ?>
            <form action="<?= e(url('logout.php')) ?>" method="post">
                <?= Csrf::field() ?>
                <button type="submit"><?= icon('logout') ?> Sign out</button>
            </form>
        </div>
    </details>

    <form class="topbar__logout" action="<?= e(url('logout.php')) ?>" method="post">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-logout"><?= icon('logout') ?><span>Logout</span></button>
    </form>
</header>

<div class="layout">
    <?php require ROOT_PATH . '/includes/sidebar.php'; ?>

    <main class="content" id="main">
        <?php require ROOT_PATH . '/includes/flash.php'; ?>
