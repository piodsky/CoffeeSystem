<?php
/**
 * Products: validation, listing, CRUD and stock adjustments (with audit log).
 * Stock only changes through sales or adjustStock(), never by editing the product,
 * so every change lands in stock_movements.
 */
declare(strict_types=1);

final class Products
{
    public const MAX_STOCK = 99999;

    /** Adjustment reasons: key => [label, allowed direction] */
    public const REASONS = [
        'restock'  => ['Restock / delivery', 'add'],
        'return'   => ['Customer return', 'add'],
        'damaged'  => ['Damaged / defective', 'remove'],
        'supplier' => ['Returned to supplier (RMA)', 'remove'],
        'count'    => ['Stock count correction', 'both'],
        'other'    => ['Other', 'both'],
    ];

    public static function categories(): array
    {
        $stmt = db()->prepare('SELECT id, name FROM categories WHERE is_active = ? ORDER BY sort_order, name');
        $stmt->execute([1]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT p.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.product_id = p.id) AS times_sold
               FROM products p JOIN categories c ON c.id = p.category_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    /** @param array{q:string, category:?int, status:string} $f */
    public static function count(array $f): int
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM products p WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT p.id, p.code, p.barcode, p.name, p.price, p.stock, p.reorder_level, p.image, p.is_active,
                    c.name AS category_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.product_id = p.id) AS times_sold
               FROM products p JOIN categories c ON c.id = p.category_id
              WHERE {$where}
              ORDER BY p.is_active DESC, c.sort_order, p.code
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    private static function where(array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];
        if ($f['q'] !== '') {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like);
        }
        if ($f['category'] !== null) {
            $where[]  = 'p.category_id = ?';
            $params[] = $f['category'];
        }
        $where[] = match ($f['status']) {
            'active'   => 'p.is_active = 1',
            'inactive' => 'p.is_active = 0',
            'low'      => 'p.is_active = 1 AND p.stock > 0 AND p.stock <= p.reorder_level',
            'out'      => 'p.is_active = 1 AND p.stock = 0',
            default    => '1 = 1',
        };
        return [implode(' AND ', $where), $params];
    }

    public static function summary(): array
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS items,
                    COALESCE(SUM(price * stock), 0) AS stock_value,
                    COALESCE(SUM(stock > 0 AND stock <= reorder_level), 0) AS low,
                    COALESCE(SUM(stock = 0), 0) AS out_of_stock
               FROM products WHERE is_active = ?'
        );
        $stmt->execute([1]);
        return $stmt->fetch();
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @return array{0: array, 1: array<string,string>}  [clean data, field errors]
     */
    public static function validate(array $in, ?int $id): array
    {
        $errors = [];
        $data = [
            'category_id'   => input_int($in, 'category_id', 1),
            'code'          => strtoupper(input_string($in, 'code', 20)),
            'barcode'       => input_string($in, 'barcode', 50),
            'name'          => input_string($in, 'name', 100),
            'description'   => input_string($in, 'description', 255),
            'price'         => input_decimal($in, 'price', 0, 999999.99),
            'reorder_level' => input_int($in, 'reorder_level', 0, 9999),
            'is_active'     => isset($in['is_active']) ? 1 : 0,
        ];

        $categoryIds = array_map('intval', array_column(self::categories(), 'id'));
        if ($data['category_id'] === null || !in_array($data['category_id'], $categoryIds, true)) {
            $errors['category_id'] = 'Choose a category.';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $data['code'])) {
            $errors['code'] = 'Use 2–20 letters, numbers or dashes (e.g. ITM-0013).';
        } elseif (self::taken('code', $data['code'], $id)) {
            $errors['code'] = 'Another product already uses this code.';
        }
        if ($data['barcode'] !== '') {
            if (!preg_match('/^[A-Za-z0-9-]{4,50}$/', $data['barcode'])) {
                $errors['barcode'] = 'Barcode must be 4–50 letters, numbers or dashes.';
            } elseif (self::taken('barcode', $data['barcode'], $id)) {
                $errors['barcode'] = 'Another product already uses this barcode.';
            }
        }
        if (mb_strlen($data['name']) < 2) {
            $errors['name'] = 'Enter the product name.';
        }
        if ($data['price'] === null) {
            $errors['price'] = 'Enter a price from 0.00 to 999,999.99.';
        }
        if ($data['reorder_level'] === null) {
            $errors['reorder_level'] = 'Enter a whole number from 0 to 9999.';
        }

        // Opening stock is only set when creating; afterwards use Adjust stock.
        if ($id === null) {
            $data['stock'] = input_int($in, 'stock', 0, self::MAX_STOCK);
            if ($data['stock'] === null) {
                $errors['stock'] = 'Enter a whole number from 0 to ' . number_format(self::MAX_STOCK) . '.';
            }
        }

        $data['barcode']     = $data['barcode'] !== '' ? $data['barcode'] : null;
        $data['description'] = $data['description'] !== '' ? $data['description'] : null;

        return [$data, $errors];
    }

    private static function taken(string $column, string $value, ?int $exceptId): bool
    {
        $column = $column === 'barcode' ? 'barcode' : 'code'; // whitelist — never interpolate input
        $stmt = db()->prepare("SELECT 1 FROM products WHERE {$column} = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Create / update / status / delete
    // ------------------------------------------------------------------

    public static function create(array $d, ?string $image, int $userId): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO products (category_id, code, barcode, name, description, price, stock, reorder_level, image, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $d['category_id'], $d['code'], $d['barcode'], $d['name'], $d['description'],
                number_format($d['price'], 2, '.', ''), $d['stock'], $d['reorder_level'], $image, $d['is_active'],
            ]);
            $id = (int) $pdo->lastInsertId();
            self::log($id, $userId, 'initial', $d['stock'], $d['stock'], 'Opening stock');
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function update(int $id, array $d, ?string $image): void
    {
        db()->prepare(
            'UPDATE products SET category_id = ?, code = ?, barcode = ?, name = ?, description = ?, price = ?,
                                 reorder_level = ?, image = ?, is_active = ?
              WHERE id = ?'
        )->execute([
            $d['category_id'], $d['code'], $d['barcode'], $d['name'], $d['description'],
            number_format($d['price'], 2, '.', ''), $d['reorder_level'], $image, $d['is_active'], $id,
        ]);
    }

    /** @return array the product after the change */
    public static function toggleActive(int $id): array
    {
        $product = self::find($id) ?? throw new HttpException(404, 'Product not found.');
        db()->prepare('UPDATE products SET is_active = ? WHERE id = ?')
            ->execute([(int) $product['is_active'] === 1 ? 0 : 1, $id]);
        $product['is_active'] = (int) $product['is_active'] === 1 ? 0 : 1;
        return $product;
    }

    /** Hard delete — only for products that were never sold (sales history must keep its link). */
    public static function delete(int $id): array
    {
        $product = self::find($id) ?? throw new HttpException(404, 'Product not found.');
        if ((int) $product['times_sold'] > 0) {
            throw new HttpException(409, "{$product['name']} has sales history, so it can't be deleted. Deactivate it instead to hide it from the POS.");
        }
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        ImageUpload::delete($product['image']);
        return $product;
    }

    // ------------------------------------------------------------------
    // Stock
    // ------------------------------------------------------------------

    /**
     * Add or remove stock with a reason. Returns [product name, new stock].
     * @param int $change  positive = add, negative = remove
     */
    public static function adjustStock(int $id, int $change, string $reason, string $note, int $userId): array
    {
        if ($change === 0) {
            throw new HttpException(422, 'Enter a quantity greater than zero.');
        }
        [$label, $direction] = self::REASONS[$reason] ?? throw new HttpException(422, 'Choose a reason.');
        if (($direction === 'add' && $change < 0) || ($direction === 'remove' && $change > 0)) {
            throw new HttpException(422, "“{$label}” can't be used when " . ($change > 0 ? 'adding' : 'removing') . ' stock.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT name, stock FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $product = $stmt->fetch() ?: throw new HttpException(404, 'Product not found.');

            $new = (int) $product['stock'] + $change;
            if ($new < 0) {
                throw new HttpException(422, "You can't remove " . abs($change) . " — only {$product['stock']} {$product['name']} in stock.");
            }
            if ($new > self::MAX_STOCK) {
                throw new HttpException(422, 'Stock can be at most ' . number_format(self::MAX_STOCK) . '.');
            }

            $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$new, $id]);
            self::log($id, $userId, $reason === 'restock' ? 'restock' : 'adjustment', $change, $new,
                $label . ($note !== '' ? ' — ' . $note : ''));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [$product['name'], $new];
    }

    /** Write one audit row. Call inside the same transaction as the stock change. */
    public static function log(int $productId, ?int $userId, string $type, int $qty, int $stockAfter, ?string $note, ?int $saleId = null): void
    {
        db()->prepare(
            'INSERT INTO stock_movements (product_id, user_id, sale_id, type, quantity, stock_after, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$productId, $userId, $saleId, $type, $qty, $stockAfter, $note !== null ? mb_substr($note, 0, 255) : null]);
    }

    public static function movements(int $productId, int $limit = 15): array
    {
        $stmt = db()->prepare(
            'SELECT m.type, m.quantity, m.stock_after, m.note, m.created_at, m.sale_id,
                    u.username, s.sale_no
               FROM stock_movements m
               LEFT JOIN users u ON u.id = m.user_id
               LEFT JOIN sales s ON s.id = m.sale_id
              WHERE m.product_id = ?
              ORDER BY m.id DESC
              LIMIT ?'
        );
        $stmt->execute([$productId, $limit]);
        return $stmt->fetchAll();
    }
}
