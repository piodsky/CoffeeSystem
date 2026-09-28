<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('sales-history');

$phase    = 4;
$intro    = 'Every completed, held and cancelled sale.';
$features = [
    'Searchable list of sales by date, cashier, customer and payment type',
    'View sale details and reprint receipts',
    'Resume held (saved) sales and void sales (admin)',
];

require ROOT_PATH . '/includes/header.php';
require ROOT_PATH . '/includes/coming-soon.php';
require ROOT_PATH . '/includes/footer.php';
