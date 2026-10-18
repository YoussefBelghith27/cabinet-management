<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public const STAFF = ['admin', 'dentist', 'secretary'];

    private const ACCOUNT_MAX_FAILS = 5;
    private const IP_MAX_FAILS = 30;
    private const MFA_MAX_FAILS = 10;
    private const WINDOW = 900; // 15 minutes

    private static ?array $user = null;
    private static bool $loaded = false;

    /**
     * Current user, re-validated against the database on every request:
     * deactivated accounts, password changes (session_epoch) and tenant mismatch end the session.
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $uid = $_SESSION['uid'] ?? null;
        if (!$uid) {
            return null;
        }
        $user = Database::one(
            'SELECT u.*, c.name AS clinic_name, c.slug AS clinic_slug, c.currency
               FROM users u JOIN clinics c ON c.id = u.clinic_id WHERE u.id = ?',
            [$uid]
        );
        if (
            !$user || !$user['is_active']
            || (int) $user['clinic_id'] !== (int) ($_SESSION['clinic_id'] ?? 0)
            || (int) $user['session_epoch'] !== (int) ($_SESSION['epoch'] ?? -1)
        ) {
            Session::destroy();
            Session::start();
            return null;
        }
        return self::$user = $user;
    }

    /** @return 'ok'|'mfa'|'invalid'|'locked' */
    public static function attempt(string $email, string $password, ?int $patientClinicId = null): string
    {
        $email = mb_strtolower(trim($email));
        $accountKey = 'login:acct:' . ($patientClinicId ?? 'staff') . ':' . $email;
        $ipKey = 'login:ip:' . client_ip();

        if (RateLimiter::tooMany($accountKey, self::ACCOUNT_MAX_FAILS, self::WINDOW)
            || RateLimiter::tooMany($ipKey, self::IP_MAX_FAILS, self::WINDOW)) {
            Audit::log('login_blocked', 'warning', ['email' => $email]);
            return 'locked';
        }

        $user = $patientClinicId === null
            ? Database::one("SELECT * FROM users WHERE email = ? AND role IN ('admin','dentist','secretary')", [$email])
            : Database::one("SELECT * FROM users WHERE email = ? AND clinic_id = ? AND role = 'patient'", [$email, $patientClinicId]);

        // Always run password_verify so response time does not reveal whether the account exists.
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$allwNi4vOTNYQ0lwNTBBaQ$2FS7/a5mPl+9t8XE4arXD4w2ANr5Xs934XqxP5CFT74';
        $valid = password_verify($password, $hash) && $user !== null && (bool) $user['is_active'];

        if (!$valid) {
            RateLimiter::hit($ipKey, self::WINDOW);
            $fails = RateLimiter::hit($accountKey, self::WINDOW);
            $clinicId = $user ? (int) $user['clinic_id'] : $patientClinicId;
            Audit::log('login_failed', 'warning', ['email' => $email, 'fails' => $fails], $clinicId, $user ? (int) $user['id'] : null);
            if ($fails === self::ACCOUNT_MAX_FAILS) {
                Audit::log('account_locked', 'warning', ['email' => $email, 'minutes' => self::WINDOW / 60], $clinicId, $user ? (int) $user['id'] : null);
            }
            return 'invalid';
        }

        RateLimiter::clear($accountKey);
        if (password_needs_rehash($user['password_hash'], password_algo())) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, password_algo()), $user['id']]);
        }

        if ($user['mfa_enabled']) {
            Session::regenerate();
            $_SESSION['mfa_pending'] = ['uid' => (int) $user['id'], 'at' => time(), 'attempts' => 0];
            return 'mfa';
        }

        self::login($user);
        return 'ok';
    }

    /** Second factor: TOTP code or single-use recovery code. */
    public static function verifyMfa(string $code): bool
    {
        $pending = $_SESSION['mfa_pending'] ?? null;
        if (!$pending || time() - $pending['at'] > 300) {
            unset($_SESSION['mfa_pending']);
            return false;
        }
        $user = Database::one('SELECT * FROM users WHERE id = ? AND is_active AND mfa_enabled', [$pending['uid']]);
        if (!$user) {
            unset($_SESSION['mfa_pending']);
            return false;
        }

        // Per-account cap: a successful password login resets the login counter, so without it
        // someone who knows the password could re-login every 5 codes and brute-force the TOTP.
        $mfaKey = 'mfa:uid:' . $user['id'];
        if (RateLimiter::tooMany($mfaKey, self::MFA_MAX_FAILS, self::WINDOW)) {
            unset($_SESSION['mfa_pending']);
            Audit::log('mfa_blocked', 'warning', [], (int) $user['clinic_id'], (int) $user['id']);
            return false;
        }

        $step = Totp::verify(Crypto::decrypt((string) $user['mfa_secret']), $code, (int) $user['mfa_last_step']);
        if ($step !== null) {
            Database::run('UPDATE users SET mfa_last_step = ? WHERE id = ?', [$step, $user['id']]);
            self::login($user);
            return true;
        }

        if (self::useRecoveryCode($user, $code)) {
            Audit::log('mfa_recovery_code_used', 'warning', [], (int) $user['clinic_id'], (int) $user['id']);
            self::login($user);
            return true;
        }

        $_SESSION['mfa_pending']['attempts']++;
        RateLimiter::hit($mfaKey, self::WINDOW);
        Audit::log('mfa_failed', 'warning', [], (int) $user['clinic_id'], (int) $user['id']);
        if ($_SESSION['mfa_pending']['attempts'] >= 5) {
            unset($_SESSION['mfa_pending']);
            Audit::log('mfa_failed_repeated', 'warning', [], (int) $user['clinic_id'], (int) $user['id']);
        }
        return false;
    }

    private static function useRecoveryCode(array $user, string $code): bool
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($code) !== 10 || empty($user['mfa_recovery'])) {
            return false;
        }
        $hashes = json_decode((string) $user['mfa_recovery'], true) ?: [];
        foreach ($hashes as $i => $hash) {
            if (password_verify($code, $hash)) {
                unset($hashes[$i]);
                Database::run('UPDATE users SET mfa_recovery = ? WHERE id = ?', [json_encode(array_values($hashes)), $user['id']]);
                return true;
            }
        }
        return false;
    }

    public static function login(array $user): void
    {
        Session::regenerate(); // prevents session fixation
        unset($_SESSION['mfa_pending']);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['clinic_id'] = (int) $user['clinic_id'];
        $_SESSION['epoch'] = (int) $user['session_epoch'];
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        Audit::log('login_success', 'info', ['role' => $user['role']], (int) $user['clinic_id'], (int) $user['id']);
        self::$loaded = false;
    }

    public static function logout(): void
    {
        if (isset($_SESSION['uid'])) {
            Audit::log('logout');
        }
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
    }

    /** Invalidates every other session of this user (after password change / reset / deactivation). */
    public static function bumpEpoch(int $userId): void
    {
        Database::run('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
        if (($userId === (int) ($_SESSION['uid'] ?? 0))) {
            $_SESSION['epoch'] = (int) Database::value('SELECT session_epoch FROM users WHERE id = ?', [$userId]);
            Session::regenerate();
            self::$loaded = false;
        }
    }

    /** @return array{0: string[], 1: string[]} [plain codes to show once, hashes to store] */
    public static function makeRecoveryCodes(): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $plain[] = substr($code, 0, 5) . '-' . substr($code, 5);
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return [$plain, $hashes];
    }
}
