<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Cache;
use App\Core\Lang;
use App\Core\Response;
use App\Core\Settings;
use PDO;

/**
 * robots.txt и XML-карты сайта в формате старого сайта (Webasyst):
 *   /sitemap.xml                       — индекс: sitemap-blog.xml, sitemap-site.xml, sitemap-shop-1..N.xml,
 *                                        украинские sitemap-ua-blog.xml, sitemap-ua-site.xml, sitemap-ua-shop-1..N.xml + карты фото
 *   /sitemap-shop-N.xml                — по 10 000 адресов подряд: главная, категории, бренды, товары, в конце
 *                                        страницы магазина, /sitemap/ и /reviews/ (порядок и разбиение как на живом)
 *   /sitemap-site.xml                  — страницы /pages/… (приложение «Сайт»)
 *   /sitemap-blog.xml                  — /blog/ и статьи
 *   /sitemap-ua-*.xml                  — те же адреса украинской версии (/ua/…), то же разбиение на файлы
 *   /sitemap-shop-product_images-N.xml — фото товаров (Google Картинки), по 10 000 фото
 * Украинская версия — отдельными файлами, а не xhtml:link в каждом <url>: связь языков (hreflang ru-UA / uk-UA /
 * x-default) уже есть в <head> каждой страницы, Google достаточно одного способа; отдельные файлы не раздувают
 * карты в 3–4 раза и оставляют русские файлы в прежнем виде (история в панелях вебмастеров сохраняется).
 * Товары выбираются по первичному ключу порциями без загрузки всей таблицы в память.
 * robots.txt и карты есть только в корне сайта: /ua/robots.txt, /ua/sitemap*.xml → 404.
 */
final class SitemapController
{
    private const LIMIT = 10000;
    private const TTL = 21600;
    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** Язык адресов в формируемом файле: ru — /…, uk — /ua/… */
    private static string $lang = 'ru';

    public function robots(): Response
    {
        if (Lang::isUk()) return Response::notFound();
        $base = rtrim(url('/'), '/');
        $custom = trim((string) Settings::get('robots_txt', ''));   // можно переопределить в настройках
        $txt = $custom !== '' ? $custom : implode("\n", [
            'User-agent: *',
            'Disallow: /mailer/unsubscribe/',
            'Disallow: /my/',
            'Disallow: /checkout/',
            'Disallow: /order/',
            'Disallow: /admin/',
            'Disallow: /cart/json/',
            'Disallow: /search/suggest/',
            'Disallow: /products/cards/',
            'Disallow: /ua/my/',
            'Disallow: /ua/checkout/',
            'Disallow: /ua/order/',
            'Disallow: /ua/cart/json/',
            'Disallow: /ua/search/suggest/',
            '',
            'Host: ' . $base,
        ]);
        if (!preg_match('/^Sitemap:/mi', $txt)) $txt .= "\nSitemap: " . $base . '/sitemap.xml';
        return Response::text($txt . "\n", 'text/plain; charset=utf-8')->cache(86400);
    }

    public function index(): Response
    {
        if (Lang::isUk()) return Response::notFound();
        $shop = $this->shopFiles();
        $names = ['blog', 'site'];
        for ($i = 1; $i <= $shop; $i++) $names[] = 'shop-' . $i;
        array_push($names, 'ua-blog', 'ua-site');
        for ($i = 1; $i <= $shop; $i++) $names[] = 'ua-shop-' . $i;
        for ($i = 1, $n = $this->imageFiles(); $i <= $n; $i++) $names[] = 'shop-product_images-' . $i;
        return $this->sitemapIndex($names, date('c'));
    }

    /** sitemap-{name}.xml; украинские — sitemap-ua-{blog|site|shop-N}.xml */
    public function part(string $name): Response
    {
        if (Lang::isUk()) return Response::notFound();
        self::$lang = 'ru';
        if (preg_match('/^ua-(blog|site|shop-[1-9]\d{0,3})$/', $name, $m)) {
            self::$lang = 'uk';
            $name = $m[1];
        }
        return match (true) {
            $name === 'blog' => $this->blog(),
            $name === 'site' => $this->site(),
            (bool) preg_match('/^shop-([1-9]\d{0,3})$/', $name, $m) => $this->shop((int) $m[1]),
            // вложенный индекс, как на старом сайте (на случай, если он добавлен в панели вебмастера)
            $name === 'shop-product_images' => $this->sitemapIndex(array_map(static fn($i) => 'shop-product_images-' . $i,
                range(1, max(1, $this->imageFiles()))), date('c')),
            (bool) preg_match('/^shop-product_images-([1-9]\d{0,3})$/', $name, $m) => $this->images((int) $m[1]),
            default => Response::notFound(),
        };
    }

