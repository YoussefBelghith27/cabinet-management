<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\View;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

/** Encode for HTML output (prevents XSS). Use for every dynamic value in views. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function old(string $key, mixed $default = ''): string
{
    $value = Session::old()[$key] ?? $default;
    return is_scalar($value) ? (string) $value : '';
}

function field_error(string $key): string
{
    $message = Session::errors()[$key] ?? null;
    return $message ? '<div class="field-error">' . e($message) . '</div>' : '';
}

function redirect(string $path): never
{
    // Only local paths: prevents open redirects.
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
        $path = '/';
    }
    header('Location: ' . $path, true, 303);
    exit;
}

function abort(int $status, string $message = ''): never
{
    throw new HttpException($status, $message);
}

/** Self-service creation of new clinics (tenants). Off by default: a single-clinic deployment must not let strangers sign up. */
function registration_open(): bool
{
    return (bool) env('ALLOW_CLINIC_REGISTRATION', false);
}

/** True on Vercel: read-only file system, logs go to the platform, requests arrive through its proxy. */
function serverless(): bool
{
    // VERCEL is only set when system variables are exposed; LAMBDA_TASK_ROOT always is (vercel-php).
    return getenv('VERCEL') !== false || getenv('LAMBDA_TASK_ROOT') !== false;
}

function client_ip(): string
{
    // On Vercel, REMOTE_ADDR is the local runtime; Vercel's edge overwrites X-Real-IP with the real
    // client address, so it can be trusted there. Elsewhere REMOTE_ADDR only: forwarded headers are
    // client-controlled unless behind a trusted proxy.
    if (serverless()) {
        $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''))[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function current_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return rtrim(rawurldecode($path), '/') ?: '/';
}

function nav_active(string $prefix): string
{
    $path = current_path();
    return ($path === $prefix || str_starts_with($path, $prefix . '/')) ? ' class="active"' : '';
}

function currency_decimals(string $currency): int
{
    return in_array(strtoupper($currency), ['TND', 'KWD', 'BHD', 'OMR', 'LYD'], true) ? 3 : 2;
}

function money(mixed $amount, string $currency = 'TND'): string
{
    return number_format((float) $amount, currency_decimals($currency), ',', ' ') . ' ' . $currency;
}

function fmt_date(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '—';
}

function fmt_dt(?string $datetime): string
{
    return $datetime ? date('d/m/Y H:i', strtotime($datetime)) : '—';
}

function status_label(string $status): string
{
    return [
        'pending' => 'En attente',
        'confirmed' => 'Confirmé',
        'completed' => 'Terminé',
        'cancelled' => 'Annulé',
        'no_show' => 'Absent',
        'unpaid' => 'Impayée',
        'partial' => 'Partielle',
        'paid' => 'Payée',
    ][$status] ?? $status;
}

function role_label(string $role): string
{
    return [
        'admin' => 'Administrateur',
        'dentist' => 'Dentiste',
        'secretary' => 'Secrétaire',
        'patient' => 'Patient',
    ][$role] ?? $role;
}

function method_label(string $method): string
{
    return [
        'cash' => 'Espèces',
        'card' => 'Carte',
        'check' => 'Chèque',
        'transfer' => 'Virement',
        'insurance' => 'Assurance',
    ][$method] ?? $method;
}

function slugify(string $text): string
{
    $map = ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n'];
    $text = strtr(mb_strtolower($text, 'UTF-8'), $map);
    $text = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    return substr($text, 0, 50) ?: 'cabinet';
}

function random_password(int $length = 14): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function password_algo(): string|int|null
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function render_error(int $status, string $message, bool $json, ?string $ref = null): void
{
    $defaults = [
        400 => 'Requête invalide.',
        401 => 'Authentification requise.',
        403 => 'Accès refusé.',
        404 => 'Page introuvable.',
        405 => 'Méthode non autorisée.',
        413 => 'Requête trop volumineuse.',
        419 => 'Jeton de sécurité expiré. Rechargez la page et réessayez.',
        429 => 'Trop de requêtes. Réessayez plus tard.',
        500 => 'Une erreur interne est survenue.',
    ];
    $message = $message !== '' ? $message : ($defaults[$status] ?? 'Erreur.');
    if (!headers_sent()) {
        http_response_code($status);
    }
    if ($json) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $message, 'ref' => $ref], JSON_UNESCAPED_UNICODE);
        return;
    }
    try {
        echo View::render('errors/error', ['status' => $status, 'message' => $message, 'ref' => $ref, 'title' => 'Erreur ' . $status], 'public');
    } catch (Throwable) {
        echo '<h1>' . $status . '</h1><p>' . e($message) . '</p>';
    }
}

/** Inline icon from the sprite in app/Views/partials/icons.php (decorative: hidden from screen readers). */
function icon(string $name, string $class = ''): string
{
    $name = preg_replace('/[^a-z-]/', '', $name);
    return '<svg class="i' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false"><use href="#i-' . $name . '"/></svg>';
}

/** "Amira Ben Salah" → "AB" (avatar placeholder). */
function initials(string $name): string
{
    $words = array_values(array_filter(preg_split('/[\s.\-]+/u', preg_replace('/^(dr|pr)\.?\s+/iu', '', trim($name))) ?: []));
    $letters = array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 0, 2));
    return implode('', $letters) ?: '?';
}

/** French long date without the intl extension: "jeudi 16 octobre 2026". */
function fr_date(?int $timestamp = null): string
{
    $timestamp ??= time();
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $days[(int) date('w', $timestamp)] . ' ' . date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);
}
