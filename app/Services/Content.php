<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;

/** Контент для меню и блоков (страницы, баннеры, буквы брендов) — всё из кэша. */
final class Content
{
    /** Страницы магазина для меню (без дублей /pages/…) */
    public static function menuPages(): array
    {
        return Cache::remember('content.menu_pages', 86400, static fn() =>
            App::db()->all("SELECT id, url, name, name_uk FROM pages WHERE status = 1 AND in_menu = 1 ORDER BY sort, id"));
    }

    public static function banners(string $place): array
    {
        return Cache::remember('content.banners.' . $place, 86400, static fn() =>
            App::db()->all('SELECT * FROM banners WHERE place = ? AND status = 1 ORDER BY sort, id', [$place]));
    }

    /** Первые буквы брендов, у которых есть товары: A B C … Б В З … */
    public static function brandLetters(): array
    {
        return Cache::remember('content.brand_letters', 86400, static function () {
            $letters = [];
            foreach (Catalog::brands() as $b) {
                if ((int) $b['hidden'] || !(int) $b['product_count']) continue;
                $l = mb_strtoupper(mb_substr(trim($b['name']), 0, 1));
                if ($l !== '' && preg_match('/\p{L}/u', $l)) $letters[$l] = 1;
            }
            $keys = array_keys($letters);
            usort($keys, static function ($a, $b) {
                $ca = preg_match('/[A-Z]/', $a) ? 0 : 1; $cb = preg_match('/[A-Z]/', $b) ? 0 : 1;
                return $ca <=> $cb ?: strcmp($a, $b);
            });
            return $keys;
        });
    }
}
