<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set((string) env('APP_TIMEZONE', 'Africa/Tunis'));

// Never leak stack traces to visitors; errors go to storage/logs instead.
error_reporting(E_ALL);
ini_set('display_errors', env('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
if (!serverless()) {
    ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log'); // on Vercel: stderr → Vercel logs
}
ini_set('expose_php', '0');

// Command-line scripts (install, backup, migration, tests): show the error instead of failing silently.
if (PHP_SAPI === 'cli') {
    set_exception_handler(static function (Throwable $e): void {
        $message = $e->getMessage();
        if (str_contains($message, 'password authentication failed')) {
            $message .= "\n  → Le mot de passe dans .env ne correspond pas à celui de la base Supabase"
                . " (Project Settings > Database > Reset database password).";
        } elseif (str_contains($message, 'Tenant or user not found')) {
            $message .= "\n  → Vérifiez DB_HOST (région du pooler) et DB_PROJECT_REF dans .env.";
        }
        fwrite(STDERR, '✘ ' . $e::class . ' : ' . $message . "\n");
        error_log($e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        exit(1);
    });
}
