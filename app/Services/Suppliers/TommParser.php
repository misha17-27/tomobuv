<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Разбор страниц tomm.ua (Webasyst Shop-Script, тема supreme): плитка списка (поиск /ru/search/?query=&page=N — весь каталог,
 * новые сверху) и карточка товара. Названия товаров у бренда — только артикул («60112A»), буква в конце — цвет.
 *
 * Плитка <div class="product  flexdiscount-product-wrap">: id товара бренда (input product_id — стабилен; код и адрес бренд
 * меняет), адрес /ru/{раздел}[/{подраздел}]/{slug}/ (slug с кодом не совпадает у ≈95 товаров — для кода не годится),
 * цена ящика (div.price-opt), цена пары (span.price, при скидке span.price-new и старая span.compare-at-price), таблица
 * listfeatures «Пар в ящике», «Размер», наличие div.stock yes|no (кнопка «В корзину» есть всегда), фото — data-images
 * (размер 970, самый крупный на страницах). Нет в наличии — обычно цена 0.
 * Карточка: H1 = код, «Бренд» (линейка: BiKi …), «Сезон», «Материал верх / подошвы / подкладки», все фото.
 */
final class TommParser
{
    /** Подписи характеристик RU/UA → поле (значения на сайте бренда не переводятся) */
    private const FEATURES = [
        'пар в ящике' => 'pairs', 'пар у ящику' => 'pairs', 'размер' => 'size', 'розмір' => 'size', 'бренд' => 'line', 'сезон' => 'season',
        'материал верх' => 'material', 'матеріал верх' => 'material', 'материал подошвы' => 'sole', 'матеріал підошви' => 'sole',
        'материал подкладки' => 'lining', 'матеріал підкладки' => 'lining',
    ];

