<?php
/**
 * CSRF protection (synchronizer token, one per session).
 *   Forms: <?= Csrf::field() ?>          -> posts "_csrf"
 *   AJAX:  header "X-CSRF-Token" (read from <meta name="csrf-token">)
 */
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function rotate(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function validate(mixed $token): bool
    {
        return is_string($token)
            && isset($_SESSION['_csrf']) && is_string($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }

    /** Validate the token of the current request, or stop with 403. */
    public static function verifyRequest(): void
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::validate($token)) {
            abort(403, 'Please refresh the page and try again.', 'Security Check Failed');
        }
    }
}
