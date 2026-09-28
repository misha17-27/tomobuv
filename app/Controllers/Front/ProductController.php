<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Image;
use App\Core\Lang;
use App\Core\Paginator;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\View;
use App\Services\Catalog;
use App\Services\Products;

/**
 * Страница товара /product/{url}/, отзывы о товаре /product/{url}/reviews/ (как в Webasyst)
 * и HTML-карточки по списку id /products/cards/?ids=… (блок «Вы недавно смотрели»).
 *
 * SEO повторяет живой сайт: свои meta_* товара, иначе шаблоны seo.product_meta_* с переменными
 * {$product.name}, {$product.seo_name}, {$product.format_price}, {$category.name}, {$category.seo_name}.
 */
final class ProductController
{
    private const REVIEWS_ON_PAGE = 5;     // отзывов во вкладке на странице товара
    private const REVIEWS_PER_PAGE = 20;   // на странице /reviews/
    private const SIMILAR = 10;            // «Похожие товары»
    private const SIMILAR_POOL = 300;      // сколько свежих товаров категории держать в кэше для подбора
    private const CARDS_MAX = 12;          // карточек за один запрос /products/cards/

    // ------------------------------------------------------------------ страница товара

    public function show(string $url): Response
    {
        $p = self::find($url);
        if (!$p) return Response::notFound();
        if ($url !== $p['url']) return self::toCanonical($p['link']);
        $pid = $p['id'];

        $cat = self::seoCategory($p);
        $images = Products::images($pid);
        [$rCount, $rAvg] = self::reviewStats($pid);
        // Оценка: по одобренным отзывам, иначе перенесённая из Webasyst (products.rating) — как звёзды на старом сайте.
        // Значение и число оценок берутся из одного источника, чтобы микроразметка не противоречила сама себе.
        if ($rCount && $rAvg > 0) {
            [$rating, $ratingCount] = [$rAvg, $rCount];
        } else {
            $ratingCount = (int) $p['rating_count'];
            $rating = $ratingCount > 0 ? round((float) $p['rating'], 1) : 0.0;
        }

        $seo = Seo::make();
        $vars = self::seoVars($p, $cat);
        $seo->title = Seo::pick($p['meta_title'], 'seo.product_meta_title', $vars) ?: $p['name'];
        $seo->description = Seo::pick($p['meta_description'], 'seo.product_meta_description', $vars);
        $seo->keywords = Seo::pick($p['meta_keywords'], 'seo.product_meta_keywords', $vars);
        $seo->h1 = Seo::pick($p['h1'], 'seo.product_h1', $vars) ?: $p['name'];
        $seo->canonical = url($p['link']);
        $seo->ogType = 'website';
        if ($p['image_id']) {
            $seo->ogImage = Image::url($pid, (int) $p['image_id'], $p['image_ext'] ?: 'jpg', '750x0');
        }
        $seo->jsonLd[] = self::jsonLd($p, $images, $seo, $rCount, $rating, $ratingCount);

        $html = View::render('front/product', [
            'seo'       => $seo,
            'p'         => $p,
            'images'    => $images,
            'features'  => Products::features($pid),
            'crumbs'    => self::crumbs($p),
            'category'  => $p['category_id'] ? Catalog::category((int) $p['category_id']) : null,
            'reviews'   => $rCount ? self::reviewList($pid, 0, self::REVIEWS_ON_PAGE) : [],
            'rCount'    => $rCount,
            'rAvg'      => $rAvg,
            'rating'    => $rating,
            'ratingCount' => $ratingCount,
            'similar'   => self::similar($p),
            'phones'    => Settings::json('phones', []),
            'freeBoxes' => (int) Settings::get('free_shipping_boxes', 20),
            'scripts'   => ['js/product.js'],
            'bodyClass' => 'pp-page',
        ]);
        return Response::html($html)->cache(3600);
    }

    // ------------------------------------------------------------------ отзывы

    /** Отдельная страница отзывов (адрес как в Webasyst) */
    public function reviews(string $url): Response
    {
        $p = self::find($url);
        if (!$p) return Response::notFound();
        if ($url !== $p['url']) return self::toCanonical($p['link'] . 'reviews/');
        $res = self::reviewsPage($p);
        return $res->status === 200 ? $res->cache(3600) : $res;
    }

