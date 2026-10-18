<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Hardened session: strict mode, HttpOnly + SameSite cookies (Secure + __Host- prefix over HTTPS),
 * idle and absolute timeouts, ID regeneration on privilege change.
 */
final class Session
{
    private static array $old = [];
    private static array $errors = [];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = str_starts_with((string) env('APP_URL', ''), 'https://');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (self::absoluteSeconds()));
        session_name($secure ? '__Host-gcsess' : 'gcsess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $idle = (int) env('SESSION_IDLE_MINUTES', 30) * 60;
        $expired = isset($_SESSION['_last'])
            && ($now - $_SESSION['_last'] > $idle || $now - ($_SESSION['_created'] ?? $now) > self::absoluteSeconds());
        if ($expired) {
            $wasLoggedIn = isset($_SESSION['uid']);
            self::destroy();
            session_start();
            session_regenerate_id(true);
            if ($wasLoggedIn) {
                self::flash('warning', 'Votre session a expiré. Veuillez vous reconnecter.');
            }
        }
        $_SESSION['_created'] ??= $now;
        $_SESSION['_last'] = $now;

        // One-shot form state from the previous request.
        self::$old = $_SESSION['_old'] ?? [];
        self::$errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }

    private static function absoluteSeconds(): int
    {
        return (int) env('SESSION_ABSOLUTE_HOURS', 10) * 3600;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => $p['samesite'],
            ]);
            session_destroy();
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    public static function setOld(array $input): void
    {
        // Never keep passwords or tokens in session.
        $_SESSION['_old'] = array_filter(
            $input,
            fn ($v, $k) => is_scalar($v) && !str_contains((string) $k, 'password') && $k !== '_csrf' && $k !== 'code',
            ARRAY_FILTER_USE_BOTH
        );
    }

    public static function setErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    public static function old(): array
    {
        return self::$old;
    }

    public static function errors(): array
    {
        return self::$errors;
    }
}