    // ------------------------------------------------------------------ части карты

    private function blog(): Response
    {
        $rows = App::db()->all("SELECT url, published_at, updated_at FROM blog_posts
            WHERE status = 'published' AND published_at <= NOW() ORDER BY published_at DESC, id DESC");
        $x = $this->open();
        $last = $rows ? max(array_map(static fn($r) => self::mod($r['published_at'], $r['updated_at']), $rows)) : null;
        $x .= self::entry('/blog/', $last, 'daily', '1');
        foreach ($rows as $r) $x .= self::entry('/blog/' . $r['url'] . '/', self::mod($r['published_at'], $r['updated_at']), 'weekly', '0.5');
        return $this->close($x);
    }

    private function site(): Response
    {
        $x = $this->open();
        foreach (App::db()->all("SELECT url, updated_at FROM pages WHERE status = 1 AND url LIKE 'pages/%' ORDER BY sort, id") as $r) {
            $x .= self::entry('/' . $r['url'], $r['updated_at'], 'monthly', '0.6');
        }
        return $this->close($x);
    }

    /** Файл N: адреса с позиции (N-1)*10000 из последовательности [начало][товары][хвост] */
    private function shop(int $n): Response
    {
        $c = $this->counts();
        if ($n > $this->shopFiles()) return Response::notFound();
        $from = ($n - 1) * self::LIMIT;
        $to = $from + self::LIMIT;
        $x = $this->open();

        // 1) главная, категории, бренды (их немного — целиком из базы)
        if ($from < $c['head']) {
            $head = $this->head();
            foreach (array_slice($head, $from, min($to, $c['head']) - $from) as $u) $x .= $u;
        }
        // 2) товары: смещение внутри списка товаров и сколько взять
        $pFrom = max(0, $from - $c['head']);
        $pTo = min($c['products'], $to - $c['head']);
        if ($pTo > $pFrom) {
            $db = App::db();
            $startId = $pFrom > 0 ? (int) $db->value('SELECT id FROM products WHERE status = 1 ORDER BY id LIMIT 1 OFFSET ' . $pFrom) : 0;
            $this->stream('SELECT url, created_at, updated_at FROM products WHERE status = 1 AND id >= ? ORDER BY id LIMIT ' . ($pTo - $pFrom),
                [$startId], static function (array $r) use (&$x) {
                    $x .= self::entry('/product/' . $r['url'] . '/', self::mod($r['created_at'], $r['updated_at']), 'weekly', '0.8');
                });
        }
        // 3) хвост: страницы магазина, HTML-карта, отзывы
        $tFrom = max(0, $from - $c['head'] - $c['products']);
        $tTo = $to - $c['head'] - $c['products'];
        if ($tTo > 0 && $tFrom < $c['tail']) {
            foreach (array_slice($this->tail(), $tFrom, $tTo - $tFrom) as $u) $x .= $u;
        }
        return $this->close($x);
    }

    /**
     * Фото товаров, как на старом сайте: файл N — фото с (N-1)*10000 по 10 000 штук (не товаров), порядок — товары по id,
     * фото товара от новых к старым (i.id DESC); фото одного товара подряд — в одном <url>, товар на стыке файлов — в обоих.
     */
    private function images(int $n): Response
    {
        if ($n > $this->imageFiles()) return Response::notFound();
        $x = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<urlset xmlns="' . self::NS . '" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        $cur = null;
        $this->stream('SELECT p.id, p.url, i.id AS image_id, i.ext FROM products p JOIN product_images i ON i.product_id = p.id
            WHERE p.status = 1 ORDER BY p.id, i.id DESC LIMIT ' . self::LIMIT . ' OFFSET ' . (($n - 1) * self::LIMIT),
            [], static function (array $r) use (&$x, &$cur) {
                if ($cur !== $r['id']) {
                    if ($cur !== null) $x .= "  </url>\n";
                    $x .= '  <url>' . "\n" . '    <loc>' . self::xml(url('/product/' . $r['url'] . '/')) . "</loc>\n";
                    $cur = $r['id'];
                }
                $src = \App\Core\Image::url((int) $r['id'], (int) $r['image_id'], (string) $r['ext'], '970');
                $x .= '    <image:image><image:loc>' . self::xml(str_starts_with($src, 'http') ? $src : url($src)) . "</image:loc></image:image>\n";
            });
        if ($cur !== null) $x .= "  </url>\n";
        return $this->close($x);
    }

    // ------------------------------------------------------------------ данные

    /** Главная, категории (по дереву), бренды с товарами — готовые <url> */
    private function head(): array
    {
        $db = App::db();
        $out = [self::entry('/', $db->value('SELECT MAX(created_at) FROM products WHERE status = 1'), 'always', '1')];
        foreach ($db->all('SELECT url, created_at, updated_at FROM categories WHERE status = 1 ORDER BY lft, sort, id') as $r) {
            $out[] = self::entry('/category/' . $r['url'] . '/', self::mod($r['created_at'], $r['updated_at']), 'weekly', '0.6');
        }
        // дата бренда — последнее поступление его товаров (индекс brand: brand_id, status, created_at)
        $mod = $db->pairs('SELECT brand_id, MAX(created_at) FROM products WHERE status = 1 AND brand_id IS NOT NULL GROUP BY brand_id');
        foreach ($db->all('SELECT id, url FROM brands WHERE hidden = 0 AND product_count > 0 ORDER BY name, id') as $r) {
            $out[] = self::entry('/brand/' . urlencode((string) $r['url']) . '/', $mod[$r['id']] ?? null, 'daily', '0.2');
        }
        return $out;
    }

    /** Страницы магазина (/o-kompanii/ …), HTML-карта сайта, отзывы о магазине */
    private function tail(): array
    {
        $out = [];
        foreach (App::db()->all("SELECT url, updated_at FROM pages WHERE status = 1 AND url NOT LIKE 'pages/%' ORDER BY sort, id") as $r) {
            $out[] = self::entry('/' . $r['url'], $r['updated_at'], 'monthly', '0.6');
        }
        $out[] = self::entry('/sitemap/', null, 'daily', '0.8');
        $out[] = self::entry('/reviews/', null, 'weekly', '0.1');
        return $out;
    }

    /** Количества для разбиения на файлы (кэш 6 ч; сбрасывается вместе со всем кэшем после правок в админке) */
    private function counts(): array
    {
        return Cache::remember('sitemap.counts', self::TTL, static function () {
            $db = App::db();
            return [
                'head'     => 1 + (int) $db->value('SELECT COUNT(*) FROM categories WHERE status = 1')
                    + (int) $db->value('SELECT COUNT(*) FROM brands WHERE hidden = 0 AND product_count > 0'),
                'products' => (int) $db->value('SELECT COUNT(*) FROM products WHERE status = 1'),
                'tail'     => (int) $db->value("SELECT COUNT(*) FROM pages WHERE status = 1 AND url NOT LIKE 'pages/%'") + 2,
            ];
        });
    }

    private function shopFiles(): int
    {
        $c = $this->counts();
        return max(1, (int) ceil(($c['head'] + $c['products'] + $c['tail']) / self::LIMIT));
    }

    /** Файлов с фото: по 10 000 фото активных товаров в файле */
    private function imageFiles(): int
    {
        $images = (int) Cache::remember('sitemap.images', self::TTL, static fn() => (int) App::db()->value(
            'SELECT COUNT(*) FROM products p JOIN product_images i ON i.product_id = p.id WHERE p.status = 1'));
        return (int) ceil($images / self::LIMIT);
    }

    /** Построчное чтение без буферизации всего результата (товаров десятки тысяч) */
    private function stream(string $sql, array $params, callable $fn): void
    {
        $pdo = App::db()->pdo();
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $st = App::db()->query($sql, $params);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) $fn($r);
            $st->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    // ------------------------------------------------------------------ XML

    private function sitemapIndex(array $names, string $mod): Response
    {
        $x = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<sitemapindex xmlns="' . self::NS . '">' . "\n";
        foreach ($names as $name) {
            $x .= "  <sitemap>\n    <loc>" . self::xml(url('/sitemap-' . $name . '.xml')) . "</loc>\n    <lastmod>" . $mod . "</lastmod>\n  </sitemap>\n";
        }
        return Response::text($x . '</sitemapindex>' . "\n", 'application/xml; charset=utf-8')->cache(self::TTL);
    }

    private function open(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<urlset xmlns="' . self::NS . '">' . "\n";
    }

    private function close(string $x): Response
    {
        return Response::text($x . '</urlset>' . "\n", 'application/xml; charset=utf-8')->cache(self::TTL);
    }

    /** <url> адреса на языке текущего файла (self::$lang: ru — /…, uk — /ua/…) */
    private static function entry(string $path, ?string $mod, string $freq, string $prio): string
    {
        return "  <url>\n    <loc>" . self::xml(url(Lang::path($path, self::$lang))) . "</loc>\n"
            . ($mod ? '    <lastmod>' . date('c', strtotime($mod)) . "</lastmod>\n" : '')
            . '    <changefreq>' . $freq . "</changefreq>\n    <priority>" . $prio . "</priority>\n  </url>\n";
    }

    /** Дата изменения: большая из двух (updated_at может быть пустым) */
    private static function mod(?string $a, ?string $b): ?string
    {
        if (!$b) return $a;
        if (!$a) return $b;
        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
