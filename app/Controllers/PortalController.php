<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Availability;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;

/** Patient portal: a patient only ever sees their own appointments, in their own clinic. */
final class PortalController extends Controller
{
    private const MAX_UPCOMING = 3;

    public function home(string $slug): void
    {
        $clinic = $this->clinic($slug);
        $user = Auth::user();
        if ($user && $user['role'] === 'patient' && (int) $user['clinic_id'] === (int) $clinic['id']) {
            redirect("/p/$slug/account");
        }
        $this->view('portal/home', ['title' => $clinic['name'], 'clinic' => $clinic, 'days' => SettingsController::DAYS], 'portal');
    }

    public function registerForm(string $slug): void
    {
        $clinic = $this->clinic($slug);
        $this->view('portal/register', ['title' => 'Créer mon compte patient', 'clinic' => $clinic], 'portal');
    }

    public function register(string $slug): void
    {
        $clinic = $this->clinic($slug);
        $this->rateLimit('portal-register:' . client_ip(), 5, 3600);
        [$d, $errors] = Validator::make($_POST, [
            'first_name' => 'required|max:80',
            'last_name' => 'required|max:80',
            'birth_date' => 'required|date',
            'phone' => 'required|phone',
            'email' => 'required|email',
            'password' => 'required|password',
            'password_confirmation' => 'required',
        ]);
        if (!$errors && $d['password'] !== $d['password_confirmation']) {
            $errors['password_confirmation'] = 'Les mots de passe ne correspondent pas.';
        }
        if (!$errors && $d['birth_date'] > date('Y-m-d')) {
            $errors['birth_date'] = 'Date invalide.';
        }
        if (!$errors && Database::value('SELECT 1 FROM users WHERE clinic_id = ? AND email = ?', [$clinic['id'], $d['email']])) {
            $errors['email'] = 'Un compte existe déjà avec cette adresse. Connectez-vous.';
        }
        if ($errors) {
            $this->back("/p/$slug/register", $errors, $_POST);
        }

        // A new patient record is always created: the account is never auto-linked to an
        // existing medical record by e-mail (that would allow takeover of someone's file).
        $userId = Database::transaction(function () use ($clinic, $d) {
            $patientId = Database::insert('patients', [
                'clinic_id' => $clinic['id'],
                'first_name' => $d['first_name'],
                'last_name' => $d['last_name'],
                'birth_date' => $d['birth_date'],
                'phone' => $d['phone'],
                'email' => $d['email'],
                'notes' => 'Inscrit via le portail patient.',
            ]);
            return Database::insert('users', [
                'clinic_id' => $clinic['id'],
                'patient_id' => $patientId,
                'role' => 'patient',
                'name' => $d['first_name'] . ' ' . $d['last_name'],
                'email' => $d['email'],
                'password_hash' => password_hash($d['password'], password_algo()),
            ]);
        });
        Audit::log('patient_registered', 'info', [], (int) $clinic['id'], $userId);
        Auth::login(['id' => $userId, 'clinic_id' => $clinic['id'], 'session_epoch' => 0, 'role' => 'patient']);
        Session::flash('success', 'Compte créé. Vous pouvez maintenant prendre rendez-vous.');
        redirect("/p/$slug/account");
    }

    public function loginForm(string $slug): void
    {
        $clinic = $this->clinic($slug);
        $this->view('portal/login', ['title' => 'Connexion patient', 'clinic' => $clinic], 'portal');
    }

    public function login(string $slug): void
    {
        $clinic = $this->clinic($slug);
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $result = Auth::attempt($email, $password, (int) $clinic['id']);
        if ($result === 'ok') {
            redirect("/p/$slug/account");
        }
        if ($result === 'mfa') {
            redirect('/mfa');
        }
        Session::setOld(['email' => $email]);
        Session::flash('error', $result === 'locked' ? 'Trop de tentatives. Réessayez dans 15 minutes.' : 'Identifiants invalides.');
        redirect("/p/$slug/login");
    }

