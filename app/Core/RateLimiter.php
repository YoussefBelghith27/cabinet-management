<?php
declare(strict_types=1);

namespace App\Core;

/** Fixed-window rate limiter stored in MySQL (works across Apache workers). */
final class RateLimiter
{
    /** Records one hit and returns the number of hits in the current window. */
    public static function hit(string $key, int $windowSeconds): int
    {
        $bucket = hash('sha256', $key);
        $now = time();
        $cutoff = $now - $windowSeconds;
        // Atomic upsert; in ON CONFLICT both CASEs read the row's previous values.
        $hits = Database::value(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?::bigint)
             ON CONFLICT (bucket) DO UPDATE SET
               hits = CASE WHEN rate_limits.window_start <= ?::bigint THEN 1 ELSE rate_limits.hits + 1 END,
               window_start = CASE WHEN rate_limits.window_start <= ?::bigint THEN ?::bigint ELSE rate_limits.window_start END
             RETURNING hits',
            [$bucket, $now, $cutoff, $cutoff, $now]
        );
        if (random_int(1, 200) === 1) {
            Database::run('DELETE FROM rate_limits WHERE window_start < ?::bigint', [$now - 86400]);
        }
        return (int) $hits;
    }

    public static function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        $row = Database::one('SELECT hits, window_start FROM rate_limits WHERE bucket = ?', [hash('sha256', $key)]);
        return $row !== null && $row['window_start'] > time() - $windowSeconds && $row['hits'] >= $max;
    }

    public static function clear(string $key): void
    {
        Database::run('DELETE FROM rate_limits WHERE bucket = ?', [hash('sha256', $key)]);
    }
}
