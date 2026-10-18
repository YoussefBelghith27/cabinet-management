<?php
declare(strict_types=1);

namespace App\Core;

final class Clinics
{
    /** Creates a clinic (tenant) with its first admin account and a default treatment catalog. */
    public static function create(string $clinicName, string $adminName, string $email, string $password): array
    {
        return Database::transaction(function () use ($clinicName, $adminName, $email, $password) {
            $base = slugify($clinicName);
            $slug = $base;
            for ($i = 2; Database::value('SELECT 1 FROM clinics WHERE slug = ?', [$slug]); $i++) {
                $slug = $base . '-' . $i;
            }
            $clinicId = Database::insert('clinics', [
                'name' => $clinicName,
                'slug' => $slug,
                'email' => $email,
                'opening_hours' => json_encode(Availability::defaultHours()),
            ]);
            $userId = Database::insert('users', [
                'clinic_id' => $clinicId,
                'role' => 'admin',
                'name' => $adminName,
                'email' => $email,
                'password_hash' => password_hash($password, password_algo()),
            ]);
            $catalog = [
                ['Consultation', 40, 30], ['Détartrage', 80, 30], ['Soin de carie (composite)', 120, 45],
                ['Extraction simple', 100, 30], ['Radiographie panoramique', 60, 15], ['Traitement de canal', 250, 60],
                ['Blanchiment', 400, 60], ['Couronne céramique', 900, 60],
            ];
            foreach ($catalog as [$name, $price, $duration]) {
                Database::insert('treatments', ['clinic_id' => $clinicId, 'name' => $name, 'price' => $price, 'duration_minutes' => $duration]);
            }
            return ['clinic_id' => $clinicId, 'user_id' => $userId, 'slug' => $slug];
        });
    }

    public static function staffEmailTaken(string $email): bool
    {
        return (bool) Database::value("SELECT 1 FROM users WHERE email = ? AND role <> 'patient'", [$email]);
    }
}
