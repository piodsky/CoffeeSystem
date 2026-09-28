<?php
/**
 * GET /api/pos/products.php
 * All sellable products with live stock, for the POS grid (filtered client-side).
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard('GET');

$stmt = db()->prepare(
    'SELECT p.id, p.category_id, p.code, p.barcode, p.name, p.price, p.stock, p.reorder_level, p.image,
            c.icon AS category_icon
       FROM products p
       JOIN categories c ON c.id = p.category_id
      WHERE p.is_active = ? AND c.is_active = ?
      ORDER BY c.sort_order, p.code'
);
$stmt->execute([1, 1]);

$products = array_map(static fn (array $p): array => [
    'id'            => (int) $p['id'],
    'category_id'   => (int) $p['category_id'],
    'code'          => $p['code'],
    'barcode'       => $p['barcode'],
    'name'          => $p['name'],
    'price_cents'   => to_cents($p['price']),
    'stock'         => (int) $p['stock'],
    'reorder_level' => (int) $p['reorder_level'],
    'category_icon' => $p['category_icon'],
    'image_url'     => ImageUpload::url($p['image']),
], $stmt->fetchAll());

json_response([
    'ok'           => true,
    'products'     => $products,
    'next_sale_no' => Sales::nextNumber(),
    'vat_rate'     => (float) setting('vat_rate', '12'),
]);
