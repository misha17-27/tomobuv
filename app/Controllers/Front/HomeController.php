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
use App\Services\Products;

/** Главная страница. Образец для остальных контроллеров витрины. */
final class HomeController
{
    public function index(): Response
    {
        $seo = Seo::make(
            (string) Settings::get('seo.home_page_meta_title', (string) Settings::get('site_title')),
            (string) Settings::get('seo.home_page_meta_description', ''),
            (string) Settings::get('seo.home_page_meta_keywords', '')
        );
        $seo->canonical = url('/');
        $seo->jsonLd[] = [
            '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => Settings::get('store_name', 'Tomobuv'),
            'url' => url('/'), 'logo' => url('/assets/img/logo.png'), 'telephone' => Settings::json('phones', [''])[0],
        ];

        // Блоки главной — id из кэша (10 мин), карточки одним запросом
        $newIds = Cache::remember('home.new', 600, static fn() => self::setIds('new', 24));
        $promoIds = Cache::remember('home.promo', 600, static fn() => self::setIds('promo', 16));
        $women = Catalog::categoryByUrl('zhyenskaya-obuv');
        $womenIds = $women ? Cache::remember('home.women', 600, static fn() => array_map('intval', App::db()->col(
            'SELECT product_id FROM catalog_index WHERE category_id = ? AND in_stock = 1 ORDER BY created_at DESC LIMIT 8', [(int) $women['id']]))) : [];

        $html = View::render('front/home', [
            'seo'      => $seo,
            'slides'   => Content::banners('home_slider'),
            'wide'     => Content::banners('home_wide'),
            'cats'     => array_values(array_filter(Catalog::roots(), static fn($c) => !empty($c['image']))),
            'new'      => Products::cards(array_slice($newIds, 0, 10)),
            'promo'    => Products::cards(array_slice($promoIds, 0, 10)),
            'women'    => $women,
            'womenProducts' => Products::cards($womenIds),
            'brands'   => array_slice(array_values(array_filter(Catalog::brands(), static fn($b) => !$b['hidden'] && $b['product_count'] > 0)), 0, 16),
            'posts'    => Cache::remember('home.posts', 3600, static fn() => App::db()->all(
                "SELECT url, title, title_uk, text_before_cut, text_before_cut_uk, text, text_uk, published_at FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 2")),
            'showAlpha' => true,
        ]);
        return Response::html($html)->cache(1800);
    }

    /** Набор товаров: ручной (product_set_items) или динамический по правилу (как «Новинки», «Промо» в Webasyst) */
    private static function setIds(string $id, int $limit): array
    {
        $set = App::db()->row('SELECT * FROM product_sets WHERE id = ?', [$id]);
        if (!$set) return $id === 'new' ? Products::latestIds($limit) : [];
        $limit = min($limit, (int) $set['limit'] ?: $limit);
        if ((int) $set['type'] === 0) return Products::setIds($id, $limit);
        $rule = (string) $set['rule'];
        $order = match (true) {
            str_starts_with($rule, 'compare_price') => 'compare_price DESC',
            str_starts_with($rule, 'price') => 'price ' . (str_contains($rule, 'ASC') ? 'ASC' : 'DESC'),
            str_starts_with($rule, 'rating') => 'rating DESC',
            str_starts_with($rule, 'total_sales') => 'sales DESC',
            default => 'created_at DESC',
        };
        $where = str_starts_with($rule, 'compare_price') ? ' AND compare_price > 0' : '';
        return array_map('intval', App::db()->col("SELECT id FROM products WHERE status = 1$where ORDER BY $order LIMIT $limit"));
    }
}
