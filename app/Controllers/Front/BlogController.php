<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Cache;
use App\Core\Lang;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\View;
use App\Services\Catalog;

/**
 * Блог: /blog/ (список, ?page=N по 10), /blog/{url}/ (статья), /blog/rss/ (RSS-лента, была на старом сайте).
 * SEO как на старом сайте (приложение «Блог» Webasyst): список — название блога («Tomobuv»),
 * статья — meta_title, иначе «Tomobuv » Заголовок»; description/keywords — свои поля статьи.
 * Украинская версия: title/text/meta… из колонок *_uk (DB::$localize), если заполнены.
 */
final class BlogController
{
    private const PER_PAGE = 10;
    private const WHERE = "status = 'published' AND published_at <= NOW()";
    /** Колонки для анонса (с украинскими вариантами — их подставляет DB::$localize) */
    private const TEASER_COLS = 'id, url, title, title_uk, text_before_cut, text_before_cut_uk, text, text_uk, image, published_at';

    public function index(): Response
    {
        $db = App::db();
        $total = (int) Cache::remember('blog.count', 3600, static fn() =>
            (int) App::db()->value('SELECT COUNT(*) FROM blog_posts WHERE ' . self::WHERE));
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        if (Request::page() > $pg->pages) return Response::notFound();   // ?page=99 — не плодим пустые дубли

        $rows = $db->all('SELECT ' . self::TEASER_COLS . ' FROM blog_posts
            WHERE ' . self::WHERE . ' ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?', [self::PER_PAGE, $pg->offset]);

        $seo = Seo::make((string) Settings::get('blog.meta_title', self::blogName()),
            (string) Settings::get('blog.meta_description', ''), (string) Settings::get('blog.meta_keywords', ''));
        $seo->canonical = url('/blog/');
        // На старом сайте (приложение «Блог») у страниц ?page=N тот же title без « | Страница N» — оставляем как было
        if ($pg->page > 1) $seo->prev = url(lurl($pg->page === 2 ? '/blog/' : '/blog/?page=' . ($pg->page - 1)));
        if ($pg->hasNext()) $seo->next = url(lurl('/blog/?page=' . ($pg->page + 1)));

        $html = View::render('front/blog', [
            'seo'   => $seo,
            'posts' => array_map([self::class, 'teaser'], $rows),
            'pg'    => $pg,
        ]);
        return Response::html($html)->cache(86400);
    }

    public function post(string $url): Response
    {
        if ($url === 'rss') return $this->rss();
        $post = App::db()->row('SELECT * FROM blog_posts WHERE url = ? AND ' . self::WHERE, [$url]);
        if (!$post) return Response::notFound();

        $t = self::teaser($post);
        $own = trim((string) $post['meta_title']);
        $seo = Seo::make($own !== '' ? $own : self::blogName() . ' » ' . $post['title'],
            trim((string) $post['meta_description']), trim((string) $post['meta_keywords']));
        $seo->canonical = url('/blog/' . $post['url'] . '/');
        $seo->ogType = 'article';
        $seo->ogTitle = (string) $post['title'];
        $seo->ogDescription = $seo->description !== '' ? $seo->description : $t['excerpt'];
        // og:image и картинка в разметке — только реально существующий файл (своя обложка статьи или картинка из текста)
        $seo->ogImage = trim((string) ($post['image'] ?? '')) !== '' ? media((string) $post['image'])
            : PageController::ogImage((string) $post['text_before_cut'] . ' ' . (string) $post['text']);
        $img = $seo->ogImage;
        $store = (string) Settings::get('store_name', 'Tomobuv');
        $seo->jsonLd[] = array_filter([
            '@context' => 'https://schema.org', '@type' => 'BlogPosting',
            'headline' => (string) $post['title'],
            'inLanguage' => Lang::isUk() ? 'uk' : 'ru',
            'datePublished' => date('c', strtotime((string) $post['published_at'])),
            'dateModified' => date('c', strtotime((string) ($post['updated_at'] ?: $post['published_at']))),
            'image' => $img ? (str_starts_with($img, 'http') ? $img : url($img)) : null,
            'author' => ['@type' => 'Organization', 'name' => $store],
            'publisher' => ['@type' => 'Organization', 'name' => $store, 'logo' => ['@type' => 'ImageObject', 'url' => url('/assets/img/logo.png')]],
            'mainEntityOfPage' => Lang::absUrl($seo->canonical),
        ]);

        $others = array_values(array_filter(self::latest(7), static fn($p) => $p['url'] !== $post['url']));
        $cat = Catalog::categoryByUrl('dyetskaya-obuv') ?? (Catalog::roots()[0] ?? null);
        $html = View::render('front/post', [
            'seo'    => $seo,
            'post'   => $t + ['html' => PageController::prepareHtml((string) $post['text'])],
            'others' => array_slice($others, 0, 6),
            'cat'    => $cat,
        ]);
        return Response::html($html)->cache(86400);
    }

    /** RSS 2.0: последние 20 статей (адрес /blog/rss/ был на старом сайте); на /ua/blog/rss/ — украинская лента */
    private function rss(): Response
    {
        $name = self::blogName();
        $abs = static fn(string $path) => url(lurl($path));
        $x = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
            . '<title>' . self::xml($name) . '</title><link>' . self::xml($abs('/blog/')) . '</link>'
            . '<description>' . self::xml(t('Статьи {name}', ['name' => $name])) . '</description><language>' . (Lang::isUk() ? 'uk' : 'ru') . '</language>'
            . '<atom:link href="' . self::xml($abs('/blog/rss/')) . '" rel="self" type="application/rss+xml"/>';
        foreach (self::latest(20) as $p) {
            $link = $abs('/blog/' . $p['url'] . '/');
            $x .= '<item><title>' . self::xml($p['title']) . '</title><link>' . self::xml($link) . '</link>'
                . '<guid isPermaLink="true">' . self::xml($link) . '</guid>'
                . '<pubDate>' . date(DATE_RSS, strtotime($p['published_at'])) . '</pubDate>'
                . '<description>' . self::xml($p['excerpt']) . '</description></item>';
        }
        $x .= '</channel></rss>';
        return Response::text($x, 'application/rss+xml; charset=utf-8')->cache(21600);
    }

    // ------------------------------------------------------------------ для других страниц

    /** Последние статьи (анонсы) — блок «Другие статьи», страница «Статьи», RSS. Кэш 1 час (свой на каждый язык). */
    public static function latest(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return Cache::remember('blog.latest.' . $limit, 3600, static fn() => array_map([self::class, 'teaser'], App::db()->all(
            'SELECT ' . self::TEASER_COLS . ' FROM blog_posts
             WHERE ' . self::WHERE . ' ORDER BY published_at DESC, id DESC LIMIT ' . $limit)));
    }

    /** Заголовки всех статей (HTML-карта сайта) */
    public static function titles(): array
    {
        return Cache::remember('blog.titles', 3600, static fn() => App::db()->all(
            'SELECT url, title, title_uk FROM blog_posts WHERE ' . self::WHERE . ' ORDER BY published_at DESC, id DESC LIMIT 1000'));
    }

    public static function blogName(): string
    {
        return (string) Settings::get('blog.name', Settings::get('store_name', 'Tomobuv'));
    }

    /** Анонс статьи: дата, текст до ката (или начало текста), первая картинка */
    public static function teaser(array $r): array
    {
        $cut = (string) ($r['text_before_cut'] ?? '');
        // на /ua/: есть украинский текст, но нет украинского анонса — анонс из украинского текста, а не русский «кат»
        if (Lang::isUk() && trim((string) ($r['text_uk'] ?? '')) !== '' && trim((string) ($r['text_before_cut_uk'] ?? '')) === '') $cut = '';
        $src = trim($cut) !== '' ? $cut : (string) $r['text'];
        return [
            'url'          => (string) $r['url'],
            'title'        => (string) $r['title'],
            'published_at' => (string) $r['published_at'],
            'date'         => self::date((string) $r['published_at']),
            'excerpt'      => str_limit(self::plain($src), 240),
            'image'        => trim((string) ($r['image'] ?? '')) !== '' ? media((string) $r['image'])
                : self::firstImage((string) ($r['text_before_cut'] ?? '') . ' ' . (string) $r['text']),
        ];
    }

    /** «22 июля 2017» / «22 липня 2017» */
    public static function date(string $dt): string
    {
        $ts = strtotime($dt);
        if (!$ts) return '';
        $m = [t('января'), t('февраля'), t('марта'), t('апреля'), t('мая'), t('июня'),
              t('июля'), t('августа'), t('сентября'), t('октября'), t('ноября'), t('декабря')];
        return (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

    /** Текст без HTML: скрытые блоки микроразметки (display:none), скрипты и стили убираются */
    private static function plain(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<(\w+)\b[^>]*display:\s*none[^>]*>[^<]*</\1>#i', ' ', $html);
        $html = (string) preg_replace('#<(br|p|div|li|h\d)\b#i', ' <$1', $html);
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * Картинка анонса: первая из текста, файл которой есть на сервере (часть картинок старых статей потеряна
     * ещё на старом сайте — битые ссылки на хостинге ушли бы в index.php и рисовали страницу 404).
     * При разработке без копии файлов — со старого сайта (images.remote_base, /wa-data/ надёжнее /uploads/);
     * внешние картинки — как есть.
     */
    private static function firstImage(string $html): ?string
    {
        $srcs = PageController::imageSrcs($html);
        foreach ($srcs as $s) if (PageController::localFile($s)) return $s;
        $remote = rtrim((string) App::config('images.remote_base', ''), '/');
        if ($remote !== '') foreach ($srcs as $s) if (str_starts_with($s, '/wa-data/')) return $remote . $s;
        foreach ($srcs as $s) if (preg_match('#^https?://#i', $s)) return $s;
        return null;
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
