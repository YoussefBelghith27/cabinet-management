<?php
declare(strict_types=1);

// PHP built-in server: let it serve real static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Router;
use App\Core\Session;

// Security headers (strict CSP: no inline scripts, no third-party resources).
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');
if (str_starts_with((string) env('APP_URL', ''), 'https://')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header_remove('X-Powered-By');

$path = current_path();
$isApi = str_starts_with($path, '/api/');

try {
    $maxBytes = (int) env('MAX_BODY_BYTES', 1048576);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
        throw new HttpException(413);
    }

    Session::start();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !Csrf::verify()) {
        Audit::log('csrf_failed', 'warning', ['path' => $path]);
        throw new HttpException(419);
    }

    $router = new Router();
    require BASE_PATH . '/app/routes.php';
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (HttpException $e) {
    render_error($e->status, $e->getMessage(), $isApi);
} catch (Throwable $e) {
    // Full details in the log, a reference number for the user, no stack trace in the response.
    $ref = strtoupper(bin2hex(random_bytes(4)));
    error_log("[$ref] " . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    render_error(500, env('APP_DEBUG', false) ? $e->getMessage() : '', $isApi, $ref);
}
