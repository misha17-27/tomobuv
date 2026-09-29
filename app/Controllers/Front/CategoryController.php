<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Cache;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\View;
use App\Services\Catalog;
use App\Services\Listing;
use App\Services\Products;
use App\Services\SeoVars;

/**
 * Страница категории /category/{url}/ — список товаров с фильтрами, сортировкой и пагинацией.
 * SEO — как на старом сайте (плагин SEO Webasyst): свои meta категории, иначе шаблоны seo.category_*
 * (переменные — App\Services\SeoVars, длина под норму — App\Core\Seo::pick),
 * на ?page=N — « | Страница N» к title и description первой страницы (как основная форма на старом сайте), canonical на первую
 * страницу; шаблоны seo.category_pagination_* — только если включены (по умолчанию выключены: SeoFix), номер страницы в них
 * уже есть — второго суффикса нет (Seo::pageSuffix).
 */
final class CategoryController
{
    public function show(string $url): Response
    {
        $cat = Catalog::categoryByUrl($url) ?? Catalog::hiddenCategoryByUrl($url);   // скрытые открываются по прямому адресу, как на старом сайте
        if (!$cat) return Response::notFound();
        $id = (int) $cat['id'];
        $full = self::full($id);
        $base = Catalog::categoryUrl($cat);

        [$defSort, $defOrder] = self::defaultSort($full, (int) $cat['type']);
        $L = Listing::fromRequest($base, $defSort, $defOrder);
        $L->runCategory($cat);         // ?page= за концом списка — пустая страница 200, как на старом сайте
        $products = Products::cards($L->ids);

        // адреса с ?{код}[]= PageCache и так не кэширует; страницы за концом списка — не копим в кэше
        $cacheable = !$L->hasFeatureFilters() && !$L->outOfRange;
        $grid = 'grid g4';

        [$hasPrice, $fids] = Listing::parseFilterSetting($full['filter'] ?? '');
        $groups = $L->categoryGroups($id, $fids);
        if ($L->total === 0 && !$L->hasFilters()) { $hasPrice = false; $groups = []; }   // пустая категория — без фильтров
        $hasFilters = $hasPrice || $groups;
        if (!$hasFilters) $grid = 'grid';

        if (self::isAjax()) {
            $r = Response::json($L->ajaxPayload(View::render('front/partials/listing', ['L' => $L, 'products' => $products, 'itemsOnly' => true], null)));
            return $cacheable ? $r->cache(1800) : $r;
        }

        $seo = self::seo($cat, $full, $L);
        $path = Catalog::path($id);
        if (!$path || (int) end($path)['id'] !== $id) $path = array_merge(Catalog::path((int) $cat['parent_id']), [$cat]);
        $crumbs = [];
        foreach ($path as $i => $c) {
            $crumbs[] = ['name' => nice_case((string) $c['name']), 'url' => $i < count($path) - 1 ? Catalog::categoryUrl($c) : null];
        }

        $html = View::render('front/category', [
            'seo' => $seo,
            'cat' => $cat,
            'full' => $full,
            'crumbs' => $crumbs,
            'L' => $L,
            'products' => $products,
            'groups' => $groups,
            'hasPrice' => $hasPrice,
            'priceRange' => $hasPrice ? Listing::categoryPriceRange($id) : [0, 0],
            'subcats' => self::subcats($cat),
            'grid' => $grid,
            'sorting' => (int) ($full['enable_sorting'] ?? 1) === 1,
            'withDefaultSort' => !in_array($defSort, ['create_datetime'], true) || $defOrder !== 'desc',
            'showText' => $L->pageNo() === 1 && !$L->isModified(),
            'scripts' => ['js/catalog.js'],
        ]);
        $r = Response::html($html);
        return $cacheable ? $r->cache(1800) : $r;
    }

