<?php
declare(strict_types=1);

namespace App\Core;

/** AES-256-GCM encryption with APP_KEY, for secrets stored in the database (e.g. MFA seeds). */
final class Crypto
{
    private static function key(): string
    {
        $key = (string) env('APP_KEY', '');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: '';
        }
        if (strlen($key) !== 32) {
            throw new \RuntimeException('APP_KEY invalide : 32 octets attendus (base64:...).');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Données chiffrées invalides.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('Déchiffrement impossible.');
        }
        return $plain;
    }
}
