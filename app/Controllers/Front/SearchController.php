<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Cache;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\View;
use App\Services\Listing;
use App\Services\Products;

/**
 * Поиск /search/?query=… и списки старой темы Webasyst на том же адресе:
 *   /search/?_balance_type=favorites — избранное (cookie fav), /search/?_balance_type=viewed — просмотренные (cookie viewed).
 * /search/suggest/?q=… — подсказки для строки поиска в шапке (JSON для app.js).
 *
 * Кэш: результаты поиска — кэш страниц 10 минут (ключ включает query); избранное и просмотренные —
 * персональные, без кэша. Пустой /search/ тоже не кэшируется: _balance_type не входит в ключ кэша страниц,
 * и закэшированный /search/ подменил бы избранное.
 */
final class SearchController
{
    private const SUGGEST_LIMIT = 8;

    public function index(): Response
    {
        // ?query[]=… и т.п. — не строка: на обычный адрес поиска (шапка выводит query как строку)
        foreach (['query', '_balance_type'] as $k) {
            if (isset($_GET[$k]) && !is_scalar($_GET[$k])) return Response::redirect('/search/', 301);
        }
        $type = (string) ($_GET['_balance_type'] ?? '');
        if ($type === 'favorites' || $type === 'viewed') return $this->balance($type);

        $query = Listing::normalizeQuery(is_scalar($_GET['query'] ?? null) ? (string) $_GET['query'] : '');
        $L = Listing::fromRequest('/search/', 'create_datetime', 'desc', $query !== '' ? ['query' => $query] : [], false);
        if ($query !== '') {
            $L->runSearch($query);      // ?page= за концом списка — пустая страница 200, как на старом сайте
        } else {
            $L->runIds([]);
        }
        $products = Products::cards($L->ids);

        if (CategoryController::isAjax()) {
            $r = Response::json($L->ajaxPayload(View::render('front/partials/listing', ['L' => $L, 'products' => $products, 'itemsOnly' => true], null)));
            return ($query !== '' && !$L->outOfRange) ? $r->cache(600) : $r;
        }

        // Title как на старом сайте: «{запрос} — {site_title}», на ?page=N — без суффикса страницы
        $site = (string) Settings::get('site_title', 'Tomobuv');
        $seo = Seo::make($query !== '' ? $query . ' — ' . $site : t('Поиск') . ' — ' . $site);
        $seo->h1 = $query !== '' ? t('По запросу «{query}»', ['query' => $query]) : t('Поиск по каталогу');
        $seo->robots = 'noindex, follow';

        $html = View::render('front/search', [
            'seo' => $seo, 'L' => $L, 'products' => $products, 'query' => $query, 'mode' => 'search',
            'scripts' => ['js/catalog.js'],
        ]);
        $r = Response::html($html);
        return ($query !== '' && !$L->outOfRange) ? $r->cache(600) : $r;
    }

    /** Избранное и просмотренные: id из cookie, порядок — как в cookie (последние добавленные сверху) */
    private function balance(string $type): Response
    {
        $fav = $type === 'favorites';
        $ids = Listing::cookieIds($fav ? 'fav' : 'viewed', $fav ? Listing::MAX_FAV : Listing::MAX_VIEWED);
        $L = Listing::fromRequest('/search/', 'create_datetime', 'desc', ['_balance_type' => $type], false);
        if (!$L->runIds($ids)) {
            // страница вне списка (после удаления товаров) — на первую
            return Response::redirect(Listing::build('/search/', ['_balance_type' => $type]));
        }
        $products = Products::cards($L->ids);

        if (CategoryController::isAjax()) {
            return Response::json($L->ajaxPayload(View::render('front/partials/listing', ['L' => $L, 'products' => $products, 'itemsOnly' => true], null)));
        }

        $title = $fav ? t('Избранное') : t('Просмотренные товары');
        $seo = Seo::make($title);
        $seo->h1 = $title;
        $seo->robots = 'noindex, follow';

        $html = View::render('front/search', [
            'seo' => $seo, 'L' => $L, 'products' => $products, 'query' => '', 'mode' => $type,
            'scripts' => ['js/catalog.js'],
        ]);
        return Response::html($html);     // персональная страница — без кэша
    }

    /** Подсказки: {items:[{name,url,img,brand,size,price}], total} — до 8 товаров, кэш данных 10 минут */
    public function suggest(): Response
    {
        $q = Listing::normalizeQuery(is_scalar($_GET['q'] ?? null) ? (string) $_GET['q'] : '');
        if (mb_strlen($q) < 2) return Response::json(['items' => [], 'total' => 0]);
        $data = Cache::remember('search.suggest.' . md5(mb_strtolower($q)), 600, static function () use ($q) {
            $ids = Listing::searchIds($q);
            $items = [];
            foreach (Products::cards(array_slice($ids, 0, self::SUGGEST_LIMIT)) as $p) {
                $items[] = [
                    'name' => (string) $p['name'], 'url' => $p['link'], 'img' => $p['img_small'],
                    'brand' => (string) $p['brand'], 'size' => (string) $p['size'], 'price' => (float) $p['price'],
                ];
            }
            return ['items' => $items, 'total' => count($ids)];
        });
        return Response::json($data)->header('Cache-Control', 'private, max-age=60');
    }
}
