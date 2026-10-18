<?php
declare(strict_types=1);

/**
 * Migration unique des données MySQL (XAMPP) vers Supabase : php database/migrate_from_mysql.php
 *
 * - lit l'ancienne base MySQL (MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASS dans .env)
 * - copie toutes les tables dans le schéma Supabase en conservant les identifiants
 *   (les secrets 2FA restent lisibles : ils sont chiffrés avec la même APP_KEY)
 * - recale les compteurs d'identifiants, puis compare le nombre de lignes table par table
 * Tout se fait dans une seule transaction : en cas d'erreur, Supabase reste vide.
 * Refuse de s'exécuter si le schéma Supabase contient déjà des données.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

const TABLES = ['clinics', 'patients', 'users', 'treatments', 'appointments', 'procedures',
    'invoices', 'invoice_items', 'payments', 'audit_log'];
const BOOLEAN_COLUMNS = ['is_active', 'must_change_password', 'mfa_enabled', 'portal_enabled'];

$mysql = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('MYSQL_HOST', '127.0.0.1'), env('MYSQL_PORT', '3306'), env('MYSQL_DATABASE', 'gestion_cabinet')),
    (string) env('MYSQL_USER', 'root'),
    (string) env('MYSQL_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pg = Database::connect((string) env('DB_ADMIN_USER', 'postgres'), (string) env('DB_ADMIN_PASS', ''));

foreach (TABLES as $table) {
    if ($pg->query('SELECT 1 FROM ' . Database::ident($table) . ' LIMIT 1')->fetchColumn()) {
        exit("✘ La table « $table » contient déjà des données sur Supabase : migration annulée (rien n'a été modifié).\n");
    }
}

$pg->beginTransaction();
try {
    $copied = [];
    foreach (TABLES as $table) {
        $rows = $mysql->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetchAll();
        foreach ($rows as $row) {
            foreach (BOOLEAN_COLUMNS as $col) {
                if (array_key_exists($col, $row)) {
                    $row[$col] = $row[$col] ? 't' : 'f';
                }
            }
            $cols = implode(', ', array_map([Database::class, 'ident'], array_keys($row)));
            $marks = implode(', ', array_fill(0, count($row), '?'));
            // MySQL DATETIMEs are clinic-local times: the session time zone (APP_TIMEZONE) reads them as such.
            $pg->prepare('INSERT INTO ' . Database::ident($table) . " ($cols) VALUES ($marks)")->execute(array_values($row));
        }
        // Next generated id continues after the migrated ones.
        $pg->exec(sprintf(
            "SELECT setval(pg_get_serial_sequence('%s', 'id'), COALESCE((SELECT max(id) FROM %s), 1), (SELECT max(id) FROM %s) IS NOT NULL)",
            Database::schema() . '.' . $table,
            Database::ident($table),
            Database::ident($table)
        ));
        $copied[$table] = count($rows);
    }

    foreach ($copied as $table => $expected) {
        $actual = (int) $pg->query('SELECT count(*) FROM ' . Database::ident($table))->fetchColumn();
        if ($actual !== $expected) {
            throw new RuntimeException("$table : $expected lignes lues, $actual écrites");
        }
    }
    $pg->commit();
} catch (Throwable $e) {
    $pg->rollBack();
    exit('✘ Migration annulée, Supabase inchangé : ' . $e->getMessage() . "\n");
}

foreach ($copied as $table => $n) {
    echo str_pad($table, 16) . " $n\n";
}
echo "✔ Migration terminée : " . array_sum($copied) . " lignes copiées et vérifiées.\n";
echo "  Gardez l'ancienne base MySQL quelques jours, le temps de vérifier l'application.\n";
