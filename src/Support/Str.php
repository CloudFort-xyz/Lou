<?php

declare(strict_types=1);

namespace Lou\Support;

final class Str
{
    /** "Példa Kft. – Árvíztűrő" → "pelda-kft-arvizturo" */
    public static function slug(string $s, string $fallback = 'item'): string
    {
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            $s = $t?->transliterate($s) ?: $s;
        } else {
            $s = strtr($s, ['ő' => 'o', 'ű' => 'u', 'Ő' => 'O', 'Ű' => 'U']);
            $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        }
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
        return $s !== '' ? $s : $fallback;
    }

    /** Safe ASCII file name, keeping the extension: "Árvíz kép.JPG" → "arviz-kep.jpg" */
    public static function fileName(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = self::slug(pathinfo($name, PATHINFO_FILENAME), 'file');
        return $ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext) ? "$base.$ext" : $base;
    }
}
