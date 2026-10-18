<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Availability;
use App\Core\Controller;
use App\Core\Database;

/** JSON endpoints: authenticated, rate-limited, scoped to the caller's clinic. */
final class ApiController extends Controller
{
    public function appointments(): void
    {
        $user = $this->requireStaff();
        $this->rateLimit('api:user:' . $user['id'], 120, 60);

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['start'] ?? ''));
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['end'] ?? ''));
        if (!$start || !$end || $end <= $start || $start->diff($end)->days > 62) {
            abort(400, 'Paramètres start/end invalides (AAAA-MM-JJ, 62 jours max).');
        }

        $rows = Database::all(
            "SELECT a.id, a.starts_at, a.ends_at, a.status, a.reason, a.dentist_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient
               FROM appointments a JOIN patients p ON p.id = a.patient_id AND p.clinic_id = a.clinic_id
              WHERE a.clinic_id = ? AND a.starts_at >= ? AND a.starts_at < ?
              ORDER BY a.starts_at LIMIT 2000",
            [$user['clinic_id'], $start->format('Y-m-d'), $end->format('Y-m-d')]
        );
        $this->json(['data' => $rows]);
    }

    public function slots(string $slug): void
    {
        $this->rateLimit('api:slots:' . client_ip(), 60, 60);
        $clinic = Database::one('SELECT * FROM clinics WHERE slug = ? AND portal_enabled', [$slug]);
        if (!$clinic) {
            abort(404);
        }
        $date = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
        $this->json(['date' => $date, 'slots' => Availability::slots($clinic, $date)]);
    }
}
