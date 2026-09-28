<?php
declare(strict_types=1);

namespace App\Core;

final class Str
{
    private const TR = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','ґ'=>'g','д'=>'d','е'=>'e','ё'=>'yo','є'=>'ye','ж'=>'zh','з'=>'z','и'=>'i','і'=>'i','ї'=>'yi',
        'й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh',
        'ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];

    /** URL-слаг: «Кросівки Jong•Golf B11661-0» → krosivki-jonggolf-b11661-0 */
    public static function slug(string $s, int $max = 190): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, self::TR);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        return substr($s, 0, $max) ?: 'item';
    }

    /** Нормализация телефона к виду 380XXXXXXXXX (или '' если не похоже на телефон) */
    public static function phone(string $s): string
    {
        $d = preg_replace('/\D+/', '', $s);
        if (strlen($d) === 10 && $d[0] === '0') $d = '38' . $d;
        if (strlen($d) === 9) $d = '380' . $d;
        return strlen($d) >= 11 && strlen($d) <= 13 ? $d : '';
    }

    public static function email(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : '';
    }

    public static function random(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
