<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * PDO wrapper for PostgreSQL (Supabase). Parameters are never interpolated into SQL: they travel
 * separately in the protocol (PQexecParams, server-side binding), so user input cannot change a query.
 * PGSQL_ATTR_DISABLE_PREPARES sends query + parameters in ONE round trip instead of three
 * (prepare / execute / deallocate) — essential when the database is ~130 ms away.
 *
 * Supabase notes:
 *  - connect through the Supavisor pooler (session mode, port 5432); with no named prepared
 *    statements the transaction mode (6543) also works;
 *  - the pooler expects "role.project_ref" as user name (built automatically from DB_PROJECT_REF);
 *  - all tables live in a private schema (DB_SCHEMA, default "cabinet") that Supabase's public REST API
 *    does not expose.
 */
final class Database
{
    private static ?PDO $pdo = null;
    /** Own flag: PDO::inTransaction() answers true on a broken connection (status "unknown"). */
    private static bool $inTransaction = false;

    public static function pdo(): PDO
    {
        // Web requests reuse a persistent connection: opening TLS + auth to Supabase costs 1–2.5 s,
        // a query ~120 ms. CLI scripts (install, backup, tests) keep short-lived connections.
        $persistent = PHP_SAPI !== 'cli' && env('DB_PERSISTENT', true);
        return self::$pdo ??= self::connect((string) env('DB_USER', ''), (string) env('DB_PASS', ''), true, $persistent);
    }

    /** Opens a TLS connection with the clinic time zone and the app schema as search path. */
    public static function connect(string $user, #[\SensitiveParameter] string $password, bool $useAppSchema = true, bool $persistent = false): PDO
    {
        try {
            return self::open($user, $password, $useAppSchema, $persistent);
        } catch (\PDOException $e) {
            // A reused connection may have been closed by Supabase (idle timeout, project restart).
            // The failed query marks it broken, so the second attempt gets a fresh connection.
            if ($persistent && preg_match('/server closed|terminat|SSL|connection|08\d{3}/i', $e->getMessage())) {
                return self::open($user, $password, $useAppSchema, true);
            }
            throw $e;
        }
    }

    private static function open(string $user, #[\SensitiveParameter] string $password, bool $useAppSchema, bool $persistent): PDO
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new \RuntimeException("Extension PHP pdo_pgsql absente : activez « extension=pdo_pgsql » dans C:\\xampp\\php\\php.ini puis redémarrez Apache (ou le serveur PHP).");
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '5432'),
            env('DB_NAME', 'postgres'),
            env('DB_SSLMODE', 'require')
        );
        $rootCert = (string) env('DB_SSLROOTCERT', '');
        if ($rootCert !== '') {
            $dsn .= ';sslrootcert=' . $rootCert; // with DB_SSLMODE=verify-full: also checks the server identity
        }
        $pdo = new PDO($dsn, self::loginUser($user), $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::PGSQL_ATTR_DISABLE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            // PHP rolls back any transaction left open on a persistent connection at the end of the request.
            PDO::ATTR_PERSISTENT => $persistent,
        ]);
        // Timestamps are timestamptz: reading/writing them in the clinic's zone keeps PHP and SQL in agreement.
        // One round trip for the whole session setup.
        $pdo->exec('SET TIME ZONE ' . $pdo->quote((string) env('APP_TIMEZONE', 'Africa/Tunis'))
            . "; SET statement_timeout = '15s'"
            . ($useAppSchema ? '; SET search_path TO ' . self::ident(self::schema()) : ''));
        return $pdo;
    }

    /** Supabase pooler user names carry the project ref ("cabinet_app.abcd1234"). */
    public static function loginUser(string $user): string
    {
        $ref = (string) env('DB_PROJECT_REF', '');
        if ($ref !== '' && !str_contains($user, '.') && str_contains((string) env('DB_HOST', ''), 'pooler.supabase.com')) {
            return $user . '.' . $ref;
        }
        return $user;
    }

    public static function schema(): string
    {
        $schema = (string) env('DB_SCHEMA', 'cabinet');
        self::assertIdentifier($schema);
        return $schema;
    }

    /** Quotes a code-defined identifier (table, column, schema) for PostgreSQL. */
    public static function ident(string $name): string
    {
        self::assertIdentifier($name);
        return '"' . $name . '"';
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        // PDO sends parameters as text; PHP booleans must become Postgres boolean literals.
        $values = array_map(fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : $v, array_values($params));
        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($values);
            return $stmt;
        } catch (\PDOException $e) {
            // Supabase can drop a connection mid-request. A read outside a transaction is safe to
            // replay on a fresh connection; a write is never replayed (it may already have been applied).
            $lost = preg_match('/server closed the connection|terminating connection|no connection to the server|SSL SYSCALL/i', $e->getMessage());
            $isRead = preg_match('/^\s*(SELECT|WITH)\b/i', $sql) && !preg_match('/\bFOR\s+UPDATE\b/i', $sql);
            if (!$lost || !$isRead || self::$inTransaction) {
                throw $e;
            }
            self::$pdo = null;
            $stmt = self::pdo()->prepare($sql); // pdo() reconnects (persistent handle marked broken → new one)
            $stmt->execute($values);
            return $stmt;
        }
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        // fetchColumn() would confuse a boolean false value with "no row".
        $row = self::run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /** Table and column names come from code only, never from user input. */
    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        array_map([self::class, 'assertIdentifier'], array_keys($data));
        $cols = implode(', ', array_map([self::class, 'ident'], array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        return (int) self::value('INSERT INTO ' . self::ident($table) . " ($cols) VALUES ($marks) RETURNING id", array_values($data));
    }

    /** Updates a row scoped by id AND clinic_id (tenant isolation). */
    public static function updateOwned(string $table, int $id, int $clinicId, array $data): void
    {
        self::assertIdentifier($table);
        array_map([self::class, 'assertIdentifier'], array_keys($data));
        $set = implode(', ', array_map(fn ($c) => self::ident($c) . ' = ?', array_keys($data)));
        self::run('UPDATE ' . self::ident($table) . " SET $set WHERE id = ? AND clinic_id = ?", [...array_values($data), $id, $clinicId]);
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        self::$inTransaction = true;
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\PDOException) {
                // Connection already gone: the server discards the transaction; keep the original error.
            }
            throw $e;
        } finally {
            self::$inTransaction = false;
        }
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException('Identifiant SQL invalide.');
        }
    }
}
