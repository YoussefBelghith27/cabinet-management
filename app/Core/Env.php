<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Loads secrets/config from the .env file (kept outside the web root and out of Git).
 * Real environment variables take precedence, so production can inject secrets directly.
 */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Fichier .env introuvable : copiez .env.example vers .env (ou lancez php database/install.php).');
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = self::$vars[$key] ?? null;
        }
        if ($value === null) {
            return $default;
        }
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }
}
