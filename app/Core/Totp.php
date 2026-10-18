<?php
declare(strict_types=1);

namespace App\Core;

/** RFC 6238 TOTP (Google Authenticator, Microsoft Authenticator, Aegis…). */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

    /** Returns the matched time-step (store it to block replay), or null. */
    public static function verify(string $secret, string $code, int $lastUsedStep = 0): ?int
    {
        $code = (string) preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return null;
        }
        $now = intdiv(time(), 30);
        for ($i = -1; $i <= 1; $i++) {
            $step = $now + $i;
            if ($step > $lastUsedStep && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N2', 0, $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
