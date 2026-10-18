<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Security event log (DB table audit_log + storage/logs/security.log) with alerting
 * for suspicious activity (storage/logs/alerts.log + e-mail if ALERT_EMAIL is set).
 */
final class Audit
{
    private const ALERT_EVENTS = [
        'account_locked',
        'cross_tenant_access_attempt',
        'mfa_failed_repeated',
        'backup_failed',
        'backup_verify_failed',
        'mfa_disabled',
    ];

    public static function log(string $event, string $severity = 'info', array $details = [], ?int $clinicId = null, ?int $userId = null): void
    {
        $clinicId ??= isset($_SESSION['clinic_id']) ? (int) $_SESSION['clinic_id'] : null;
        $userId ??= isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
        $ip = PHP_SAPI === 'cli' ? 'cli' : client_ip();
        $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? PHP_SAPI), 0, 255);
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        try {
            Database::run(
                'INSERT INTO audit_log (clinic_id, user_id, event, severity, ip, user_agent, details) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$clinicId, $userId, $event, $severity, $ip, $agent, $json]
            );
        } catch (\Throwable $e) {
            error_log('audit_log insert failed: ' . $e->getMessage());
        }

        $line = json_encode([
            'at' => date('c'), 'event' => $event, 'severity' => $severity, 'clinic' => $clinicId,
            'user' => $userId, 'ip' => $ip, 'details' => $details,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents(BASE_PATH . '/storage/logs/security.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($severity === 'critical' || in_array($event, self::ALERT_EVENTS, true)) {
            self::alert($line);
        }
    }

    private static function alert(string $line): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/alerts.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        $to = (string) env('ALERT_EMAIL', '');
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && function_exists('mail')) {
            @mail($to, '[' . env('APP_NAME', 'GestionCabinet') . '] Alerte sécurité', $line);
        }
    }
}
