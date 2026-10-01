<?php
/**
 * Sales: numbering, checkout (transaction + stock deduction), lookup.
 * Prices and totals are always recomputed here from the database;
 * the browser only sends product IDs and quantities.
 */
declare(strict_types=1);

final class Sales
{
    public const PAYMENT_TYPES = ['cash' => 'Cash', 'gcash' => 'GCash', 'card' => 'Card'];
    /** Statuses shown in Sales History ('held' is unused). */
    public const STATUSES  = ['completed' => 'Completed', 'cancelled' => 'Voided'];
    public const MAX_LINES = 100;
    public const MAX_QTY   = 999;

    public static function formatNumber(int $id): string
    {
        return str_pad((string) $id, 7, '0', STR_PAD_LEFT);
    }

    /** Preview of the next sale number (the real one is assigned on save). */
    public static function nextNumber(): string
    {
        $stmt = db()->prepare('SELECT COALESCE(MAX(id), 0) + 1 FROM sales');
        $stmt->execute();
        return self::formatNumber((int) $stmt->fetchColumn());
    }

    /**
     * Complete a sale: validate stock, save sale + items, deduct stock — all or nothing.
     *
     * @param array<int,int> $qtyById    product_id => quantity
     * @param int|null       $paidCents  cash received (required for cash, ignored otherwise)
     * @throws HttpException 409 when stock is insufficient, 422 on invalid data
     */
    public static function complete(
        int $userId,
        array $qtyById,
        ?int $customerId,
        string $paymentType,
        float $discountPercent,
        ?int $paidCents,
    ): array {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            if ($customerId !== null) {
                $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND is_active = ?');
                $stmt->execute([$customerId, 1]);
                if (!$stmt->fetch()) {
                    throw new HttpException(422, 'The selected customer no longer exists. Please choose another.');
                }
            }

            // Lock the product rows so two tills can't sell the last item twice.
            $ids  = array_keys($qtyById);
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, code, name, price, stock, is_active FROM products WHERE id IN ({$in}) ORDER BY id FOR UPDATE"
            );
            $stmt->execute($ids);
            $products = [];
            foreach ($stmt->fetchAll() as $row) {
                $products[(int) $row['id']] = $row;
            }

            $lines    = [];
            $problems = [];
            $subtotal = 0;
            foreach ($qtyById as $id => $qty) {
                $p = $products[$id] ?? null;
                if ($p === null || (int) $p['is_active'] !== 1) {
                    $problems[] = ['product_id' => $id, 'stock' => 0, 'message' => 'An item in the cart is no longer available.'];
                    continue;
                }
                if ((int) $p['stock'] < $qty) {
                    $problems[] = [
                        'product_id' => $id,
                        'stock'      => (int) $p['stock'],
                        'message'    => (int) $p['stock'] === 0
                            ? sprintf('%s is out of stock.', $p['name'])
                            : sprintf('%s: only %d left.', $p['name'], $p['stock']),
                    ];
                    continue;
                }
                $price     = to_cents($p['price']);
                $lines[]   = [
                    'id' => $id, 'code' => $p['code'], 'name' => $p['name'], 'price' => $price, 'qty' => $qty,
                    'stock_after' => (int) $p['stock'] - $qty, // row is locked, so this is exact
                ];
                $subtotal += $price * $qty;
            }

            if ($problems) {
                throw new HttpException(
                    409,
                    'Please update the cart. ' . implode(' ', array_column($problems, 'message')),
                    ['problems' => $problems]
                );
            }

            // VAT is applied on the discounted amount.
            $vatRate  = (float) setting('vat_rate', '12');
            $discount = (int) round($subtotal * $discountPercent / 100);
            $vat      = (int) round(($subtotal - $discount) * $vatRate / 100);
            $total    = $subtotal - $discount + $vat;

