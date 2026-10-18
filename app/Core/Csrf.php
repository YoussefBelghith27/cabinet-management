<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    /** Checked for every POST request in the front controller (form field or X-CSRF-Token header). */
    public static function verify(): bool
    {
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($sent) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $sent);
    }
}
