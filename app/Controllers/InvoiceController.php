<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;

final class InvoiceController extends Controller
{
    private const ROLES = ['admin', 'secretary'];
    private const MAX_LINES = 30;

    public function index(): void
    {
        $user = $this->requireRole(self::ROLES);
        $cid = (int) $user['clinic_id'];
        $status = in_array($_GET['status'] ?? '', ['unpaid', 'partial', 'paid', 'cancelled'], true) ? $_GET['status'] : '';

        $sql = 'SELECT i.*, p.first_name, p.last_name FROM invoices i
                  JOIN patients p ON p.id = i.patient_id AND p.clinic_id = i.clinic_id
                 WHERE i.clinic_id = ?';
        $params = [$cid];
        if ($status !== '') {
            $sql .= ' AND i.status = ?';
            $params[] = $status;
        }
        $invoices = Database::all($sql . ' ORDER BY i.issued_on DESC, i.id DESC LIMIT 300', $params);

        $this->view('invoices/index', ['title' => 'Factures', 'invoices' => $invoices, 'status' => $status, 'currency' => $user['currency']]);
    }

    public function create(): void
    {
        $user = $this->requireRole(self::ROLES);
        $cid = (int) $user['clinic_id'];
        $this->view('invoices/form', [
            'title' => 'Nouvelle facture',
            'patientId' => ctype_digit((string) ($_GET['patient_id'] ?? '')) ? (int) $_GET['patient_id'] : 0,
            'patients' => Database::all('SELECT id, first_name, last_name FROM patients WHERE clinic_id = ? ORDER BY last_name, first_name LIMIT 2000', [$cid]),
            'treatments' => Database::all('SELECT id, name, price FROM treatments WHERE clinic_id = ? AND is_active ORDER BY name', [$cid]),
            'currency' => $user['currency'],
        ]);
    }

    public function store(): void
    {
        $user = $this->requireRole(self::ROLES);
        $cid = (int) $user['clinic_id'];
        [$d, $errors] = Validator::make($_POST, [
            'patient_id' => 'required|int',
            'issued_on' => 'required|date',
            'notes' => 'max:500',
        ]);
        if (!$errors && !Database::value('SELECT 1 FROM patients WHERE id = ? AND clinic_id = ?', [$d['patient_id'], $cid])) {
            $errors['patient_id'] = 'Patient introuvable.';
        }

        // Line items: totals are always computed server-side, never trusted from the browser.
        $descriptions = $this->postArray('item_description');
        $quantities = $this->postArray('item_quantity');
        $prices = $this->postArray('item_price');
        $lines = [];
        foreach (array_slice($descriptions, 0, self::MAX_LINES, true) as $i => $desc) {
            [$line, $lineErrors] = Validator::make(
                ['description' => $desc, 'quantity' => $quantities[$i] ?? '', 'unit_price' => $prices[$i] ?? ''],
                ['description' => 'max:255', 'quantity' => 'int', 'unit_price' => 'decimal']
            );
            if ($line['description'] === null && $line['unit_price'] === null) {
                continue; // empty row
            }
            if ($lineErrors || $line['description'] === null || $line['unit_price'] === null) {
                $errors['items'] = 'Ligne ' . ($i + 1) . ' invalide : description et prix obligatoires.';
                break;
            }
            $qty = max(1, min(999, (int) ($line['quantity'] ?? 1)));
            $lines[] = [
                'description' => $line['description'],
                'quantity' => $qty,
                'unit_price' => round((float) $line['unit_price'], 3),
                'line_total' => round($qty * (float) $line['unit_price'], 3),
            ];
        }
        if (!$lines && !isset($errors['items'])) {
            $errors['items'] = 'Ajoutez au moins une ligne.';
        }
        if ($errors) {
            $this->back('/invoices/new', $errors, $_POST);
        }

        $total = round(array_sum(array_column($lines, 'line_total')), 3);
        $id = Database::transaction(function () use ($cid, $d, $lines, $total, $user) {
            // Row lock on the clinic: sequential invoice numbers without race conditions.
            $seq = (int) Database::value('SELECT invoice_seq FROM clinics WHERE id = ? FOR UPDATE', [$cid]) + 1;
            Database::run('UPDATE clinics SET invoice_seq = ? WHERE id = ?', [$seq, $cid]);
            $invoiceId = Database::insert('invoices', [
                'clinic_id' => $cid,
                'patient_id' => (int) $d['patient_id'],
                'number' => sprintf('F%s-%05d', date('Y', strtotime($d['issued_on'])), $seq),
                'issued_on' => $d['issued_on'],
                'total' => $total,
                'status' => $total > 0 ? 'unpaid' : 'paid',
                'notes' => $d['notes'],
                'created_by' => $user['id'],
            ]);
            foreach ($lines as $line) {
                Database::insert('invoice_items', ['clinic_id' => $cid, 'invoice_id' => $invoiceId] + $line);
            }
            return $invoiceId;
        });

        Session::flash('success', 'Facture créée.');
        redirect("/invoices/$id");
    }

