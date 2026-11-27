<?php

declare(strict_types=1);

namespace Lou\Support;

use Lou\LouException;

/**
 * A small QR code encoder (ISO/IEC 18004): byte mode, error correction level M, versions 1–10 (up to 213 bytes),
 * which is plenty for an otpauth:// link. Output is an SVG, so no image library or JavaScript is needed.
 * Follows the structure of Project Nayuki's reference implementation.
 */
final class QrCode
{
    /** version => [EC codewords per block, [[number of blocks, data codewords per block], …]] for level M */
    private const BLOCKS = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    /** @var list<list<bool>> [row][column], true = dark */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $isFunction = [];
    private int $size;

    private function __construct(private readonly int $version)
    {
        $this->size = 17 + 4 * $version;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    public static function encode(string $text): self
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $n = count($bytes);
        foreach (self::BLOCKS as $version => [$ec, $groups]) {
            $capacity = array_sum(array_map(fn (array $g): int => $g[0] * $g[1], $groups));
            $countBits = $version < 10 ? 8 : 16;
            if (4 + $countBits + 8 * $n <= 8 * $capacity) {
                $qr = new self($version);
                $qr->build($bytes, $countBits, $capacity, $ec, $groups);
                return $qr;
            }
        }
        throw new LouException('The text is too long for a QR code');
    }

    /** @return list<list<bool>> */
    public function modules(): array
    {
        return $this->modules;
    }

    /** An SVG drawing, black on white with the standard 4-module quiet zone, scaled to the given width in pixels. */
    public function svg(int $pixels = 200, string $label = ''): string
    {
        $q = 4;
        $dim = $this->size + 2 * $q;
        $path = '';
        foreach ($this->modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + $q) . ',' . ($y + $q) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" class="qr" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $pixels . '" height="' . $pixels
            . '" role="img" shape-rendering="crispEdges"' . ($label !== '' ? ' aria-label="' . Html::escape($label) . '"' : '') . '>'
            . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    /** @param list<int> $bytes @param list<array{0: int, 1: int}> $groups */
    private function build(array $bytes, int $countBits, int $capacity, int $ec, array $groups): void
    {
        // data bits: byte mode indicator, length, the bytes, terminator, padding
        $bits = [];
        $push = static function (int $value, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $push(0b0100, 4);
        $push(count($bytes), $countBits);
        foreach ($bytes as $b) {
            $push($b, 8);
        }
        $push(0, min(4, 8 * $capacity - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        $data = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $data[] = (int) bindec(implode('', $chunk));
        }
        for ($pad = 0xEC; count($data) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }

        // split into blocks, add Reed–Solomon error correction, interleave
        $blocks = [];
        $offset = 0;
        foreach ($groups as [$count, $len]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $offset, $len);
                $offset += $len;
                $blocks[] = [$chunk, self::reedSolomon($chunk, $ec)];
            }
        }
        $codewords = [];
        $maxData = max(array_map(fn (array $b): int => count($b[0]), $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as [$d]) {
                if (isset($d[$i])) {
                    $codewords[] = $d[$i];
                }
            }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($blocks as [, $e]) {
                $codewords[] = $e[$i];
            }
        }

        $this->drawFunctionPatterns();
        $this->drawCodewords($codewords);
        // pick the mask with the lowest penalty
        $best = 0;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                [$best, $bestScore] = [$mask, $score];
            }
            $this->applyMask($mask);   // XOR again undoes it
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) {
                        $d = max(abs($dx), abs($dy));
                        $this->set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGN[$this->version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $px) {
            foreach ($pos as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0);   // reserves the areas; drawn for real after masking
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = $this->version << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = 0b00 << 3 | $mask;   // 00 = error correction level M
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        $s = $this->size;
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
            $this->set($s - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $s - 15 + $i, $bit($i));
        }
        $this->set(8, $s - 8, true);   // the dark module
    }

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $s = $this->size;
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $s - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                    // remainder bits stay light
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
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($flip) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The standard's penalty rules 1 (runs), 2 (2×2 blocks), 3 (finder-like patterns) and 4 (dark/light balance). */
    private function penalty(): int
    {
        $s = $this->size;
        $m = $this->modules;
        $score = 0;
        $dark = 0;
        $lines = [];
        for ($i = 0; $i < $s; $i++) {
            $lines[] = $m[$i];
            $lines[] = array_column($m, $i);
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($k = 1; $k <= $s; $k++) {
                if ($k < $s && $line[$k] === $line[$k - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $score += $run - 2;
                }
                $run = 1;
            }
            $str = implode('', array_map('intval', $line));
            $score += 40 * (substr_count($str, '10111010000') + substr_count($str, '00001011101'));
        }
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                $dark += (int) $m[$y][$x];
                if ($x < $s - 1 && $y < $s - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $total = $s * $s;
        $score += 10 * intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 10;
        return $score;
    }

    /** @param list<int> $data @return list<int> the EC codewords */
    private static function reedSolomon(array $data, int $degree): array
    {
        // GF(2^8) with the polynomial x^8 + x^4 + x^3 + x^2 + 1
        $mul = static function (int $a, int $b): int {
            $r = 0;
            for ($i = 7; $i >= 0; $i--) {
                $r = ($r << 1) ^ (($r >> 7) * 0x11D);
                $r ^= (($b >> $i) & 1) * $a;
            }
            return $r & 0xFF;
        };
        // generator polynomial (x - α^0)(x - α^1)…, coefficients highest first, leading 1 dropped
        $gen = array_fill(0, $degree, 0);
        $gen[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $gen[$j] = $mul($gen[$j], $root) ^ ($gen[$j + 1] ?? 0);
            }
            $root = $mul($root, 0x02);
        }
        $rem = array_fill(0, $degree, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($rem);
            $rem[] = 0;
            foreach ($gen as $j => $g) {
                $rem[$j] ^= $mul($g, $factor);
            }
        }
        return $rem;
    }
}
