<?php
/**
 * Customers: validation, listing with purchase stats, CRUD.
 * Used by the Customers pages and the POS quick-add (api/customers/create.php).
 */
declare(strict_types=1);

final class Customers
{
    /** Active customers for dropdowns. */
    public static function active(): array
    {
        $stmt = db()->prepare('SELECT id, name, phone FROM customers WHERE is_active = ? ORDER BY name');
        $stmt->execute([1]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Validate raw input (form or JSON).
     * @return array{0: array{name:string, phone:?string, email:?string, address:?string}, 1: array<string,string>}
     */
    public static function check(array $input, ?int $id = null): array
    {
        $name    = input_string($input, 'name', 100);
        $phone   = input_string($input, 'phone', 30);
        $email   = input_string($input, 'email', 120);
        $address = input_string($input, 'address', 255);
        $errors  = [];

        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Enter the customer name (at least 2 characters).';
        }
        if ($phone !== '') {
            if (!preg_match('/^[0-9+()\s-]{7,30}$/', $phone)) {
                $errors['phone'] = 'Enter a valid phone number (digits, spaces, + - ( ) only).';
            } elseif ($owner = self::phoneOwner($phone, $id)) {
                $errors['phone'] = "This number already belongs to {$owner}.";
            }
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        return [[
            'name'    => $name,
            'phone'   => $phone !== '' ? $phone : null,
            'email'   => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
        ], $errors];
    }

    /** Same as check(), but throws the first error as a 422 (for JSON APIs). */
    public static function validate(array $input, ?int $id = null): array
    {
        [$data, $errors] = self::check($input, $id);
        if ($errors) {
            throw new HttpException(422, (string) reset($errors), ['errors' => $errors]);
        }
        return $data;
    }

    private static function phoneOwner(string $phone, ?int $exceptId): ?string
    {
        $stmt = db()->prepare('SELECT name FROM customers WHERE phone = ? AND id <> ? LIMIT 1');
        $stmt->execute([$phone, $exceptId ?? 0]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string) $name : null;
    }

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    /** @param array{q:string, status:string} $f */
    public static function count(array $f): int
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM customers c WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT c.id, c.name, c.phone, c.email, c.is_active, c.created_at,
                    COALESCE(s.visits, 0) AS visits, COALESCE(s.spent, 0) AS spent, s.last_visit
               FROM customers c
               LEFT JOIN (
                    SELECT customer_id, COUNT(*) AS visits, SUM(total) AS spent, MAX(created_at) AS last_visit
                      FROM sales WHERE status = ? AND customer_id IS NOT NULL
                     GROUP BY customer_id
               ) s ON s.customer_id = c.id
              WHERE {$where}
              ORDER BY c.is_active DESC, c.name
              LIMIT ? OFFSET ?"
        );
        $stmt->execute(['completed', ...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    private static function where(array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];
        if ($f['q'] !== '') {
            $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like);
        }
        $where[] = match ($f['status']) {
            'active'   => 'c.is_active = 1',
            'inactive' => 'c.is_active = 0',
            default    => '1 = 1',
        };
        return [implode(' AND ', $where), $params];
    }

    /** Visits, total spent, average and last visit for one customer. */
    public static function stats(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS visits, COALESCE(SUM(total), 0) AS spent,
                    COALESCE(AVG(total), 0) AS average, MAX(created_at) AS last_visit
               FROM sales WHERE customer_id = ? AND status = ?'
        );
        $stmt->execute([$id, 'completed']);
        return $stmt->fetch();
    }

    public static function recentSales(int $id, int $limit = 10): array
    {
        $stmt = db()->prepare(
            'SELECT s.id, s.sale_no, s.total, s.payment_type, s.status, s.created_at,
                    (SELECT COALESCE(SUM(quantity), 0) FROM sale_items si WHERE si.sale_id = s.id) AS items
               FROM sales s
              WHERE s.customer_id = ?
              ORDER BY s.created_at DESC
              LIMIT ?'
        );
        $stmt->execute([$id, $limit]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Create / update / status / delete
    // ------------------------------------------------------------------

    public static function create(array $data): int
    {
        db()->prepare('INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)')
            ->execute([$data['name'], $data['phone'], $data['email'], $data['address']]);
        return (int) db()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        db()->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?')
            ->execute([$data['name'], $data['phone'], $data['email'], $data['address'], $id]);
    }

    public static function toggleActive(int $id): array
    {
        $customer = self::find($id) ?? throw new HttpException(404, 'Customer not found.');
        $next = (int) $customer['is_active'] === 1 ? 0 : 1;
        db()->prepare('UPDATE customers SET is_active = ? WHERE id = ?')->execute([$next, $id]);
        $customer['is_active'] = $next;
        return $customer;
    }

    /** Hard delete — only for customers without any sales (their receipts must keep the name). */
    public static function delete(int $id): array
    {
        $customer = self::find($id) ?? throw new HttpException(404, 'Customer not found.');
        $stmt = db()->prepare('SELECT COUNT(*) FROM sales WHERE customer_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new HttpException(409, "{$customer['name']} has purchase history, so they can't be deleted. Deactivate them instead to hide them from the POS.");
        }
        db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        return $customer;
    }
}
