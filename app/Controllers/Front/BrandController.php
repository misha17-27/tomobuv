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
use App\Services\Content;
use App\Services\Listing;
use App\Services\Products;
use App\Services\SeoVars;

/**
 * Бренды: /brand/ — все бренды с товарами (буквы ?letter=), /brand/{имя}/ — товары бренда.
 * Адрес бренда как в плагине брендов Webasyst: urlencode(имя) — /brand/Mona+Lisa/, /brand/JH-%D0%AF%D0%9D/,
 * либо собственный адрес (/brand/lepard/). SEO: свои brands.title / meta_description, иначе шаблоны seo.brand_meta_* (см. seo()).
 */
final class BrandController
{
    /** Характеристики для фильтра на странице бренда (как на старом сайте: цвет, материалы, пол, размер) */
    private const FILTER_FEATURES = ['color', 'material_vneshniy', 'pol', 'material_vnutri', 'material', 'size'];

    public function index(): Response
    {
        $letters = Content::brandLetters();
        $letter = '';
        if (isset($_GET['letter'])) {
            $raw = is_scalar($_GET['letter']) ? trim((string) $_GET['letter']) : '';
            $letter = $raw !== '' ? mb_strtoupper(mb_substr($raw, 0, 1)) : '';
            if ($letter === '' || !in_array($letter, $letters, true)) return Response::redirect('/brand/', 301);
            // одна форма адреса для каждой буквы: ?letter=a, ?letter=Ab → ?letter=A
            if ($raw !== $letter) return Response::redirect('/brand/?letter=' . rawurlencode($letter), 301);
        }
        $brands = array_values(array_filter(Catalog::brands(), static fn($b) => !(int) $b['hidden'] && (int) $b['product_count'] > 0));
        usort($brands, static fn($a, $b) => strnatcasecmp(trim((string) $a['name']), trim((string) $b['name'])));

        // Группы по первой букве: латиница, кириллица, затем «0–9»
        $groups = [];
        foreach ($brands as $b) {
            $l = mb_strtoupper(mb_substr(trim((string) $b['name']), 0, 1));
            $key = preg_match('/\p{L}/u', $l) ? $l : '0–9';
            if ($letter !== '' && $key !== $letter) continue;
            $groups[$key][] = $b;
        }
        $order = array_flip($letters);
        uksort($groups, static fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
        $top = $letter === '' ? array_slice(self::byCount($brands), 0, 12) : [];

        $name = $letter !== '' ? t('Бренды на букву {letter}', ['letter' => $letter]) : t('Бренды');
        $seo = Seo::make(
            Seo::tpl((string) Settings::get('seo.page_meta_title', '{$page.name} | интернет-магазин {$store_info.name}'),
                ['page' => ['name' => $name], 'store_info' => Seo::storeInfo()]),
            t('Бренды обуви оптом в интернет-магазине Том Обувь: производители детской, подростковой, женской и мужской обуви. Одесса, 7 км, доставка по всей Украине.')
        );
        $seo->h1 = $name;
        $seo->canonical = url('/brand/');
        if ($letter !== '') $seo->robots = 'noindex, follow';

        $html = View::render('front/brands', [
            'seo' => $seo, 'groups' => $groups, 'letters' => $letters, 'letter' => $letter, 'top' => $top,
            'total' => count($brands), 'scripts' => ['js/catalog.js'],
        ]);
        return Response::html($html)->cache(3600);
    }

    public function show(string $name): Response
    {
        [$brand, $canonicalRedirect] = self::find($name);
        // бренд без товаров на витрине — 404, как на старом сайте (плагин брендов Webasyst)
        if (!$brand || (int) $brand['product_count'] === 0) return Response::notFound();
        if ($canonicalRedirect) return Response::redirect(Catalog::brandUrl($brand) . self::qs(), 301);

        $full = self::full((int) $brand['id']);
        $base = Catalog::brandUrl($brand);
        $L = Listing::fromRequest($base, 'create_datetime', 'desc');
        $L->runBrand($brand);          // ?page= за концом списка — пустая страница 200, как на старом сайте
        $products = Products::cards($L->ids);
        // адреса с ?{код}[]= PageCache и так не кэширует; страницы за концом списка — не копим в кэше
        $cacheable = !$L->hasFeatureFilters() && !$L->outOfRange;

        if (CategoryController::isAjax()) {
            $r = Response::json($L->ajaxPayload(View::render('front/partials/listing', ['L' => $L, 'products' => $products, 'itemsOnly' => true], null)));
            return $cacheable ? $r->cache(1800) : $r;
        }

        // Фильтры: цена + характеристики товаров бренда; категории бренда — ссылками на категорию с фильтром по бренду
        $fids = [];
        foreach (Listing::filterFeatures() as $fid => $f) if (in_array($f['code'], self::FILTER_FEATURES, true)) $fids[] = $fid;
        $groups = (int) $brand['product_count'] > 0 ? $L->brandGroups((int) $brand['id'], $fids) : [];
        $groups = array_values(array_filter($groups, static fn($g) => count($g['values']) > 1 || $g['active']));
        $hasPrice = (int) $brand['product_count'] > 1;

        $seo = self::seo($brand, $full, $L);
        $html = View::render('front/brand', [
            'seo' => $seo,
            'brand' => $brand,
            'full' => $full,
            'L' => $L,
            'products' => $products,
            'groups' => $groups,
            'hasPrice' => $hasPrice,
            'priceRange' => $hasPrice ? Listing::brandPriceRange((int) $brand['id']) : [0, 0],
            'cats' => self::categories((int) $brand['id']),
            'showText' => $L->pageNo() === 1 && !$L->isModified(),
            'showAlpha' => true,
            'scripts' => ['js/catalog.js'],
        ]);
        $r = Response::html($html);
        return $cacheable ? $r->cache(1800) : $r;
    }

    /**
     * Поиск бренда по аргументу маршрута (роутер получает путь уже без /ua и после rawurldecode:
     * /brand/Mona+Lisa/ → «Mona+Lisa», /brand/JH-%D0%AF%D0%9D/ → «JH-ЯН», %26 → «&»).
     * 1) точное совпадение с основным адресом бренда в том же виде (urlencode как в Webasyst, «+» = пробел, %2B = «+»);
     * 2) как urldecode() в Webasyst: «+» → пробел (/brand/Mona%20Lisa/, другой регистр) — с 301 на основной адрес;
     * 3) старые ссылки по имени бренда, у которого теперь собственный адрес (Леопард → /brand/lepard/) — 301.
     * Возвращает [бренд, нужен ли 301 на основной адрес].
     */
    private static function find(string $param): array
    {
        if ($param === '' || mb_strlen($param) > 255) return [null, false];
        $seg = Cache::remember('brand.segments', 86400, static function () {
            $m = [];
            // бренды с товарами — первыми: при совпадении сегментов («A B» и «A+B») побеждает бренд с товарами
            $list = Catalog::brands();
            uasort($list, static fn($a, $b) => ((int) $b['product_count'] > 0) <=> ((int) $a['product_count'] > 0));
            foreach ($list as $b) $m[mb_strtolower(rawurldecode(urlencode((string) $b['url'])))] ??= (int) $b['id'];
            return $m;
        });
        $id = $seg[mb_strtolower($param)] ?? null;
        if ($id && ($b = Catalog::brands()[$id] ?? null)) {
            return [$b, rawurldecode(urlencode((string) $b['url'])) !== $param];   // другой регистр — 301
        }
        $plain = str_replace('+', ' ', $param);
        if ($b = Catalog::brandByUrl($plain)) return [$b, true];
        $low = mb_strtolower(trim($plain));
        foreach (Catalog::brands() as $b) {
            if (mb_strtolower(trim((string) $b['name'])) === $low) return [$b, true];
        }
        return [null, false];
    }

    /** Разрешённые параметры для редиректа */
    private static function qs(): string
    {
        $q = [];
        foreach (['page', 'sort', 'order', 'view'] as $k) {
            if (isset($_GET[$k]) && is_scalar($_GET[$k]) && $_GET[$k] !== '') $q[$k] = (string) $_GET[$k];
        }
        return $q ? '?' . http_build_query($q) : '';
    }

    private static function full(int $id): array
    {
        return Cache::remember('catalog.brand_full.' . $id, 86400, static fn() => App::db()->row(
            'SELECT title, title_uk, h1, h1_uk, meta_keywords, meta_keywords_uk, meta_description, meta_description_uk,
                summary, summary_uk, description, description_uk, seo_description, seo_description_uk FROM brands WHERE id = ?', [$id]) ?? []);
    }

    /**
     * SEO: свои значения бренда, иначе шаблоны seo.brand_meta_title / seo.brand_meta_description
     * («{$brand.name} — обувь оптом…», переменные — App\Services\SeoVars::brand), если seo.brand_is_enabled; без шаблона —
     * title = имя бренда, description пуст (как на старом сайте). Keywords и H1 — только свои.
     */
    private static function seo(array $brand, array $full, Listing $L): Seo
    {
        $own = static fn($v) => ($v !== null && trim((string) $v) !== '') ? trim((string) $v) : '';
        $name = (string) $brand['name'];
        $tpl = (string) Settings::get('seo.brand_is_enabled', '1') !== '0';
        $vars = SeoVars::brand($brand);
        $pick = static fn($v, string $key): string => $tpl ? Seo::pick($v, $key, $vars) : $own($v);
        $seo = Seo::make($pick($full['title'] ?? null, 'seo.brand_meta_title') ?: $name, $pick($full['meta_description'] ?? null, 'seo.brand_meta_description'),
            $own($full['meta_keywords'] ?? null));
        $seo->h1 = trim($own($full['h1'] ?? null)) ?: $name;
        $L->applySeo($seo, false);  // как на старом сайте: без « | Страница N»; canonical → первая страница; фильтры/сортировка — noindex
        if (!empty($brand['image'])) $seo->ogImage = media((string) $brand['image']);
        return $seo;
    }

    /** Разделы каталога с товарами бренда: [['name','url','count']] — ссылки на категорию с фильтром ?brand[]=id */
    private static function categories(int $brandId): array
    {
        $counts = Cache::remember('brand.cats.' . $brandId, 3600, static fn() => App::db()->pairs(
            'SELECT ci.category_id, COUNT(*) FROM products p JOIN catalog_index ci ON ci.product_id = p.id
             WHERE p.brand_id = ? AND p.status = 1 GROUP BY ci.category_id', [$brandId]));
        $brandF = null;
        foreach (Listing::filterFeatures() as $f) if ($f['code'] === 'brand') $brandF = $f;
        if (!$brandF) return [];
        $out = [];
        foreach (Catalog::categories() as $c) {                  // порядок дерева
            $n = (int) ($counts[$c['id']] ?? 0);
            if ($n === 0 || (int) $c['depth'] > 1) continue;
            $parent = $c['parent_id'] ? Catalog::category((int) $c['parent_id']) : null;
            $out[] = [
                'name' => ($parent ? nice_case((string) $parent['name']) . ' · ' : '') . nice_case((string) $c['name']),
                'url' => Listing::build(Catalog::categoryUrl($c), [$brandF['code'] => [$brandId]]),
                'count' => $n, 'root' => !$parent,
            ];
        }
        return $out;
    }

    private static function byCount(array $brands): array
    {
        usort($brands, static fn($a, $b) => ((int) $b['product_count'] <=> (int) $a['product_count']) ?: strnatcasecmp($a['name'], $b['name']));
        return $brands;
    }
}
