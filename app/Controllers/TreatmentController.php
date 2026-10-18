<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;

final class TreatmentController extends Controller
{
    private const RULES = ['name' => 'required|max:120', 'price' => 'required|decimal', 'duration_minutes' => 'required|int'];

    public function index(): void
    {
        $user = $this->requireRole(['admin']);
        $this->view('treatments/index', [
            'title' => 'Actes & tarifs',
            'treatments' => Database::all('SELECT * FROM treatments WHERE clinic_id = ? ORDER BY is_active DESC, name', [$user['clinic_id']]),
            'currency' => $user['currency'],
        ]);
    }

    public function store(): void
    {
        $user = $this->requireRole(['admin']);
        $data = $this->validated('/treatments');
        Database::insert('treatments', $data + ['clinic_id' => $user['clinic_id']]);
        Session::flash('success', 'Acte ajouté.');
        redirect('/treatments');
    }

    public function update(int $id): void
    {
        $user = $this->requireRole(['admin']);
        $this->findOwned('treatments', $id, (int) $user['clinic_id']);
        $data = $this->validated('/treatments');
        $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        Database::updateOwned('treatments', $id, (int) $user['clinic_id'], $data);
        Session::flash('success', 'Acte mis à jour.');
        redirect('/treatments');
    }

    private function validated(string $back): array
    {
        [$data, $errors] = Validator::make($_POST, self::RULES);
        if (!$errors && ((int) $data['duration_minutes'] < 5 || (int) $data['duration_minutes'] > 480)) {
            $errors['duration_minutes'] = 'Durée entre 5 et 480 minutes.';
        }
        if ($errors) {
            $this->back($back, $errors, $_POST);
        }
        return $data;
    }
}
