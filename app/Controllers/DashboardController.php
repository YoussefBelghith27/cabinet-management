<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Availability;
use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $user = $this->requireStaff();
        $cid = (int) $user['clinic_id'];
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');

        $dentistFilter = $user['role'] === 'dentist' ? ' AND (a.dentist_id = ? OR a.dentist_id IS NULL)' : '';
        $params = $user['role'] === 'dentist' ? [$cid, $today, $user['id']] : [$cid, $today];
        $todayList = Database::all(
            "SELECT a.*, p.first_name, p.last_name, p.phone, u.name AS dentist_name, t.name AS treatment_name
               FROM appointments a
               JOIN patients p ON p.id = a.patient_id AND p.clinic_id = a.clinic_id
               LEFT JOIN users u ON u.id = a.dentist_id AND u.clinic_id = a.clinic_id
               LEFT JOIN treatments t ON t.id = a.treatment_id AND t.clinic_id = a.clinic_id
              WHERE a.clinic_id = ? AND a.starts_at::date = ?::date $dentistFilter
              ORDER BY a.starts_at",
            $params
        );

        $stats = [
            'today' => count(array_filter($todayList, fn ($a) => !in_array($a['status'], ['cancelled'], true))),
            'pending' => (int) Database::value("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND status = 'pending' AND starts_at >= NOW()", [$cid]),
            'patients' => (int) Database::value('SELECT COUNT(*) FROM patients WHERE clinic_id = ?', [$cid]),
            'new_patients' => (int) Database::value('SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND created_at >= ?', [$cid, $monthStart]),
        ];

        $finance = null;
        if ($user['role'] === 'admin') {
            $rows = Database::all(
                "SELECT to_char(paid_on, 'YYYY-MM') AS ym, SUM(amount) AS total FROM payments
                  WHERE clinic_id = ? AND paid_on >= ? GROUP BY ym",
                [$cid, date('Y-m-01', strtotime('-5 months'))]
            );
            $byMonth = array_column($rows, 'total', 'ym');
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
                $months[$ym] = (float) ($byMonth[$ym] ?? 0);
            }
            $finance = [
                'month_revenue' => $months[date('Y-m')],
                'outstanding' => (float) Database::value("SELECT COALESCE(SUM(total - paid), 0) FROM invoices WHERE clinic_id = ? AND status IN ('unpaid','partial')", [$cid]),
                'months' => $months,
            ];
        }

        $pendingList = Database::all(
            "SELECT a.*, p.first_name, p.last_name, p.phone FROM appointments a
               JOIN patients p ON p.id = a.patient_id AND p.clinic_id = a.clinic_id
              WHERE a.clinic_id = ? AND a.status = 'pending' AND a.starts_at >= NOW()
              ORDER BY a.starts_at LIMIT 10",
            [$cid]
        );

        // Today's breakdown, computed from the list already loaded (no extra round trip).
        $byStatus = array_count_values(array_column($todayList, 'status'));
        $active = count($todayList) - ($byStatus['cancelled'] ?? 0);
        $today = [
            'total' => $active,
            'confirmed' => ($byStatus['confirmed'] ?? 0) + ($byStatus['completed'] ?? 0),
            'pending' => $byStatus['pending'] ?? 0,
            'cancelled' => ($byStatus['cancelled'] ?? 0) + ($byStatus['no_show'] ?? 0),
        ];
        $today['confirmed_pct'] = $active > 0 ? (int) round($today['confirmed'] / $active * 100) : 0;

        $clinic = Database::one('SELECT * FROM clinics WHERE id = ?', [$cid]);
        $practitioners = [];
        foreach ($todayList as $a) {
            if ($a['dentist_id']) {
                $practitioners[(int) $a['dentist_id']] = $a['dentist_name'];
            }
        }

        $this->view('dashboard', [
            'title' => 'Tableau de bord',
            'user' => $user,
            'clinic' => $clinic,
            'todayList' => $todayList,
            'pendingList' => $pendingList,
            'stats' => $stats,
            'today' => $today,
            'freeSlots' => array_slice(Availability::slots($clinic, date('Y-m-d')), 0, 8),
            'practitioners' => $practitioners,
            'finance' => $finance,
        ]);
    }
}
