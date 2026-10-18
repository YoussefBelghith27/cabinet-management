<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;

final class PatientController extends Controller
{
    /** Roles allowed to read/write clinical data (medical history, procedures). */
    private const CLINICAL = ['admin', 'dentist'];

    private const RULES = [
        'first_name' => 'required|max:80',
        'last_name' => 'required|max:80',
        'birth_date' => 'date',
        'gender' => 'in:F,M,O',
        'phone' => 'phone',
        'email' => 'email',
        'address' => 'max:255',
        'insurance_number' => 'max:50',
        'allergies' => 'max:1000',
        'notes' => 'max:5000',
    ];

    public function index(): void
    {
        $user = $this->requireStaff();
        $cid = (int) $user['clinic_id'];
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;

        $where = 'clinic_id = ?';
        $params = [$cid];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            // Case- and accent-insensitive, like the former MySQL collation ("helene" finds "Hélène").
            $where .= " AND (extensions.unaccent(first_name || ' ' || last_name) ILIKE extensions.unaccent(?)"
                . " OR extensions.unaccent(last_name || ' ' || first_name) ILIKE extensions.unaccent(?)"
                . ' OR phone ILIKE ? OR email ILIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM patients WHERE $where", $params);
        $offset = ($page - 1) * $perPage;
        $patients = Database::all(
            "SELECT p.*, (SELECT MAX(starts_at) FROM appointments a WHERE a.clinic_id = p.clinic_id AND a.patient_id = p.id AND a.starts_at < NOW()) AS last_visit
               FROM patients p WHERE $where ORDER BY last_name, first_name LIMIT $perPage OFFSET $offset",
            $params
        );

        $this->view('patients/index', [
            'title' => 'Patients',
            'patients' => $patients,
            'q' => $q,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'total' => $total,
        ]);
    }

    public function create(): void
    {
        $user = $this->requireStaff();
        $this->view('patients/form', ['title' => 'Nouveau patient', 'patient' => null, 'clinical' => in_array($user['role'], self::CLINICAL, true)]);
    }

    public function store(): void
    {
        $user = $this->requireStaff();
        $data = $this->validated($user, '/patients/new');
        $data['clinic_id'] = $user['clinic_id'];
        $id = Database::insert('patients', $data);
        Session::flash('success', 'Patient créé.');
        redirect("/patients/$id");
    }

    public function show(int $id): void
    {
        $user = $this->requireStaff();
        $cid = (int) $user['clinic_id'];
        $patient = $this->findOwned('patients', $id, $cid);
        $clinical = in_array($user['role'], self::CLINICAL, true);

        $appointments = Database::all(
            "SELECT a.*, u.name AS dentist_name, t.name AS treatment_name FROM appointments a
               LEFT JOIN users u ON u.id = a.dentist_id AND u.clinic_id = a.clinic_id
               LEFT JOIN treatments t ON t.id = a.treatment_id AND t.clinic_id = a.clinic_id
              WHERE a.clinic_id = ? AND a.patient_id = ? ORDER BY a.starts_at DESC LIMIT 50",
            [$cid, $id]
        );
        $procedures = $clinical ? Database::all(
            "SELECT pr.*, u.name AS dentist_name, t.name AS treatment_name FROM procedures pr
               LEFT JOIN users u ON u.id = pr.dentist_id AND u.clinic_id = pr.clinic_id
               LEFT JOIN treatments t ON t.id = pr.treatment_id AND t.clinic_id = pr.clinic_id
              WHERE pr.clinic_id = ? AND pr.patient_id = ? ORDER BY pr.performed_on DESC, pr.id DESC",
            [$cid, $id]
        ) : [];
        $invoices = in_array($user['role'], ['admin', 'secretary'], true)
            ? Database::all('SELECT * FROM invoices WHERE clinic_id = ? AND patient_id = ? ORDER BY issued_on DESC', [$cid, $id])
            : [];
        $balance = (float) Database::value("SELECT COALESCE(SUM(total - paid), 0) FROM invoices WHERE clinic_id = ? AND patient_id = ? AND status IN ('unpaid','partial')", [$cid, $id]);

        $this->view('patients/show', [
            'title' => $patient['first_name'] . ' ' . $patient['last_name'],
            'user' => $user,
            'patient' => $patient,
            'clinical' => $clinical,
            'appointments' => $appointments,
            'procedures' => $procedures,
            'invoices' => $invoices,
            'balance' => $balance,
            'treatments' => Database::all('SELECT id, name FROM treatments WHERE clinic_id = ? AND is_active ORDER BY name', [$cid]),
            'hasPortalAccount' => (bool) Database::value("SELECT 1 FROM users WHERE clinic_id = ? AND patient_id = ? AND role = 'patient'", [$cid, $id]),
        ]);
    }

    public function edit(int $id): void
    {
        $user = $this->requireStaff();
        $patient = $this->findOwned('patients', $id, (int) $user['clinic_id']);
        $this->view('patients/form', ['title' => 'Modifier le patient', 'patient' => $patient, 'clinical' => in_array($user['role'], self::CLINICAL, true)]);
    }

    public function update(int $id): void
    {
        $user = $this->requireStaff();
        $this->findOwned('patients', $id, (int) $user['clinic_id']);
        $data = $this->validated($user, "/patients/$id/edit");
        Database::updateOwned('patients', $id, (int) $user['clinic_id'], $data);
        Session::flash('success', 'Dossier mis à jour.');
        redirect("/patients/$id");
    }

    public function addProcedure(int $id): void
    {
        $user = $this->requireRole(self::CLINICAL);
        $cid = (int) $user['clinic_id'];
        $this->findOwned('patients', $id, $cid);
        [$d, $errors] = Validator::make($_POST, [
            'performed_on' => 'required|date',
            'tooth' => 'max:20',
            'treatment_id' => 'int',
            'description' => 'required|max:3000',
        ]);
        if ($d['treatment_id'] && !Database::value('SELECT 1 FROM treatments WHERE id = ? AND clinic_id = ?', [$d['treatment_id'], $cid])) {
            $errors['treatment_id'] = 'Acte introuvable.';
        }
        if ($errors) {
            $this->back("/patients/$id#soins", $errors, $_POST);
        }
        Database::insert('procedures', [
            'clinic_id' => $cid,
            'patient_id' => $id,
            'dentist_id' => $user['id'],
            'treatment_id' => $d['treatment_id'] ? (int) $d['treatment_id'] : null,
            'performed_on' => $d['performed_on'],
            'tooth' => $d['tooth'],
            'description' => $d['description'],
        ]);
        Audit::log('procedure_added', 'info', ['patient_id' => $id]);
        Session::flash('success', 'Soin ajouté au dossier.');
        redirect("/patients/$id#soins");
    }

    private function validated(array $user, string $back): array
    {
        $rules = self::RULES;
        $clinical = in_array($user['role'], self::CLINICAL, true);
        if ($clinical) {
            $rules['medical_history'] = 'max:5000';
        }
        [$data, $errors] = Validator::make($_POST, $rules);
        if (!$errors && $data['birth_date'] && $data['birth_date'] > date('Y-m-d')) {
            $errors['birth_date'] = 'Date de naissance dans le futur.';
        }
        if ($errors) {
            $this->back($back, $errors, $_POST);
        }
        return $data; // secretaries never overwrite medical_history (not in their rule set)
    }
}
