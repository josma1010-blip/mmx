<?php
declare(strict_types=1);
/**
 * Generador de códigos QR en PHP puro (modo byte, corrección de errores M, versiones 1-40).
 * Basado en el algoritmo de referencia de Project Nayuki (MIT). Salida SVG.
 */
final class QrCode {
    private const ECC_M = [-1,10,16,26,18,24,16,18,22,22,26,30,22,22,24,24,28,28,26,26,26,26,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28];
    private const BLK_M = [-1,1,1,1,2,2,4,4,4,5,5,5,8,9,9,10,10,11,13,14,16,17,17,18,20,21,23,25,26,28,29,31,33,35,37,38,40,43,45,47,49];
    private int $size; private int $ver; private array $m = []; private array $f = [];

    public static function svg(string $text, int $scale = 8, int $border = 4): string {
        $q = new self($text);
        $s = $q->size; $parts = [];
        for ($y = 0; $y < $s; $y++) for ($x = 0; $x < $s; $x++) if ($q->m[$y][$x]) $parts[] = 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
        $d = $s + $border * 2; $px = $d * $scale;
        return '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 ' . $d . ' ' . $d . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#FFFFFF"/><path d="' . implode('', $parts) . '" fill="#000000"/></svg>';
    }

    public static function png(string $text, int $scale = 8, int $border = 4): string {
        $q = new self($text); $d = $q->size + $border * 2;
        $im = imagecreate($d * $scale, $d * $scale);
        $w = imagecolorallocate($im, 255, 255, 255); $b = imagecolorallocate($im, 0, 0, 0);
        for ($y = 0; $y < $q->size; $y++) for ($x = 0; $x < $q->size; $x++) if ($q->m[$y][$x])
            imagefilledrectangle($im, ($x + $border) * $scale, ($y + $border) * $scale, ($x + $border + 1) * $scale - 1, ($y + $border + 1) * $scale - 1, $b);
        ob_start(); imagepng($im); imagedestroy($im); return (string)ob_get_clean();
    }

    private function __construct(string $text) {
        $data = array_values(unpack('C*', $text) ?: []);
        $len = count($data);
        for ($v = 1; ; $v++) {
            if ($v > 40) throw new InvalidArgumentException('Texto demasiado largo para QR');
            $cap = self::numDataCw($v) * 8;
            $need = 4 + ($v <= 9 ? 8 : 16) + 8 * $len;
            if ($need <= $cap) break;
        }
        $this->ver = $v; $this->size = $v * 4 + 17;
        $bits = [];
        $push = function (int $val, int $n) use (&$bits) { for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
        $push(4, 4); $push($len, $v <= 9 ? 8 : 16);
        foreach ($data as $b) $push($b, 8);
        $cap = self::numDataCw($v) * 8;
        $push(0, min(4, $cap - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) $push($pad, 8);
        $cw = [];
        for ($i = 0; $i < count($bits); $i += 8) { $b = 0; for ($j = 0; $j < 8; $j++) $b = ($b << 1) | $bits[$i + $j]; $cw[] = $b; }

        $s = $this->size;
        $this->m = array_fill(0, $s, array_fill(0, $s, false));
        $this->f = array_fill(0, $s, array_fill(0, $s, false));
        $this->drawFunction();
        $all = $this->addEcc($cw);
        $this->drawCodewords($all);
        $best = 0; $bestP = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask); $this->drawFormat($mask);
            $p = $this->penalty();
            if ($p < $bestP) { $bestP = $p; $best = $mask; }
            $this->applyMask($mask);
        }
        $this->applyMask($best); $this->drawFormat($best);
    }

