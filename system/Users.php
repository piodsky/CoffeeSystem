<?php
/**
 * User accounts: listing, validation, create/update, activate/deactivate, delete, passwords.
 * Safety rules (enforced here, not just hidden in the UI):
 *   - you can't deactivate, delete or change the role of your own account;
 *   - there is always at least one active admin;
 *   - users who rang up sales can only be deactivated (sales keep their cashier).
 */
declare(strict_types=1);

final class Users
{
    public const MIN_PASSWORD = 8;
    /** bcrypt only uses the first 72 bytes */
    public const MAX_PASSWORD = 72;
    /** Passwords that are refused outright (the shipped defaults and the usual suspects). */
    private const WEAK = ['admin123', 'cashier123', 'password', 'password1', 'password123', '12345678',
        '123456789', '1234567890', 'qwerty123', 'qwertyui', '11111111', '00000000', 'execom123', 'abcd1234'];
    /** The sample logins from database.sql — used to warn at sign-in. */
    public const DEFAULT_PASSWORDS = ['admin123', 'cashier123'];

    public static function all(): array
    {
        $stmt = db()->prepare(
            'SELECT u.id, u.username, u.full_name, u.role, u.is_active, u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM sales s WHERE s.user_id = u.id) AS sales
               FROM users u
              ORDER BY u.is_active DESC, u.role, u.full_name'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT u.id, u.username, u.full_name, u.role, u.is_active, u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM sales s WHERE s.user_id = u.id) AS sales
               FROM users u WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function activeAdmins(?int $exceptId = null): int
    {
        $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?");
        $stmt->execute([$exceptId ?? 0]);
        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /** Why a password is not acceptable, or null if it is fine. */
    public static function passwordProblem(string $password, string $username = ''): ?string
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            return 'Use at least ' . self::MIN_PASSWORD . ' characters.';
        }
        if (strlen($password) > self::MAX_PASSWORD) {
            return 'Use at most ' . self::MAX_PASSWORD . ' characters.';
        }
        if ($username !== '' && stripos($password, $username) !== false) {
            return "Don't include the username in the password.";
        }
        if (in_array(strtolower($password), self::WEAK, true) || count(array_unique(str_split($password))) < 4) {
            return 'This password is too easy to guess. Choose another.';
        }
        return null;
    }

    /**
     * Validate the user form. $id = null for a new user; $selfId = the admin doing it.
     * The password is required for new users and optional when editing (blank = keep).
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $input, ?int $id, int $selfId): array
    {
        $data = [
            'username'  => input_string($input, 'username', 50),
            'full_name' => input_string($input, 'full_name', 100),
            'role'      => is_string($input['role'] ?? null) && array_key_exists($input['role'], config('app.roles')) ? $input['role'] : '',
        ];
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $confirm  = is_string($input['password_confirm'] ?? null) ? $input['password_confirm'] : '';
        $errors   = [];

        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $data['username'])) {
            $errors['username'] = 'Use 3–50 letters, numbers, dots, dashes or underscores (no spaces).';
        } else {
            $stmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $stmt->execute([$data['username'], $id ?? 0]);
            if ($stmt->fetch()) {
                $errors['username'] = 'This username is already taken.';
            }
        }
        if (mb_strlen($data['full_name']) < 2) {
            $errors['full_name'] = 'Enter the full name (at least 2 characters).';
        }
        if ($data['role'] === '') {
            $errors['role'] = 'Choose a role.';
        }

        if ($id !== null) {
            $current = self::find($id) ?? throw new HttpException(404, 'User not found.');
            if ($id === $selfId && $data['role'] !== '' && $data['role'] !== $current['role']) {
                $errors['role'] = "You can't change your own role.";
            } elseif ($current['role'] === 'admin' && $data['role'] === 'cashier' && (int) $current['is_active'] === 1
                && self::activeAdmins($id) === 0) {
                $errors['role'] = 'This is the only active admin. Make another user an admin first.';
            }
        }

        if ($password !== '' || $id === null) {
            $problem = self::passwordProblem($password, $data['username']);
            if ($problem !== null) {
                $errors['password'] = $problem;
            } elseif (!hash_equals($password, $confirm)) {
                $errors['password_confirm'] = "The passwords don't match.";
            }
        }
        $data['password'] = $password; // '' = keep the current one

        return [$data, $errors];
    }

    // ------------------------------------------------------------------
    // Create / update / status / delete
    // ------------------------------------------------------------------

    public static function create(array $data): int
    {
        db()->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)')
            ->execute([$data['username'], password_hash($data['password'], PASSWORD_DEFAULT), $data['full_name'], $data['role']]);
        return (int) db()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        db()->prepare('UPDATE users SET username = ?, full_name = ?, role = ? WHERE id = ?')
            ->execute([$data['username'], $data['full_name'], $data['role'], $id]);
        if ($data['password'] !== '') {
            self::setPassword($id, $data['password']);
        }
    }

    /** New password hash. Other sessions of that user end on their next request (see Auth::user()). */
    public static function setPassword(int $id, string $password): void
    {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    /** @return array the user after the change */
    public static function toggleActive(int $id, int $selfId): array
    {
        $user = self::find($id) ?? throw new HttpException(404, 'User not found.');
        if ($id === $selfId) {
            throw new HttpException(409, "You can't deactivate your own account.");
        }
        $active = (int) $user['is_active'] === 1;
        if ($active && $user['role'] === 'admin' && self::activeAdmins($id) === 0) {
            throw new HttpException(409, "{$user['full_name']} is the only active admin and can't be deactivated.");
        }
        db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 0 : 1, $id]);
        $user['is_active'] = $active ? 0 : 1;
        return $user;
    }

    public static function delete(int $id, int $selfId): array
    {
        $user = self::find($id) ?? throw new HttpException(404, 'User not found.');
        if ($id === $selfId) {
            throw new HttpException(409, "You can't delete your own account.");
        }
        if ((int) $user['sales'] > 0) {
            throw new HttpException(409, "{$user['full_name']} has {$user['sales']} sales on record and can only be deactivated.");
        }
        if ($user['role'] === 'admin' && (int) $user['is_active'] === 1 && self::activeAdmins($id) === 0) {
            throw new HttpException(409, "{$user['full_name']} is the only active admin and can't be deleted.");
        }
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        return $user;
    }

    /** Change your own password after confirming the current one. @return array<string,string> field errors */
    public static function changeOwnPassword(int $id, string $current, string $new, string $confirm): array
    {
        $stmt = db()->prepare('SELECT username, password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: throw new HttpException(404, 'User not found.');

        if (!password_verify($current, $user['password_hash'])) {
            return ['current_password' => 'The current password is not correct.'];
        }
        if (hash_equals($current, $new)) {
            return ['password' => 'Choose a password different from the current one.'];
        }
        $problem = self::passwordProblem($new, $user['username']);
        if ($problem !== null) {
            return ['password' => $problem];
        }
        if (!hash_equals($new, $confirm)) {
            return ['password_confirm' => "The passwords don't match."];
        }
        self::setPassword($id, $new);
        return [];
    }
}
