<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('reports');

$phase    = 4;
$intro    = 'How the shop is performing.';
$features = [
    'Daily sales totals with date range filter',
    'Top-selling items by quantity and revenue',
    'Sales by payment type and by cashier',
];

require ROOT_PATH . '/includes/header.php';
require ROOT_PATH . '/includes/coming-soon.php';
require ROOT_PATH . '/includes/footer.php';
