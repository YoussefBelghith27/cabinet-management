<?php
declare(strict_types=1);

/**
 * Données de démonstration : php database/seed.php (ou install.php --seed)
 * Les identifiants générés sont écrits dans storage/demo-credentials.txt (ignoré par Git).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
if (!defined('BASE_PATH')) {
    require dirname(__DIR__) . '/app/bootstrap.php';
}

use App\Core\Clinics;
use App\Core\Database;

if (Database::value("SELECT 1 FROM clinics WHERE slug = 'cabinet-demo'")) {
    echo "• Le cabinet de démonstration existe déjà.\n";
    return;
}

$credentials = [];
$pw = fn () => random_password(12) . '7';

$adminPw = $pw();
$demo = Clinics::create('Cabinet Démo', 'Dr. Amira Ben Salah', 'admin@demo.test', $adminPw);
$cid = $demo['clinic_id'];
Database::run("UPDATE clinics SET slug = 'cabinet-demo', phone = '+216 71 000 000', address = '12 avenue Habib Bourguiba, Tunis', chairs = 2 WHERE id = ?", [$cid]);
$credentials[] = ['Administrateur (2FA à activer au 1er login)', 'admin@demo.test', $adminPw, '/login'];

$dentistPw = $pw();
$dentistId = Database::insert('users', ['clinic_id' => $cid, 'role' => 'dentist', 'name' => 'Dr. Karim Trabelsi', 'email' => 'dentiste@demo.test', 'password_hash' => password_hash($dentistPw, password_algo())]);
$credentials[] = ['Dentiste', 'dentiste@demo.test', $dentistPw, '/login'];

$secPw = $pw();
Database::insert('users', ['clinic_id' => $cid, 'role' => 'secretary', 'name' => 'Sana Mejri', 'email' => 'secretaire@demo.test', 'password_hash' => password_hash($secPw, password_algo())]);
$credentials[] = ['Secrétaire', 'secretaire@demo.test', $secPw, '/login'];

$people = [
    ['Yassine', 'Haddad', '1985-03-12', 'M', '+216 20 111 222', 'Pénicilline'],
    ['Mariem', 'Gharbi', '1992-07-25', 'F', '+216 22 333 444', null],
    ['Omar', 'Jebali', '1978-11-02', 'M', '+216 98 555 666', null],
    ['Ines', 'Chaabane', '2001-01-18', 'F', '+216 50 777 888', 'Latex'],
    ['Hichem', 'Sassi', '1969-05-30', 'M', '+216 55 999 000', null],
    ['Leila', 'Bouazizi', '1995-09-09', 'F', '+216 23 121 314', null],
];
$patientIds = [];
foreach ($people as [$first, $last, $birth, $gender, $phone, $allergies]) {
    $patientIds[] = Database::insert('patients', [
        'clinic_id' => $cid, 'first_name' => $first, 'last_name' => $last, 'birth_date' => $birth, 'gender' => $gender,
        'phone' => $phone, 'email' => strtolower($first) . '.' . strtolower($last) . '@exemple.test', 'allergies' => $allergies,
        'medical_history' => $last === 'Sassi' ? 'Diabète type 2, sous metformine.' : null,
    ]);
}

$patientPw = $pw();
Database::insert('users', ['clinic_id' => $cid, 'patient_id' => $patientIds[1], 'role' => 'patient', 'name' => 'Mariem Gharbi', 'email' => 'mariem.gharbi@exemple.test', 'password_hash' => password_hash($patientPw, password_algo())]);
$credentials[] = ['Patient (portail)', 'mariem.gharbi@exemple.test', $patientPw, '/p/cabinet-demo/login'];

$treatments = Database::all('SELECT id, name, price, duration_minutes FROM treatments WHERE clinic_id = ?', [$cid]);
$monday = new DateTimeImmutable('monday this week');
$slots = ['09:00', '10:00', '11:30', '14:00', '15:30', '16:30'];
foreach (range(-7, 9) as $offset) {
    $day = $monday->modify("+$offset days");
    if ((int) $day->format('N') === 7) {
        continue;
    }
    foreach (array_slice($slots, 0, 2 + abs($offset) % 3) as $i => $time) {
        $t = $treatments[($offset + 7 + $i) % count($treatments)];
        $start = new DateTimeImmutable($day->format('Y-m-d') . " $time");
        $isPast = $start < new DateTimeImmutable();
        Database::insert('appointments', [
            'clinic_id' => $cid,
            'patient_id' => $patientIds[($offset + 7 + $i) % count($patientIds)],
            'dentist_id' => $i % 2 ? $dentistId : $demo['user_id'],
            'treatment_id' => $t['id'],
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->modify('+' . $t['duration_minutes'] . ' minutes')->format('Y-m-d H:i:s'),
            'status' => $isPast ? ($i === 2 ? 'no_show' : 'completed') : ($offset > 4 && $i === 0 ? 'pending' : 'confirmed'),
            'source' => $offset > 4 && $i === 0 ? 'portal' : 'staff',
        ]);
    }
}

Database::insert('procedures', ['clinic_id' => $cid, 'patient_id' => $patientIds[0], 'dentist_id' => $dentistId, 'treatment_id' => $treatments[2]['id'],
    'performed_on' => $monday->modify('-5 days')->format('Y-m-d'), 'tooth' => '36', 'description' => 'Carie occlusale, obturation composite A2. Pas de sensibilité post-opératoire.']);

// Invoices over the last months
foreach ([[0, 5, 'paid'], [1, 3, 'paid'], [2, 2, 'partial'], [3, 1, 'paid'], [4, 0, 'unpaid'], [5, 0, 'partial']] as $n => [$pIdx, $monthsAgo, $state]) {
    $t = $treatments[$n % count($treatments)];
    $date = date('Y-m-d', strtotime("-$monthsAgo months -" . ($n + 1) . ' days'));
    $total = (float) $t['price'] * 2;
    $seq = (int) Database::value('SELECT invoice_seq FROM clinics WHERE id = ?', [$cid]) + 1;
    Database::run('UPDATE clinics SET invoice_seq = ? WHERE id = ?', [$seq, $cid]);
    $paid = $state === 'paid' ? $total : ($state === 'partial' ? round($total / 2, 3) : 0);
    $invoiceId = Database::insert('invoices', ['clinic_id' => $cid, 'patient_id' => $patientIds[$pIdx], 'number' => sprintf('F%s-%05d', substr($date, 0, 4), $seq),
        'issued_on' => $date, 'total' => $total, 'paid' => $paid, 'status' => $state]);
    Database::insert('invoice_items', ['clinic_id' => $cid, 'invoice_id' => $invoiceId, 'description' => $t['name'], 'quantity' => 2, 'unit_price' => $t['price'], 'line_total' => $total]);
    if ($paid > 0) {
        Database::insert('payments', ['clinic_id' => $cid, 'invoice_id' => $invoiceId, 'amount' => $paid, 'method' => $n % 2 ? 'card' : 'cash', 'paid_on' => $date]);
    }
}

$lines = ["Identifiants de démonstration (générés le " . date('d/m/Y H:i') . ") — NE PAS utiliser en production", ''];
foreach ($credentials as [$role, $email, $password, $url]) {
    $lines[] = str_pad($role, 44) . " $email  /  $password   ($url)";
}
file_put_contents(BASE_PATH . '/storage/demo-credentials.txt', implode(PHP_EOL, $lines) . PHP_EOL);
echo "✔ Cabinet de démonstration créé. Identifiants : storage/demo-credentials.txt\n";
