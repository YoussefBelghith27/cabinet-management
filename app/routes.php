<?php
declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\AppointmentController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\InvoiceController;
use App\Controllers\PatientController;
use App\Controllers\PortalController;
use App\Controllers\SecurityController;
use App\Controllers\SettingsController;
use App\Controllers\StaffController;
use App\Controllers\TreatmentController;

/** @var App\Core\Router $router */

// Public
$router->get('/', [HomeController::class, 'index']);
$router->get('/register', [HomeController::class, 'registerForm']);
$router->post('/register', [HomeController::class, 'register']);
$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/mfa', [AuthController::class, 'mfaForm']);
$router->post('/mfa', [AuthController::class, 'mfa']);
$router->post('/logout', [AuthController::class, 'logout']);

// Staff
$router->get('/dashboard', [DashboardController::class, 'index']);

$router->get('/appointments', [AppointmentController::class, 'index']);
$router->get('/appointments/new', [AppointmentController::class, 'create']);
$router->post('/appointments', [AppointmentController::class, 'store']);
$router->get('/appointments/{id}/edit', [AppointmentController::class, 'edit']);
$router->post('/appointments/{id}', [AppointmentController::class, 'update']);
$router->post('/appointments/{id}/status', [AppointmentController::class, 'status']);

$router->get('/patients', [PatientController::class, 'index']);
$router->get('/patients/new', [PatientController::class, 'create']);
$router->post('/patients', [PatientController::class, 'store']);
$router->get('/patients/{id}', [PatientController::class, 'show']);
$router->get('/patients/{id}/edit', [PatientController::class, 'edit']);
$router->post('/patients/{id}', [PatientController::class, 'update']);
$router->post('/patients/{id}/procedures', [PatientController::class, 'addProcedure']);

$router->get('/invoices', [InvoiceController::class, 'index']);
$router->get('/invoices/new', [InvoiceController::class, 'create']);
$router->post('/invoices', [InvoiceController::class, 'store']);
$router->get('/invoices/{id}', [InvoiceController::class, 'show']);
$router->get('/invoices/{id}/print', [InvoiceController::class, 'print']);
$router->post('/invoices/{id}/payments', [InvoiceController::class, 'addPayment']);
$router->post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);

$router->get('/treatments', [TreatmentController::class, 'index']);
$router->post('/treatments', [TreatmentController::class, 'store']);
$router->post('/treatments/{id}', [TreatmentController::class, 'update']);

$router->get('/staff', [StaffController::class, 'index']);
$router->post('/staff', [StaffController::class, 'store']);
$router->post('/staff/{id}/toggle', [StaffController::class, 'toggle']);
$router->post('/staff/{id}/reset-password', [StaffController::class, 'resetPassword']);

$router->get('/settings', [SettingsController::class, 'index']);
$router->post('/settings', [SettingsController::class, 'update']);

$router->get('/security', [SecurityController::class, 'index']);
$router->post('/security/password', [SecurityController::class, 'password']);
$router->post('/security/mfa/enable', [SecurityController::class, 'enableMfa']);
$router->post('/security/mfa/disable', [SecurityController::class, 'disableMfa']);
$router->get('/security/events', [SecurityController::class, 'events']);

// JSON API
$router->get('/api/appointments', [ApiController::class, 'appointments']);
$router->get('/api/portal/{slug}/slots', [ApiController::class, 'slots']);

// Patient portal
$router->get('/p/{slug}', [PortalController::class, 'home']);
$router->get('/p/{slug}/register', [PortalController::class, 'registerForm']);
$router->post('/p/{slug}/register', [PortalController::class, 'register']);
$router->get('/p/{slug}/login', [PortalController::class, 'loginForm']);
$router->post('/p/{slug}/login', [PortalController::class, 'login']);
$router->get('/p/{slug}/account', [PortalController::class, 'account']);
$router->post('/p/{slug}/book', [PortalController::class, 'book']);
$router->get('/p/{slug}/appointments/{id}', [PortalController::class, 'appointment']);
$router->post('/p/{slug}/appointments/{id}/cancel', [PortalController::class, 'cancel']);
$router->get('/p/{slug}/care', [PortalController::class, 'care']);
$router->get('/p/{slug}/invoices', [PortalController::class, 'invoices']);
$router->get('/p/{slug}/invoices/{id}', [PortalController::class, 'invoice']);
$router->get('/p/{slug}/invoices/{id}/print', [PortalController::class, 'printInvoice']);
$router->get('/p/{slug}/profile', [PortalController::class, 'profile']);
$router->post('/p/{slug}/profile', [PortalController::class, 'updateProfile']);
$router->post('/p/{slug}/password', [PortalController::class, 'password']);
