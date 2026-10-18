<?php
declare(strict_types=1);

/**
 * Déchiffre une sauvegarde produite par scripts/backup.php.
 *
 *   php scripts/decrypt_backup.php storage/backups/<fichier>.json.gz.enc
 *
 * Écrit <fichier>.json.gz à côté (JSON compressé : tables, colonnes et lignes au format COPY).
 * Nécessite le même APP_KEY que lors de la sauvegarde. Le fichier déchiffré contient des données
 * de santé en clair : supprimez-le dès qu'il n'est plus utile.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Crypto;

$in = $argv[1] ?? '';
if (!str_ends_with($in, '.enc') || !is_file($in)) {
    fwrite(STDERR, "Usage : php scripts/decrypt_backup.php <fichier.json.gz.enc>\n");
    exit(1);
}

$gz = Crypto::decrypt((string) file_get_contents($in));
$dump = json_decode((string) gzdecode($gz), true);
if (($dump['format'] ?? '') !== 'gestioncabinet-copy-v1') {
    fwrite(STDERR, "✘ Contenu inattendu : ce n'est pas une sauvegarde GestionCabinet.\n");
    exit(1);
}

$out = substr($in, 0, -4);
file_put_contents($out, $gz);
$rows = array_sum(array_map(fn ($t) => count($t['rows']), $dump['tables']));
echo "✔ Déchiffré : $out (" . count($dump['tables']) . " tables, $rows lignes, sauvegarde du {$dump['created_at']})\n";