    public function show(int $id): void
    {
        $user = $this->requireRole(self::ROLES);
        $this->view('invoices/show', ['title' => 'Facture'] + $this->load($id, (int) $user['clinic_id']) + ['user' => $user]);
    }

    public function print(int $id): void
    {
        $user = $this->requireRole(self::ROLES);
        $data = $this->load($id, (int) $user['clinic_id']);
        $data['clinic'] = Database::one('SELECT * FROM clinics WHERE id = ?', [$user['clinic_id']]);
        $this->view('invoices/print', ['title' => 'Facture ' . $data['invoice']['number']] + $data, 'print');
    }

    public function addPayment(int $id): void
    {
        $user = $this->requireRole(self::ROLES);
        $cid = (int) $user['clinic_id'];
        $this->findOwned('invoices', $id, $cid);
        [$d, $errors] = Validator::make($_POST, [
            'amount' => 'required|decimal',
            'method' => 'required|in:cash,card,check,transfer,insurance',
            'paid_on' => 'required|date',
            'reference' => 'max:100',
        ]);
        if ($errors) {
            $this->back("/invoices/$id", $errors, $_POST);
        }

        Database::transaction(function () use ($id, $cid, $d, $user) {
            $invoice = Database::one('SELECT * FROM invoices WHERE id = ? AND clinic_id = ? FOR UPDATE', [$id, $cid]);
            $due = round((float) $invoice['total'] - (float) $invoice['paid'], 3);
            $amount = round((float) $d['amount'], 3);
            if ($invoice['status'] === 'cancelled' || $amount <= 0 || $amount > $due + 0.0005) {
                $this->back("/invoices/$id", ['amount' => 'Montant invalide (reste à payer : ' . money($due, $user['currency']) . ').'], $_POST);
            }
            Database::insert('payments', [
                'clinic_id' => $cid,
                'invoice_id' => $id,
                'amount' => $amount,
                'method' => $d['method'],
                'paid_on' => $d['paid_on'],
                'reference' => $d['reference'],
                'created_by' => $user['id'],
            ]);
            $paid = round((float) $invoice['paid'] + $amount, 3);
            $status = $paid + 0.0005 >= (float) $invoice['total'] ? 'paid' : 'partial';
            Database::updateOwned('invoices', $id, $cid, ['paid' => $paid, 'status' => $status]);
        });

        Audit::log('payment_recorded', 'info', ['invoice_id' => $id, 'amount' => $d['amount'], 'method' => $d['method']]);
        Session::flash('success', 'Paiement enregistré.');
        redirect("/invoices/$id");
    }

    public function cancel(int $id): void
    {
        $user = $this->requireRole(['admin']);
        $invoice = $this->findOwned('invoices', $id, (int) $user['clinic_id']);
        if ((float) $invoice['paid'] > 0) {
            Session::flash('error', 'Impossible d’annuler une facture qui a déjà reçu des paiements.');
            redirect("/invoices/$id");
        }
        Database::updateOwned('invoices', $id, (int) $user['clinic_id'], ['status' => 'cancelled']);
        Audit::log('invoice_cancelled', 'warning', ['invoice_id' => $id, 'number' => $invoice['number']]);
        Session::flash('success', 'Facture annulée.');
        redirect("/invoices/$id");
    }

    private function load(int $id, int $cid): array
    {
        $invoice = $this->findOwned('invoices', $id, $cid);
        return [
            'invoice' => $invoice,
            'patient' => Database::one('SELECT * FROM patients WHERE id = ? AND clinic_id = ?', [$invoice['patient_id'], $cid]),
            'items' => Database::all('SELECT * FROM invoice_items WHERE invoice_id = ? AND clinic_id = ? ORDER BY id', [$id, $cid]),
            'payments' => Database::all('SELECT * FROM payments WHERE invoice_id = ? AND clinic_id = ? ORDER BY paid_on, id', [$id, $cid]),
            'currency' => (string) Database::value('SELECT currency FROM clinics WHERE id = ?', [$cid]),
        ];
    }
}
