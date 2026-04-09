<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class PHashService
{
    /**
     * Compute a 64-bit perceptual hash as 16 hex chars using difference hash (dHash).
     *
     * @throws RuntimeException
     */
    public function computeHash(string $absoluteFilePath): string
    {
        if (! is_readable($absoluteFilePath)) {
            throw new RuntimeException('Image not readable for hashing');
        }

        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD extension required for duplicate scan');
        }

        $contents = file_get_contents($absoluteFilePath);
        if ($contents === false) {
            throw new RuntimeException('Could not read image file');
        }

        $img = imagecreatefromstring($contents);
        if ($img === false) {
            throw new RuntimeException('Could not load image');
        }

        $w = 9;
        $h = 8;
        $small = imagecreatetruecolor($w, $h);
        if ($small === false) {
            imagedestroy($img);
            throw new RuntimeException('Could not allocate image');
        }

        imagecopyresampled($small, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        imagedestroy($img);

        $gray = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray[$y * $w + $x] = (int) (($r + $g + $b) / 3);
            }
        }

        imagedestroy($small);

        $bits = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w - 1; $x++) {
                $left = $gray[$y * $w + $x];
                $right = $gray[$y * $w + $x + 1];
                $bits .= $left > $right ? '1' : '0';
            }
        }

        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex((int) bindec(substr($bits, $i, 4)));
        }

        return str_pad($hex, 16, '0', STR_PAD_LEFT);
    }

    public function hammingDistanceHex(string $hashA, string $hashB): int
    {
        $hashA = str_pad(Str::lower($hashA), 16, '0', STR_PAD_LEFT);
        $hashB = str_pad(Str::lower($hashB), 16, '0', STR_PAD_LEFT);

        if (strlen($hashA) !== 16 || strlen($hashB) !== 16) {
            return PHP_INT_MAX;
        }

        $dist = 0;
        for ($i = 0; $i < 16; $i++) {
            $xor = hexdec($hashA[$i]) ^ hexdec($hashB[$i]);
            $dist += substr_count(decbin($xor), '1');
        }

        return $dist;
    }
}
