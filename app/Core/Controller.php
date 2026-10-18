<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    /** Tables that carry a clinic_id column and may be loaded through findOwned(). */
    private const TENANT_TABLES = ['patients', 'appointments', 'invoices', 'treatments', 'users', 'procedures', 'payments'];

    protected function view(string $view, array $data = [], ?string $layout = 'app'): void
    {
        header('Cache-Control: no-store'); // pages contain patient data: never cache
        echo View::render($view, $data, $layout);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Server-side authorization: the user's role AND clinic come from the session/database,
     * never from the request. Admins without MFA (when required) are sent to the security page.
     */
    protected function requireRole(array $roles, bool $exempt = false): array
    {
        $user = Auth::user();
        if (!$user) {
            if (str_starts_with(current_path(), '/api/')) {
                abort(401);
            }
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '/dashboard';
            }
            Session::flash('warning', 'Veuillez vous connecter.');
            redirect('/login');
        }
        if (!in_array($user['role'], $roles, true)) {
            Audit::log('access_denied', 'warning', ['path' => current_path(), 'role' => $user['role']]);
            abort(403);
        }
        if (!$exempt) {
            if ($user['must_change_password']) {
                Session::flash('warning', 'Veuillez choisir un nouveau mot de passe avant de continuer.');
                redirect('/security');
            }
            if ($user['role'] === 'admin' && env('MFA_REQUIRED_FOR_ADMIN', true) && !$user['mfa_enabled']) {
                Session::flash('warning', "L'authentification à deux facteurs est obligatoire pour les administrateurs.");
                redirect('/security');
            }
        }
        return $user;
    }

    protected function requireStaff(): array
    {
        return $this->requireRole(Auth::STAFF);
    }

    /**
     * Loads a record only if it belongs to the given clinic. A record that exists in another
     * clinic answers 404 (no information leak) and raises a security alert.
     */
    protected function findOwned(string $table, int $id, int $clinicId): array
    {
        if (!in_array($table, self::TENANT_TABLES, true)) {
            throw new \LogicException('Table non autorisée.');
        }
        $row = Database::one('SELECT * FROM ' . Database::ident($table) . ' WHERE id = ? AND clinic_id = ?', [$id, $clinicId]);
        if (!$row) {
            if (Database::value('SELECT 1 FROM ' . Database::ident($table) . ' WHERE id = ?', [$id])) {
                Audit::log('cross_tenant_access_attempt', 'critical', ['table' => $table, 'id' => $id, 'path' => current_path()]);
            }
            abort(404);
        }
        return $row;
    }

    protected function rateLimit(string $key, int $max, int $windowSeconds): void
    {
        if (RateLimiter::hit($key, $windowSeconds) > $max) {
            Audit::log('rate_limited', 'warning', ['bucket' => preg_replace('/:[^:]*@[^:]*/', ':***', $key)]);
            header('Retry-After: ' . $windowSeconds);
            abort(429);
        }
    }

    protected function back(string $to, array $errors, array $input = []): never
    {
        Session::setOld($input);
        Session::setErrors($errors);
        Session::flash('error', 'Veuillez corriger les erreurs du formulaire.');
        redirect($to);
    }

    protected function postArray(string $key): array
    {
        $value = $_POST[$key] ?? [];
        return is_array($value) ? $value : [];
    }
}
