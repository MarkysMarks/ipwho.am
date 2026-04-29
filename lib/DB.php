<?php
// ── ipwho.am — Database wrapper ────────────────────────────────────────────
class DB
{
    private static ?PDO $pdo = null;
    private static array $cfg = [];

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $c   = self::$cfg;
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";

        self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);

        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $row = self::q($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    /** Returns true on success, or the error message string on failure */
    public static function ping(): bool|string
    {
        try {
            self::value('SELECT 1');
            return true;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