    /** Новый отзыв: CSRF + honeypot + лимит по IP → статус «moderation» */
    public function addReview(string $url): Response
    {
        $p = self::find($url);
        if (!$p) return Response::notFound();
        $ajax = Request::isAjax();
        $fail = static function (string $msg, int $code = 422) use ($ajax, $p): Response {
            return $ajax ? Response::json(['ok' => false, 'error' => $msg], $code)
                : self::reviewsPage($p, ['error' => $msg, 'old' => $_POST], $code);
        };
        $okMsg = t('Спасибо! Отзыв появится после проверки модератором.');
        $ok = static fn(): Response => $ajax ? Response::json(['ok' => true, 'message' => $okMsg])
            : self::reviewsPage($p, ['ok' => $okMsg]);

        if (!Csrf::check()) return $fail(t('Страница устарела. Обновите её и отправьте отзыв ещё раз.'), 419);
        if (Request::post('website') !== '') return $ok();     // бот заполнил скрытое поле — делаем вид, что всё хорошо

        $name = self::clean(Request::post('name'), 100);
        $text = self::clean(Request::post('text'), 5000, true);
        $rate = Request::postInt('rate');
        if (mb_strlen($name) < 2) return $fail(t('Укажите ваше имя.'));
        if ($rate < 1 || $rate > 5) return $fail(t('Поставьте оценку от 1 до 5.'));
        if (mb_strlen($text) < 5) return $fail(t('Напишите текст отзыва.'));
        if (preg_match('#https?://|www\.|\[url#iu', $text)) return $fail(t('Ссылки в отзывах запрещены.'));
        if (!RateLimit::hit('product_review:' . Request::ip(), 5, 3600)) return $fail(t('Слишком много отзывов подряд. Попробуйте позже.'), 429);

        $id = App::db()->insert('product_reviews', [
            'product_id'  => $p['id'],
            'customer_id' => Auth::id() ?: null,
            'name'        => $name,
            'text'        => $text,
            'rate'        => $rate,
            'status'      => 'moderation',
            'ip'          => substr(Request::ip(), 0, 45),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        // письмо администратору — как у отзывов о магазине (после ответа посетителю, по-русски)
        RequestController::notifyAdmin('Новый отзыв о товаре №' . $id, [
            ['Товар', $p['name'] . ' — ' . url($p['link'])], ['Имя', $name], ['Оценка', $rate . ' из 5'], ['Отзыв', $text],
            ['Язык', Lang::isUk() ? 'украинский' : ''], ['Дата', date('d.m.Y H:i')], ['IP', Request::ip()],
        ], '/admin/reviews/?type=product');
        return $ok();
    }

    // ------------------------------------------------------------------ карточки по id

    /** HTML карточек (partials/card) для блока «Вы недавно смотрели». Не более 12 id. */
    public function cards(): Response
    {
        $ids = [];
        foreach (explode(',', Request::get('ids')) as $x) {
            $x = trim($x);
            if ($x !== '' && ctype_digit($x) && strlen($x) < 11 && (int) $x > 0) $ids[(int) $x] = (int) $x;
            if (count($ids) >= self::CARDS_MAX) break;
        }
        $html = '';
        foreach ($ids ? Products::cards(array_values($ids)) : [] as $card) {
            $html .= View::render('front/partials/card', ['p' => $card], null);
        }
        // Кэш страниц здесь не нужен: у каждого посетителя свой набор ids (меняется с каждым просмотром),
        // PageCache плодил бы файл на каждую комбинацию. Ответ лёгкий (1 запрос), его кэширует браузер.
        return Response::html($html)
            ->header('Cache-Control', 'public, max-age=600')
            ->header('X-Robots-Tag', 'noindex');
    }

    // ------------------------------------------------------------------ внутреннее

    /**
     * Товар по url. Скрытый (status=0) открывается по прямому адресу, как на старом сайте (Webasyst
     * отдаёт такие страницы с кодом 200), но купить его нельзя: «Нет в наличии», кнопки выключены
     * (корзина и «1 клик» тоже не принимают status≠1). В каталоге, поиске и sitemap его нет.
     */
    private static function find(string $url): ?array
    {
        if ($url === '' || strlen($url) > 255) return null;
        $p = Products::byUrl($url);
        if (!$p || !in_array((int) $p['status'], [0, 1], true)) return null;
        $p['hidden'] = (int) $p['status'] === 0;
        if ($p['hidden']) $p['in_stock'] = 0;
        return $p;
    }

    /**
     * Адрес в другом регистре (/product/KROSSOVKI-X/ — MySQL сравнивает без учёта регистра) → 301 на
     * настоящий, как на старом сайте: без дублей страниц. Путь собран из url товара в базе, query сохраняется.
     */
    private static function toCanonical(string $path): Response
    {
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        return Response::redirect($path . ($qs !== '' ? '?' . $qs : ''), 301);
    }

    /**
     * Категория для SEO-шаблонов: основная категория товара (даже если скрыта),
     * а у товаров без категории — первая категория дерева (так делает старый сайт: «Акция»).
     */
    private static function seoCategory(array $p): ?array
    {
        $cid = (int) ($p['category_id'] ?? 0);
        if ($cid) {
            $c = Catalog::category($cid)
                ?? App::db()->row('SELECT id, parent_id, name, name_uk, url, seo_name, seo_name_uk, status FROM categories WHERE id = ?', [$cid]);
            if ($c) return $c;
        }
        $first = Catalog::categories();
        return $first ? reset($first) : null;
    }

    private static function seoVars(array $p, ?array $cat): array
    {
        $seoName = trim((string) ($p['seo_name'] ?? ''));
        $catSeo = trim((string) ($cat['seo_name'] ?? ''));
        return [
            'product' => [
                'name'         => $p['name'],
                'seo_name'     => $seoName !== '' ? $seoName : $p['name'],
                'format_price' => price_format($p['price']),
                'price'        => (string) round($p['price']),
                'sku'          => $p['sku'],
            ],
            'category' => [
                'name'     => (string) ($cat['name'] ?? ''),
                'seo_name' => $catSeo !== '' ? $catSeo : (string) ($cat['name'] ?? ''),
            ],
        ];
    }

    /** Хлебные крошки: путь основной категории (только активные) + товар */
    private static function crumbs(array $p): array
    {
        $items = [];
        foreach ($p['category_id'] ? Catalog::path((int) $p['category_id']) : [] as $c) {
            $items[] = ['name' => nice_case((string) $c['name']), 'url' => Catalog::categoryUrl($c)];
        }
        $items[] = ['name' => $p['name']];
        return $items;
    }

    /** schema.org/Product: цена — за пару, как в микроразметке старого сайта */
    private static function jsonLd(array $p, array $images, Seo $seo, int $rCount, float $rating, int $ratingCount): array
    {
        $abs = static fn(string $u): string => str_starts_with($u, 'http') ? $u : url($u);
        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $p['name'],
            'sku'      => $p['sku'] !== '' ? $p['sku'] : (string) $p['id'],
            'url'      => Lang::absUrl(url($p['link'])),
        ];
        $imgs = [];
        foreach (array_slice($images, 0, 6) as $im) {
            $imgs[] = $abs(Image::url($p['id'], (int) $im['id'], (string) $im['ext'], '750x0', (string) $im['filename']));
        }
        if (!$imgs && $p['image_id']) $imgs[] = $abs(Image::url($p['id'], (int) $p['image_id'], $p['image_ext'] ?: 'jpg', '750x0'));
        if ($imgs) $ld['image'] = $imgs;
        if ($seo->description !== '') $ld['description'] = $seo->description;
        if ($p['brand'] !== '') $ld['brand'] = ['@type' => 'Brand', 'name' => $p['brand']];
        $ld['offers'] = [
            '@type'         => 'Offer',
            'price'         => number_format($p['price'], 2, '.', ''),
            'priceCurrency' => 'UAH',
            'availability'  => $p['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'url'           => Lang::absUrl(url($p['link'])),
        ];
        if ($rating > 0 && $ratingCount > 0) {
            $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $rating, 'ratingCount' => $ratingCount, 'bestRating' => 5, 'worstRating' => 1];
            if ($rCount > 0) $ld['aggregateRating']['reviewCount'] = $rCount;
        }
        return $ld;
    }

    /** [кол-во одобренных отзывов, средняя оценка] — по индексу product (product_id, status, created_at) */
    private static function reviewStats(int $pid): array
    {
        $r = App::db()->row("SELECT COUNT(*) n, AVG(rate) a FROM product_reviews WHERE product_id = ? AND status = 'approved'", [$pid]);
        return [(int) ($r['n'] ?? 0), $r && $r['a'] !== null ? round((float) $r['a'], 1) : 0.0];
    }

    private static function reviewList(int $pid, int $offset, int $limit): array
    {
        return App::db()->all("SELECT id, name, title, text, rate, response, created_at FROM product_reviews
            WHERE product_id = ? AND status = 'approved' ORDER BY created_at DESC, id DESC LIMIT " . max(0, $offset) . ', ' . max(1, $limit), [$pid]);
    }

    /** Страница /product/{url}/reviews/. $flash — результат отправки формы без JS (не кэшируется). */
    private static function reviewsPage(array $p, ?array $flash = null, int $status = 200): Response
    {
        $pid = $p['id'];
        [$rCount, $rAvg] = self::reviewStats($pid);
        $page = $flash ? 1 : Request::page();
        $pg = new Paginator($rCount, self::REVIEWS_PER_PAGE, $page);
        if ($page > $pg->pages) return Response::notFound();

        // Мета как на живом сайте (Webasyst): title и H1 «{название} отзывы», keywords «{свои keywords | название, категория}, Отзывы»,
        // description — свой товара. Шаблон seo.product_review_meta_title у плагина SEO на старом сайте выключен —
        // применяется, только если в настройках включить seo.product_review_is_enabled = 1.
        $cat = self::seoCategory($p);
        $kw = trim((string) $p['meta_keywords']);
        if ($kw === '') $kw = $p['name'] . (!empty($cat['name']) ? ', ' . $cat['name'] : '');
        $default = t('{name} отзывы', ['name' => $p['name']]);
        $title = (string) Settings::get('seo.product_review_is_enabled', '') === '1'
            ? Seo::pick(null, 'seo.product_review_meta_title', self::seoVars($p, $cat)) : '';
        $seo = Seo::make($title !== '' ? $title : $default, trim((string) $p['meta_description']), $kw . ', ' . t('Отзывы'));
        $seo->h1 = $default;
        $base = $p['link'] . 'reviews/';
        $seo->canonical = url($base);
        $seo->paginate($pg->page, $base, $pg->pages);
        if ($pg->page > 1) $seo->prev = Lang::absUrl(url($pg->url($pg->page - 1)));
        if ($pg->hasNext()) $seo->next = Lang::absUrl(url($pg->url($pg->page + 1)));
        if ($p['image_id']) $seo->ogImage = Image::url($pid, (int) $p['image_id'], $p['image_ext'] ?: 'jpg', '750x0');

        $crumbs = self::crumbs($p);
        $crumbs[count($crumbs) - 1]['url'] = $p['link'];
        $crumbs[] = ['name' => t('Отзывы')];

        $html = View::render('front/product', [
            'seo'       => $seo,
            'p'         => $p,
            'mode'      => 'reviews',
            'crumbs'    => $crumbs,
            'reviews'   => $rCount ? self::reviewList($pid, $pg->offset, $pg->perPage) : [],
            'rCount'    => $rCount,
            'rAvg'      => $rAvg,
            'pager'     => $pg,
            'flash'     => $flash,
            'phones'    => Settings::json('phones', []),
            'freeBoxes' => (int) Settings::get('free_shipping_boxes', 20),
            'scripts'   => ['js/product.js'],
            'bodyClass' => 'pp-page',
        ]);
        return Response::html($html, $status);
    }

    /**
     * «Похожие товары»: из основной категории (через catalog_index) — ближайшие по цене
     * среди свежих товаров в наличии. Пул категории (id + цена) кэшируется на час.
     */
    private static function similarIds(array $p): array
    {
        $cid = (int) ($p['category_id'] ?? 0);
        if (!$cid) return [];
        $cands = [$cid];
        if (!Catalog::category($cid)) {                   // скрытая категория — берём родителя
            $parent = (int) App::db()->value('SELECT parent_id FROM categories WHERE id = ?', [$cid]);
            if ($parent) $cands[] = $parent;
        }
        $pool = [];
        foreach ($cands as $c) {
            $pool = Cache::remember('product.similar.' . $c, 3600, static function () use ($c) {
                $rows = App::db()->all('SELECT product_id, price FROM catalog_index
                    WHERE category_id = ? AND in_stock = 1 ORDER BY created_at DESC LIMIT ' . self::SIMILAR_POOL, [$c]);
                return array_map(static fn($r) => [(int) $r['product_id'], (float) $r['price']], $rows);
            });
            if (count($pool) > 1) break;
        }
        $price = (float) $p['price'];
        $pool = array_values(array_filter($pool, static fn($x) => $x[0] !== $p['id']));
        usort($pool, static fn($a, $b) => abs($a[1] - $price) <=> abs($b[1] - $price));   // сортировка стабильна: при равной цене — новее
        return array_column(array_slice($pool, 0, self::SIMILAR + 6), 0);   // с запасом: без фото отсеются
    }

    /** Карточки «Похожих товаров»: только с фото, не больше SIMILAR */
    private static function similar(array $p): array
    {
        $cards = array_filter(Products::cards(self::similarIds($p)), static fn($c) => !empty($c['image_id']));
        return array_slice(array_values($cards), 0, self::SIMILAR);
    }

    /** Очистка текста из формы: без тегов и управляющих символов, с ограничением длины */
    private static function clean(string $s, int $max, bool $multiline = false): string
    {
        $s = strip_tags(str_replace("\r", '', $s));
        $s = (string) preg_replace($multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', ' ', $s);
        $s = (string) preg_replace('/[ \t]+/u', ' ', $s);
        if ($multiline) $s = (string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace("/ *\n */", "\n", $s));
        $s = trim($s);
        return mb_substr($s, 0, $max);
    }
}