    private static function rawModules(int $v): int {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) { $n = intdiv($v, 7) + 2; $r -= (25 * $n - 10) * $n - 55; if ($v >= 7) $r -= 36; }
        return $r;
    }
    private static function numDataCw(int $v): int { return intdiv(self::rawModules($v), 8) - self::ECC_M[$v] * self::BLK_M[$v]; }

    private function setF(int $x, int $y, bool $d): void { $this->m[$y][$x] = $d; $this->f[$y][$x] = true; }

    private function drawFunction(): void {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) { $this->setF(6, $i, $i % 2 == 0); $this->setF($i, 6, $i % 2 == 0); }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy])
            for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
                $d = max(abs($dx), abs($dy)); $x = $cx + $dx; $y = $cy + $dy;
                if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) $this->setF($x, $y, $d != 2 && $d != 4);
            }
        $pos = $this->alignPos(); $n = count($pos);
        for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
            if (($i == 0 && $j == 0) || ($i == 0 && $j == $n - 1) || ($i == $n - 1 && $j == 0)) continue;
            for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $this->setF($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) != 1);
        }
        $this->drawFormat(0);
        if ($this->ver >= 7) {
            $rem = $this->ver;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = ($this->ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) { $b = (($bits >> $i) & 1) == 1; $a = $s - 11 + $i % 3; $c = intdiv($i, 3); $this->setF($a, $c, $b); $this->setF($c, $a, $b); }
        }
    }
    private function alignPos(): array {
        $v = $this->ver; if ($v == 1) return [];
        $n = intdiv($v, 7) + 2;
        $step = ($v == 32) ? 26 : intdiv(($v * 4 + $n * 2 + 1), ($n * 2 - 2)) * 2;
        $r = [6];
        for ($i = 0, $p = $this->size - 7; $i < $n - 1; $i++, $p -= $step) array_splice($r, 1, 0, [$p]);
        return $r;
    }
    private function drawFormat(int $mask): void {
        $data = (0 << 3) | $mask; // ECC M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $g = fn($i) => (($bits >> $i) & 1) == 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) $this->setF(8, $i, $g($i));
        $this->setF(8, 7, $g(6)); $this->setF(8, 8, $g(7)); $this->setF(7, 8, $g(8));
        for ($i = 9; $i < 15; $i++) $this->setF(14 - $i, 8, $g($i));
        for ($i = 0; $i < 8; $i++) $this->setF($s - 1 - $i, 8, $g($i));
        for ($i = 8; $i < 15; $i++) $this->setF(8, $s - 15 + $i, $g($i));
        $this->setF(8, $s - 8, true);
    }
    private static function gmul(int $x, int $y): int {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) { $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0x1FF; $z ^= (($y >> $i) & 1) * $x; }
        return $z & 0xFF;
    }
    private function addEcc(array $data): array {
        $v = $this->ver; $nb = self::BLK_M[$v]; $el = self::ECC_M[$v];
        $raw = intdiv(self::rawModules($v), 8);
        $nShort = $nb - $raw % $nb; $shortLen = intdiv($raw, $nb);
        $div = array_fill(0, $el, 0); $div[$el - 1] = 1; $root = 1;
        for ($i = 0; $i < $el; $i++) {
            for ($j = 0; $j < $el; $j++) { $div[$j] = self::gmul($div[$j], $root); if ($j + 1 < $el) $div[$j] ^= $div[$j + 1]; }
            $root = self::gmul($root, 0x02);
        }
        $blocks = []; $k = 0;
        for ($i = 0; $i < $nb; $i++) {
            $dl = $shortLen - $el + ($i < $nShort ? 0 : 1);
            $dat = array_slice($data, $k, $dl); $k += $dl;
            $res = array_fill(0, $el, 0);
            foreach ($dat as $b) {
                $factor = $b ^ array_shift($res); $res[] = 0;
                for ($j = 0; $j < $el; $j++) $res[$j] ^= self::gmul($div[$j], $factor);
            }
            if ($i < $nShort) $dat[] = 0;
            $blocks[] = array_merge($dat, $res);
        }
        $out = [];
        $bl = count($blocks[0]);
        for ($i = 0; $i < $bl; $i++) for ($j = 0; $j < $nb; $j++) {
            if ($i != $shortLen - $el || $j >= $nShort) $out[] = $blocks[$j][$i];
        }
        return $out;
    }
    private function drawCodewords(array $data): void {
        $s = $this->size; $i = 0; $total = count($data) * 8;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right == 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) for ($j = 0; $j < 2; $j++) {
                $x = $right - $j; $up = (($right + 1) & 2) == 0; $y = $up ? $s - 1 - $vert : $vert;
                if (!$this->f[$y][$x] && $i < $total) { $this->m[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) == 1; $i++; }
            }
        }
    }
    private function applyMask(int $mask): void {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) for ($x = 0; $x < $s; $x++) {
            if ($this->f[$y][$x]) continue;
            switch ($mask) {
                case 0: $inv = ($x + $y) % 2 == 0; break;
                case 1: $inv = $y % 2 == 0; break;
                case 2: $inv = $x % 3 == 0; break;
                case 3: $inv = ($x + $y) % 3 == 0; break;
                case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 == 0; break;
                case 5: $inv = $x * $y % 2 + $x * $y % 3 == 0; break;
                case 6: $inv = ($x * $y % 2 + $x * $y % 3) % 2 == 0; break;
                default: $inv = (($x + $y) % 2 + $x * $y % 3) % 2 == 0;
            }
            if ($inv) $this->m[$y][$x] = !$this->m[$y][$x];
        }
    }
    /** Penalización simplificada (reglas 1, 2 y 4). Cualquier máscara es válida; esto solo mejora legibilidad. */
    private function penalty(): int {
        $s = $this->size; $p = 0; $dark = 0;
        for ($y = 0; $y < $s; $y++) {
            $runR = 1; $runC = 1;
            for ($x = 0; $x < $s; $x++) {
                if ($this->m[$y][$x]) $dark++;
                if ($x > 0) {
                    if ($this->m[$y][$x] === $this->m[$y][$x - 1]) { $runR++; if ($runR == 5) $p += 3; elseif ($runR > 5) $p++; } else $runR = 1;
                    if ($this->m[$x][$y] === $this->m[$x - 1][$y]) { $runC++; if ($runC == 5) $p += 3; elseif ($runC > 5) $p++; } else $runC = 1;
                }
                if ($x < $s - 1 && $y < $s - 1) { $c = $this->m[$y][$x]; if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) $p += 3; }
            }
        }
        $total = $s * $s; $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $p + max(0, $k) * 10;
    }
}
