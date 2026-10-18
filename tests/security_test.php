<?php
declare(strict_types=1);

/**
 * Tests de sécurité de bout en bout (HTTP réel) : php tests/security_test.php [http://localhost:8080]
 * Crée deux cabinets de test (A et B), vérifie l'isolation, les rôles, CSRF, XSS, injection SQL,
 * limitation des tentatives, MFA, en-têtes… puis supprime les données de test.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Totp;

$base = rtrim($argv[1] ?? (string) env('APP_URL', 'http://localhost:8080'), '/');

require __DIR__ . '/HttpClient.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? "  \033[32m✔\033[0m " : "  \033[31m✘\033[0m ") . $label . ($ok || $detail === '' ? '' : "  → $detail") . "\n";
}
function section(string $title): void
{
    echo "\n\033[1m$title\033[0m\n";
}

// ---------------------------------------------------------------- fixtures
$suffix = bin2hex(random_bytes(3));
$password = 'Test-' . bin2hex(random_bytes(6)) . '9';
$hash = password_hash($password, password_algo());
$fixtures = [];
foreach (['a', 'b'] as $k) {
    $cid = Database::insert('clinics', ['name' => "Test $k $suffix", 'slug' => "test-$k-$suffix", 'opening_hours' => json_encode(App\Core\Availability::defaultHours())]);
    $pid = Database::insert('patients', ['clinic_id' => $cid, 'first_name' => 'Secret', 'last_name' => "Patient" . strtoupper($k) . $suffix, 'medical_history' => "ANTECEDENT-$k-$suffix"]);
    $users = [];
    foreach (['admin', 'dentist', 'secretary'] as $role) {
        $users[$role] = Database::insert('users', ['clinic_id' => $cid, 'role' => $role, 'name' => "$role $k", 'email' => "$role-$k-$suffix@test.test", 'password_hash' => $hash]);
    }
    $users['patient'] = Database::insert('users', ['clinic_id' => $cid, 'patient_id' => $pid, 'role' => 'patient', 'name' => "patient $k", 'email' => "patient-$k-$suffix@test.test", 'password_hash' => $hash]);
    $start = date('Y-m-d 10:00:00', strtotime('+3 days'));
    $aid = Database::insert('appointments', ['clinic_id' => $cid, 'patient_id' => $pid, 'starts_at' => $start, 'ends_at' => date('Y-m-d H:i:s', strtotime($start) + 1800), 'status' => 'confirmed']);
    $iid = Database::insert('invoices', ['clinic_id' => $cid, 'patient_id' => $pid, 'number' => "T-$k-$suffix", 'issued_on' => date('Y-m-d'), 'total' => 100]);
    Database::insert('procedures', ['clinic_id' => $cid, 'patient_id' => $pid, 'performed_on' => date('Y-m-d'), 'description' => "SOIN-$k-$suffix"]);
    // A second patient of the SAME clinic: the portal must keep patients apart, not only clinics.
    $pid2 = Database::insert('patients', ['clinic_id' => $cid, 'first_name' => 'Voisin', 'last_name' => "Voisin$k$suffix"]);
    $start2 = date('Y-m-d 15:00:00', strtotime('+4 days'));
    $aid2 = Database::insert('appointments', ['clinic_id' => $cid, 'patient_id' => $pid2, 'starts_at' => $start2, 'ends_at' => date('Y-m-d H:i:s', strtotime($start2) + 1800), 'status' => 'confirmed']);
    $iid2 = Database::insert('invoices', ['clinic_id' => $cid, 'patient_id' => $pid2, 'number' => "T2-$k-$suffix", 'issued_on' => date('Y-m-d'), 'total' => 55]);
    Database::insert('procedures', ['clinic_id' => $cid, 'patient_id' => $pid2, 'performed_on' => date('Y-m-d'), 'description' => "SOIN-VOISIN-$k-$suffix"]);
    $fixtures[$k] = compact('cid', 'pid', 'users', 'aid', 'iid', 'pid2', 'aid2', 'iid2') + ['slug' => "test-$k-$suffix"];
}
$A = $fixtures['a'];
$B = $fixtures['b'];

function login(string $base, string $email, string $password, string $path = '/login'): Client
{
    $c = new Client($base);
    $c->get($path);
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $c->body, $m);
    $c->post($path, ['email' => $email, 'password' => $password, '_csrf' => $m[1] ?? '']);
    $c->loginRedirect = $c->location();
    $c->forgetToken(); // the app rotates the CSRF token at login
    return $c;
}

try {
    // ------------------------------------------------------------ 1. Authentication
    section('1. Authentification sécurisée');
    $anon = new Client($base);
    $anon->get('/login');
    $before = $anon->sessionId();
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $anon->body, $m);
    $anon->post('/login', ['email' => "dentist-a-$suffix@test.test", 'password' => $password, '_csrf' => $m[1]]);
    check('Connexion valide → redirection tableau de bord', $anon->status === 303 && $anon->location() === '/dashboard', $anon->status . ' ' . $anon->location());
    check('ID de session régénéré après connexion (anti-fixation)', $before !== '' && $anon->sessionId() !== '' && $before !== $anon->sessionId());
    $dentistA = $anon;
    $dentistA->get('/dashboard');

    $bad = login($base, "dentist-a-$suffix@test.test", 'mauvais-mot-de-passe1');
    check('Mot de passe faux refusé', $bad->location() === '/login');

    $brute = new Client($base);
    $victim = "nobody-$suffix@test.test";
    for ($i = 0; $i < 5; $i++) {
        $brute->post('/login', ['email' => $victim, 'password' => "wrong$i-password"]);
    }
    $brute->post('/login', ['email' => $victim, 'password' => 'wrong-again-1']);
    $brute->get('/login');
    check('Verrouillage après 5 échecs (anti brute-force)', str_contains($brute->body, 'Trop de tentatives'));
    check('Verrouillage journalisé (account_locked)', (bool) Database::value("SELECT 1 FROM audit_log WHERE event = 'account_locked' AND details LIKE ?", ["%$victim%"]));

    $adminA = login($base, "admin-a-$suffix@test.test", $password);
    $adminA->get('/dashboard');
    check('Admin sans 2FA forcé vers /security', $adminA->status === 303 && $adminA->location() === '/security');

    // MFA flow with a known secret
    $secret = Totp::generateSecret();
    Database::run('UPDATE users SET mfa_enabled = true, mfa_secret = ? WHERE id = ?', [Crypto::encrypt($secret), $A['users']['admin']]);
    $mfa = login($base, "admin-a-$suffix@test.test", $password);
    check('Admin avec 2FA → étape /mfa', $mfa->location() === '/mfa');
    $mfa->get('/dashboard');
    check('Pas d’accès avant validation du 2e facteur', $mfa->status === 303 && $mfa->location() === '/login');
    $mfa->get('/mfa');
    $mfa->post('/mfa', ['code' => '000000']);
    check('Code 2FA faux refusé', $mfa->location() === '/mfa');
    $mfa->post('/mfa', ['code' => Totp::code($secret, intdiv(time(), 30))]);
    check('Code 2FA valide → connecté', $mfa->location() === '/dashboard', $mfa->location());
    $mfa->get('/dashboard');
    check('Admin 2FA accède au tableau de bord', $mfa->status === 200);
    $adminA = $mfa;
    $adminA->get('/dashboard');

    // Deactivated account loses its session immediately
    $sec = login($base, "secretary-a-$suffix@test.test", $password);
    $sec->get('/patients');
    $okBefore = $sec->status === 200;
    Database::run('UPDATE users SET is_active = false, session_epoch = session_epoch + 1 WHERE id = ?', [$A['users']['secretary']]);
    $sec->get('/patients');
    check('Compte désactivé : session invalidée immédiatement', $okBefore && $sec->status === 303);
    Database::run('UPDATE users SET is_active = true WHERE id = ?', [$A['users']['secretary']]);
    $secretaryA = login($base, "secretary-a-$suffix@test.test", $password);

    // ------------------------------------------------------------ 2. Authorization & tenant isolation
    section('2. Autorisation et séparation des cabinets');
    $dentistA->get("/patients/{$B['pid']}");
    check('Dossier patient d’un autre cabinet → 404', $dentistA->status === 404, (string) $dentistA->status);
    check('Aucune donnée de B dans la réponse', !str_contains($dentistA->body, "ANTECEDENT-b-$suffix"));
    $dentistA->get("/appointments/{$B['aid']}/edit");
    check('RDV d’un autre cabinet → 404', $dentistA->status === 404);
    $adminA->get("/invoices/{$B['iid']}");
    check('Facture d’un autre cabinet → 404', $adminA->status === 404);
    $dentistA->post("/patients/{$B['pid']}", ['first_name' => 'Pirate', 'last_name' => 'Pirate']);
    check('Modification d’un patient de B refusée', $dentistA->status === 404 && Database::value('SELECT first_name FROM patients WHERE id = ?', [$B['pid']]) === 'Secret');
    $before = (int) Database::value('SELECT COUNT(*) FROM appointments');
    $dentistA->post('/appointments', ['patient_id' => $B['pid'], 'date' => date('Y-m-d', strtotime('+5 days')), 'time' => '10:00', 'duration' => '30', 'status' => 'confirmed']);
    check('RDV avec un patient d’un autre cabinet refusé', (int) Database::value('SELECT COUNT(*) FROM appointments') === $before);
    $adminA->post("/invoices/{$B['iid']}/payments", ['amount' => '10', 'method' => 'cash', 'paid_on' => date('Y-m-d')]);
    check('Paiement sur facture d’un autre cabinet refusé', $adminA->status === 404 && (float) Database::value('SELECT paid FROM invoices WHERE id = ?', [$B['iid']]) === 0.0);
    check('Tentatives inter-cabinets journalisées en "critical"', (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE event = 'cross_tenant_access_attempt' AND clinic_id = ?", [$A['cid']]) >= 4);

    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime('+30 days'));
    $dentistA->get("/api/appointments?start=$from&end=$to");
    $ids = array_column(json_decode($dentistA->body, true)['data'] ?? [], 'id');
    check('API : uniquement les RDV du cabinet de l’utilisateur', in_array($A['aid'], $ids) && !in_array($B['aid'], $ids));

    $secretaryA->get('/staff');
    check('Secrétaire → gestion d’équipe interdite (403)', $secretaryA->status === 403);
    $dentistA->get('/invoices');
    check('Dentiste → factures interdites (403)', $dentistA->status === 403);
    $secretaryA->get("/patients/{$A['pid']}");
    check('Secrétaire : antécédents médicaux masqués', $secretaryA->status === 200 && !str_contains($secretaryA->body, "ANTECEDENT-a-$suffix"));
    $dentistA->get("/patients/{$A['pid']}");
    check('Dentiste : antécédents visibles', str_contains($dentistA->body, "ANTECEDENT-a-$suffix"));
    $secretaryA->post("/patients/{$A['pid']}", ['first_name' => 'Secret', 'last_name' => "PatientA$suffix", 'medical_history' => 'ECRASE']);
    check('Secrétaire ne peut pas écraser les antécédents', Database::value('SELECT medical_history FROM patients WHERE id = ?', [$A['pid']]) === "ANTECEDENT-a-$suffix");

    $patientA = login($base, "patient-a-$suffix@test.test", $password, "/p/{$A['slug']}/login");
    check('Patient : connexion au portail de son cabinet', $patientA->location() === "/p/{$A['slug']}/account", $patientA->location());
    $patientA->get('/dashboard');
    check('Patient → espace professionnel interdit (403)', $patientA->status === 403);
    $patientA->get("/p/{$B['slug']}/account");
    check('Patient → portail d’un autre cabinet interdit (403)', $patientA->status === 403);
    $patientA->post("/p/{$A['slug']}/appointments/{$B['aid']}/cancel");
    check('Patient ne peut pas annuler le RDV d’un autre', $patientA->status === 404 && Database::value('SELECT status FROM appointments WHERE id = ?', [$B['aid']]) === 'confirmed');
    $cross = login($base, "patient-a-$suffix@test.test", $password, "/p/{$B['slug']}/login");
    check('Compte patient de A inutilisable sur le portail de B', $cross->location() === "/p/{$B['slug']}/login");

    // Patient area: own records visible, a neighbour's (same clinic) or another clinic's never.
    $pa = "/p/{$A['slug']}";
    $patientA->get("$pa/appointments/{$A['aid']}");
    check('Espace patient : son propre RDV est visible', $patientA->status === 200);
    $patientA->get("$pa/appointments/{$A['aid2']}");
    check('Espace patient : RDV d’un autre patient du même cabinet → 404', $patientA->status === 404);
    $patientA->get("$pa/appointments/{$B['aid']}");
    check('Espace patient : RDV d’un autre cabinet → 404', $patientA->status === 404);
    $patientA->get("$pa/invoices/{$A['iid']}");
    check('Espace patient : sa propre facture est visible', $patientA->status === 200 && str_contains($patientA->body, "T-a-$suffix"));
    $patientA->get("$pa/invoices/{$A['iid2']}");
    check('Espace patient : facture d’un autre patient → 404', $patientA->status === 404);
    $patientA->get("$pa/invoices/{$A['iid2']}/print");
    check('Espace patient : impression de la facture d’un autre patient → 404', $patientA->status === 404);
    $patientA->get("$pa/invoices");
    check('Espace patient : la liste ne montre que ses factures', str_contains($patientA->body, "T-a-$suffix") && !str_contains($patientA->body, "T2-a-$suffix"));
    $patientA->get("$pa/care");
    check('Espace patient : ses soins visibles, pas ceux du voisin', str_contains($patientA->body, "SOIN-a-$suffix") && !str_contains($patientA->body, "SOIN-VOISIN"));
    $patientA->get("$pa/profile");
    check('Espace patient : ses antécédents médicaux ne sont pas exposés', !str_contains($patientA->body, "ANTECEDENT-a-$suffix"));
    $patientA->post("$pa/profile", ['phone' => '+216 20 000 000', 'email' => "pirate-$suffix@test.test", 'current_password' => '']);
    check('Changer l’e-mail de connexion exige le mot de passe', Database::value('SELECT email FROM users WHERE id = ?', [$A['users']['patient']]) === "patient-a-$suffix@test.test");
    $patientA->post("$pa/profile", ['phone' => '+216 20 000 000', 'email' => "dentist-a-$suffix@test.test", 'current_password' => $password]);
    check('Impossible de prendre l’e-mail d’un autre compte du cabinet', Database::value('SELECT email FROM users WHERE id = ?', [$A['users']['patient']]) === "patient-a-$suffix@test.test");
    check('Accès au dossier d’un autre patient journalisé (critique)', (int) Database::value("SELECT count(*) FROM audit_log WHERE event = 'cross_patient_access_attempt' AND clinic_id = ?", [$A['cid']]) >= 3);

    // ------------------------------------------------------------ 3. Input validation, SQLi, XSS, CSRF
    section('3. Validation des entrées (SQL, XSS, CSRF)');
    $noCsrf = new Client($base);
    $noCsrf->request('POST', '/login', http_build_query(['email' => 'x@y.z', 'password' => 'x']));
    check('POST sans jeton CSRF → 419', $noCsrf->status === 419, (string) $noCsrf->status);
    $dentistA->post('/patients', ['_csrf' => 'deadbeef', 'first_name' => 'X', 'last_name' => 'Y']);
    check('POST avec jeton CSRF invalide → 419', $dentistA->status === 419);

    $xss = '<script>alert(1)</script>';
    $dentistA->post('/patients', ['first_name' => $xss, 'last_name' => "Xss$suffix", 'email' => 'pas-un-email']);
    check('Validation serveur : e-mail invalide refusé', $dentistA->location() === '/patients/new');
    $dentistA->post('/patients', ['first_name' => $xss, 'last_name' => "Xss$suffix"]);
    $dentistA->get($dentistA->location());
    check('XSS : balise <script> encodée en sortie', !str_contains($dentistA->body, $xss) && str_contains($dentistA->body, '&lt;script&gt;alert(1)&lt;/script&gt;'));

    $dentistA->get('/patients?q=' . rawurlencode("' OR '1'='1' -- "));
    check('Injection SQL dans la recherche : sans effet', $dentistA->status === 200 && !str_contains($dentistA->body, "<strong>PatientB$suffix</strong>"));
    $dentistA->get('/patients?q=' . rawurlencode("PatientB$suffix"));
    check('Recherche ne fuit pas les patients d’un autre cabinet', $dentistA->status === 200 && !str_contains($dentistA->body, "<strong>PatientB$suffix</strong>"));
    $dentistA->get('/patients/1%20OR%201=1');
    check('Paramètre d’URL non numérique rejeté (404)', $dentistA->status === 404);
    $dentistA->post('/appointments', ['patient_id' => $A['pid'], 'date' => '2026-02-30', 'time' => '25:99', 'duration' => '30', 'status' => 'hacked']);
    check('Date/heure/statut invalides refusés', str_contains($dentistA->location(), '/appointments/new'));

    // ------------------------------------------------------------ 4. Secrets
    section('4. Protection des secrets');
    $anon = new Client($base);
    foreach (['/.env', '/../.env', '/app/Core/Database.php', '/storage/logs/security.log', '/database/schema.sql'] as $p) {
        $anon->get($p);
        check("Fichier sensible inaccessible : $p", $anon->status === 404 || $anon->status === 403, (string) $anon->status);
    }
    $gitignore = (string) file_get_contents(BASE_PATH . '/.gitignore');
    check('.env exclu de Git', (bool) preg_match('/^\.env$/m', $gitignore));
    check('Secret 2FA chiffré en base (pas en clair)', !str_contains((string) Database::value('SELECT mfa_secret FROM users WHERE id = ?', [$A['users']['admin']]), $secret));
    check('Mots de passe hachés (argon2id/bcrypt)', (bool) preg_match('/^\$(argon2id|2y)\$/', (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$A['users']['admin']])));
    check('Application connectée avec un rôle dédié (ni root ni postgres)', !in_array(env('DB_USER'), ['root', 'postgres'], true));
    check('Rôle applicatif sans privilège d’administration', Database::value('SELECT rolsuper OR rolcreaterole OR rolbypassrls FROM pg_roles WHERE rolname = current_user') === false);
    check('Tables invisibles pour les rôles publics de Supabase (anon/authenticated)', !Database::value("SELECT 1 FROM pg_roles r WHERE r.rolname IN ('anon', 'authenticated') AND has_schema_privilege(r.oid, current_schema(), 'USAGE')"));
    check('Row Level Security active sur toutes les tables', !Database::value('SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND NOT rowsecurity'));
    $anon->get('/');
    check('Aucune clé/secret dans le HTML public', !str_contains($anon->body, (string) env('APP_KEY')) && !str_contains($anon->body, (string) env('DB_PASS')));

    // ------------------------------------------------------------ 5. API protection & errors
    section('5. Protection de l’API et gestion des erreurs');
    $anon->get('/api/appointments?start=2026-01-01&end=2026-01-31');
    check('API sans authentification → 401 JSON', $anon->status === 401 && str_contains($anon->headers['content-type'] ?? '', 'json'));
    $dentistA->get('/api/appointments?start=2026-01-01&end=2027-12-31');
    check('API : plage de dates excessive refusée (400)', $dentistA->status === 400);
    $big = str_repeat('a', 2 * 1024 * 1024);
    $anon->request('POST', '/login', 'x=' . $big);
    check('Corps de requête > 1 Mo refusé (413)', $anon->status === 413, (string) $anon->status);
    $limited = new Client($base);
    $codes = [];
    for ($i = 0; $i < 62; $i++) {
        $codes[] = $limited->get("/api/portal/{$A['slug']}/slots?date=" . date('Y-m-d', strtotime('+3 days')))->status;
    }
    check('API publique limitée (429 après 60 req/min)', in_array(429, $codes, true) && $codes[0] === 200);
    $anon->get('/route-qui-nexiste-pas');
    check('404 propre, sans trace technique', $anon->status === 404 && !str_contains($anon->body, 'Stack') && !str_contains($anon->body, '.php'));
    $anon->request('DELETE', '/patients');
    check('Méthode HTTP non prévue → 405', $anon->status === 405);

    $anon->get('/');
    $h = $anon->headers;
    check('En-tête Content-Security-Policy strict', str_contains($h['content-security-policy'] ?? '', "script-src 'self'") && !str_contains($h['content-security-policy'] ?? '', 'unsafe-inline'));
    check('En-têtes X-Frame-Options / nosniff / Referrer-Policy', ($h['x-frame-options'] ?? '') === 'DENY' && ($h['x-content-type-options'] ?? '') === 'nosniff' && isset($h['referrer-policy']));
    check('X-Powered-By masqué', !isset($h['x-powered-by']));
    $c = new Client($base);
    $c->get('/login');
    $setCookie = '';
    $ch = curl_init($base . '/login');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
    $raw = (string) curl_exec($ch);
    preg_match('/^Set-Cookie: gcsess=.*$/mi', $raw, $sm);
    $setCookie = strtolower($sm[0] ?? '');
    check('Cookie de session HttpOnly + SameSite', str_contains($setCookie, 'httponly') && str_contains($setCookie, 'samesite=lax'));
    $dentistA->get('/patients');
    check('Pages patients non mises en cache (no-store)', str_contains($dentistA->headers['cache-control'] ?? '', 'no-store'));

    // ------------------------------------------------------------ 6. Monitoring
    section('6. Journalisation et surveillance');
    foreach (['login_success', 'login_failed', 'account_locked', 'cross_tenant_access_attempt', 'access_denied', 'csrf_failed', 'rate_limited'] as $event) {
        check("Événement journalisé : $event", (bool) Database::value("SELECT 1 FROM audit_log WHERE event = ? AND created_at >= now() - interval '10 minutes'", [$event]));
    }
    check('Alertes écrites dans storage/logs/alerts.log', is_file(BASE_PATH . '/storage/logs/alerts.log') && str_contains((string) file_get_contents(BASE_PATH . '/storage/logs/alerts.log'), 'cross_tenant_access_attempt'));
    check('Mots de passe jamais journalisés', !str_contains((string) @file_get_contents(BASE_PATH . '/storage/logs/security.log'), $password));
} finally {
    // ------------------------------------------------------------ cleanup
    foreach ($fixtures as $f) {
        foreach (['payments', 'invoice_items', 'invoices', 'procedures', 'appointments', 'users', 'patients', 'treatments'] as $t) {
            Database::run('DELETE FROM ' . Database::ident($t) . ' WHERE clinic_id = ?', [$f['cid']]);
        }
        Database::run('DELETE FROM clinics WHERE id = ?', [$f['cid']]);
    }
    Database::run('DELETE FROM rate_limits'); // reset counters touched by the tests
}

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "Résultat : $passed réussis, $failed échoués\033[0m\n";
exit($failed === 0 ? 0 : 1);