            if ($paymentType === 'cash') {
                if ($paidCents === null || $paidCents < $total) {
                    throw new HttpException(422, 'Amount received is less than the total of ' . money(from_cents($total)) . '.');
                }
            } else {
                $paidCents = $total; // GCash / card are charged the exact amount
            }
            $change = $paidCents - $total;

            $pdo->prepare(
                'INSERT INTO sales (sale_no, user_id, customer_id, payment_type, status, subtotal, discount_percent,
                                    discount_amount, vat_rate, vat_amount, total, amount_paid, change_amount,
                                    created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([
                'TMP' . bin2hex(random_bytes(8)), // replaced with the padded ID below
                $userId,
                $customerId,
                $paymentType,
                'completed',
                from_cents($subtotal),
                number_format($discountPercent, 2, '.', ''),
                from_cents($discount),
                number_format($vatRate, 2, '.', ''),
                from_cents($vat),
                from_cents($total),
                from_cents($paidCents),
                from_cents($change),
            ]);
            $saleId = (int) $pdo->lastInsertId();
            $saleNo = self::formatNumber($saleId);
            $pdo->prepare('UPDATE sales SET sale_no = ? WHERE id = ?')->execute([$saleNo, $saleId]);

            $insertItem = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, product_code, product_name, unit_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $deduct = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');

            foreach ($lines as $line) {
                $insertItem->execute([
                    $saleId, $line['id'], $line['code'], $line['name'],
                    from_cents($line['price']), $line['qty'], from_cents($line['price'] * $line['qty']),
                ]);
                $deduct->execute([$line['qty'], $line['id'], $line['qty']]);
                if ($deduct->rowCount() !== 1) {
                    throw new HttpException(409, "Not enough stock for {$line['name']}.");
                }
                Products::log($line['id'], $userId, 'sale', -$line['qty'], $line['stock_after'], null, $saleId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id'           => $saleId,
            'sale_no'      => $saleNo,
            'total_cents'  => $total,
            'paid_cents'   => $paidCents,
            'change_cents' => $change,
            'payment_type' => $paymentType,
            'receipt_url'  => url('pages/receipt.php?id=' . $saleId),
        ];
    }

    /** Sale header + items (receipts, sale details). Null if not found. */
    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT s.*, u.full_name AS cashier_name, COALESCE(c.name, 'Walk-in Customer') AS customer_name,
                    v.full_name AS voided_by_name
               FROM sales s
               JOIN users u ON u.id = s.user_id
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN users v ON v.id = s.voided_by
              WHERE s.id = ?"
        );
        $stmt->execute([$id]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }

        $stmt = db()->prepare(
            'SELECT product_id, product_code, product_name, unit_price, quantity, line_total
               FROM sale_items WHERE sale_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        $sale['items'] = $stmt->fetchAll();

        return $sale;
    }

    // ------------------------------------------------------------------
    // Sales History
    // ------------------------------------------------------------------

