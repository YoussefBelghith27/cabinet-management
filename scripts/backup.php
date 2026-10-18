<?php
declare(strict_types=1);

/**
 * Sauvegarde de la base Supabase (PostgreSQL) + rotation + test de restauration.
 *
 *   php scripts/backup.php             sauvegarde, puis la restaure dans un schéma temporaire
 *                                      et vérifie que chaque table contient le même nombre de lignes
 *   php scripts/backup.php --no-verify
 *
 * Fonctionne sans pg_dump (absent de XAMPP) : chaque table est exportée avec COPY, dans un seul
 * instantané cohérent (transaction REPEATABLE READ), vers storage/backups/*.json.gz.
 * Le plan gratuit de Supabase n'inclut pas de sauvegardes : ce script est votre filet de sécurité.
 * À planifier chaque nuit (Planificateur de tâches Windows ou cron) — voir README.md.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Audit;
use App\Core\Database;

// Parents before children, so a restore satisfies foreign keys.
const TABLES = ['clinics', 'patients', 'users', 'treatments', 'appointments', 'procedures',
    'invoices', 'invoice_items', 'payments', 'audit_log'];
// PDO embeds this in E'...': the doubled backslash becomes COPY's standard \N null marker.
const NULL_MARK = '\\\\N';

$schema = Database::schema();
$backupDir = BASE_PATH . '/storage/backups';
$keepDays = (int) env('BACKUP_KEEP_DAYS', 14);
$verify = !in_array('--no-verify', $argv, true);

function fail(string $event, string $message): never
{
    fwrite(STDERR, "✘ $message\n");
    Audit::log($event, 'critical', ['message' => $message], null, null);
    exit(1);
}

function columns(PDO $pdo, string $schema, string $table): string
{
    $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position');
    $stmt->execute([$schema, $table]);
    return implode(',', array_map(fn ($c) => Database::ident($c), $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

try {
    // Admin account: the app role cannot create the temporary restore schema.
    $pdo = Database::connect((string) env('DB_ADMIN_USER', 'postgres'), (string) env('DB_ADMIN_PASS', ''), false);
} catch (Throwable $e) {
    fail('backup_failed', 'Connexion impossible : ' . $e->getMessage());
}

// 1. Export (one consistent snapshot)
$dump = ['format' => 'gestioncabinet-copy-v1', 'schema' => $schema, 'created_at' => date('c'), 'tables' => []];
try {
    $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    foreach (TABLES as $table) {
        $fields = columns($pdo, $schema, $table);
        $rows = $pdo->pgsqlCopyToArray(Database::ident($schema) . '.' . Database::ident($table), "\t", NULL_MARK,$fields);
        $dump['tables'][$table] = ['columns' => $fields, 'rows' => $rows === false ? [] : $rows];
    }
    $pdo->exec('COMMIT');
} catch (Throwable $e) {
    fail('backup_failed', 'Export : ' . $e->getMessage());
}

$file = "$backupDir/" . env('DB_NAME', 'postgres') . "-$schema-" . date('Ymd-His') . '.json.gz';
if (file_put_contents($file, gzencode(json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 9)) === false) {
    fail('backup_failed', "Écriture impossible : $file");
}
$counts = array_map(fn ($t) => count($t['rows']), $dump['tables']);
echo '✔ Sauvegarde : ' . basename($file) . ' (' . round(filesize($file) / 1024, 1) . ' Ko, ' . array_sum($counts) . " lignes)\n";

// 2. Verify: restore the file just written into a throwaway schema and compare row counts
if ($verify) {
    $restored = json_decode((string) gzdecode((string) file_get_contents($file)), true);
    $test = Database::ident($schema . '_restore_test');
    try {
        $pdo->exec("DROP SCHEMA IF EXISTS $test CASCADE");
        $pdo->exec("CREATE SCHEMA $test");
        $pdo->exec("SET search_path TO $test");
        $pdo->exec(str_replace('{{APP_ROLE}}', Database::ident((string) env('DB_USER')), (string) file_get_contents(BASE_PATH . '/database/schema.sql')));
        $mismatch = [];
        foreach (TABLES as $table) {
            $data = $restored['tables'][$table];
            if ($data['rows']) {
                $pdo->pgsqlCopyFromArray("$test." . Database::ident($table), $data['rows'], "\t", NULL_MARK,$data['columns']);
            }
            $n = (int) $pdo->query("SELECT count(*) FROM $test." . Database::ident($table))->fetchColumn();
            if ($n !== $counts[$table]) {
                $mismatch[] = "$table (sauvegardé {$counts[$table]}, restauré $n)";
            }
        }
    } catch (Throwable $e) {
        $mismatch = ['restauration impossible : ' . $e->getMessage()];
    } finally {
        $pdo->exec("DROP SCHEMA IF EXISTS $test CASCADE");
    }
    if ($mismatch) {
        fail('backup_verify_failed', 'Contenu restauré incohérent : ' . implode(', ', $mismatch));
    }
    echo '✔ Test de restauration réussi (' . count(TABLES) . " tables, contraintes et clés étrangères vérifiées).\n";
}

// 3. Rotation
$removed = 0;
foreach (glob("$backupDir/*.json.gz") as $old) {
    if (filemtime($old) < time() - $keepDays * 86400) {
        unlink($old);
        $removed++;
    }
}
echo "✔ Rotation : $removed ancienne(s) sauvegarde(s) supprimée(s) (conservation $keepDays jours).\n";

Audit::log('backup_completed', 'info', ['file' => basename($file), 'rows' => array_sum($counts), 'verified' => $verify]);
echo "\nPensez à copier storage/backups/ hors de ce PC (disque externe, cloud chiffré).\n";
