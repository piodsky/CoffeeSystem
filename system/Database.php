<?php
/**
 * PDO connection (lazy singleton). Always use prepared statements:
 *   $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
 *   $stmt->execute([$id]);
 */
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $c = config('database');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);

        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real server-side prepared statements
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // Strict mode: invalid/oversized values raise errors instead of being silently truncated.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        // Keep NOW() in MySQL aligned with PHP's timezone.
        $pdo->prepare('SET time_zone = ?')->execute([(new DateTimeImmutable())->format('P')]);

        return self::$pdo = $pdo;
    }
}

function db(): PDO
{
    return Database::connection();
}