    /**
     * @param array{q:string, from:?string, to:?string, status:string, payment:string, cashier:?int} $f
     *        from/to are validated 'Y-m-d' dates (inclusive) or null.
     */
    public static function count(array $f): int
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT s.id, s.sale_no, s.customer_id, s.payment_type, s.status, s.total, s.created_at,
                    COALESCE(c.name, 'Walk-in Customer') AS customer_name, u.full_name AS cashier_name,
                    (SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si WHERE si.sale_id = s.id) AS items
               FROM sales s
               JOIN users u ON u.id = s.user_id
               LEFT JOIN customers c ON c.id = s.customer_id
              WHERE {$where}
              ORDER BY s.created_at DESC, s.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Totals for the filtered period. The status filter is ignored so voids are always counted. */
    public static function summary(array $f): array
    {
        [$where, $params] = self::where(['status' => 'all'] + $f);
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(s.status = 'completed'), 0) AS sales,
                    COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.total END), 0) AS revenue,
                    COALESCE(AVG(CASE WHEN s.status = 'completed' THEN s.total END), 0) AS average,
                    COALESCE(SUM(s.status = 'cancelled'), 0) AS voided,
                    COALESCE(SUM(CASE WHEN s.status = 'cancelled' THEN s.total END), 0) AS voided_total
               FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
              WHERE {$where}"
        );
        $stmt->execute($params);
        return $stmt->fetch();
    }

    private static function where(array $f): array
    {
        $where  = ["s.status IN ('completed', 'cancelled')"];
        $params = [];
        if ($f['q'] !== '') {
            // Sale number, customer, or any item on the sale (name / code)
            $where[] = '(s.sale_no LIKE ? OR c.name LIKE ? OR EXISTS (
                            SELECT 1 FROM sale_items si
                             WHERE si.sale_id = s.id AND (si.product_name LIKE ? OR si.product_code LIKE ?)))';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like, $like);
        }
        if ($f['from'] !== null) {
            $where[]  = 's.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== null) {
            $where[]  = 's.created_at < ?'; // before the start of the next day
            $params[] = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        if (isset(self::STATUSES[$f['status']])) {
            $where[]  = 's.status = ?';
            $params[] = $f['status'];
        }
        if (isset(self::PAYMENT_TYPES[$f['payment']])) {
            $where[]  = 's.payment_type = ?';
            $params[] = $f['payment'];
        }
        if ($f['cashier'] !== null) {
            $where[]  = 's.user_id = ?';
            $params[] = $f['cashier'];
        }
        return [implode(' AND ', $where), $params];
    }

    /** Users who can appear as the cashier of a sale (for the filter). */
    public static function cashiers(): array
    {
        $stmt = db()->prepare('SELECT id, full_name, username FROM users ORDER BY full_name');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Void a completed sale: mark it cancelled and put every item back in stock
     * (logged as 'void' in stock_movements) — all or nothing.
     * Returns [sale_no, units returned].
     */
    public static function void(int $id, int $userId, string $reason): array
    {
        $len = mb_strlen($reason);
        if ($len < 3 || $len > 255) {
            throw new HttpException(422, 'Enter the reason for voiding (3–255 characters).');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, sale_no, status FROM sales WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $sale = $stmt->fetch() ?: throw new HttpException(404, 'Sale not found.');
            if ($sale['status'] === 'cancelled') {
                throw new HttpException(409, "Sale No. {$sale['sale_no']} is already voided.");
            }
            if ($sale['status'] !== 'completed') {
                throw new HttpException(409, 'Only completed sales can be voided.');
            }

            // Units to return per product (products deleted since then have product_id NULL).
            $stmt = $pdo->prepare(
                'SELECT product_id, SUM(quantity) AS qty FROM sale_items
                  WHERE sale_id = ? AND product_id IS NOT NULL GROUP BY product_id ORDER BY product_id'
            );
            $stmt->execute([$id]);
            $returns = [];
            foreach ($stmt->fetchAll() as $row) {
                $returns[(int) $row['product_id']] = (int) $row['qty'];
            }

            $units = 0;
            if ($returns) {
                $in   = implode(',', array_fill(0, count($returns), '?'));
                $stmt = $pdo->prepare("SELECT id, stock FROM products WHERE id IN ({$in}) ORDER BY id FOR UPDATE");
                $stmt->execute(array_keys($returns));
                $restock = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
                foreach ($stmt->fetchAll() as $p) {
                    $qty = $returns[(int) $p['id']];
                    $restock->execute([$qty, $p['id']]);
                    Products::log((int) $p['id'], $userId, 'void', $qty, (int) $p['stock'] + $qty,
                        "Voided sale No. {$sale['sale_no']}: {$reason}", $id);
                    $units += $qty;
                }
            }

            $pdo->prepare(
                "UPDATE sales SET status = 'cancelled', voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?"
            )->execute([$userId, $reason, $id]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return [$sale['sale_no'], $units];
    }
}
