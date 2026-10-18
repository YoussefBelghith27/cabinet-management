<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\QrCode;
use App\Core\Session;
use App\Core\Totp;
use App\Core\Validator;

final class SecurityController extends Controller
{
    public function index(): void
    {
        // Exempt: this page is where forced password change / MFA enrolment happens.
        $user = $this->requireRole(Auth::STAFF, exempt: true);
        if (!$user['mfa_enabled']) {
            $_SESSION['mfa_setup_secret'] ??= Totp::generateSecret();
        }
        $secret = $_SESSION['mfa_setup_secret'] ?? null;
        $this->view('security/index', [
            'title' => 'Mon compte & sécurité',
            'user' => $user,
            'secret' => $secret,
            'otpUri' => $otpUri = $secret ? Totp::uri($secret, $user['email'], (string) env('APP_NAME', 'GestionCabinet')) : null,
            // QR generated on our server: the 2FA secret is never sent to a third-party QR service.
            'qrSvg' => $otpUri ? QrCode::svg($otpUri, 220, 'QR code à scanner avec votre application d’authentification') : null,
            'recoveryCodes' => $_SESSION['recovery_codes_once'] ?? null,
            'mfaRequired' => $user['role'] === 'admin' && env('MFA_REQUIRED_FOR_ADMIN', true),
        ]);
        unset($_SESSION['recovery_codes_once']);
    }

    public function password(): void
    {
        $user = $this->requireRole(Auth::STAFF, exempt: true);
        $this->rateLimit('pwchange:' . $user['id'], 10, 3600);
        [$d, $errors] = Validator::make($_POST, [
            'current_password' => 'required',
            'password' => 'required|password',
            'password_confirmation' => 'required',
        ]);
        if (!$errors && !password_verify((string) $d['current_password'], $user['password_hash'])) {
            $errors['current_password'] = 'Mot de passe actuel incorrect.';
            Audit::log('password_change_failed', 'warning');
        }
        if (!$errors && $d['password'] !== $d['password_confirmation']) {
            $errors['password_confirmation'] = 'Les mots de passe ne correspondent pas.';
        }
        if (!$errors && hash_equals((string) $d['current_password'], (string) $d['password'])) {
            $errors['password'] = 'Le nouveau mot de passe doit être différent.';
        }
        if ($errors) {
            $this->back('/security', $errors);
        }
        Database::run('UPDATE users SET password_hash = ?, must_change_password = false WHERE id = ?', [password_hash($d['password'], password_algo()), $user['id']]);
        Auth::bumpEpoch((int) $user['id']); // logs out other devices, keeps this one
        Audit::log('password_changed', 'info');
        Session::flash('success', 'Mot de passe modifié. Vos autres sessions ont été fermées.');
        redirect('/security');
    }

    public function enableMfa(): void
    {
        $user = $this->requireRole(Auth::STAFF, exempt: true);
        $this->rateLimit('mfa-enable:' . $user['id'], 10, 900);
        $secret = $_SESSION['mfa_setup_secret'] ?? null;
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        if ($user['mfa_enabled'] || !$secret) {
            redirect('/security');
        }
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            Session::flash('error', 'Code incorrect. Vérifiez l’heure de votre téléphone et réessayez.');
            redirect('/security');
        }
        [$plain, $hashes] = Auth::makeRecoveryCodes();
        Database::run(
            'UPDATE users SET mfa_enabled = true, mfa_secret = ?, mfa_last_step = ?, mfa_recovery = ? WHERE id = ?',
            [Crypto::encrypt($secret), $step, json_encode($hashes), $user['id']]
        );
        unset($_SESSION['mfa_setup_secret']);
        $_SESSION['recovery_codes_once'] = $plain;
        Audit::log('mfa_enabled', 'info');
        Session::flash('success', 'Double authentification activée. Conservez vos codes de secours en lieu sûr.');
        redirect('/security');
    }

    public function disableMfa(): void
    {
        $user = $this->requireRole(Auth::STAFF, exempt: true);
        if ($user['role'] === 'admin' && env('MFA_REQUIRED_FOR_ADMIN', true)) {
            abort(403, 'La double authentification est obligatoire pour les administrateurs.');
        }
        $this->rateLimit('mfa-disable:' . $user['id'], 5, 900);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        $step = $user['mfa_enabled'] ? Totp::verify(Crypto::decrypt((string) $user['mfa_secret']), $code, (int) $user['mfa_last_step']) : null;
        if (!password_verify($password, $user['password_hash']) || $step === null) {
            Session::flash('error', 'Mot de passe ou code incorrect.');
            redirect('/security');
        }
        Database::run('UPDATE users SET mfa_enabled = false, mfa_secret = NULL, mfa_recovery = NULL WHERE id = ?', [$user['id']]);
        Audit::log('mfa_disabled', 'warning');
        Session::flash('success', 'Double authentification désactivée.');
        redirect('/security');
    }

    public function events(): void
    {
        $user = $this->requireRole(['admin']);
        $severity = in_array($_GET['severity'] ?? '', ['info', 'warning', 'critical'], true) ? $_GET['severity'] : '';
        $sql = 'SELECT l.*, u.name AS user_name FROM audit_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.clinic_id = ?';
        $params = [$user['clinic_id']];
        if ($severity) {
            $sql .= ' AND l.severity = ?';
            $params[] = $severity;
        }
        $this->view('security/events', [
            'title' => 'Journal de sécurité',
            'events' => Database::all($sql . ' ORDER BY l.id DESC LIMIT 300', $params),
            'severity' => $severity,
            'summary' => Database::one(
                "SELECT count(*) FILTER (WHERE event = 'login_failed') AS failed,
                        count(*) FILTER (WHERE event = 'account_locked') AS locked,
                        count(*) FILTER (WHERE severity = 'critical') AS critical
                   FROM audit_log WHERE clinic_id = ? AND created_at >= now() - interval '7 days'",
                [$user['clinic_id']]
            ),
        ]);
    }
}
