<?php
declare(strict_types=1);

/**
 * Installation sur Supabase (PostgreSQL) : php database/install.php [--seed]
 *  1. crée .env depuis .env.example (APP_KEY + mot de passe du rôle applicatif aléatoires) s'il n'existe pas
 *  2. avec le compte administrateur Supabase (DB_ADMIN_USER / DB_ADMIN_PASS) :
 *     - crée le schéma privé de l'application (DB_SCHEMA, non exposé par l'API REST de Supabase)
 *     - crée le rôle de connexion de l'application (DB_USER) avec droits limités (SELECT/INSERT/UPDATE/DELETE)
 *     - retire tout accès aux rôles publics de Supabase (anon, authenticated)
 *     - crée les tables, index, Row Level Security
 *  3. --seed : ajoute un cabinet de démonstration
 * Le script est idempotent : il peut être relancé sans perte de données.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
if (!extension_loaded('pdo_pgsql')) {
    exit("L'extension PHP pdo_pgsql n'est pas activée (C:\\xampp\\php\\php.ini : décommentez extension=pdo_pgsql).\n");
}

$base = dirname(__DIR__);
$envFile = $base . '/.env';

if (!is_file($envFile)) {
    $env = file_get_contents($base . '/.env.example');
    $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=base64:' . base64_encode(random_bytes(32)), $env);
    $env = preg_replace('/^DB_PASS=.*$/m', 'DB_PASS=' . bin2hex(random_bytes(18)), $env);
    file_put_contents($envFile, $env);
    echo "✔ .env créé avec une APP_KEY et un mot de passe aléatoire pour le rôle de l'application.\n";
    echo "  Complétez maintenant DB_HOST, DB_PROJECT_REF et DB_ADMIN_PASS dans .env (voir README), puis relancez.\n";
    exit(0);
}

require $base . '/app/bootstrap.php';

use App\Core\Database;

$appRole = (string) env('DB_USER');
$appPass = (string) env('DB_PASS');
$adminUser = (string) env('DB_ADMIN_USER', 'postgres');
$adminPass = (string) env('DB_ADMIN_PASS', '');
$schema = Database::schema();

if (!preg_match('/^[a-z_][a-z0-9_]{0,40}$/', $appRole) || in_array($appRole, ['postgres', 'anon', 'authenticated', 'service_role'], true)) {
    exit("DB_USER doit être un rôle dédié à l'application (ex. cabinet_app), jamais postgres.\n");
}
if (strlen($appPass) < 24) {
    exit("DB_PASS est vide ou trop court : mettez un mot de passe aléatoire long (ou supprimez .env pour en générer un).\n");
}
if ($adminPass === '' || str_contains((string) env('DB_HOST'), 'VOTRE')) {
    exit("Complétez DB_HOST, DB_PROJECT_REF et DB_ADMIN_PASS dans .env (voir README, section Supabase).\n");
}

$admin = Database::connect($adminUser, $adminPass, false);
$roleExists = static fn (string $role): bool => (bool) $admin->query('SELECT 1 FROM pg_roles WHERE rolname = ' . $admin->quote($role))->fetchColumn();
$s = Database::ident($schema);
$r = Database::ident($appRole);

$admin->beginTransaction();

// Accent-insensitive patient search (Supabase keeps extensions in the "extensions" schema).
$admin->exec('CREATE SCHEMA IF NOT EXISTS extensions');
$admin->exec('CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA extensions');

// Application login role: least privilege, no superuser/createrole/bypassrls.
if ($roleExists($appRole)) {
    // Supabase's postgres role may not touch SUPERUSER/BYPASSRLS on ALTER: check them instead.
    $flags = $admin->query('SELECT rolsuper OR rolcreaterole OR rolcreatedb OR rolbypassrls FROM pg_roles WHERE rolname = ' . $admin->quote($appRole))->fetchColumn();
    if ($flags) {
        exit("✘ Le rôle « $appRole » existe avec des privilèges d'administration : supprimez-le ou choisissez un autre DB_USER.\n");
    }
    $admin->exec("ALTER ROLE $r WITH LOGIN PASSWORD " . $admin->quote($appPass));
} else {
    $admin->exec("CREATE ROLE $r WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD " . $admin->quote($appPass));
}
$admin->exec("ALTER ROLE $r SET search_path = $s");
$admin->exec("ALTER ROLE $r SET statement_timeout = '15s'");
// Also enforced per connection; as a role default it holds even through a transaction-mode pooler.
$admin->exec("ALTER ROLE $r SET timezone = " . $admin->quote((string) env('APP_TIMEZONE', 'Africa/Tunis')));

// Private schema, invisible to Supabase's public API roles.
$admin->exec("CREATE SCHEMA IF NOT EXISTS $s");
$admin->exec("REVOKE ALL ON SCHEMA $s FROM PUBLIC");
$admin->exec("SET search_path TO $s");
$admin->exec(str_replace('{{APP_ROLE}}', $r, (string) file_get_contents(__DIR__ . '/schema.sql')));

$apiRoles = array_values(array_filter(['anon', 'authenticated'], $roleExists));
if ($apiRoles) {
    $list = implode(', ', array_map([Database::class, 'ident'], $apiRoles));
    $admin->exec("REVOKE ALL ON SCHEMA $s FROM $list");
    $admin->exec("REVOKE ALL ON ALL TABLES IN SCHEMA $s FROM $list");
    $admin->exec("REVOKE ALL ON ALL SEQUENCES IN SCHEMA $s FROM $list");
}

$admin->exec("GRANT USAGE ON SCHEMA $s TO $r");
$admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA $s TO $r");
$admin->exec("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA $s TO $r");
$admin->exec("GRANT USAGE ON SCHEMA extensions TO $r");
$admin->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA $s GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $r");
$admin->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA $s GRANT USAGE, SELECT ON SEQUENCES TO $r");

$admin->commit();
echo "✔ Schéma privé « $schema », rôle « $appRole » (SELECT/INSERT/UPDATE/DELETE uniquement) et Row Level Security en place.\n";

if (in_array('--seed', $argv, true)) {
    require __DIR__ . '/seed.php';
}

echo "\nTerminé. Démarrez le serveur : php -S localhost:8080 -t public public/index.php\n";
