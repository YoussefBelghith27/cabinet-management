<?php
declare(strict_types=1);

/**
 * Parcours métier de bout en bout : php tests/functional_test.php [http://localhost:8080]
 * Inscription d'un cabinet → 2FA → équipe → patient → RDV (conflits) → facture → paiements
 * → portail patient (créneaux, réservation, confirmation, annulation). Nettoie ensuite.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/HttpClient.php';

use App\Core\Database;
use App\Core\Totp;

$base = rtrim($argv[1] ?? (string) env('APP_URL', 'http://localhost:8080'), '/');
$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? "  \033[32m✔\033[0m " : "  \033[31m✘\033[0m ") . $label . ($ok || $detail === '' ? '' : "  → $detail") . "\n";
}

/** Rebuilds the module matrix from the page's inline QR SVG and decodes it with jsQR. */
function scan_qr_from_page(string $html): ?string
{
    if (!preg_match('/<svg[^>]*class="qr"[^>]*viewBox="0 0 (\d+) \d+"|<svg[^>]*viewBox="0 0 (\d+) \d+"[^>]*class="qr"/', $html, $v)
        || !preg_match('/<path fill="#0f172a" d="([^"]*)"/', $html, $p)) {
        return null;
    }
    $n = (int) ($v[1] ?: $v[2]) - 8;
    $matrix = array_fill(0, $n, array_fill(0, $n, 0));
    preg_match_all('/M(\d+) (\d+)h1v1h-1z/', $p[1], $cells, PREG_SET_ORDER);
    foreach ($cells as [, $x, $y]) {
        $matrix[(int) $y - 4][(int) $x - 4] = 1;
    }
    $file = BASE_PATH . '/storage/tmp/qr-page-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($file, json_encode([['text' => '', 'matrix' => $matrix]]));
    $out = json_decode((string) shell_exec('node ' . escapeshellarg(BASE_PATH . '/tests/qr-decode.mjs') . ' ' . escapeshellarg($file)), true);
    unlink($file);
    return $out[0]['got'] ?? null;
}

$suffix = bin2hex(random_bytes(3));
$email = "owner-$suffix@test.test";
$password = 'Owner-' . bin2hex(random_bytes(5)) . '1';
$clinicId = null;

if (!registration_open()) {
    fwrite(STDERR, "Ce test crée un cabinet via /register : mettez ALLOW_CLINIC_REGISTRATION=true dans .env (puis redémarrez le serveur).\n");
    exit(1);
}

