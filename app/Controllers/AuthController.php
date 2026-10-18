<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Session;

final class AuthController extends Controller
{
    public function loginForm(): void
    {
        $user = Auth::user();
        if ($user && $user['role'] !== 'patient') {
            redirect('/dashboard');
        }
        $this->view('auth/login', ['title' => 'Connexion'], 'public');
    }

    public function login(): void
    {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        $result = Auth::attempt($email, $password);
        if ($result === 'locked') {
            Session::flash('error', 'Trop de tentatives. Réessayez dans 15 minutes.');
            redirect('/login');
        }
        if ($result === 'invalid') {
            Session::setOld(['email' => $email]);
            Session::flash('error', 'Identifiants invalides.');
            redirect('/login');
        }
        if ($result === 'mfa') {
            redirect('/mfa');
        }
        $this->redirectIntended();
    }

    public function mfaForm(): void
    {
        if (empty($_SESSION['mfa_pending'])) {
            redirect('/login');
        }
        $this->view('auth/mfa', ['title' => 'Vérification en deux étapes'], 'public');
    }

    public function mfa(): void
    {
        if (empty($_SESSION['mfa_pending'])) {
            redirect('/login');
        }
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        if (Auth::verifyMfa($code)) {
            $this->redirectIntended();
        }
        if (empty($_SESSION['mfa_pending'])) {
            Session::flash('error', 'Vérification échouée. Veuillez vous reconnecter.');
            redirect('/login');
        }
        Session::flash('error', 'Code invalide.');
        redirect('/mfa');
    }

    public function logout(): void
    {
        $user = Auth::user();
        $target = ($user && $user['role'] === 'patient') ? '/p/' . $user['clinic_slug'] : '/login';
        Auth::logout();
        redirect($target);
    }

    private function redirectIntended(): never
    {
        $user = Auth::user();
        if ($user && $user['role'] === 'patient') {
            redirect('/p/' . $user['clinic_slug'] . '/account');
        }
        $intended = $_SESSION['intended'] ?? '/dashboard';
        unset($_SESSION['intended']);
        redirect(is_string($intended) ? $intended : '/dashboard');
    }
}
