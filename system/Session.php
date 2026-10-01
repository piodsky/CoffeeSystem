<?php
/**
 * Hardened session handling:
 *  - strict mode, cookie-only, HttpOnly, SameSite=Lax, Secure on HTTPS
 *  - cookie scoped to this app's path (/EXECOMLOGISTICS/)
 *  - user-agent binding, periodic session ID rotation
 *  - optional auto-logout (SESSION_IDLE_TIMEOUT / SESSION_ABSOLUTE_TIMEOUT, 0 = off)
 */
declare(strict_types=1);

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Keep session files around long enough that PHP's garbage collector
        // never signs a user out on its own (7 days when auto-logout is off).
        $idle     = (int) config('app.session.idle_timeout', 0);
        $lifetime = $idle > 0 ? $idle + 300 : 7 * 86400;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_name((string) config('app.session.name', 'EXECOM_SID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => base_path() . '/',
            'domain'   => '',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::enforceLimits();
    }

    /** Call right after a successful login (prevents session fixation). */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $now = time();
        $_SESSION['_created']       = $now;
        $_SESSION['_last_activity'] = $now;
        $_SESSION['_rotated_at']    = $now;
        $_SESSION['_fingerprint']   = self::fingerprint();
    }

    /** Wipe the session completely, then start a fresh anonymous one. */
    public static function restart(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
        self::start();
    }

    private static function enforceLimits(): void
    {
        $now = time();

        if (isset($_SESSION['auth'])) {
            $idle     = (int) config('app.session.idle_timeout', 0);
            $absolute = (int) config('app.session.absolute_timeout', 0);

            $signOut = ($idle > 0 && $now - (int) ($_SESSION['_last_activity'] ?? 0) > $idle)
                || ($absolute > 0 && $now - (int) ($_SESSION['_created'] ?? 0) > $absolute)
                // Cookie replayed from a different browser -> treat as hijack.
                || !hash_equals((string) ($_SESSION['_fingerprint'] ?? ''), self::fingerprint());

            if ($signOut) {
                self::restart();
                flash('warning', 'Please sign in to continue.');
                return;
            }

            // Rotate the ID on normal page loads only; rotating during parallel
            // AJAX calls could sign the cashier out mid-sale.
            $rotateEvery = (int) config('app.session.rotate_every', 900);
            if (!is_api_request() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
                && $now - (int) ($_SESSION['_rotated_at'] ?? 0) > $rotateEvery) {
                session_regenerate_id(true);
                $_SESSION['_rotated_at'] = $now;
            }
        }

        $_SESSION['_last_activity'] = $now;
    }

    private static function fingerprint(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}