    /** Заголовок X-Requested-With — тот же признак, что использует PageCache для отдельного ключа */
    public static function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /** Поля категории, которых нет в общем кэше Catalog::categories() */
    private static function full(int $id): array
    {
        return Cache::remember('catalog.category_full.' . $id, 86400, static fn() => App::db()->row(
            'SELECT meta_title, meta_title_uk, meta_keywords, meta_keywords_uk, meta_description, meta_description_uk, seo_name, seo_name_uk,
                description, description_uk, seo_description, seo_description_uk, filter, sort_products, enable_sorting
             FROM categories WHERE id = ?', [$id]) ?? []);   // кэш отдельный для каждого языка, *_uk подставляются сами
    }

    /** Сортировка по умолчанию из настройки категории (как в Webasyst) */
    private static function defaultSort(array $full, int $type): array
    {
        $s = strtolower(trim((string) ($full['sort_products'] ?? '')));
        if ($s === '') return $type === 1 ? ['create_datetime', 'desc'] : ['sort', 'asc'];
        [$col, $dir] = array_pad(preg_split('/\s+/', $s) ?: [], 2, '');
        $dir = $dir === 'asc' ? 'asc' : ($dir === 'desc' ? 'desc' : '');
        return match ($col) {
            'create_datetime' => ['create_datetime', $dir ?: 'desc'],
            'edit_datetime' => ['edit_datetime', $dir ?: 'desc'],
            'price' => ['price', $dir ?: 'asc'],
            'name' => ['name', $dir ?: 'asc'],
            default => ['create_datetime', 'desc'],
        };
    }

    /** Собственное значение как есть (без обрезки — как выводит старый сайт) или '' */
    private static function own(?string $v): string
    {
        return ($v !== null && trim($v) !== '') ? $v : '';
    }

    private static function seo(array $cat, array $full, Listing $L): Seo
    {
        $page = $L->pageNo();
        $name = (string) $cat['name'];
        // переменные — общие с SEO-обзором и автоисправлением: full_name («Детская обувь: кеды 26-32»), product_count…
        $vars = SeoVars::category(['seo_name' => self::own($full['seo_name'] ?? null)] + $cat) + ['page_number' => $page];
        $on = (bool) Settings::get('seo.category_is_enabled', 1);
        $pag = $page > 1 && (bool) Settings::get('seo.category_pagination_is_enabled', 0);
        $pick = static function (?string $own, string $key) use ($vars, $on): string {
            $own = self::own($own);
            if ($own !== '') return $own;
            return $on ? Seo::pick(null, $key, $vars) : '';
        };
        $title = $pick($full['meta_title'] ?? null, $pag ? 'seo.category_pagination_meta_title' : 'seo.category_meta_title');
        $seo = Seo::make(
            $title !== '' ? $title : $name,
            $pick($full['meta_description'] ?? null, $pag ? 'seo.category_pagination_meta_description' : 'seo.category_meta_description'),
            $pick($full['meta_keywords'] ?? null, 'seo.category_meta_keywords')
        );
        $seo->h1 = $name;
        $L->applySeo($seo);         // « | Страница N», canonical → первая страница; фильтры/сортировка — noindex
        if (!empty($cat['image'])) $seo->ogImage = media((string) $cat['image']);
        return $seo;
    }

    /**
     * Подкатегории чипсами: дочерние категории с товарами; для размерных рядов (3-й уровень) —
     * соседние ряды и «Все размеры» (ссылка на родителя).
     */
    private static function subcats(array $cat): array
    {
        $out = [];
        $kids = array_filter(Catalog::children((int) $cat['id']), static fn($c) => $c && (int) $c['product_count'] > 0);
        if ($kids) {
            foreach ($kids as $c) $out[] = ['name' => $c['name'], 'url' => Catalog::categoryUrl($c), 'count' => (int) $c['product_count'], 'on' => false];
            return ['title' => '', 'items' => $out];
        }
        if ((int) $cat['depth'] >= 2 && ($parent = Catalog::category((int) $cat['parent_id']))) {
            $sib = array_filter(Catalog::children((int) $parent['id']), static fn($c) => $c && ((int) $c['product_count'] > 0 || (int) $c['id'] === (int) $cat['id']));
            if (count($sib) > 1) {
                $out[] = ['name' => t('Все размеры'), 'url' => Catalog::categoryUrl($parent), 'count' => (int) $parent['product_count'], 'on' => false];
                foreach ($sib as $c) $out[] = ['name' => $c['name'], 'url' => Catalog::categoryUrl($c), 'count' => (int) $c['product_count'], 'on' => (int) $c['id'] === (int) $cat['id']];
                return ['title' => nice_case((string) $parent['name']) . ':', 'items' => $out];
            }
        }
        return ['title' => '', 'items' => []];
    }
}
