<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Lang;

/**
 * Переменные SEO-шаблонов — одни и те же для витрины (Front\ProductController, CategoryController, BrandController),
 * SEO-обзора и превью админки (SeoAudit, AdminCatalog) и автоисправления (SeoFix): иначе точки в админке разойдутся с витриной.
 *
 *   product.name, seo_name, sku, price, format_price («450 грн.»; пусто при цене ≤ 0), box_qty (пар в ящике),
 *   sizes — только «чистый» диапазон вида 36-41 (мусор вроде «_-39», «One Size» — пусто);
 *   category.name, seo_name, full_name («Детская обувь: кеды 26-32»), product_count;
 *   brand.name, product_count.
 * На /ua/ (Lang::isUk) названия категорий — name_uk, если заполнены (как DB::$localize).
 */
final class SeoVars
{
    /** «язык|id|имя» → полное имя категории */
    private static array $full = [];
    /** Скрытые категории (их нет в кэше справочника Catalog): id → строка; null — ещё не загружены */
    private static ?array $hidden = null;

    public static function product(array $p, ?array $cat): array
    {
        $name = (string) ($p['name'] ?? '');
        $seoName = trim((string) ($p['seo_name'] ?? ''));
        $price = (float) ($p['price'] ?? 0);
        $box = (int) ($p['box_qty'] ?? 0);
        return [
            'product' => [
                'name'         => $name,
                'seo_name'     => $seoName !== '' ? $seoName : $name,
                'format_price' => $price > 0 ? price_format($price) : '',
                'price'        => (string) round($price),
                'sku'          => (string) ($p['sku'] ?? ''),
                'box_qty'      => $box > 0 ? (string) $box : '',
                'sizes'        => self::sizes((string) ($p['size'] ?? '')),
            ],
        ] + self::category($cat);
    }

    /** $c — строка категории (из Catalog или базы); seo_name — своё SEO-название категории, если есть */
    public static function category(?array $c): array
    {
        if (!$c) return ['category' => ['name' => '', 'seo_name' => '', 'full_name' => '', 'product_count' => '']];
        $name = (string) ($c['name'] ?? '');
        $seo = trim((string) ($c['seo_name'] ?? ''));
        $count = $c['product_count'] ?? (self::row((int) ($c['id'] ?? 0))['product_count'] ?? 0);
        return ['category' => [
            'name'          => $name,
            'seo_name'      => $seo !== '' ? $seo : $name,
            'full_name'     => self::fullName($c),
            'product_count' => (string) (int) $count,
        ]];
    }

    public static function brand(array $b): array
    {
        return ['brand' => ['name' => trim((string) ($b['name'] ?? '')), 'product_count' => (string) (int) ($b['product_count'] ?? 0)]];
    }

    /**
     * Полное имя категории: «Корень: подкатегория» — «Детская обувь: кроссовки», у размерной подкатегории (в имени нет слова)
     * — с ближайшим предком со словом: «Детская обувь: кеды 26-32». Корень — в регистре предложения («ДЕТСКАЯ ОБУВЬ» →
     * «Детская обувь»), кириллица подкатегории — строчными. Корневая категория — просто своё имя.
     */
    public static function fullName(?array $c): string
    {
        $id = (int) ($c['id'] ?? 0);
        $key = Lang::current() . '|' . $id . '|' . ($c['name'] ?? '');     // имя — в ключе: превью ещё не сохранённой правки
        if ($id && isset(self::$full[$key])) return self::$full[$key];
        $chain = [];
        $row = $c;
        $guard = 0;
        while ($row && $guard++ < 10) {
            array_unshift($chain, self::loc($row));
            $pid = (int) ($row['parent_id'] ?? (self::row((int) ($row['id'] ?? 0))['parent_id'] ?? 0));
            $row = $pid ? self::row($pid) : null;
        }
        if (!$chain) return '';
        $root = nice_case(trim((string) $chain[0]['name']));
        $out = $root;
        if (count($chain) > 1) {
            $last = trim((string) end($chain)['name']);
            $sub = self::lowerCyr($last);
            if (!self::hasWord($last)) {
                for ($i = count($chain) - 2; $i >= 1; $i--) {
                    $n = trim((string) $chain[$i]['name']);
                    if (self::hasWord($n)) { $sub = self::lowerCyr($n) . ' ' . $sub; break; }
                }
            }
            $out = $root . ': ' . $sub;
        }
        if ($id) self::$full[$key] = $out;
        return $out;
    }

    /**
     * Категория товара для шаблонов — как Front\ProductController::seoCategory: основная (даже скрытая),
     * у товара без категории — первая в дереве. Без запросов в цикле: скрытые категории загружаются один раз.
     */
    public static function productCategory(array $p): ?array
    {
        $cid = (int) ($p['category_id'] ?? 0);
        $c = $cid ? self::row($cid) : null;
        if (!$c) {
            $all = Catalog::categories();
            $c = $all ? reset($all) : null;
        }
        return $c ? self::loc($c) : null;
    }

    /** Размеры: «36-41», «28-31,5»; всё прочее (мусор, «One Size», «0-12») — пусто */
    public static function sizes(string $s): string
    {
        if (!preg_match('/^\s*(\d{2}(?:[.,]5)?)\s*-\s*(\d{2}(?:[.,]5)?)\s*$/', $s, $m)) return '';
        $a = (float) str_replace(',', '.', $m[1]);
        $b = (float) str_replace(',', '.', $m[2]);
        return ($a >= 15 && $b <= 50 && $a < $b) ? $m[1] . '-' . $m[2] : '';
    }

    /** Сбросить запомненное (после правок категорий в долгом процессе) */
    public static function reset(): void
    {
        self::$full = [];
        self::$hidden = null;
    }

    /** Строка категории по id: из справочника (активные) или из базы (скрытые, одним запросом на все) */
    private static function row(int $id): ?array
    {
        if (!$id) return null;
        $c = Catalog::category($id);
        if ($c) return $c;
        if (self::$hidden === null) {
            self::$hidden = [];
            foreach (App::db()->query('SELECT id, parent_id, depth, name, name_uk, url, seo_name, seo_name_uk, status, product_count
                FROM categories WHERE status <> 1')->fetchAll() as $r) {
                self::$hidden[(int) $r['id']] = $r;
            }
        }
        return self::$hidden[$id] ?? null;
    }

    /** Строка с украинскими названиями на /ua/ (повторный вызов на уже подставленной строке ничего не меняет) */
    private static function loc(array $row): array
    {
        return Lang::isUk() ? Lang::localize($row) : $row;
    }

    private static function hasWord(string $s): bool
    {
        return (bool) preg_match('/\p{L}{3,}/u', $s);
    }

    private static function lowerCyr(string $s): string
    {
        return (string) preg_replace_callback('/\p{Cyrillic}+/u', static fn($m) => mb_strtolower($m[0]), $s);
    }
}
