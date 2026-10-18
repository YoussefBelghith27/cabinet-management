<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;

final class SettingsController extends Controller
{
    public const DAYS = ['1' => 'Lundi', '2' => 'Mardi', '3' => 'Mercredi', '4' => 'Jeudi', '5' => 'Vendredi', '6' => 'Samedi', '7' => 'Dimanche'];

    public function index(): void
    {
        $user = $this->requireRole(['admin']);
        $clinic = Database::one('SELECT * FROM clinics WHERE id = ?', [$user['clinic_id']]);
        $this->view('settings/index', [
            'title' => 'Paramètres du cabinet',
            'clinic' => $clinic,
            'hours' => json_decode((string) $clinic['opening_hours'], true) ?: [],
            'days' => self::DAYS,
        ]);
    }

    public function update(): void
    {
        $user = $this->requireRole(['admin']);
        [$d, $errors] = Validator::make($_POST, [
            'name' => 'required|max:150',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'max:255',
            'currency' => 'required|in:TND,EUR,MAD,DZD,USD,CAD,CHF',
            'slot_minutes' => 'required|in:10,15,20,30,45,60',
            'chairs' => 'required|in:1,2,3,4,5,6,7,8',
        ]);

        $hours = [];
        foreach (array_keys(self::DAYS) as $day) {
            $hours[$day] = [];
            if (empty($_POST['open'][$day])) {
                continue;
            }
            foreach (['am', 'pm'] as $part) {
                $from = $_POST["{$part}_from"][$day] ?? '';
                $to = $_POST["{$part}_to"][$day] ?? '';
                if ($from === '' && $to === '') {
                    continue;
                }
                if (!is_string($from) || !is_string($to) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to) || $from >= $to) {
                    $errors['hours'] = 'Horaires invalides pour ' . self::DAYS[$day] . '.';
                    continue;
                }
                $hours[$day][] = [$from, $to];
            }
        }
        if ($errors) {
            $this->back('/settings', $errors, $_POST);
        }

        Database::run(
            'UPDATE clinics SET name = ?, email = ?, phone = ?, address = ?, currency = ?, slot_minutes = ?, chairs = ?, opening_hours = ?, portal_enabled = ? WHERE id = ?',
            [$d['name'], $d['email'], $d['phone'], $d['address'], $d['currency'], (int) $d['slot_minutes'], (int) $d['chairs'], json_encode($hours), isset($_POST['portal_enabled']) ? 1 : 0, $user['clinic_id']]
        );
        Audit::log('settings_updated', 'info');
        Session::flash('success', 'Paramètres enregistrés.');
        redirect('/settings');
    }
}
