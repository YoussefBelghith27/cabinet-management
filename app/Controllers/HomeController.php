<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clinics;
use App\Core\Controller;
use App\Core\Session;
use App\Core\Validator;

final class HomeController extends Controller
{
    public function index(): void
    {
        $user = Auth::user();
        if ($user && $user['role'] !== 'patient') {
            redirect('/dashboard');
        }
        $this->view('home', ['title' => 'Logiciel de gestion de cabinet dentaire'], 'public');
    }

    public function registerForm(): void
    {
        if (!registration_open()) {
            abort(404);
        }
        $this->view('auth/register', ['title' => 'Créer mon cabinet'], 'public');
    }

    public function register(): void
    {
        if (!registration_open()) {
            abort(404);
        }
        $this->rateLimit('register:' . client_ip(), 5, 3600);

        [$data, $errors] = Validator::make($_POST, [
            'clinic_name' => 'required|max:150',
            'name' => 'required|max:120',
            'email' => 'required|email',
            'password' => 'required|password',
            'password_confirmation' => 'required',
        ]);
        if (!$errors && $data['password'] !== $data['password_confirmation']) {
            $errors['password_confirmation'] = 'Les mots de passe ne correspondent pas.';
        }
        if (!$errors && Clinics::staffEmailTaken($data['email'])) {
            $errors['email'] = 'Cette adresse est déjà utilisée.';
        }
        if ($errors) {
            $this->back('/register', $errors, $_POST);
        }

        $created = Clinics::create($data['clinic_name'], $data['name'], $data['email'], $data['password']);
        Audit::log('clinic_registered', 'info', ['slug' => $created['slug']], $created['clinic_id'], $created['user_id']);

        Auth::login(['id' => $created['user_id'], 'clinic_id' => $created['clinic_id'], 'session_epoch' => 0, 'role' => 'admin']);
        Session::flash('success', 'Cabinet créé ! Activez maintenant la double authentification pour sécuriser votre compte.');
        redirect('/security');
    }
}
