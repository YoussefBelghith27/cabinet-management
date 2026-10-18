<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clinics;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;

final class StaffController extends Controller
{
    public function index(): void
    {
        $user = $this->requireRole(['admin']);
        $this->view('staff/index', [
            'title' => 'Équipe',
            'user' => $user,
            'staff' => Database::all("SELECT * FROM users WHERE clinic_id = ? AND role <> 'patient' ORDER BY is_active DESC, name", [$user['clinic_id']]),
            'newPassword' => $_SESSION['staff_temp_password'] ?? null,
        ]);
        unset($_SESSION['staff_temp_password']);
    }

    public function store(): void
    {
        $user = $this->requireRole(['admin']);
        [$d, $errors] = Validator::make($_POST, [
            'name' => 'required|max:120',
            'email' => 'required|email',
            'role' => 'required|in:admin,dentist,secretary',
        ]);
        if (!$errors && Clinics::staffEmailTaken($d['email'])) {
            $errors['email'] = 'Cette adresse est déjà utilisée.';
        }
        if ($errors) {
            $this->back('/staff', $errors, $_POST);
        }
        $temp = random_password();
        $id = Database::insert('users', [
            'clinic_id' => $user['clinic_id'],
            'role' => $d['role'],
            'name' => $d['name'],
            'email' => $d['email'],
            'password_hash' => password_hash($temp, password_algo()),
            'must_change_password' => 1,
        ]);
        Audit::log('staff_created', 'warning', ['staff_id' => $id, 'role' => $d['role'], 'email' => $d['email']]);
        $_SESSION['staff_temp_password'] = ['email' => $d['email'], 'password' => $temp];
        redirect('/staff');
    }

    public function toggle(int $id): void
    {
        $user = $this->requireRole(['admin']);
        $member = $this->findOwned('users', $id, (int) $user['clinic_id']);
        if ($member['role'] === 'patient' || $id === (int) $user['id']) {
            abort(403);
        }
        $active = $member['is_active'] ? 0 : 1;
        Database::updateOwned('users', $id, (int) $user['clinic_id'], ['is_active' => $active]);
        Auth::bumpEpoch($id); // ends the member's open sessions
        Audit::log($active ? 'staff_activated' : 'staff_deactivated', 'warning', ['staff_id' => $id]);
        Session::flash('success', $active ? 'Compte réactivé.' : 'Compte désactivé (sessions fermées).');
        redirect('/staff');
    }

    public function resetPassword(int $id): void
    {
        $user = $this->requireRole(['admin']);
        $member = $this->findOwned('users', $id, (int) $user['clinic_id']);
        if ($member['role'] === 'patient' || $id === (int) $user['id']) {
            abort(403);
        }
        $temp = random_password();
        Database::updateOwned('users', $id, (int) $user['clinic_id'], [
            'password_hash' => password_hash($temp, password_algo()),
            'must_change_password' => 1,
        ]);
        Auth::bumpEpoch($id);
        Audit::log('staff_password_reset', 'warning', ['staff_id' => $id]);
        $_SESSION['staff_temp_password'] = ['email' => $member['email'], 'password' => $temp];
        redirect('/staff');
    }
}
