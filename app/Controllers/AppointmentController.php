<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;

final class AppointmentController extends Controller
{
    private const STATUSES = 'pending,confirmed,completed,cancelled,no_show';

    public function index(): void
    {
        $user = $this->requireStaff();
        $cid = (int) $user['clinic_id'];

        $ref = is_string($_GET['week'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['week']) : false;
        $ref = $ref ?: new \DateTimeImmutable('today');
        $monday = $ref->modify('monday this week');
        $sunday = $monday->modify('+7 days');

        $dentistId = isset($_GET['dentist']) && ctype_digit((string) $_GET['dentist']) ? (int) $_GET['dentist'] : null;
        $sql = "SELECT a.*, p.first_name, p.last_name, u.name AS dentist_name, t.name AS treatment_name
                  FROM appointments a
                  JOIN patients p ON p.id = a.patient_id AND p.clinic_id = a.clinic_id
                  LEFT JOIN users u ON u.id = a.dentist_id AND u.clinic_id = a.clinic_id
                  LEFT JOIN treatments t ON t.id = a.treatment_id AND t.clinic_id = a.clinic_id
                 WHERE a.clinic_id = ? AND a.starts_at >= ? AND a.starts_at < ? AND a.status <> 'cancelled'";
        $params = [$cid, $monday->format('Y-m-d'), $sunday->format('Y-m-d')];
        if ($dentistId) {
            $sql .= ' AND a.dentist_id = ?';
            $params[] = $dentistId;
        }
        $appointments = Database::all($sql . ' ORDER BY a.starts_at', $params);

        $clinic = Database::one('SELECT opening_hours FROM clinics WHERE id = ?', [$cid]);
        [$startHour, $endHour] = $this->visibleHours(json_decode((string) $clinic['opening_hours'], true) ?: [], $appointments);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+$i days");
            $days[$d->format('Y-m-d')] = ['date' => $d, 'items' => []];
        }
        foreach ($appointments as $a) {
            $days[substr($a['starts_at'], 0, 10)]['items'][] = $a;
        }
        foreach ($days as &$day) {
            $day['items'] = $this->assignLanes($day['items']);
        }
        unset($day);

        $this->view('appointments/index', [
            'title' => 'Agenda',
            'days' => $days,
            'monday' => $monday,
            'startHour' => $startHour,
            'endHour' => $endHour,
            'dentists' => $this->dentists($cid),
            'dentistId' => $dentistId,
        ]);
    }

