<?php
/**
 * Sidebar menu AND page access control — single source of truth.
 * require_page('<key>') uses 'roles' to allow or deny access to the page.
 */
declare(strict_types=1);

return [
    'pos' => [
        'label' => 'POS Sales',
        'icon'  => 'home',
        'url'   => 'pages/pos.php',
        'roles' => ['admin', 'cashier'],
    ],
    'sales-history' => [
        'label' => 'Sales History',
        'icon'  => 'receipt',
        'url'   => 'pages/sales-history.php',
        'roles' => ['admin', 'cashier'],
    ],
    'inventory' => [
        'label' => 'Inventory',
        'icon'  => 'box',
        'url'   => 'pages/inventory.php',
        'roles' => ['admin'],
    ],
    'customers' => [
        'label' => 'Customers',
        'icon'  => 'user',
        'url'   => 'pages/customers.php',
        'roles' => ['admin', 'cashier'],
    ],
    'reports' => [
        'label' => 'Reports',
        'icon'  => 'chart',
        'url'   => 'pages/reports.php',
        'roles' => ['admin'],
    ],
    'settings' => [
        'label' => 'Settings',
        'icon'  => 'settings',
        'url'   => 'pages/settings.php',
        'roles' => ['admin'],
    ],
];
