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

    /** Sale header + items for receipts. Null if not found. */
    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT s.*, u.full_name AS cashier_name, COALESCE(c.name, 'Walk-in Customer') AS customer_name
               FROM sales s
               JOIN users u ON u.id = s.user_id
               LEFT JOIN customers c ON c.id = s.customer_id
              WHERE s.id = ?"
        );
        $stmt->execute([$id]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }

        $stmt = db()->prepare(
            'SELECT product_code, product_name, unit_price, quantity, line_total
               FROM sale_items WHERE sale_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        $sale['items'] = $stmt->fetchAll();

        return $sale;
    }
}