try {
    echo "\n\033[1mInscription & sécurité du compte\033[0m\n";
    $admin = new Client($base);
    $admin->get('/register');
    $admin->post('/register', ['clinic_name' => "Cabinet Fonctionnel $suffix", 'name' => 'Dr Test', 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
    check('Création du cabinet → page sécurité', $admin->location() === '/security', $admin->location());
    $clinicId = (int) Database::value('SELECT clinic_id FROM users WHERE email = ?', [$email]);
    $slug = (string) Database::value('SELECT slug FROM clinics WHERE id = ?', [$clinicId]);
    check('Catalogue d’actes par défaut créé', (int) Database::value('SELECT COUNT(*) FROM treatments WHERE clinic_id = ?', [$clinicId]) >= 5);

    $admin->get('/security');
    // Scan the QR code served by the page, like a phone would, and enrol with the secret it contains.
    $scanned = scan_qr_from_page($admin->body);
    preg_match('/[?&]secret=([A-Z2-7]+)/', (string) $scanned, $m);
    $secret = $m[1] ?? '';
    preg_match('/<p class="secret">([A-Z2-7 ]+)<\/p>/', $admin->body, $k);
    check('2FA : le QR code de la page se scanne (lien otpauth://totp)', str_starts_with((string) $scanned, 'otpauth://totp/') && str_contains((string) $scanned, rawurlencode($email)));
    check('2FA : le QR code contient la même clé que la saisie manuelle', $secret !== '' && $secret === str_replace(' ', '', $k[1] ?? ''));
    $admin->post('/security/mfa/enable', ['code' => Totp::code($secret, intdiv(time(), 30))]);
    $admin->get('/security');
    check('2FA activée + 8 codes de secours affichés une fois', substr_count($admin->body, '<span>') >= 8 && (bool) Database::value('SELECT mfa_enabled FROM users WHERE email = ?', [$email]));
    $admin->get('/security');
    check('Codes de secours non ré-affichés', !str_contains($admin->body, 'Vos codes de secours'));
    $admin->get('/dashboard');
    check('Accès au tableau de bord après 2FA', $admin->status === 200);

    echo "\n\033[1mÉquipe & paramètres\033[0m\n";
    $admin->post('/staff', ['name' => 'Sara Sec', 'email' => "sec-$suffix@test.test", 'role' => 'secretary']);
    $admin->get('/staff');
    preg_match('/<span class="mono">([A-Za-z0-9]+)<\/span>/', $admin->body, $m);
    $temp = $m[1] ?? '';
    check('Membre créé avec mot de passe temporaire', strlen($temp) >= 12);
    $sec = new Client($base);
    $sec->get('/login');
    $sec->post('/login', ['email' => "sec-$suffix@test.test", 'password' => $temp]);
    $sec->forgetToken();
    $sec->get('/patients');
    check('Mot de passe temporaire → changement obligatoire', $sec->location() === '/security');
    $newPw = 'Nouveau-' . bin2hex(random_bytes(4)) . '2';
    $sec->get('/security');
    $sec->post('/security/password', ['current_password' => $temp, 'password' => $newPw, 'password_confirmation' => $newPw]);
    $sec->get('/patients');
    check('Après changement : accès normal', $sec->status === 200);

    $hours = ['open' => ['1' => 1, '2' => 1, '3' => 1, '4' => 1, '5' => 1, '6' => 1, '7' => 1]];
    foreach (range(1, 7) as $d) {
        $hours['am_from'][$d] = '08:00';
        $hours['am_to'][$d] = '12:00';
        $hours['pm_from'][$d] = '13:00';
        $hours['pm_to'][$d] = '19:00';
    }
    $admin->post('/settings', ['name' => "Cabinet Fonctionnel $suffix", 'currency' => 'TND', 'slot_minutes' => '30', 'chairs' => '1', 'portal_enabled' => '1', 'phone' => '+216 71 123 456'] + $hours);
    check('Paramètres enregistrés (horaires 7j/7)', str_contains((string) Database::value('SELECT opening_hours FROM clinics WHERE id = ?', [$clinicId]), '19:00'));

    echo "\n\033[1mPatients & agenda\033[0m\n";
    $sec->post('/patients', ['first_name' => 'Nadia', 'last_name' => 'Fonction', 'phone' => '+216 20 000 001', 'allergies' => 'Iode']);
    $patientId = (int) Database::value('SELECT id FROM patients WHERE clinic_id = ? AND last_name = ?', [$clinicId, 'Fonction']);
    check('Patient créé par la secrétaire', $patientId > 0 && str_starts_with($sec->location(), '/patients/'));
    $day = date('Y-m-d', strtotime('+2 days'));
    $sec->post('/appointments', ['patient_id' => $patientId, 'date' => $day, 'time' => '10:00', 'duration' => '30', 'status' => 'confirmed', 'reason' => 'Contrôle']);
    check('RDV créé', (int) Database::value('SELECT COUNT(*) FROM appointments WHERE clinic_id = ?', [$clinicId]) === 1);
    $sec->post('/appointments', ['patient_id' => $patientId, 'date' => $day, 'time' => '10:15', 'duration' => '30', 'status' => 'confirmed']);
    $sec->get('/appointments/new');
    check('Conflit d’horaire détecté (même patient)', str_contains($sec->body, 'Conflit avec le rendez-vous'));
    $sec->get("/appointments?week=$day");
    check('RDV visible dans l’agenda de la semaine', str_contains($sec->body, 'Fonction'));

    echo "\n\033[1mFacturation\033[0m\n";
    $sec->post('/invoices', [
        'patient_id' => $patientId, 'issued_on' => date('Y-m-d'),
        'item_description' => ['Détartrage', 'Radio'], 'item_quantity' => ['1', '2'], 'item_price' => ['80', '30,5'],
        'total' => '1', // ignored: totals are computed server-side
    ]);
    $invoice = Database::one('SELECT * FROM invoices WHERE clinic_id = ?', [$clinicId]);
    check('Facture créée, total calculé côté serveur (141.000)', $invoice && (float) $invoice['total'] === 141.0, (string) ($invoice['total'] ?? 'null'));
    check('Numérotation séquentielle', $invoice && $invoice['number'] === sprintf('F%s-00001', date('Y')));
    $sec->post("/invoices/{$invoice['id']}/payments", ['amount' => '500', 'method' => 'cash', 'paid_on' => date('Y-m-d')]);
    check('Paiement supérieur au reste dû refusé', (float) Database::value('SELECT paid FROM invoices WHERE id = ?', [$invoice['id']]) === 0.0);
    $sec->post("/invoices/{$invoice['id']}/payments", ['amount' => '41', 'method' => 'card', 'paid_on' => date('Y-m-d')]);
    check('Paiement partiel → statut "partial"', Database::value('SELECT status FROM invoices WHERE id = ?', [$invoice['id']]) === 'partial');
    $sec->post("/invoices/{$invoice['id']}/payments", ['amount' => '100', 'method' => 'cash', 'paid_on' => date('Y-m-d')]);
    check('Solde réglé → statut "paid"', Database::value('SELECT status FROM invoices WHERE id = ?', [$invoice['id']]) === 'paid');
    $sec->get("/invoices/{$invoice['id']}/print");
    check('Version imprimable', $sec->status === 200 && str_contains($sec->body, 'Net à payer'));
    $admin->get('/dashboard');
    check('Encaissements visibles au tableau de bord (admin)', str_contains($admin->body, '141,000 TND'));

    echo "\n\033[1mPortail patient\033[0m\n";
    $pat = new Client($base);
    $pat->get("/p/$slug");
    check('Page publique du cabinet', $pat->status === 200 && str_contains($pat->body, '+216 71 123 456'));
    $pat->get("/p/$slug/register");
    $ppw = 'Patient-' . bin2hex(random_bytes(4)) . '3';
    $pat->post("/p/$slug/register", ['first_name' => 'Karim', 'last_name' => 'Portail', 'birth_date' => '1990-01-01', 'phone' => '+216 20 000 002', 'email' => "karim-$suffix@test.test", 'password' => $ppw, 'password_confirmation' => $ppw]);
    check('Inscription patient → espace patient', $pat->location() === "/p/$slug/account");
    $pat->forgetToken();
    $pat->get("/p/$slug/account");
    $pat->get("/api/portal/$slug/slots?date=$day");
    $slots = json_decode($pat->body, true)['slots'] ?? [];
    check('API créneaux : 10:00 occupé (1 fauteuil), autres libres', $slots && !in_array('10:00', $slots, true) && in_array('10:30', $slots, true), implode(',', array_slice($slots, 0, 6)));
    $pat->get("/p/$slug/account");
    $pat->post("/p/$slug/book", ['date' => $day, 'time' => '10:00']);
    check('Réservation d’un créneau occupé refusée', (int) Database::value("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND source = 'portal'", [$clinicId]) === 0);
    $pat->post("/p/$slug/book", ['date' => $day, 'time' => '10:30', 'reason' => 'Douleur']);
    $booked = Database::one("SELECT * FROM appointments WHERE clinic_id = ? AND source = 'portal'", [$clinicId]);
    check('Réservation en ligne → statut "En attente"', $booked && $booked['status'] === 'pending');
    $admin->get('/dashboard');
    check('Demande visible au tableau de bord du cabinet', str_contains($admin->body, 'Portail') && str_contains($admin->body, 'Douleur'));
    $admin->post("/appointments/{$booked['id']}/status", ['status' => 'confirmed', 'back' => '/dashboard']);
    check('Cabinet confirme la demande', Database::value('SELECT status FROM appointments WHERE id = ?', [$booked['id']]) === 'confirmed');
    $pat->get("/p/$slug/account");
    check('Patient voit son RDV confirmé', str_contains($pat->body, 'Confirmé'));
    $pat->post("/p/$slug/appointments/{$booked['id']}/cancel");
    check('Patient annule son RDV', Database::value('SELECT status FROM appointments WHERE id = ?', [$booked['id']]) === 'cancelled');

    echo "\n\033[1mEspace patient : consultations, soins, factures, profil\033[0m\n";
    $portalPatient = (int) Database::value("SELECT patient_id FROM users WHERE email = ?", ["karim-$suffix@test.test"]);
    $pat->get("/p/$slug/account");
    $pat->post("/p/$slug/book", ['date' => $day, 'time' => '11:00', 'reason' => 'Contrôle annuel']);
    $rdv = Database::one("SELECT * FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status = 'pending'", [$clinicId, $portalPatient]);
    $pat->get("/p/$slug/appointments/{$rdv['id']}");
    check('Détail du RDV : date, heure, motif, adresse du cabinet', $pat->status === 200 && str_contains($pat->body, '11:00') && str_contains($pat->body, 'Contrôle annuel') && str_contains($pat->body, '+216 71 123 456'));
    check('Détail du RDV : annulation proposée', str_contains($pat->body, 'Annuler ce rendez-vous'));

    $admin->get("/patients/$portalPatient");
    $admin->post("/patients/$portalPatient/procedures", ['performed_on' => date('Y-m-d'), 'tooth' => '36', 'description' => "Obturation composite, contrôle dans 6 mois ($suffix)"]);
    $pat->get("/p/$slug/care");
    check('Mes soins : le compte rendu du dentiste est lisible', str_contains($pat->body, "Obturation composite, contrôle dans 6 mois ($suffix)") && str_contains($pat->body, 'dent 36'));

    $sec->get('/invoices/new');
    $sec->post('/invoices', ['patient_id' => $portalPatient, 'issued_on' => date('Y-m-d'), 'item_description' => ['Soin carie'], 'item_quantity' => ['1'], 'item_price' => ['120']]);
    $inv = Database::one('SELECT * FROM invoices WHERE clinic_id = ? AND patient_id = ?', [$clinicId, $portalPatient]);
    $sec->get("/invoices/{$inv['id']}");
    $sec->post("/invoices/{$inv['id']}/payments", ['amount' => '50', 'method' => 'cash', 'paid_on' => date('Y-m-d')]);
    $pat->get("/p/$slug/invoices");
    check('Mes factures : reste à payer correct (70,000 TND)', str_contains($pat->body, $inv['number']) && str_contains($pat->body, '70,000 TND'));
    $pat->get("/p/$slug/invoices/{$inv['id']}");
    check('Détail de facture : lignes et paiements', str_contains($pat->body, 'Soin carie') && str_contains($pat->body, 'Espèces'));
    $pat->get("/p/$slug/invoices/{$inv['id']}/print");
    check('Facture imprimable, retour vers l’espace patient', $pat->status === 200 && str_contains($pat->body, "href=\"/p/$slug/invoices/{$inv['id']}\""));

    $pat->get("/p/$slug/profile");
    $pat->post("/p/$slug/profile", ['phone' => '+216 22 333 444', 'email' => "karim-$suffix@test.test", 'address' => '5 rue de Marseille, Tunis']);
    check('Profil : coordonnées mises à jour sans mot de passe (e-mail inchangé)', Database::value('SELECT phone FROM patients WHERE id = ?', [$portalPatient]) === '+216 22 333 444');
    $newEmail = "karim.new-$suffix@test.test";
    $pat->get("/p/$slug/profile");
    $pat->post("/p/$slug/profile", ['phone' => '+216 22 333 444', 'email' => $newEmail, 'current_password' => $ppw]);
    check('Profil : changement d’e-mail avec mot de passe', Database::value("SELECT email FROM users WHERE patient_id = ? AND role = 'patient'", [$portalPatient]) === $newEmail);
    $newPpw = 'Nouveau-' . bin2hex(random_bytes(4)) . '8';
    $pat->get("/p/$slug/profile");
    $pat->post("/p/$slug/password", ['current_password' => $ppw, 'password' => $newPpw, 'password_confirmation' => $newPpw]);
    $relog = new Client($base);
    $relog->get("/p/$slug/login");
    $relog->post("/p/$slug/login", ['email' => $newEmail, 'password' => $newPpw]);
    check('Reconnexion avec le nouvel e-mail et le nouveau mot de passe', $relog->location() === "/p/$slug/account");
} finally {
    if ($clinicId) {
        foreach (['payments', 'invoice_items', 'invoices', 'procedures', 'appointments', 'users', 'patients', 'treatments'] as $t) {
            Database::run('DELETE FROM ' . Database::ident($t) . ' WHERE clinic_id = ?', [$clinicId]);
        }
        Database::run('DELETE FROM clinics WHERE id = ?', [$clinicId]);
    }
    Database::run('DELETE FROM rate_limits');
}

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "Résultat : $passed réussis, $failed échoués\033[0m\n";
exit($failed === 0 ? 0 : 1);
