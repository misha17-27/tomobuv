<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Cache;
use App\Core\Lang;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\View;
use App\Services\Catalog;
use App\Services\Content;

/**
 * Информационные страницы (таблица pages): /o-kompanii/, /dostavka-i-oplata/, /kontakty/ …
 * и их дубли /pages/o-kompanii/ … со своими мета-тегами (на старом сайте это были страницы
 * магазина и страницы приложения «Сайт»). Маршрут /{path*} — последний в routes.php:
 * всё, чего нет в pages, получает 404 (ErrorController перед этим проверяет таблицу redirects).
 * /sitemap/ — HTML-карта сайта (на старом сайте — плагин htmlmap).
 * Украинская версия (/ua/…): name/h1/title/content… берутся из колонок *_uk (DB::$localize), если заполнены.
 */
final class PageController
{
    public function show(string $path): Response
    {
        // Страницы всегда со слэшем на конце (адреса без слэша редиректит index.php)
        if (!str_ends_with($path, '/') || strlen($path) > 255) return Response::notFound();
        if ($path === 'sitemap/') return $this->htmlMap();

        $id = self::urlMap()[$path] ?? null;
        $page = $id ? App::db()->row('SELECT * FROM pages WHERE id = ? AND status = 1', [$id]) : null;
        if (!$page) return Response::notFound();

        $slug = self::slug($path);              // 'pages/kontakty/' → 'kontakty/'
        $html = self::prepareHtml((string) $page['content']);
        $contacts = $slug === 'kontakty/' ? self::contacts($html) : null;
        if ($contacts) {
            // Старая Яндекс-карта (скрипт конструктора) заменена картой Google в блоке контактов
            $html = (string) preg_replace('#<script[^>]*api-maps\.yandex\.[^>]*>.*?</script>#is', '', $html);
        }

        $html = View::render('front/page', [
            'seo'      => self::seo($page, $path),
            'page'     => ['name' => $page['name'], 'h1' => trim((string) $page['h1']) ?: $page['name'], 'html' => $html],
            'menu'     => self::menu($slug),
            'contacts' => $contacts,
            'posts'    => $slug === 'stati/' ? BlogController::latest(24) : [],
            'map'      => null,
        ]);
        return Response::html($html)->cache(86400);
    }

    /** [url => id] активных страниц — чтобы случайные адреса (боты, опечатки) не ходили в базу */
    private static function urlMap(): array
    {
        return Cache::remember('content.page_urls', 86400, static fn() =>
            App::db()->pairs('SELECT url, id FROM pages WHERE status = 1'));
    }

    private static function slug(string $path): string
    {
        return str_starts_with($path, 'pages/') ? substr($path, 6) : $path;
    }

    /**
     * Основной адрес дубля: /pages/o-kompanii/ (страница приложения «Сайт» на старом сайте) → /o-kompanii/, если такая
     * страница есть и включена; иначе null. $active — адреса включённых страниц (url => id), как urlMap().
     * Этим же пользуется SEO-обзор (дубль с canonical не считается отдельной страницей в поиске).
     */
    public static function mainPath(string $url, array $active): ?string
    {
        $url = ltrim($url, '/');
        if (!str_starts_with($url, 'pages/')) return null;
        $main = self::slug($url);
        return $main !== '' && isset($active[$main]) ? '/' . $main : null;
    }

    /**
     * SEO как на старом сайте: title = pages.title, иначе шаблон seo.page_meta_title
     * («{$page.name} | интернет-магазин {$store_info.name}»; на /ua/ — seo.page_meta_title.uk);
     * description — свой, иначе отрывок текста страницы (Seo::excerpt: первые связные абзацы, 120–160 символов),
     * иначе seo.page_meta_description (страница-список без связного текста — /stati/); keywords — свои, иначе seo.page_meta_keywords.
     */
    private static function seo(array $page, string $path): Seo
    {
        $vars = ['page' => ['name' => (string) $page['name'], 'title' => '']];
        $tpl = (string) Settings::get('seo.page_is_enabled', '1') !== '0';   // шаблоны SEO для страниц включены
        $pick = static function ($own, string $key) use ($tpl, $vars): string {
            $own = trim((string) $own);
            return $own !== '' || !$tpl ? $own : Seo::pick('', $key, $vars);
        };
        $title = $pick($page['title'], 'seo.page_meta_title');
        $desc = trim((string) $page['meta_description']);
        if ($desc === '') $desc = Seo::excerpt((string) $page['content']);
        $seo = Seo::make($title !== '' ? $title : (string) $page['name'],
            $desc !== '' ? $desc : $pick('', 'seo.page_meta_description'),
            $pick($page['meta_keywords'], 'seo.page_meta_keywords'));
        // canonical: заданный в админке, иначе у дубля /pages/x/ — основная страница /x/ (та же страница магазина, см. mainPath),
        // иначе сама страница. На старом сайте canonical не было — это улучшение; для /ua/ layout сам подставит украинский адрес.
        $canon = trim((string) $page['canonical']);
        if ($canon === '') $canon = (string) self::mainPath($path, self::urlMap());
        $seo->canonical = $canon !== '' ? (preg_match('#^https?://#', $canon) ? $canon : url($canon)) : url('/' . $path);
        $seo->ogImage = self::ogImage((string) $page['content']);
        return $seo;
    }