    public function create(): void
    {
        $user = $this->requireStaff();
        $prefill = [
            'patient_id' => ctype_digit((string) ($_GET['patient_id'] ?? '')) ? $_GET['patient_id'] : '',
            'date' => is_string($_GET['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d'),
            'time' => is_string($_GET['time'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $_GET['time']) ? $_GET['time'] : '09:00',
            'duration' => '30',
            'status' => 'confirmed',
        ];
        $this->form($user, null, $prefill);
    }

    public function store(): void
    {
        $user = $this->requireStaff();
        $data = $this->validated($user, null);
        $data['clinic_id'] = $user['clinic_id'];
        $data['created_by'] = $user['id'];
        $data['source'] = 'staff';
        Database::insert('appointments', $data);
        Session::flash('success', 'Rendez-vous enregistré.');
        redirect('/appointments?week=' . substr($data['starts_at'], 0, 10));
    }

    public function edit(int $id): void
    {
        $user = $this->requireStaff();
        $a = $this->findOwned('appointments', $id, (int) $user['clinic_id']);
        $start = strtotime($a['starts_at']);
        $this->form($user, $a, [
            'patient_id' => $a['patient_id'],
            'dentist_id' => $a['dentist_id'],
            'treatment_id' => $a['treatment_id'],
            'date' => date('Y-m-d', $start),
            'time' => date('H:i', $start),
            'duration' => (string) ((strtotime($a['ends_at']) - $start) / 60),
            'status' => $a['status'],
            'reason' => $a['reason'],
            'notes' => $a['notes'],
        ]);
    }

    public function update(int $id): void
    {
        $user = $this->requireStaff();
        $this->findOwned('appointments', $id, (int) $user['clinic_id']);
        $data = $this->validated($user, $id);
        Database::updateOwned('appointments', $id, (int) $user['clinic_id'], $data);
        Session::flash('success', 'Rendez-vous mis à jour.');
        redirect('/appointments?week=' . substr($data['starts_at'], 0, 10));
    }

    public function status(int $id): void
    {
        $user = $this->requireStaff();
        $this->findOwned('appointments', $id, (int) $user['clinic_id']);
        [$data, $errors] = Validator::make($_POST, ['status' => 'required|in:' . self::STATUSES]);
        if ($errors) {
            abort(400);
        }
        Database::updateOwned('appointments', $id, (int) $user['clinic_id'], ['status' => $data['status']]);
        Session::flash('success', 'Statut mis à jour : ' . status_label($data['status']) . '.');
        $back = $_POST['back'] ?? '';
        redirect(is_string($back) && preg_match('#^/(dashboard|appointments)(\?week=\d{4}-\d{2}-\d{2})?$#', $back) ? $back : '/appointments');
    }

    private function form(array $user, ?array $appointment, array $values): void
    {
        $cid = (int) $user['clinic_id'];
        $this->view('appointments/form', [
            'title' => $appointment ? 'Modifier le rendez-vous' : 'Nouveau rendez-vous',
            'appointment' => $appointment,
            'values' => array_merge($values, \App\Core\Session::old()),
            'patients' => Database::all('SELECT id, first_name, last_name, phone FROM patients WHERE clinic_id = ? ORDER BY last_name, first_name LIMIT 2000', [$cid]),
            'dentists' => $this->dentists($cid),
            'treatments' => Database::all('SELECT id, name, duration_minutes FROM treatments WHERE clinic_id = ? AND is_active ORDER BY name', [$cid]),
        ]);
    }

    private function validated(array $user, ?int $id): array
    {
        $cid = (int) $user['clinic_id'];
        [$d, $errors] = Validator::make($_POST, [
            'patient_id' => 'required|int',
            'dentist_id' => 'int',
            'treatment_id' => 'int',
            'date' => 'required|date',
            'time' => 'required|time',
            'duration' => 'required|int',
            'status' => 'required|in:' . self::STATUSES,
            'reason' => 'max:255',
            'notes' => 'max:2000',
        ]);
        $back = $id ? "/appointments/$id/edit" : '/appointments/new';

        // Referenced records must belong to the same clinic.
        if (!$errors && !Database::value('SELECT 1 FROM patients WHERE id = ? AND clinic_id = ?', [$d['patient_id'], $cid])) {
            $errors['patient_id'] = 'Patient introuvable.';
        }
        if ($d['dentist_id'] && !Database::value("SELECT 1 FROM users WHERE id = ? AND clinic_id = ? AND role IN ('admin','dentist') AND is_active", [$d['dentist_id'], $cid])) {
            $errors['dentist_id'] = 'Praticien introuvable.';
        }
        if ($d['treatment_id'] && !Database::value('SELECT 1 FROM treatments WHERE id = ? AND clinic_id = ?', [$d['treatment_id'], $cid])) {
            $errors['treatment_id'] = 'Acte introuvable.';
        }
        $duration = (int) $d['duration'];
        if (!isset($errors['duration']) && ($duration < 5 || $duration > 480)) {
            $errors['duration'] = 'Durée entre 5 et 480 minutes.';
        }
        if ($errors) {
            $this->back($back, $errors, $_POST);
        }

        $start = new \DateTimeImmutable($d['date'] . ' ' . $d['time']);
        $end = $start->modify("+$duration minutes");

        // Conflict detection: same practitioner or same patient at the same time.
        if (!in_array($d['status'], ['cancelled', 'no_show'], true)) {
            $conflict = Database::one(
                "SELECT a.starts_at, p.last_name FROM appointments a JOIN patients p ON p.id = a.patient_id
                  WHERE a.clinic_id = ? AND a.status IN ('pending','confirmed') AND a.id <> ?
                    AND a.starts_at < ? AND a.ends_at > ?
                    AND (a.patient_id = ? OR (a.dentist_id IS NOT NULL AND a.dentist_id = ?))
                  LIMIT 1",
                [$cid, $id ?? 0, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s'), $d['patient_id'], $d['dentist_id'] ?? 0]
            );
            if ($conflict) {
                $this->back($back, ['time' => 'Conflit avec le rendez-vous de ' . $conflict['last_name'] . ' à ' . date('H:i', strtotime($conflict['starts_at'])) . '.'], $_POST);
            }
        }

        return [
            'patient_id' => (int) $d['patient_id'],
            'dentist_id' => $d['dentist_id'] ? (int) $d['dentist_id'] : null,
            'treatment_id' => $d['treatment_id'] ? (int) $d['treatment_id'] : null,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            'status' => $d['status'],
            'reason' => $d['reason'],
            'notes' => $d['notes'],
        ];
    }

    /**
     * Simultaneous appointments (several chairs/practitioners) share the day column side by side:
     * each gets a lane, and every appointment in an overlapping group knows how many lanes the group uses.
     * Items must be sorted by start time.
     */
    private function assignLanes(array $items): array
    {
        $laneEnds = [];   // lane index => end timestamp of its last appointment
        $group = [];      // indexes of the current overlapping group
        $groupEnd = 0;
        $flush = function () use (&$items, &$group, &$laneEnds) {
            foreach ($group as $i) {
                $items[$i]['lanes'] = count($laneEnds);
            }
            $group = [];
            $laneEnds = [];
        };
        foreach ($items as $i => $a) {
            $start = strtotime($a['starts_at']);
            $end = strtotime($a['ends_at']);
            if ($group && $start >= $groupEnd) {
                $flush();
            }
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $start) {
                $lane++;
            }
            $laneEnds[$lane] = $end;
            $items[$i]['lane'] = $lane;
            $group[] = $i;
            $groupEnd = max($groupEnd, $end);
        }
        $flush();
        return $items;
    }

    private function dentists(int $cid): array
    {
        return Database::all("SELECT id, name FROM users WHERE clinic_id = ? AND role IN ('admin','dentist') AND is_active ORDER BY name", [$cid]);
    }

    private function visibleHours(array $hours, array $appointments): array
    {
        $min = 8;
        $max = 19;
        foreach ($hours as $ranges) {
            foreach ($ranges as [$open, $close]) {
                $min = min($min, (int) substr($open, 0, 2));
                $max = max($max, (int) ceil((int) substr($close, 0, 2) + ((int) substr($close, 3, 2) > 0 ? 1 : 0)));
            }
        }
        foreach ($appointments as $a) {
            $min = min($min, (int) date('G', strtotime($a['starts_at'])));
            $max = max($max, (int) date('G', strtotime($a['ends_at'])) + 1);
        }
        return [$min, min(24, $max)];
    }
}
