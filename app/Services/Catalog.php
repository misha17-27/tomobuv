<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;

/**
 * Справочники витрины, которые целиком держатся в кэше (их мало, а нужны на каждой странице):
 * дерево категорий (~110), бренды (~600), характеристики для фильтров.
 */
final class Catalog
{
    private static ?array $cats = null;
    private static ?array $brands = null;

    /** Все активные категории [id => row], отсортированы по дереву (lft) */
    public static function categories(): array
    {
        if (self::$cats === null) {
            self::$cats = Cache::remember('catalog.categories', 86400, static function () {
                $rows = App::db()->keyed('SELECT id, parent_id, depth, sort, name, name_uk, url, type, include_sub, status, image, banner,
                    product_count, seo_name, seo_name_uk, lft, rgt FROM categories WHERE status = 1 ORDER BY lft, sort, id');
                foreach ($rows as &$r) { $r['children'] = []; }
                unset($r);
                foreach ($rows as $id => $r) {
                    if ($r['parent_id'] && isset($rows[$r['parent_id']])) $rows[$r['parent_id']]['children'][] = $id;
                }
                return $rows;
            });
        }
        return self::$cats;
    }

    public static function category(int $id): ?array
    {
        return self::categories()[$id] ?? null;
    }

    public static function categoryByUrl(string $url): ?array
    {
        $map = Cache::remember('catalog.category_urls', 86400, static fn() => array_column(self::categories(), 'id', 'url'));
        $id = $map[$url] ?? null;
        return $id ? self::category((int) $id) : null;
    }

    /** Корневые категории (для меню) */
    public static function roots(): array
    {
        return array_values(array_filter(self::categories(), static fn($c) => (int) $c['parent_id'] === 0));
    }

    public static function children(int $id): array
    {
        $c = self::category($id);
        return $c ? array_map(static fn($i) => self::category($i), $c['children']) : [];
    }

    /** Цепочка от корня до категории (для хлебных крошек) */
    public static function path(int $id): array
    {
        $out = [];
        $guard = 0;
        while ($id && ($c = self::category($id)) && $guard++ < 10) {
            array_unshift($out, $c);
            $id = (int) $c['parent_id'];
        }
        return $out;
    }

    /**
     * Скрытая категория (status=0): в меню её нет, но по прямому адресу она открывается — как в Webasyst.
     */
    public static function hiddenCategoryByUrl(string $url): ?array
    {
        $row = Cache::remember('catalog.hidden.' . md5($url), 86400, static fn() => App::db()->row('SELECT id, parent_id, depth, sort, name, name_uk, url, type,
            include_sub, status, image, banner, product_count, seo_name, seo_name_uk, lft, rgt FROM categories WHERE url = ? AND status = 0', [$url]) ?: false);
        if (!$row) return null;
        $row['children'] = [];
        return $row;
    }

    public static function categoryUrl(array $c): string
    {
        return '/category/' . $c['url'] . '/';
    }

    /** Бренды [id => ['id','name','url','image','hidden','product_count']] */
    public static function brands(): array
    {
        if (self::$brands === null) {
            self::$brands = Cache::remember('catalog.brands', 86400, static fn() =>
                App::db()->keyed('SELECT id, name, url, image, hidden, product_count FROM brands ORDER BY name'));
        }
        return self::$brands;
    }

    public static function brand(?int $id): ?array
    {
        return $id ? (self::brands()[$id] ?? null) : null;
    }

    public static function brandByUrl(string $url): ?array
    {
        $map = Cache::remember('catalog.brand_urls', 86400, static function () {
            $m = [];
            foreach (self::brands() as $b) $m[mb_strtolower($b['url'])] = $b['id'];
            return $m;
        });
        $id = $map[mb_strtolower($url)] ?? null;
        return $id ? self::brands()[$id] : null;
    }

    /** Как в плагине брендов Webasyst: urlencode(имя) — пробел → «+», «&» → %26 (/brand/Mona+Lisa/) */
    public static function brandUrl(array $b): string
    {
        return '/brand/' . urlencode((string) $b['url']) . '/';
    }

    /** Характеристики [id => row], [code => id] */
    public static function features(): array
    {
        return Cache::remember('catalog.features', 86400, static fn() =>
            App::db()->keyed('SELECT id, code, name, name_uk, type, multiple, status, is_filter, sort FROM features ORDER BY sort, id'));
    }

    public static function featureByCode(string $code): ?array
    {
        foreach (self::features() as $f) if ($f['code'] === $code) return $f;
        return null;
    }
}
