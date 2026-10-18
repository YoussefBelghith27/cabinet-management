<?php
declare(strict_types=1);

/**
 * QR code generator check: php tests/qr_test.php
 * Encodes many payloads (versions 1 to 15, real otpauth URIs) and decodes them with jsQR, as a phone would.
 * Requires the test-only decoder: npm install --prefix tests/qr-decoder --ignore-scripts
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\QrCode;
use App\Core\Totp;

$payloads = ['A', 'Bonjour', '0123456789'];
foreach ([20, 40, 60, 80, 100, 120, 150, 180, 210, 250, 270, 290, 330, 360, 400] as $len) {
    $payloads[] = mb_strcut(str_repeat('Cabinet dentaire GestionCabinet – contrôle ', 20), 0, $len, 'UTF-8'); // accents = multi-byte UTF-8
}
foreach (['admin@demo.test', 'dr.amira.ben-salah@cabinet-dentaire-tunis.example.com'] as $email) {
    $payloads[] = Totp::uri(Totp::generateSecret(), $email, 'GestionCabinet');
}

$cases = array_map(fn ($text) => ['text' => $text, 'matrix' => array_map(fn ($row) => array_map('intval', $row), QrCode::matrix($text))], $payloads);
$file = BASE_PATH . '/storage/tmp/qr-cases-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($file, json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$out = shell_exec('node ' . escapeshellarg(BASE_PATH . '/tests/qr-decode.mjs') . ' ' . escapeshellarg($file) . ' 2>&1');
unlink($file);
$results = json_decode((string) $out, true);
if (!is_array($results)) {
    exit("✘ Décodeur indisponible : " . trim((string) $out) . "\n  → npm install --prefix tests/qr-decoder --ignore-scripts\n");
}

$failed = 0;
$versions = [];
foreach ($results as $i => $r) {
    $versions[$r['version']] = true;
    if (!$r['ok']) {
        $failed++;
        printf("  ✘ version %d (%d octets) : décodé « %s »\n", $r['version'], strlen($payloads[$i]), substr((string) $r['got'], 0, 60));
    }
}
ksort($versions);
printf("%s %d/%d QR codes décodés à l'identique (versions testées : %s)\n", $failed ? '✘' : '✔', count($results) - $failed, count($results), implode(', ', array_keys($versions)));

$svg = QrCode::svg(Totp::uri(Totp::generateSecret(), 'test@exemple.test', 'GestionCabinet'));
$safe = !preg_match('/<script|style=|on\w+=|href=/i', $svg);
printf("%s SVG sans script ni style inline (compatible avec la CSP stricte)\n", $safe ? '✔' : '✘');
exit($failed || !$safe ? 1 : 0);