    /** Адреса картинок из HTML: свой домен (https://tomobuv.com.ua/…) → относительный путь */
    public static function imageSrcs(string $html): array
    {
        if (!preg_match_all('#<img\b[^>]*?\bsrc=(["\'])([^"\']+)\1#i', $html, $m)) return [];
        return array_values(array_unique(array_map(static fn($s) => (string) preg_replace('#^https?://(?:www\.)?tomobuv\.com\.ua(?=/)#i', '',
            trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $m[2])));
    }

    /** Локальный файл картинки существует (часть картинок старого контента потеряна ещё на старом сайте) */
    public static function localFile(string $src): ?string
    {
        if (!str_starts_with($src, '/') || str_starts_with($src, '//') || str_contains($src, '..')) return null;
        $file = PUBLIC_DIR . rawurldecode((string) parse_url($src, PHP_URL_PATH));
        return is_file($file) ? $file : null;
    }

    /**
     * og:image — первая картинка текста, которая реально есть на сервере и не меньше 200 px
     * (битые ссылки и иконки мессенджеров в превью соцсетей не нужны); нет такой — og:image не выводится.
     */
    public static function ogImage(string $html): ?string
    {
        foreach (self::imageSrcs($html) as $src) {
            $file = self::localFile($src);
            $size = $file ? @getimagesize($file) : false;
            if ($size && $size[0] >= 200) return $src;
        }
        return null;
    }

    /**
     * HTML из базы для вывода:
     *  - абсолютные ссылки на свой домен (https://tomobuv.com.ua/…) → относительные (работают локально и получают /ua);
     *  - внутренние ссылки, для которых есть 301 в таблице redirects (старые /pages/<статья>), — сразу на новый адрес;
     *  - /wa-data/… (content_html) и /uploads/… при локальной разработке без копии файлов ведут на живой сайт.
     */
    public static function prepareHtml(string $html): string
    {
        if ($html === '') return '';
        $html = (string) preg_replace('#\b(href|src)=(["\'])https?://(?:www\.)?tomobuv\.com\.ua(?=[/"\'])/?#i', '$1=$2/', $html);
        $html = self::resolveRedirects($html);
        $html = content_html($html);
        // карты и видео из старого контента (iframe) грузятся только при прокрутке к ним
        $html = (string) preg_replace('#<iframe(?![^>]*\bloading=)#i', '<iframe loading="lazy"', $html);
        // битые картинки старого контента (часть файлов потеряна ещё на старом сайте) не оставляют пустых рамок
        $html = (string) preg_replace('#<img(?![^>]*\bonerror=)#i', '<img onerror="this.style.display=\'none\'"', $html);
        $remote = rtrim((string) App::config('images.remote_base', ''), '/');
        if ($remote === '' || !str_contains($html, '/uploads/')) return $html;
        return (string) preg_replace_callback('#(src|href)=(["\'])(/uploads/[^"\']+)\2#i', static function ($m) use ($remote) {
            $path = html_entity_decode($m[3]);
            return is_file(PUBLIC_DIR . rawurldecode($path)) ? $m[0] : $m[1] . '=' . $m[2] . e($remote . $path) . $m[2];
        }, $html);
    }

    /** Ссылки href="/…" из текста, у которых есть 301 в redirects, заменяются конечным адресом (один запрос) */
    private static function resolveRedirects(string $html): string
    {
        if (!preg_match_all('#\bhref=(["\'])(/(?!wa-data/|uploads/)[^"\'\#?]*)\1#i', $html, $m)) return $html;
        $keys = [];
        foreach ($m[2] as $href) {
            $p = html_entity_decode($href);
            $keys[str_ends_with($p, '/') ? $p : $p . '/'] = true;
        }
        $keys = array_slice(array_keys($keys), 0, 200);
        [$ph, $vals] = App::db()->in($keys);
        $map = App::db()->pairs("SELECT from_url, to_url FROM redirects WHERE from_url IN ($ph)", $vals);
        if (!$map) return $html;
        return (string) preg_replace_callback('#\bhref=(["\'])(/(?!wa-data/|uploads/)[^"\'\#?]*)\1#i', static function ($x) use ($map) {
            $p = html_entity_decode($x[2]);
            $to = $map[str_ends_with($p, '/') ? $p : $p . '/'] ?? null;
            return $to !== null ? 'href=' . $x[1] . e((string) $to) . $x[1] : $x[0];
        }, $html);
    }

    /** Меню инфо-страниц (Content::menuPages) + блог, отзывы и карта сайта; текущая страница подсвечена */
    private static function menu(string $current): array
    {
        $items = [];
        foreach (Content::menuPages() as $p) {
            $items[] = ['url' => '/' . $p['url'], 'name' => $p['name'], 'on' => $p['url'] === $current];
        }
        $items[] = ['url' => '/blog/', 'name' => t('Блог'), 'on' => false];
        $items[] = ['url' => '/reviews/', 'name' => t('Отзывы о магазине'), 'on' => false];
        // lurl(): Lang::rewriteLinks пропускает всё, что начинается с «sitemap» (ради sitemap.xml), — префикс /ua ставим сами
        $items[] = ['url' => lurl('/sitemap/'), 'name' => t('Карта сайта'), 'on' => $current === 'sitemap/'];
        return $items;
    }

    /** Данные блока «Контакты» из настроек (на /ua/ — варианты настроек «.uk», если заданы) */
    private static function contacts(string $html): array
    {
        $icons = ['viber' => 'viber', 'telegram' => 'tg', 'instagram' => 'ig', 'facebook' => 'fb'];
        $social = [];
        foreach (['viber' => 'Viber', 'telegram' => 'Telegram', 'instagram' => 'Instagram', 'facebook' => 'Facebook'] as $k => $name) {
            $u = trim((string) Settings::get('social_' . $k, ''));
            if ($u !== '' && preg_match('#^(https?://|viber://|tg://)#i', $u)) $social[] = ['url' => $u, 'name' => $name, 'icon' => $icons[$k]];
        }
        return [
            'phones'  => array_values(array_filter(array_map('strval', Settings::json('phones', [])))),
            'hours'   => (string) Settings::get('work_hours', ''),
            'email'   => (string) Settings::get('store_email', ''),
            'address' => (string) Settings::get('address', ''),
            'social'  => $social,
            // Поиск Google Карт: «Одесса, Промрынок 7 км» Google понимает как маршрут «Моё местоположение → рынок»
            // (пустая карта), а карточка магазина в Google («Tomobuv shoes», та же, что в карте старого сайта) даёт метку
            'map'     => (string) (Settings::get('map_query') ?: 'Tomobuv shoes, Одесса'),
            'place'   => (string) Settings::get('address', '') ?: 'Одесса, Промрынок 7 км',
            'mapLang' => Lang::isUk() ? 'uk' : 'ru',
            // На /pages/kontakty/ в тексте уже есть своя карта Google — вторую не показываем
            'showMap' => !preg_match('#<iframe[^>]+google\.[a-z.]+/maps#i', $html),
        ];
    }

    /** HTML-карта сайта: инфо-страницы, каталог, бренды, статьи — всё из кэша справочников */
    private function htmlMap(): Response
    {
        // title/description — настройки seo.sitemap_meta_* (на /ua/ — «.uk»), пусто — «Карта сайта — Tomobuv» без описания
        $seo = Seo::make(Seo::pick('', 'seo.sitemap_meta_title', []) ?: t('Карта сайта') . ' — ' . Settings::get('store_name', 'Tomobuv'),
            Seo::pick('', 'seo.sitemap_meta_description', []));
        $seo->canonical = url('/sitemap/');
        $brands = array_values(array_filter(Catalog::brands(), static fn($b) => !(int) $b['hidden'] && (int) $b['product_count'] > 0));
        usort($brands, static fn($a, $b) => strnatcasecmp((string) $a['name'], (string) $b['name']));
        $html = View::render('front/page', [
            'seo'      => $seo,
            'page'     => ['name' => t('Карта сайта'), 'h1' => t('Карта сайта'), 'html' => ''],
            'menu'     => self::menu('sitemap/'),
            'contacts' => null,
            'posts'    => [],
            'map'      => [
                'pages'  => Content::menuPages(),
                'roots'  => Catalog::roots(),
                'brands' => $brands,
                'posts'  => BlogController::titles(),
            ],
        ]);
        return Response::html($html)->cache(86400);
    }
}
