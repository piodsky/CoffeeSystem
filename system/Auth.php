<?php
/**
 * Authentication & role checks.
 * Only the user ID (and a stamp of the password hash) is kept in the session; the user row
 * is reloaded on every request, so disabling a user, changing a role or resetting a
 * password applies at once (a new password ends that user's other sessions).
 */
declare(strict_types=1);

final class Auth
{
    /** Valid bcrypt hash of a random string — used when the username doesn't exist
     *  so both paths cost one password_verify() (no username enumeration by timing). */
    private const DUMMY_HASH = '$2y$10$Frzh6ApwFrfV3jB1qMgF1OpTZrflXktAq8DBE3PaAewG28QDNXUDe';

    private static ?array $user = null;
    private static bool $loaded = false;

    /** @return array{ok:bool, error?:string} */
    public static function attempt(string $username, string $password): array
    {
        $ip      = client_ip();
        $max     = max(1, (int) config('app.security.login_max_attempts', 5));
        $minutes = max(1, (int) config('app.security.lockout_minutes', 15));

        if (self::isLockedOut($username, $ip, $max, $minutes)) {
            return ['ok' => false, 'error' => "Too many failed attempts. Please wait {$minutes} minutes and try again."];
        }

        $stmt = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $valid = password_verify($password, $user ? $user['password_hash'] : self::DUMMY_HASH) && $user !== false;

        if (!$valid) {
            self::recordAttempt($username, $ip, false);
            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }

        if ((int) $user['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'This account is disabled. Please contact the administrator.'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        db()->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND success = 0')
            ->execute([$username, $ip]);
        self::recordAttempt($username, $ip, true);
        self::login((int) $user['id']);

        return ['ok' => true];
    }

    public static function login(int $userId): void
    {
        $intended = $_SESSION['_intended'] ?? null;

        $_SESSION = [];
        Session::regenerate();
        Csrf::rotate();
        $_SESSION['auth'] = ['id' => $userId, 'pw' => self::passwordStamp($userId)];
        if (is_string($intended)) {
            $_SESSION['_intended'] = $intended;
        }

        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);

        self::$loaded = false;
        self::$user   = null;
    }

    public static function logout(): void
    {
        self::$user   = null;
        self::$loaded = true;
        Session::restart();
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['auth']['id'] ?? null;
            if (is_int($id)) {
                $stmt = db()->prepare('SELECT id, username, full_name, role, password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                $stmt->execute([$id]);
                $row   = $stmt->fetch() ?: null;
                $stamp = $row ? hash('sha256', $row['password_hash']) : '';
                if ($row !== null && !isset($_SESSION['auth']['pw'])) {
                    $_SESSION['auth']['pw'] = $stamp; // session from before stamps existed
                }
                if ($row === null || !hash_equals((string) $_SESSION['auth']['pw'], $stamp)) {
                    unset($_SESSION['auth']); // deleted, disabled, or password changed elsewhere
                    self::$user = null;
                } else {
                    unset($row['password_hash']);
                    self::$user = $row;
                }
            }
        }
        return self::$user;
    }

    /** Hash of the stored password hash: changes whenever the password does. */
    private static function passwordStamp(int $userId): string
    {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return hash('sha256', (string) $stmt->fetchColumn());
    }

    /** Keep the current session signed in after the user changes their own password. */
    public static function refreshPasswordStamp(): void
    {
        if (isset($_SESSION['auth']['id'])) {
            $_SESSION['auth']['pw'] = self::passwordStamp((int) $_SESSION['auth']['id']);
        }
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user() ? (int) self::user()['id'] : null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['role'], $roles, true);
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        if (is_api_request()) {
            json_response(['ok' => false, 'message' => 'Please sign in to continue.'], 401);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['_intended'] = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }
        redirect('login.php');
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::hasRole(...$roles)) {
            abort(403, 'You do not have permission to access this page.');
        }
    }

    /** Where to go after login: the page that was requested, if it is a safe local path. */
    public static function intendedUrl(): string
    {
        $url = $_SESSION['_intended'] ?? '';
        unset($_SESSION['_intended']);

        $prefix = base_path() . '/';
        if (is_string($url) && str_starts_with($url, $prefix)
            && !preg_match('#//|\\\\|[\r\n]#', substr($url, 1))) {
            return $url;
        }
        return url('pages/pos.php');
    }

    private static function isLockedOut(string $username, string $ip, int $max, int $minutes): bool
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $stmt  = db()->prepare(
            'SELECT COALESCE(SUM(username = ?), 0) AS pair_failures, COUNT(*) AS ip_failures
               FROM login_attempts
              WHERE ip_address = ? AND success = 0 AND attempted_at >= ?'
        );
        $stmt->execute([$username, $ip, $since]);
        $row = $stmt->fetch();

        // Per username+IP limit, plus a looser per-IP limit against password spraying.
        return (int) $row['pair_failures'] >= $max || (int) $row['ip_failures'] >= $max * 4;
    }

    private static function recordAttempt(string $username, string $ip, bool $success): void
    {
        db()->prepare('INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($username, 0, 50), $ip, $success ? 1 : 0, date('Y-m-d H:i:s')]);

        // Housekeeping: keep 30 days of history.
        if (random_int(1, 50) === 1) {
            db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
                ->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]);
        }
    }
}
