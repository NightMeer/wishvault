<?php
declare(strict_types=1);

/** Einfacher Key-Value-Cache in SQLite. */
final class Cache
{
    public static function get(string $key): mixed
    {
        $stmt = db()->prepare('SELECT value FROM cache WHERE key = ? AND expires_at > ?');
        $stmt->execute([$key, time()]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : json_decode($v, true);
    }

    public static function set(string $key, mixed $value, int $ttl): void
    {
        db()->prepare('INSERT INTO cache (key, value, expires_at) VALUES (?, ?, ?)
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at')
            ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE), time() + $ttl]);
    }

    public static function delete(string $key): void
    {
        db()->prepare('DELETE FROM cache WHERE key = ?')->execute([$key]);
    }

    public static function purgeExpired(): void
    {
        db()->prepare('DELETE FROM cache WHERE expires_at <= ?')->execute([time()]);
    }
}