    public static function text(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('~\s+~u', ' ', $s));
    }

    /** Число из «7 520 грн.», «1 042,50 грн» → float | null */
    public static function num(string $s): ?float
    {
        if (!preg_match('~(\d[\d\s\x{00A0}]*(?:[.,]\d+)?)~u', $s, $m)) return null;
        return (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $m[1]);
    }

    /** Размерный ряд для сравнения: «27 – 32» → «27-32» */
    public static function size(string $s): string
    {
        return (string) preg_replace(['/\s+/u', '/[‐‑‒–—−]/u'], ['', '-'], trim($s));
    }

    /**
     * Страница списка: ['items' => [плитка …], 'pages' => число страниц по пагинации (1 — одна), 'page' => текущая,
     * 'per_page' => выбранное «товаров на странице» (null — не видно), 'grid' => есть сетка товаров]
     */
    public static function listPage(string $html): array
    {
        $res = ['items' => [], 'pages' => 1, 'page' => 1, 'per_page' => null, 'grid' => str_contains($html, 'class="product-list ')];
        if (preg_match('~<ul class="pagination">(.*?)</ul>~s', $html, $m)) {
            if (preg_match_all('~[?&](?:amp;)?page=(\d+)~', $m[1], $pp)) $res['pages'] = max(1, ...array_map('intval', $pp[1]));
            if (preg_match('~<li class="selected"><a[^>]*>(\d+)</a>~', $m[1], $c)) $res['page'] = (int) $c[1];
            $res['pages'] = max($res['pages'], $res['page']);
        }
        if (preg_match('~id="per_page_dropdown"[^>]*>\s*<span>(\d+)</span>~', $html, $m)) $res['per_page'] = (int) $m[1];
        $parts = preg_split('~<div class="product\s+flexdiscount-product-wrap">~', $html) ?: [];
        array_shift($parts);
        foreach ($parts as $b) {
            $it = self::tile($b);
            if ($it) $res['items'][] = $it;
        }
        return $res;
    }

    /** Одна плитка списка */
    private static function tile(string $b): ?array
    {
        $p = ['id' => 0, 'code' => '', 'url' => '', 'root' => '', 'sub' => '', 'photos' => [], 'price_box' => null, 'price' => null, 'old_price' => null,
            'pairs' => null, 'size' => '', 'qty' => null, 'stock' => null, 'discount' => null];
        if (preg_match('~name="product_id" value="(\d+)"~', $b, $m) || preg_match('~data-product-id="(\d+)"~', $b, $m)) $p['id'] = (int) $m[1];
        if (preg_match('~<a class="img_middle" href="([^"]+)" title="([^"]*)"~', $b, $m)) {
            $p['url'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $p['code'] = self::text($m[2]);
        }
        if ($p['code'] === '' && preg_match('~<div class="product_name[^"]*">\s*<a[^>]*>(.*?)</a>~s', $b, $m)) $p['code'] = self::text($m[1]);
        if ($p['id'] <= 0 || $p['code'] === '') return null;
        [$p['root'], $p['sub']] = self::urlCategory($p['url']);
        if (preg_match("~data-images='([^']*)'~", $b, $m)) {
            foreach ((array) json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true) as $img) {
                $src = is_array($img) ? (string) ($img['src'] ?? '') : '';
                if ($src !== '') $p['photos'][] = $src;
            }
        }
        if (!$p['photos'] && preg_match('~data-url="(/wa-data/[^"]+)"~', $b, $m)) $p['photos'][] = $m[1];
        if (!$p['photos'] && preg_match('~src="(/wa-data/[^"]+?)\.\d+(?:x\d+)?\.(jpe?g|png|webp)"~', $b, $m)) $p['photos'][] = $m[1] . '.970.' . $m[2];
        $p['photos'] = array_values(array_unique($p['photos']));
        if (preg_match('~(?:Цена|Ціна) за ящик:\s*</span>\s*<span class="price[^"]*">([^<]+)<~u', $b, $m)) $p['price_box'] = self::num($m[1]);
        if (preg_match('~<div class="prices[^"]*">(.*?)</div>~s', $b, $m)) {
            $pr = $m[1];
            if (preg_match('~<span class="price price-new[^"]*">([^<]+)<~', $pr, $x) || preg_match('~<span class="price(?:\s[^"]*)?">([^<]+)<~', $pr, $x)) $p['price'] = self::num($x[1]);
            if (preg_match('~<span class="compare-at-price[^"]*">([^<]+)<~', $pr, $x)) $p['old_price'] = self::num($x[1]);
        }
        if (preg_match('~<div class="badge discount"><span>-(\d+)%</span>~', $b, $m)) $p['discount'] = (int) $m[1];
        if (preg_match('~name="quantity" value="(\d+)"~', $b, $m)) $p['qty'] = (int) $m[1];
        if (preg_match_all('~<td class="name">(.*?)</td>\s*<td class="value">(.*?)</td>~s', $b, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $f) {
                $k = self::FEATURES[mb_strtolower(self::text($f[1]))] ?? null;
                $v = self::text($f[2]);
                if ($k === 'pairs' && preg_match('~\d+~', $v, $d)) $p['pairs'] = (int) $d[0];
                if ($k === 'size') $p['size'] = self::size($v);
            }
        }
        if (preg_match('~<div class="stock (yes|no)">~', $b, $m)) $p['stock'] = $m[1] === 'yes';
        return $p;
    }

    /** Раздел и подраздел по адресу товара: /ru/kedy/nosochki/x/ → ['kedy', 'nosochki']; /ru/tapochki/x/ → ['tapochki', ''] */
    public static function urlCategory(string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = (string) preg_replace('~^/(?:ru|uk|ua)(?=/)~', '', $path);
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn($x) => $x !== ''));
        if (count($parts) < 2) return ['', ''];
        array_pop($parts);                                                  // slug товара
        return [(string) $parts[0], (string) ($parts[1] ?? '')];
    }

    /**
     * Карточка товара: ['id', 'code', 'price', 'available' (true | false | null), 'features' => [поле => значение] (line, season,
     * material, sole, lining, pairs, size), 'photos' => [адреса 970 …]]; null — не карточка (сменился шаблон).
     * $pid — id товара у бренда (из списка; 0 — из формы «В корзину»): фото — только из папки этого товара
     * (/wa-data/public/shop/products/34/43/24334/images/…) — на карточке есть и плитки «похожих» товаров со своими фото.
     */
    public static function card(string $html, int $pid = 0): ?array
    {
        if (!preg_match('~<h1 class="product-name"[^>]*>(.*?)</h1>~s', $html, $m)) return null;
        if ($pid <= 0 && preg_match('~id="cart-form".*?name="product_id" value="(\d+)"~s', $html, $f)) $pid = (int) $f[1];
        $out = ['id' => $pid, 'code' => self::text($m[1]), 'price' => null, 'available' => null, 'features' => [], 'photos' => []];
        if (preg_match('~<span class="artnumber">([^<]+)</span>~', $html, $m)) $out['code'] = self::text($m[1]);
        if (preg_match('~itemprop="price" content="([\d.]+)"~', $html, $m)) $out['price'] = (float) $m[1];
        if (preg_match('~itemprop="availability" href="https?://schema\.org/(\w+)"~', $html, $m)) $out['available'] = $m[1] === 'InStock';
        if (preg_match('~id="product-features">(.*?)</table>~s', $html, $t) && preg_match_all('~<td class="name">(.*?)</td>\s*<td class="value">(.*?)</td>~s', $t[1], $mm, PREG_SET_ORDER)) {
            foreach ($mm as $f) {
                $k = self::FEATURES[mb_strtolower(self::text($f[1]))] ?? null;
                $v = self::text($f[2]);
                if ($k !== null && $v !== '') $out['features'][$k] = $k === 'size' ? self::size($v) : $v;
            }
        }
        // фото этого товара: самые крупные на странице (.970), по порядку галереи, без повторов; нет .970 — .750x0.
        // id товара не известен — фото не берутся (останутся фото из списка)
        if ($pid > 0) {
            $dir = '/wa-data/public/shop/products/\d{2}/\d{2}/' . $pid . '/images/(\d+)/\1';
            if (preg_match_all('~' . $dir . '\.970\.(?:jpe?g|png|webp)~', $html, $mm)) $out['photos'] = array_values(array_unique($mm[0]));
            elseif (preg_match_all('~' . $dir . '\.750x0\.(?:jpe?g|png|webp)~', $html, $mm)) $out['photos'] = array_values(array_unique($mm[0]));
        }
        return $out;
    }
}
