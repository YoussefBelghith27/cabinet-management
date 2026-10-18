<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sessions stored in PostgreSQL (SESSION_DRIVER=database), for serverless hosting (Vercel)
 * where each request may run on a different instance and local files are not shared.
 * The session ID is stored as a SHA-256 hash: a database leak does not reveal live session IDs.
 * Implements validateId() so session.use_strict_mode keeps rejecting unknown IDs.
 */
final class DbSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function __construct(private readonly int $lifetime)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = Database::value('SELECT data FROM sessions WHERE id = ? AND expires_at > ?::bigint', [self::key($id), time()]);
        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        Database::run(
            'INSERT INTO sessions (id, data, expires_at) VALUES (?, ?, ?::bigint)
             ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, expires_at = EXCLUDED.expires_at',
            [self::key($id), $data, time() + $this->lifetime]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::run('DELETE FROM sessions WHERE id = ?', [self::key($id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return Database::run('DELETE FROM sessions WHERE expires_at <= ?::bigint', [time()])->rowCount();
    }

    public function validateId(string $id): bool
    {
        return (bool) Database::value('SELECT 1 FROM sessions WHERE id = ? AND expires_at > ?::bigint', [self::key($id), time()]);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    private static function key(string $id): string
    {
        return hash('sha256', $id);
    }
}
