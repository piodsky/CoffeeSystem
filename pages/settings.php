<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('settings');

$phase    = 4;
$intro    = 'Shop details, VAT and user accounts.';
$features = [
    'Shop name, address, TIN and receipt footer',
    'VAT rate',
    'Manage admin and cashier accounts, reset passwords',
];

require ROOT_PATH . '/includes/header.php';
require ROOT_PATH . '/includes/coming-soon.php';
require ROOT_PATH . '/includes/footer.php';