    public function account(string $slug): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $appointments = Database::all(
            "SELECT a.id, a.starts_at, a.ends_at, a.status, a.reason, t.name AS treatment_name, u.name AS dentist_name
               FROM appointments a
               LEFT JOIN treatments t ON t.id = a.treatment_id AND t.clinic_id = a.clinic_id
               LEFT JOIN users u ON u.id = a.dentist_id AND u.clinic_id = a.clinic_id
              WHERE a.clinic_id = ? AND a.patient_id = ? ORDER BY a.starts_at DESC LIMIT 50",
            [$clinic['id'], $user['patient_id']]
        );
        $this->view('portal/account', [
            'title' => 'Mes rendez-vous',
            'clinic' => $clinic,
            'user' => $user,
            'appointments' => $appointments,
            'minDate' => date('Y-m-d'),
            'maxDate' => date('Y-m-d', strtotime('+' . Availability::MAX_DAYS_AHEAD . ' days')),
        ], 'portal');
    }

    public function book(string $slug): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $this->rateLimit('portal-book:' . $user['id'], 10, 3600);
        [$d, $errors] = Validator::make($_POST, ['date' => 'required|date', 'time' => 'required|time', 'reason' => 'max:255']);
        if ($errors) {
            $this->back("/p/$slug/account", $errors, $_POST);
        }

        $result = Database::transaction(function () use ($clinic, $user, $d) {
            // Lock the clinic row so two patients cannot grab the last free chair simultaneously.
            Database::value('SELECT id FROM clinics WHERE id = ? FOR UPDATE', [$clinic['id']]);
            $upcoming = (int) Database::value(
                "SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status IN ('pending','confirmed') AND starts_at >= NOW()",
                [$clinic['id'], $user['patient_id']]
            );
            if ($upcoming >= self::MAX_UPCOMING) {
                return 'Vous avez déjà ' . self::MAX_UPCOMING . ' rendez-vous à venir. Contactez le cabinet pour en ajouter.';
            }
            if (!in_array($d['time'], Availability::slots($clinic, $d['date']), true)) {
                return 'Ce créneau n’est plus disponible. Choisissez-en un autre.';
            }
            $start = new \DateTimeImmutable($d['date'] . ' ' . $d['time']);
            Database::insert('appointments', [
                'clinic_id' => $clinic['id'],
                'patient_id' => $user['patient_id'],
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $start->modify('+' . (int) $clinic['slot_minutes'] . ' minutes')->format('Y-m-d H:i:s'),
                'status' => 'pending',
                'reason' => $d['reason'],
                'source' => 'portal',
                'created_by' => $user['id'],
            ]);
            return null;
        });

        if ($result !== null) {
            Session::flash('error', $result);
        } else {
            Session::flash('success', 'Demande envoyée ! Le cabinet va confirmer votre rendez-vous.');
        }
        redirect("/p/$slug/account");
    }

    public function appointment(string $slug, int $id): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $appointment = $this->ownedByPatient('appointments', $id, Database::one(
            "SELECT a.*, t.name AS treatment_name, u.name AS dentist_name
               FROM appointments a
               LEFT JOIN treatments t ON t.id = a.treatment_id AND t.clinic_id = a.clinic_id
               LEFT JOIN users u ON u.id = a.dentist_id AND u.clinic_id = a.clinic_id
              WHERE a.id = ? AND a.clinic_id = ? AND a.patient_id = ?",
            [$id, $clinic['id'], $user['patient_id']]
        ));
        $this->view('portal/appointment', [
            'title' => 'Rendez-vous du ' . fmt_date($appointment['starts_at']),
            'clinic' => $clinic,
            'appointment' => $appointment,
            'cancellable' => self::cancellable($appointment),
        ], 'portal');
    }

    public function cancel(string $slug, int $id): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        // Scoped by clinic AND patient: a patient cannot touch another patient's appointment.
        $appointment = $this->ownedByPatient('appointments', $id, Database::one(
            'SELECT * FROM appointments WHERE id = ? AND clinic_id = ? AND patient_id = ?',
            [$id, $clinic['id'], $user['patient_id']]
        ));
        if (!self::cancellable($appointment)) {
            Session::flash('error', 'Ce rendez-vous ne peut plus être annulé en ligne (moins de 2 h avant). Appelez le cabinet.');
            redirect("/p/$slug/appointments/$id");
        }
        Database::updateOwned('appointments', $id, (int) $clinic['id'], ['status' => 'cancelled']);
        Session::flash('success', 'Rendez-vous annulé.');
        redirect("/p/$slug/account");
    }

    /** Treatments performed, with the dentist's report (the clinic chose to share it with patients). */
    public function care(string $slug): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $this->view('portal/care', [
            'title' => 'Mes soins',
            'clinic' => $clinic,
            'procedures' => Database::all(
                "SELECT pr.performed_on, pr.tooth, pr.description, t.name AS treatment_name, u.name AS dentist_name
                   FROM procedures pr
                   LEFT JOIN treatments t ON t.id = pr.treatment_id AND t.clinic_id = pr.clinic_id
                   LEFT JOIN users u ON u.id = pr.dentist_id AND u.clinic_id = pr.clinic_id
                  WHERE pr.clinic_id = ? AND pr.patient_id = ?
                  ORDER BY pr.performed_on DESC, pr.id DESC",
                [$clinic['id'], $user['patient_id']]
            ),
        ], 'portal');
    }

    public function invoices(string $slug): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $invoices = Database::all(
            "SELECT * FROM invoices WHERE clinic_id = ? AND patient_id = ? AND status <> 'cancelled' ORDER BY issued_on DESC, id DESC",
            [$clinic['id'], $user['patient_id']]
        );
        $this->view('portal/invoices', [
            'title' => 'Mes factures',
            'clinic' => $clinic,
            'invoices' => $invoices,
            'due' => array_sum(array_map(fn ($i) => (float) $i['total'] - (float) $i['paid'], $invoices)),
        ], 'portal');
    }

    public function invoice(string $slug, int $id): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $this->view('portal/invoice', ['title' => 'Facture', 'clinic' => $clinic] + $this->loadInvoice($clinic, $user, $id), 'portal');
    }

    public function printInvoice(string $slug, int $id): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $data = $this->loadInvoice($clinic, $user, $id);
        $this->view('invoices/print', $data + [
            'title' => 'Facture ' . $data['invoice']['number'],
            'clinic' => $clinic,
            'backUrl' => "/p/$slug/invoices/$id",
        ], 'print');
    }

    public function profile(string $slug): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $this->view('portal/profile', [
            'title' => 'Mon profil',
            'clinic' => $clinic,
            'user' => $user,
            'patient' => Database::one('SELECT * FROM patients WHERE id = ? AND clinic_id = ?', [$user['patient_id'], $clinic['id']]),
        ], 'portal');
    }

    public function updateProfile(string $slug): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $this->rateLimit('portal-profile:' . $user['id'], 20, 3600);
        [$d, $errors] = Validator::make($_POST, [
            'phone' => 'required|phone',
            'email' => 'required|email',
            'address' => 'max:255',
            'current_password' => 'max:128',
        ]);
        $emailChanged = !$errors && $d['email'] !== $user['email'];
        if ($emailChanged) {
            // The e-mail is the login: changing it requires the password (protects an unattended session).
            if (!password_verify((string) $d['current_password'], $user['password_hash'])) {
                $errors['current_password'] = 'Saisissez votre mot de passe actuel pour changer d’adresse e-mail.';
            } elseif (Database::value('SELECT 1 FROM users WHERE clinic_id = ? AND email = ? AND id <> ?', [$clinic['id'], $d['email'], $user['id']])) {
                $errors['email'] = 'Cette adresse est déjà utilisée par un autre compte.';
            }
        }
        if ($errors) {
            $this->back("/p/$slug/profile", $errors, $_POST);
        }
        Database::transaction(function () use ($clinic, $user, $d, $emailChanged) {
            Database::updateOwned('patients', (int) $user['patient_id'], (int) $clinic['id'], [
                'phone' => $d['phone'],
                'email' => $d['email'],
                'address' => $d['address'],
            ]);
            if ($emailChanged) {
                Database::updateOwned('users', (int) $user['id'], (int) $clinic['id'], ['email' => $d['email']]);
            }
        });
        Audit::log($emailChanged ? 'patient_email_changed' : 'patient_profile_updated', $emailChanged ? 'warning' : 'info', $emailChanged ? ['from' => $user['email'], 'to' => $d['email']] : []);
        Session::flash('success', 'Coordonnées enregistrées.');
        redirect("/p/$slug/profile");
    }

    public function password(string $slug): void
    {
        [$clinic, $user] = $this->requirePatient($slug);
        $this->rateLimit('portal-pw:' . $user['id'], 10, 3600);
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
        if ($errors) {
            $this->back("/p/$slug/profile", $errors);
        }
        Database::updateOwned('users', (int) $user['id'], (int) $clinic['id'], ['password_hash' => password_hash($d['password'], password_algo())]);
        Auth::bumpEpoch((int) $user['id']); // signs out other devices, keeps this one
        Audit::log('password_changed', 'info');
        Session::flash('success', 'Mot de passe modifié. Vos autres sessions ont été fermées.');
        redirect("/p/$slug/profile");
    }

    private function loadInvoice(array $clinic, array $user, int $id): array
    {
        $invoice = $this->ownedByPatient('invoices', $id, Database::one(
            "SELECT * FROM invoices WHERE id = ? AND clinic_id = ? AND patient_id = ? AND status <> 'cancelled'",
            [$id, $clinic['id'], $user['patient_id']]
        ));
        return [
            'invoice' => $invoice,
            'patient' => Database::one('SELECT * FROM patients WHERE id = ? AND clinic_id = ?', [$user['patient_id'], $clinic['id']]),
            'items' => Database::all('SELECT * FROM invoice_items WHERE invoice_id = ? AND clinic_id = ? ORDER BY id', [$id, $clinic['id']]),
            'payments' => Database::all('SELECT * FROM payments WHERE invoice_id = ? AND clinic_id = ? ORDER BY paid_on, id', [$id, $clinic['id']]),
            'currency' => $clinic['currency'],
        ];
    }

    /**
     * 404 for anything that is not this patient's own record — whether it belongs to another patient
     * of the same clinic or to another clinic — and a security alert when the record exists.
     */
    private function ownedByPatient(string $table, int $id, ?array $row): array
    {
        if ($row) {
            return $row;
        }
        if (Database::value('SELECT 1 FROM ' . Database::ident($table) . ' WHERE id = ?', [$id])) {
            Audit::log('cross_patient_access_attempt', 'critical', ['table' => $table, 'id' => $id, 'via' => 'portal']);
        }
        abort(404);
    }

    private static function cancellable(array $appointment): bool
    {
        return in_array($appointment['status'], ['pending', 'confirmed'], true)
            && strtotime($appointment['starts_at']) >= time() + 2 * 3600;
    }

    private function clinic(string $slug): array
    {
        $clinic = Database::one('SELECT * FROM clinics WHERE slug = ? AND portal_enabled', [$slug]);
        if (!$clinic) {
            abort(404);
        }
        return $clinic;
    }

    private function requirePatient(string $slug): array
    {
        $clinic = $this->clinic($slug);
        $user = Auth::user();
        if (!$user) {
            Session::flash('warning', 'Veuillez vous connecter.');
            redirect("/p/$slug/login");
        }
        if ($user['role'] !== 'patient' || (int) $user['clinic_id'] !== (int) $clinic['id'] || !$user['patient_id']) {
            Audit::log('access_denied', 'warning', ['path' => current_path(), 'role' => $user['role']]);
            abort(403);
        }
        return [$clinic, $user];
    }
}
