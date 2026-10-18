<?php
declare(strict_types=1);

namespace App\Core;

/**
 * QR code generator (ISO/IEC 18004), byte mode, error correction level M, versions 1–15, SVG output.
 *
 * Generated on the server on purpose: the payload is a 2FA secret (otpauth://…), it must never be
 * sent to an online QR service. Verified by tests/qr_test.php, which decodes the output with jsQR.
 */
final class QrCode
{
    /** Level M: [EC codewords per block, [blocks, data codewords per block], …] for versions 1–15. */
    private const BLOCKS_M = [
        1 => [10, [1, 16]], 2 => [16, [1, 28]], 3 => [26, [1, 44]], 4 => [18, [2, 32]], 5 => [24, [2, 43]],
        6 => [16, [4, 27]], 7 => [18, [4, 31]], 8 => [22, [2, 38], [2, 39]], 9 => [22, [3, 36], [2, 37]],
        10 => [26, [4, 43], [1, 44]], 11 => [30, [1, 50], [4, 51]], 12 => [22, [6, 36], [2, 37]],
        13 => [22, [8, 37], [1, 38]], 14 => [24, [4, 40], [5, 41]], 15 => [24, [5, 41], [5, 42]],
    ];

    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38],
        8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58],
        13 => [6, 34, 62], 14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70],
    ];

    private int $size;
    /** @var bool[][] [row][col] dark modules */
    private array $modules = [];
    /** @var bool[][] [row][col] finder/timing/alignment/format/version areas */
    private array $isFunction = [];

    /** @return bool[][] module matrix [row][col], true = dark (no quiet zone) */
    public static function matrix(string $text): array
    {
        return (new self($text))->modules;
    }

    /** SVG markup (inline-safe: no script, no style attribute) with a 4-module quiet zone. */
    public static function svg(string $text, int $pixelSize = 220, string $label = 'QR code'): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $path = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $view = $n + 8;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $view . ' ' . $view . '" width="' . $pixelSize . '" height="' . $pixelSize
            . '" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" shape-rendering="crispEdges" class="qr">'
            . '<rect width="' . $view . '" height="' . $view . '" fill="#fff"/>'
            . '<path fill="#0f172a" d="' . $path . '"/></svg>';
    }

    private function __construct(string $text)
    {
        $version = self::chooseVersion(strlen($text));
        $this->size = 17 + 4 * $version;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;

        $this->drawFunctionPatterns($version);
        $codewords = self::addEccAndInterleave(self::encodeData($text, $version), $version);
        $this->drawCodewords($codewords);

        // Pick the mask with the lowest penalty (best scannability).
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
            $this->applyMask($mask); // XOR again = undo
        }
        $this->applyMask($bestMask);
        $this->drawFormatBits($bestMask);
    }

    private static function chooseVersion(int $bytes): int
    {
        foreach (self::BLOCKS_M as $version => $spec) {
            $capacityBits = self::dataCodewords($version) * 8;
            $countBits = $version <= 9 ? 8 : 16;
            if (4 + $countBits + $bytes * 8 <= $capacityBits) {
                return $version;
            }
        }
        throw new \LengthException('Texte trop long pour un QR code (version 15 maximum).');
    }

    private static function dataCodewords(int $version): int
    {
        $total = 0;
        foreach (array_slice(self::BLOCKS_M[$version], 1) as [$blocks, $len]) {
            $total += $blocks * $len;
        }
        return $total;
    }

    /** Byte-mode segment + terminator + padding, as data codewords. */
    private static function encodeData(string $text, int $version): array
    {
        $bits = '0100' . str_pad(decbin(strlen($text)), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $capacity = self::dataCodewords($version) * 8;
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $bytes = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($bytes) < $capacity / 8; $pad ^= 0xEC ^ 0x11) {
            $bytes[] = $pad;
        }
        return $bytes;
    }

    private static function addEccAndInterleave(array $data, int $version): array
    {
        [$ecLen] = self::BLOCKS_M[$version];
        $divisor = self::rsDivisor($ecLen);
        $blocks = [];
        $offset = 0;
        foreach (array_slice(self::BLOCKS_M[$version], 1) as [$count, $len]) {
            for ($b = 0; $b < $count; $b++) {
                $chunk = array_slice($data, $offset, $len);
                $offset += $len;
                $blocks[] = [$chunk, self::rsRemainder($chunk, $divisor)];
            }
        }
        $result = [];
        $maxLen = max(array_map(fn ($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($blocks as [$chunk]) {
                if ($i < count($chunk)) {
                    $result[] = $chunk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($blocks as [, $ec]) {
                $result[] = $ec[$i];
            }
        }
        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 0x02);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMultiply($coef, $factor);
            }
        }
        return $result;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(int $version): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $dist = max(abs($dx), abs($dy));
                        $this->set($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGNMENT[$version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $cx) {
            foreach ($pos as $j => $cy) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue; // overlaps a finder pattern
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // reserves the format areas; real bits drawn after masking
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0b00 << 3) | $mask; // error correction level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn (int $i) => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }
        $this->set(8, $this->size - 8, true); // dark module
    }

    private function drawCodewords(array $codewords): void
    {
        $i = 0;
        $total = count($codewords) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // skip the vertical timing pattern
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                    // Remainder bits stay light (0).
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Standard penalty rules N1–N4 (runs, 2×2 blocks, finder-like patterns, dark balance). */
    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $lines[] = $this->modules[$i];
            $lines[] = array_column($this->modules, $i);
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($k = 1; $k <= $n; $k++) {
                if ($k < $n && $line[$k] === $line[$k - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
                $run = 1;
            }
            $s = implode('', array_map(fn ($d) => $d ? '1' : '0', $line));
            $score += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dark += $this->modules[$y][$x] ? 1 : 0;
                if ($y < $n - 1 && $x < $n - 1) {
                    $c = $this->modules[$y][$x];
                    if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                        $score += 3;
                    }
                }
            }
        }
        $score += 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
        return $score;
    }
}
