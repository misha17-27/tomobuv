<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Словарь украинских названий товаров (database/i18n/uk/product_words.php): «Кроссовки Baas L1873-1» → «Кросівки Baas L1873-1».
 * Целые слова без учёта регистра, регистр первой буквы — из оригинала, длинные фразы раньше коротких; слова от 3 букв
 * меняются и вплотную к цифрам («99377-9чёрные», «Туфли68131A»), короткие («и», «из») — только отдельно стоящие
 * (артикулы вида «465И» не трогаются). Одна логика для bin/i18n-seed-uk.php и загрузчиков поставщиков.
 */
final class UkWords
{
    private static ?array $words = null;
    private static ?array $lower = null;
    private static ?string $re = null;
    private static ?array $ruByUk = null;

    /** Словарь [русское => українське], длинные фразы первыми */
    public static function words(): array
    {
        if (self::$words === null) {
            $f = ROOT . '/database/i18n/uk/product_words.php';
            $words = is_file($f) ? (array) include $f : [];
            uksort($words, static fn($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));   // длинные фразы раньше
            self::$words = $words;
            self::$lower = [];
            foreach ($words as $ru => $uk) self::$lower[mb_strtolower((string) $ru)] = $uk;
            $reList = static fn(array $l) => implode('|', array_map(static fn($w) => preg_quote((string) $w, '/'), $l));
            $long = array_filter(array_keys($words), static fn($w) => mb_strlen((string) $w) >= 3);
            $short = array_filter(array_keys($words), static fn($w) => mb_strlen((string) $w) < 3);
            self::$re = $words ? '/' . ($long ? '(?<!\p{L})(' . $reList($long) . ')(?!\p{L})' : '(?!)')
                . ($short ? '|(?<![\p{L}\p{N}])(' . $reList($short) . ')(?![\p{L}\p{N}])' : '') . '/u' : null;
        }
        return self::$words;
    }

    /** Перевод названия / мета-текста по словарю; незнакомые слова, бренды и коды — как есть */
    public static function translate(?string $s): ?string
    {
        self::words();
        if ($s === null || $s === '' || !self::$re) return $s;
        $words = self::$words;
        $lower = self::$lower;
        return preg_replace_callback(self::$re . 'i', static function ($m) use ($words, $lower) {
            $w = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
            $uk = $words[$w] ?? $lower[mb_strtolower($w)] ?? $w;
            if (mb_strtolower($uk) === mb_strtolower($w)) return $w;   // одинаковое в обоих языках — написание как в названии («УГГИ»)
            // сохранить регистр первой буквы
            $first = mb_substr($w, 0, 1);
            if ($first === mb_strtoupper($first)) $uk = mb_strtoupper(mb_substr($uk, 0, 1)) . mb_substr($uk, 1);
            else $uk = mb_strtolower(mb_substr($uk, 0, 1)) . mb_substr($uk, 1);
            return $uk;
        }, $s);
    }

    /**
     * Русское слово для украинского (обратный словарь одиночных слов): «Кросівки» → «Кроссовки», «Сандалі» → «Сандалии».
     * Нужен поставщикам, у которых тип в русском названии бывает по-украински. null — слово не украинское или неизвестное.
     */
    public static function ru(string $uk): ?string
    {
        if (self::$ruByUk === null) {
            self::$ruByUk = [];
            foreach (self::words() as $ru => $ukw) {
                $ru = (string) $ru; $ukw = (string) $ukw;
                if (str_contains($ru, ' ') || mb_strtolower($ru) === mb_strtolower($ukw) || $ru !== mb_convert_case($ru, MB_CASE_TITLE)) continue;
                self::$ruByUk[mb_strtolower($ukw)] ??= $ru;                 // первый — более длинный/точный (словарь отсортирован)
            }
        }
        return self::$ruByUk[mb_strtolower(trim($uk))] ?? null;
    }
}
