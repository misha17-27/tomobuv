<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Seo;
use App\Core\Settings;

/**
 * SEO-обзор для админки: что видят поисковики на каждой странице из sitemap.xml.
 *
 * Итоговые title/description считаются так же, как на витрине: своё значение, иначе SEO-шаблон
 * из настроек (App\Core\Seo::pick) с теми же переменными, что в Front\ProductController и т.д.
 *
 * Четыре состояния, а не два (как в админке ARG FLEX): пустое поле — не всегда ошибка.
 *   none — «Нет»:        своего нет и шаблон тоже пустой — на сайте мета-тега не будет;
 *   warn — «Длина»:      своё задано (или результат шаблона), но короче/длиннее, чем покажет Google;
 *   ok   — «Задан»:      своё значение нормальной длины;
 *   auto — «По шаблону»: своего нет, строится из шаблона (или из названия, отрывка текста) нормальной длины — это нормально.
 * У товаров итоги считаются агрегатами по своим значениям: результат шаблона товара движок подгоняет под норму (Seo::build).
 *
 * Небольшие группы (страницы, категории, бренды, статьи) считаются целиком в PHP;
 * товары (100 000+) — только агрегатами одним SQL, список — постранично по 50.
 * Всё кэшируется на 10 минут (Cache::flush после правок в админке сбрасывает сразу).
 *
 * Украинская версия (/ua/…): SeoAudit::inLang('uk', fn) считает всё так, как её видит витрина — колонки *_uk
 * вместо русских, если заполнены (DB::$localize), шаблоны «seo.*.uk» (Settings::get), адреса с префиксом /ua.
 * Покрытие переводов (ukCoverage): у каждой строки — заполнено ли своё украинское значение и из какого шаблона
 * строится значение по умолчанию; у товаров — тот же единственный проход агрегатов, что и для русской версии.
 */
final class SeoAudit
{
    public const TITLE_MIN = Seo::TITLE_MIN;      // норма — общая с движком шаблонов (App\Core\Seo) и автоисправлением (SeoFix)
    public const TITLE_MAX = Seo::TITLE_MAX;
    public const DESC_MIN = Seo::DESC_MIN;
    public const DESC_MAX = Seo::DESC_MAX;
    public const TTL = 600;
    public const PER_PAGE = 50;
    private const SEARCH_LIMIT = 5000;

    /** Состояния точек .seo-dot: ключ → подпись */
    public const STATES = ['none' => 'Нет', 'warn' => 'Длина', 'ok' => 'Задан', 'auto' => 'По шаблону'];

    /** Фильтры таблицы (чипы) */
    public const FILTERS = ['' => 'Всё', 'none' => 'Нет', 'warn' => 'Неверная длина', 'auto' => 'По шаблону', 'closed' => 'Закрыто от индексации'];

    /** Группы таблицы (вкладки) в порядке вывода */
    public const GROUPS = ['pages' => 'Страницы', 'categories' => 'Категории', 'brands' => 'Бренды', 'blog' => 'Статьи блога', 'products' => 'Товары'];

    /** Где редактируются главная и шаблоны */
    public const SETTINGS_URL = '/admin/settings/seo/';

    /** Версии сайта для переключателя таблицы */
    public const LANGS = ['ru' => 'Русская версия', 'uk' => 'Украинская /ua/'];

    /** Покрытие украинской версии: состояние title/description на /ua/ → подпись (точки .seo-dot тех же цветов) */
    public const UK_STATES = ['own' => 'Свой перевод', 'tpl' => 'Строится сам', 'ru' => 'Русский текст', 'none' => 'Пусто'];
    public const UK_DOTS = ['own' => 'ok', 'tpl' => 'auto', 'ru' => 'warn', 'none' => 'none'];

    // ======================================================================= состояние

    /** Состояние title/description: своё значение + что покажет сайт без него (результат шаблона; вне нормы — «Длина») */
    public static function state(string $own, string $field, string $auto = ''): string
    {
        $len = mb_strlen(trim($own));
        if ($len === 0) return trim($auto) === '' ? 'none' : (self::lengthState($auto, $field) === 'warn' ? 'warn' : 'auto');
        [$min, $max] = $field === 'title' ? [self::TITLE_MIN, self::TITLE_MAX] : [self::DESC_MIN, self::DESC_MAX];
        return ($len < $min || $len > $max) ? 'warn' : 'ok';
    }

    /** Нормальная длина текста (для результата шаблона: ok / warn) */
    public static function lengthState(string $text, string $field): string
    {
        $len = mb_strlen(trim($text));
        if ($len === 0) return 'none';
        [$min, $max] = $field === 'title' ? [self::TITLE_MIN, self::TITLE_MAX] : [self::DESC_MIN, self::DESC_MAX];
        return ($len < $min || $len > $max) ? 'warn' : 'ok';
    }

    /** Строка таблицы */
    private static function row(string $group, string $name, string $path, ?string $ownTitle, string $autoTitle,
        ?string $ownDesc, string $autoDesc, string $edit, array $opt = []): array
    {
        $ownTitle = trim((string) $ownTitle);
        $ownDesc = trim((string) $ownDesc);
        $autoTitle = trim($autoTitle);
        $autoDesc = trim($autoDesc);
        return [
            'group'       => $group,
            'kind'        => (string) ($opt['kind'] ?? ''),         // home — главная (для счётчиков sitemap)
            'name'        => $name,
            'sub'         => (string) ($opt['sub'] ?? ''),
            'path'        => Lang::path($path),                     // адрес на сайте (в украинской версии — /ua/…)
            'title'       => $ownTitle !== '' ? $ownTitle : $autoTitle,
            'title_own'   => $ownTitle !== '',
            'title_state' => self::state($ownTitle, 'title', $autoTitle),
            'desc'        => $ownDesc !== '' ? $ownDesc : $autoDesc,
            'desc_own'    => $ownDesc !== '',
            'desc_state'  => self::state($ownDesc, 'description', $autoDesc),
            'edit'        => $edit,
            'edit_note'   => (string) ($opt['edit_note'] ?? ''),
            'closed'      => (string) ($opt['closed'] ?? ''),     // причина, по которой страница не попадает в поиск
            'hidden'      => (bool) ($opt['hidden'] ?? false),     // скрыта на сайте (строка бледная)
            'sitemap'     => (bool) ($opt['sitemap'] ?? true),     // есть в sitemap.xml
            'all'         => (bool) ($opt['all'] ?? true),         // показывать во «Всё»
            // для покрытия украинской версии: заполнено ли своё украинское значение и какой настройкой строится
            // значение по умолчанию ('' — из названия или перевода интерфейса; у настройки есть вариант «.uk»)
            'uk'          => [(bool) ($opt['uk'][0] ?? false), (bool) ($opt['uk'][1] ?? false)],
            'tpl'         => [(string) ($opt['tpl'][0] ?? ''), (string) ($opt['tpl'][1] ?? '')],
        ];
    }

    /** Заполнено ли значение (для колонок *_uk и настроек «….uk») */
    private static function filled($v): bool
    {
        return trim((string) $v) !== '';
    }

    /** Есть ли у настройки украинский вариант «<ключ>.uk» (без подстановки русского) */
    private static function hasUk(string $key): bool
    {
        return $key !== '' && self::filled(Settings::all()[$key . '.uk'] ?? '');
    }

    // ======================================================================= данные (кэш 10 минут)

    /**
     * Всё для экрана: строки небольших групп, агрегаты товаров, покрытие переводов товаров, время расчёта.
     * Агрегаты товаров для обеих версий считаются одним проходом в русском расчёте; украинский берёт их оттуда.
     */
    public static function overview(): array
    {
        if (Lang::isUk()) {
            $ru = self::inLang('ru', [self::class, 'overview']);
            $uk = Cache::remember('seo.audit.overview', self::TTL, static fn() => ['rows' => self::buildRows(), 'at' => time()]);
            return $uk + ['products' => $ru['products_uk'], 'products_uk' => $ru['products_uk'], 'cover' => $ru['cover']];
        }
        return Cache::remember('seo.audit.overview', self::TTL, static function (): array {
            $st = self::productStats();
            return ['rows' => self::buildRows(), 'products' => $st['ru'], 'products_uk' => $st['uk'], 'cover' => $st['cover'], 'at' => time()];
        });
    }

    /** Сбросить посчитанное (кнопка «Пересчитать») */
    public static function forget(): void
    {
        foreach (array_keys(Lang::LANGS) as $lang) {
            self::inLang($lang, static function (): void {
                Cache::forget('seo.audit.overview');
                Cache::forget('seo.audit.robots');
            });
        }
    }

    /**
     * Выполнить расчёт «глазами» версии сайта на языке $lang (ru|uk): как витрина — Lang + DB::$localize.
     * После — язык админки восстанавливается (иначе Response::send перепишет ссылки админки на /ua/…).
     */
    public static function inLang(string $lang, callable $fn)
    {
        $prevLang = Lang::current();
        $prevLoc = DB::$localize;
        // Catalog::categories() запоминает дерево в статическом свойстве при первом вызове — пусть это будет
        // русский расчёт (иначе после украинского админка получила бы украинские названия); украинские
        // названия категорий берём через Lang::localize (см. loc())
        if ($lang !== $prevLang) Catalog::categories();
        Lang::set($lang);
        DB::$localize = Lang::isUk();
        try {
            return $fn();
        } finally {
            Lang::set($prevLang);
            DB::$localize = $prevLoc;
        }
    }

    /** Колонка с учётом языка: в украинской версии — x_uk, если заполнено (как Lang::localize) */
    private static function col(string $c): string
    {
        return Lang::isUk() ? "COALESCE(NULLIF({$c}_uk, ''), {$c})" : $c;
    }

    /** Строка из кэша справочников (там русские названия + колонки *_uk) — в украинской версии с подстановкой *_uk */
    private static function loc(?array $row): ?array
    {
        return $row !== null && Lang::isUk() ? Lang::localize($row) : $row;
    }

    /**
     * Что покажет сайт, если своего title/description нет (шаблон из настроек или название) —
     * та же логика, что в контроллерах витрины. $type: product|category|page|brand|blog|home.
     * Для товара можно передать категорию ($cat), иначе она найдётся как в Front\ProductController::seoCategory.
     */
    public static function auto(string $type, array $row = [], ?array $cat = null): array
    {
        switch ($type) {
            case 'product':
                $vars = self::productVars($row, $cat ?? self::productCategory($row));
                return ['title' => Seo::pick('', 'seo.product_meta_title', $vars) ?: (string) ($row['name'] ?? ''),
                        'desc'  => Seo::pick('', 'seo.product_meta_description', $vars)];
            case 'category':                                  // Front\CategoryController::seo (первая страница)
                $on = (bool) Settings::get('seo.category_is_enabled', 1);
                $vars = self::categoryVars($row);
                return ['title' => ($on ? Seo::pick('', 'seo.category_meta_title', $vars) : '') ?: (string) ($row['name'] ?? ''),
                        'desc'  => $on ? Seo::pick('', 'seo.category_meta_description', $vars) : ''];
            case 'page':                                      // Front\PageController::seo: description — отрывок текста, иначе шаблон
                $on = (string) Settings::get('seo.page_is_enabled', '1') !== '0';
                $vars = ['page' => ['name' => (string) ($row['name'] ?? ''), 'title' => '']];
                $desc = Seo::excerpt((string) ($row['content'] ?? ''));
                return ['title' => ($on ? Seo::pick('', 'seo.page_meta_title', $vars) : '') ?: (string) ($row['name'] ?? ''),
                        'desc'  => $desc !== '' ? $desc : ($on ? Seo::pick('', 'seo.page_meta_description', $vars) : '')];
            case 'brand':                                     // Front\BrandController::seo: шаблоны seo.brand_meta_*, иначе имя
                $on = (string) Settings::get('seo.brand_is_enabled', '1') !== '0';
                $vars = SeoVars::brand($row);
                return ['title' => ($on ? Seo::pick('', 'seo.brand_meta_title', $vars) : '') ?: trim((string) ($row['name'] ?? '')),
                        'desc'  => $on ? Seo::pick('', 'seo.brand_meta_description', $vars) : ''];
            case 'blog':                                      // Front\BlogController::post: description — отрывок текста
                return ['title' => self::blogName() . ' » ' . ($row['title'] ?? ''),
                        'desc'  => Seo::excerpt(\App\Controllers\Front\BlogController::excerptSource($row))];
            case 'home':                                      // Front\HomeController::index
                return ['title' => (string) Settings::get('site_title', ''), 'desc' => ''];
        }
        return ['title' => '', 'desc' => ''];
    }

    /**
     * Всё для превью Google (партиал admin/partials/serp): адрес, свои значения, «по шаблону», состояния.
     * $row — строка из базы (товар, категория, страница, бренд, статья); для главной — пустой массив.
     */
    public static function meta(string $type, array $row): array
    {
        [$ownT, $ownD] = match ($type) {
            'page', 'brand' => [$row['title'] ?? '', $row['meta_description'] ?? ''],
            'home'          => [Settings::get('seo.home_page_meta_title', ''), Settings::get('seo.home_page_meta_description', '')],
            default         => [$row['meta_title'] ?? '', $row['meta_description'] ?? ''],
        };
        $url = (string) ($row['url'] ?? '');
        $path = match ($type) {
            'product'  => '/product/' . $url . '/',
            'category' => '/category/' . $url . '/',
            'brand'    => '/brand/' . urlencode($url) . '/',
            'blog'     => '/blog/' . $url . '/',
            'page'     => '/' . $url,
            default    => '/',
        };
        $auto = ($type === 'product' && empty($row['name'])) ? ['title' => '', 'desc' => ''] : self::auto($type, $row);
        $ownT = trim((string) $ownT);
        $ownD = trim((string) $ownD);
        return [
            'path'        => $url === '' && $type !== 'home' ? '' : $path,
            'title_own'   => $ownT, 'title_auto' => trim($auto['title']),
            'desc_own'    => $ownD, 'desc_auto' => trim($auto['desc']),
            'title_state' => self::state($ownT, 'title', $auto['title']),
            'desc_state'  => self::state($ownD, 'description', $auto['desc']),
        ];
    }

    /** Название блога — как Front\BlogController::blogName */
    private static function blogName(): string
    {
        return (string) Settings::get('blog.name', Settings::get('store_name', 'Tomobuv'));
    }

    /** Категория товара для шаблонов — как Front\ProductController::seoCategory: основная (даже скрытая), иначе первая в дереве */
    private static function productCategory(array $p): ?array
    {
        $cid = (int) ($p['category_id'] ?? 0);
        if ($cid) {
            $c = self::loc(Catalog::category($cid)) ?? App::db()->row('SELECT id, name, name_uk, url, seo_name, seo_name_uk FROM categories WHERE id = ?', [$cid]);
            if ($c) return $c;
        }
        $all = Catalog::categories();
        return $all ? self::loc(reset($all)) : null;
    }

    /**
     * Страницы, категории, бренды, блог — полные строки. Для покрытия украинской версии каждой строке передаётся
     * 'uk' — заполнены ли свои украинские title/description — и 'tpl' — настройка, из которой строится значение
     * по умолчанию (шаблон или название с вариантом «.uk»; '' — из названия записи или перевода интерфейса).
     */
    private static function buildRows(): array
    {
        $db = App::db();
        $robots = self::robots()['rules'];
        $closedBy = static fn(string $path): string => ($p = self::blockedBy(Lang::path($path), $robots)) !== '' ? 'robots.txt: Disallow ' . $p : '';
        $store = (string) Settings::get('store_name', 'Tomobuv');
        $raw = Settings::all();
        $pageTpl = (string) Settings::get('seo.page_is_enabled', '1') !== '0';
        $catTpl = (bool) Settings::get('seo.category_is_enabled', 1);
        $rows = [];

        // ---- страницы: главная, инфо-страницы, отзывы, HTML-карта (как Front\HomeController, PageController, ReviewsController)
        $home = self::auto('home');
        $rows[] = self::row('pages', 'Главная', '/',
            (string) Settings::get('seo.home_page_meta_title', ''), $home['title'],
            (string) Settings::get('seo.home_page_meta_description', ''), $home['desc'],
            self::SETTINGS_URL . '#f-seo-home_page_meta_title', ['kind' => 'home', 'closed' => $closedBy('/'), 'edit_note' => 'Настройки → SEO-шаблоны',
            'uk' => [self::filled($raw['seo.home_page_meta_title.uk'] ?? ''), self::filled($raw['seo.home_page_meta_description.uk'] ?? '')],
            'tpl' => ['site_title', '']]);

        foreach ($db->all('SELECT id, url, name, name_uk, title, title_uk, meta_description, meta_description_uk, status, canonical, content, content_uk FROM pages ORDER BY (url LIKE \'pages/%\'), sort, id') as $p) {
            $path = '/' . $p['url'];
            ['title' => $autoTitle, 'desc' => $autoDesc] = self::auto('page', $p);
            $canon = trim((string) $p['canonical']);
            $canonPath = $canon !== '' ? (string) (parse_url($canon, PHP_URL_PATH) ?? '') : '';
            $closed = !(int) $p['status'] ? 'скрыта'
                : ($canon !== '' && rtrim($canonPath, '/') !== rtrim($path, '/') ? 'canonical → ' . $canon : $closedBy($path));
            $rows[] = self::row('pages', (string) $p['name'], $path, $p['title'], $autoTitle, $p['meta_description'], $autoDesc,
                '/admin/pages/' . (int) $p['id'] . '/#h-seo', ['closed' => $closed, 'hidden' => !(int) $p['status'], 'sitemap' => (bool) (int) $p['status'],
                'sub' => str_starts_with((string) $p['url'], 'pages/') ? 'дубль из приложения «Сайт»' : '',
                'uk' => [self::filled($p['title_uk']), self::filled($p['meta_description_uk'])],
                'tpl' => $pageTpl ? ['seo.page_meta_title', 'seo.page_meta_description'] : ['', '']]);
        }
        // отзывы и HTML-карта: настройки seo.reviews_meta_* / seo.sitemap_meta_* (Front\ReviewsController::index, Front\PageController::htmlMap);
        // пусто — заголовок из кода сайта, без описания (как на старом сайте)
        foreach ([['Отзывы о магазине', '/reviews/', 'reviews', t('Отзывы')], ['Карта сайта', '/sitemap/', 'sitemap', t('Карта сайта') . ' — ' . $store]] as [$label, $path, $k, $def]) {
            $rows[] = self::row('pages', $label, $path, '', Seo::pick('', "seo.{$k}_meta_title", []) ?: $def, '', Seo::pick('', "seo.{$k}_meta_description", []),
                self::SETTINGS_URL . "#f-seo-{$k}_meta_title", ['closed' => $closedBy($path), 'edit_note' => 'Настройки → SEO-шаблоны',
                'tpl' => ["seo.{$k}_meta_title", "seo.{$k}_meta_description"]]);
        }

        // ---- категории: свои meta_*, иначе шаблоны seo.category_meta_* ({$category.seo_name} = seo_name или name)
        foreach ($db->all('SELECT id, parent_id, depth, name, name_uk, url, type, status, seo_name, seo_name_uk, product_count, meta_title, meta_title_uk, meta_description, meta_description_uk FROM categories ORDER BY lft, sort, id') as $c) {
            $path = '/category/' . $c['url'] . '/';
            $auto = self::auto('category', $c);
            $hidden = !(int) $c['status'];
            $rows[] = self::row('categories', (string) $c['name'], $path, $c['meta_title'], $auto['title'],
                $c['meta_description'], $auto['desc'],
                '/admin/categories/' . (int) $c['id'] . '/#h-seo', ['closed' => $hidden ? 'скрыта' : $closedBy($path), 'hidden' => $hidden,
                'sitemap' => !$hidden, 'sub' => implode(' · ', array_filter([(int) $c['type'] === 1 ? 'динамическая' : '',
                    (int) $c['depth'] > 0 ? 'уровень ' . ((int) $c['depth'] + 1) : ''])),
                'uk' => [self::filled($c['meta_title_uk']), self::filled($c['meta_description_uk'])],
                'tpl' => $catTpl ? ['seo.category_meta_title', 'seo.category_meta_description'] : ['', '']]);
        }

        // ---- бренды: свои brands.title / meta_description, иначе шаблоны seo.brand_meta_* (Front\BrandController::seo);
        //      имя на /ua/ — name_uk, если задано («Не вказано»), как Catalog::brands() на витрине (DB::$localize)
        foreach ($db->all('SELECT id, name, name_uk, url, title, title_uk, meta_description, meta_description_uk, hidden, product_count FROM brands ORDER BY name, id') as $b) {
            $path = '/brand/' . urlencode((string) $b['url']) . '/';
            $live = !(int) $b['hidden'] && (int) $b['product_count'] > 0;
            $closed = (int) $b['hidden'] ? 'скрыт' : ((int) $b['product_count'] === 0 ? 'нет товаров — не в sitemap' : $closedBy($path));
            $auto = self::auto('brand', $b);
            $rows[] = self::row('brands', (string) $b['name'], $path, $b['title'], $auto['title'], $b['meta_description'], $auto['desc'],
                '/admin/brands/' . (int) $b['id'] . '/#h-seo', ['closed' => $closed, 'hidden' => !$live, 'sitemap' => $live, 'all' => $live,
                'sub' => number_format((int) $b['product_count'], 0, '', ' ') . ' ' . plural((int) $b['product_count'], 'товар', 'товара', 'товаров'),
                'uk' => [self::filled($b['title_uk']), self::filled($b['meta_description_uk'])],
                'tpl' => (string) Settings::get('seo.brand_is_enabled', '1') !== '0' ? ['seo.brand_meta_title', 'seo.brand_meta_description'] : ['', '']]);
        }

        // ---- блог: список — blog.meta_title, иначе название блога (= название магазина); статья — meta_title, иначе «Блог » Заголовок»
        $rows[] = self::row('blog', 'Блог — список статей', '/blog/', (string) Settings::get('blog.meta_title', ''), self::blogName(),
            (string) Settings::get('blog.meta_description', ''), '', self::SETTINGS_URL . '#f-blog-meta_title',
            ['closed' => $closedBy('/blog/'), 'edit_note' => 'Настройки → SEO-шаблоны, блок «Блог»',
             'uk' => [self::filled($raw['blog.meta_title.uk'] ?? ''), self::filled($raw['blog.meta_description.uk'] ?? '')]]);
        $now = date('Y-m-d H:i:s');
        foreach ($db->all('SELECT id, url, title, title_uk, meta_title, meta_title_uk, meta_description, meta_description_uk, status, published_at,
            text_before_cut, text_before_cut_uk, text, text_uk FROM blog_posts ORDER BY published_at DESC, id DESC') as $p) {
            $path = '/blog/' . $p['url'] . '/';
            $live = $p['status'] === 'published' && (string) $p['published_at'] <= $now;
            $closed = $p['status'] !== 'published' ? 'черновик'
                : (!$live ? 'выйдет ' . date('d.m.Y', strtotime((string) $p['published_at'])) : $closedBy($path));
            $auto = self::auto('blog', $p);
            $rows[] = self::row('blog', (string) $p['title'], $path, $p['meta_title'], $auto['title'],
                $p['meta_description'], $auto['desc'], '/admin/blog/' . (int) $p['id'] . '/#h-seo',
                ['closed' => $closed, 'hidden' => !$live, 'sitemap' => $live, 'sub' => date('d.m.Y', strtotime((string) $p['published_at'])),
                 'uk' => [self::filled($p['meta_title_uk']), self::filled($p['meta_description_uk'])]]);
        }
        return $rows;
    }

    /** Переменные категории для шаблонов — как Front\CategoryController::seo (App\Services\SeoVars) */
    private static function categoryVars(?array $c): array
    {
        return SeoVars::category($c);
    }

    /** Переменные товара — как Front\ProductController::seoVars (App\Services\SeoVars) */
    public static function productVars(array $p, ?array $cat): array
    {
        return SeoVars::product($p, $cat);
    }

    // ======================================================================= товары

    /** Условия для SQL: пусто / неверная длина (пороги — целые константы класса) */
    private static function sql(): array
    {
        $mt = self::col('meta_title');
        $md = self::col('meta_description');
        $t = "CHAR_LENGTH(TRIM($mt))";
        $d = "CHAR_LENGTH(TRIM($md))";
        return [
            't_empty' => "($mt IS NULL OR TRIM($mt) = '')",
            't_warn'  => "($t BETWEEN 1 AND " . (self::TITLE_MIN - 1) . " OR $t > " . self::TITLE_MAX . ')',
            'd_empty' => "($md IS NULL OR TRIM($md) = '')",
            'd_warn'  => "($d BETWEEN 1 AND " . (self::DESC_MIN - 1) . " OR $d > " . self::DESC_MAX . ')',
        ];
    }

    /**
     * Агрегаты по товарам — один проход по таблице сразу для обеих версий сайта. Длина считается один раз на строку
     * и раскладывается по корзинам INTERVAL (0 — пусто, 1 — короче минимума, 2 — норма, 3 — длиннее максимума),
     * дальше GROUP BY на несколько десятков групп (быстрее, чем SUM(CASE …) с повтором выражений; ~0,24 с на 107 тыс., из них ~0,05 с — TRIM).
     * Украинская версия видит meta_*_uk, если заполнено, иначе русское — корзина «uk» = корзина *_uk, а при 0 — русская.
     * Длина — по TRIM(), как в условиях списка (sql()) и как на витрине: иначе значение с пробелами по краям попадало
     * в итогах в одну корзину, а в списке — в другую, и дальние страницы отфильтрованного списка съезжали.
     * Возвращает ['ru' => итоги, 'uk' => итоги, 'cover' => покрытие переводов (только товары на сайте)].
     */
    private static function productStats(?string $extraWhere = null, array $params = []): array
    {
        // без COALESCE: NULL даёт -1, пустая (после TRIM) строка — 0, обе — «пусто»
        $b = static fn(string $c, int $min, int $max): string => "INTERVAL(CHAR_LENGTH(TRIM($c)), 1, $min, " . ($max + 1) . ')';
        $rows = App::db()->all('SELECT status = 1 AS live, '
            . $b('meta_title', self::TITLE_MIN, self::TITLE_MAX) . ' AS t, '
            . $b('meta_description', self::DESC_MIN, self::DESC_MAX) . ' AS d, '
            . $b('meta_title_uk', self::TITLE_MIN, self::TITLE_MAX) . ' AS tu, '
            . $b('meta_description_uk', self::DESC_MIN, self::DESC_MAX) . ' AS du, COUNT(*) AS n
            FROM products' . ($extraWhere ? ' WHERE ' . $extraWhere : '') . ' GROUP BY live, t, d, tu, du', $params);
        $zero = ['total' => 0, 'hidden' => 0, 't_empty' => 0, 't_warn' => 0, 'd_empty' => 0, 'd_warn' => 0, 'r_warn' => 0, 'r_empty' => 0];
        $s = ['ru' => $zero, 'uk' => $zero];
        // покрытие: свой перевод / своего нет, но есть русское своё (на /ua/ — русский текст) / пусто в обоих (шаблон)
        $cover = ['total' => 0, 't_own' => 0, 't_ru' => 0, 't_auto' => 0, 'd_own' => 0, 'd_ru' => 0, 'd_auto' => 0];
        $add = static function (array &$x, int $t, int $d, int $n): void {
            $tE = $t === 0; $tW = $t === 1 || $t === 3;
            $dE = $d === 0; $dW = $d === 1 || $d === 3;
            $x['total'] += $n;
            if ($tE) $x['t_empty'] += $n;
            if ($tW) $x['t_warn'] += $n;
            if ($dE) $x['d_empty'] += $n;
            if ($dW) $x['d_warn'] += $n;
            if ($tW || $dW) $x['r_warn'] += $n;
            if ($tE || $dE) $x['r_empty'] += $n;
        };
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            if (!(int) $r['live']) { $s['ru']['hidden'] += $n; $s['uk']['hidden'] += $n; continue; }
            [$t, $d, $tu, $du] = [max(0, (int) $r['t']), max(0, (int) $r['d']), max(0, (int) $r['tu']), max(0, (int) $r['du'])];
            $add($s['ru'], $t, $d, $n);
            $add($s['uk'], $tu ?: $t, $du ?: $d, $n);
            $cover['total'] += $n;
            $cover[$tu ? 't_own' : ($t ? 't_ru' : 't_auto')] += $n;
            $cover[$du ? 'd_own' : ($d ? 'd_ru' : 'd_auto')] += $n;
        }
        return $s + ['cover' => $cover];
    }
    /** Есть ли у товаров описание «по шаблону» (иначе пустое описание = «Нет») */
    public static function productDescTemplate(): bool
    {
        return trim((string) Settings::get('seo.product_meta_description', '')) !== '';
    }

    /** Сколько товаров попадает под каждый фильтр (по агрегатам) */
    public static function productCounts(array $st): array
    {
        $descTpl = self::productDescTemplate();
        return [
            ''       => $st['total'],
            'none'   => $descTpl ? 0 : $st['d_empty'],              // title у товара есть всегда (шаблон или название)
            'warn'   => $st['r_warn'],
            'auto'   => $descTpl ? $st['r_empty'] : $st['t_empty'],
            'closed' => $st['hidden'],
        ];
    }

    /** WHERE для списка товаров под фильтр; null — заведомо пусто */
    private static function productWhere(string $filter): ?string
    {
        $s = self::sql();
        $descTpl = self::productDescTemplate();
        return match ($filter) {
            'none'   => $descTpl ? null : 'status = 1 AND ' . $s['d_empty'],
            'warn'   => 'status = 1 AND (' . $s['t_warn'] . ' OR ' . $s['d_warn'] . ')',
            'auto'   => 'status = 1 AND (' . $s['t_empty'] . ($descTpl ? ' OR ' . $s['d_empty'] : '') . ')',
            'closed' => 'status <> 1',
            default  => 'status = 1',
        };
    }

    /**
     * Товары для таблицы: [rows, total, counts, page, found, found_hidden, limited]. Без поиска количество берётся из агрегатов (кэш),
     * с поиском — один проход агрегатов по найденным id: found — сколько нашлось товаров на сайте (та же база, что у «Всё»),
     * found_hidden — скрытых (они под «Закрыто от индексации»), limited — упёрлись в SEARCH_LIMIT.
     */
    public static function products(string $filter, string $q, int $page, array $stats, int $perPage = self::PER_PAGE): array
    {
        $db = App::db();
        $idsWhere = '';
        $idsParams = [];
        $found = null;
        if ($q !== '') {
            $ids = self::searchIds($q);
            $found = count($ids);
            if (!$ids) return ['rows' => [], 'total' => 0, 'counts' => array_fill_keys(array_keys(self::FILTERS), 0), 'page' => 1, 'found' => 0,
                'found_hidden' => 0, 'limited' => false];
            [$ph, $idsParams] = $db->in($ids);
            $idsWhere = "id IN ($ph)";
            $counts = self::productCounts(self::productStats($idsWhere, $idsParams)[Lang::current()]);
        } else {
            $counts = self::productCounts($stats);
        }
        $total = $counts[$filter] ?? 0;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $where = self::productWhere($filter);
        $extra = ['found' => $found === null ? null : $counts[''], 'found_hidden' => $found === null ? 0 : $counts['closed'],
            'limited' => $found !== null && $found >= self::SEARCH_LIMIT];
        if ($where === null || $total === 0) return ['rows' => [], 'total' => 0, 'counts' => $counts, 'page' => 1] + $extra;
        if ($idsWhere !== '') $where .= ' AND ' . $idsWhere;
        // Дальние страницы (вторая половина списка) читаются с конца: ORDER BY id ASC и разворот — строк вдвое меньше
        // (стр. 1500 из 2100 «Неверной длины» — 0,3 с → 0,01 с). Итог берётся из агрегатов, как и число страниц.
        $offset = ($page - 1) * $perPage;
        $rev = $offset > $total / 2;
        $limit = $rev ? min($perPage, $total - $offset) : $perPage;
        $off = $rev ? max(0, $total - $offset - $perPage) : $offset;
        $order = ' ORDER BY id ' . ($rev ? 'ASC' : 'DESC');
        $cols = 'id, url, name, name_uk, sku, seo_name, seo_name_uk, price, box_qty, size, category_id, status,
            meta_title, meta_title_uk, meta_description, meta_description_uk';
        if ($off < 2000) {
            // у края списка — одним запросом по первичному ключу (останавливается на нужной строке)
            $list = $db->all("SELECT $cols FROM products WHERE $where$order LIMIT $limit OFFSET $off", $idsParams);
        } else {
            // в середине — сначала только id (для «Всё» и «Закрыто» — по индексу status, без чтения строк), потом строки
            $ids = $db->col("SELECT id FROM products WHERE $where$order LIMIT $limit OFFSET $off", $idsParams);
            $list = [];
            if ($ids) {
                [$ph, $vals] = $db->in(array_map('intval', $ids));
                $list = $db->all("SELECT $cols FROM products WHERE id IN ($ph)$order", $vals);
            }
        }
        if ($rev) $list = array_reverse($list);
        return ['rows' => self::productRows($list), 'total' => $total, 'counts' => $counts, 'page' => $page] + $extra;
    }

    /**
     * Категории товаров для шаблонов, пакетно: [id товара => категория|null]. Как Front\ProductController::seoCategory —
     * основная (даже скрытая: её нет в кэше справочника — один запрос на всех), иначе первая в дереве.
     */
    private static function productCats(array $list): array
    {
        $need = [];
        foreach ($list as $p) {
            $cid = (int) $p['category_id'];
            if ($cid && !Catalog::category($cid)) $need[$cid] = $cid;
        }
        $extra = [];
        if ($need) {
            $db = App::db();
            [$ph, $vals] = $db->in(array_values($need));
            $extra = $db->keyed("SELECT id, parent_id, depth, name, name_uk, seo_name, seo_name_uk, product_count FROM categories WHERE id IN ($ph)", $vals);
        }
        $all = Catalog::categories();
        $first = $all ? self::loc(reset($all)) : null;
        $out = [];
        foreach ($list as $p) {
            $cid = (int) $p['category_id'];
            $out[(int) $p['id']] = ($cid ? (self::loc(Catalog::category($cid)) ?? $extra[$cid] ?? null) : null) ?? $first;
        }
        return $out;
    }

    /** Итоговые title/description товаров — как на странице товара */
    private static function productRows(array $list): array
    {
        if (!$list) return [];
        $cats = self::productCats($list);
        $robots = self::robots()['rules'];
        $out = [];
        foreach ($list as $p) {
            $auto = self::auto('product', $p, $cats[(int) $p['id']]);
            $path = '/product/' . $p['url'] . '/';
            $live = (int) $p['status'] === 1;
            $blocked = $live ? self::blockedBy(Lang::path($path), $robots) : '';
            $out[] = self::row('products', (string) $p['name'], $path, $p['meta_title'], $auto['title'],
                $p['meta_description'], $auto['desc'],
                '/admin/products/' . (int) $p['id'] . '/#h-seo',
                ['closed' => !$live ? 'скрыт' : ($blocked !== '' ? 'robots.txt: Disallow ' . $blocked : ''), 'hidden' => !$live, 'sitemap' => $live,
                 'sub' => trim(((string) $p['sku'] !== '' ? 'арт. ' . $p['sku'] . ' · ' : '') . price_format($p['price']) . ' / пара'),
                 'uk' => [self::filled($p['meta_title_uk']), self::filled($p['meta_description_uk'])],
                 'tpl' => ['seo.product_meta_title', 'seo.product_meta_description']]);
        }
        return $out;
    }

    /** Поиск id товаров: точный id, начало артикула/адреса, полнотекстовый по названию; запасной — LIKE */
    private static function searchIds(string $q): array
    {
        $db = App::db();
        $q = trim($q);
        if ($q === '') return [];
        $ids = [];
        if (ctype_digit($q) && strlen($q) <= 10) $ids = $db->col('SELECT id FROM products WHERE id = ?', [(int) $q]);
        $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE sku LIKE ? LIMIT 2000', [addcslashes($q, '%_\\') . '%']));
        $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE url LIKE ? LIMIT 500', [addcslashes(mb_strtolower($q), '%_\\') . '%']));
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn($w) => mb_strlen($w) >= 3);
        if ($words) {
            $against = implode(' ', array_map(static fn($w) => '+' . $w . '*', array_slice($words, 0, 8)));
            $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE MATCH(name, sku) AGAINST (? IN BOOLEAN MODE) ORDER BY id DESC LIMIT ' . self::SEARCH_LIMIT, [$against]));
        }
        if (!$ids && mb_strlen($q) >= 2) {
            $sub = '%' . addcslashes($q, '%_\\') . '%';
            $ids = $db->col('SELECT id FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY id DESC LIMIT ' . self::SEARCH_LIMIT, [$sub, $sub]);
        }
        return array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::SEARCH_LIMIT);
    }

    // ======================================================================= списки админки

    /** Фильтр «SEO» в списках товаров, статей, страниц и категорий: ?seo= → подпись */
    public const LIST_FILTERS = ['' => 'Все', 'notitle' => 'Без своего title', 'nodesc' => 'Без своего description', 'len' => 'Длина не в норме'];

    /** Вкладка SEO-обзора (?group=) для списка админки: тип строки → группа */
    public const LIST_GROUPS = ['product' => 'products', 'blog' => 'blog', 'page' => 'pages', 'category' => 'categories'];

    /**
     * Title и description строк списка админки — те же state() и auto(), что у таблицы обзора (и витрины), пакетно:
     * шаблоны — из настроек (кэш), категории товаров — из справочника и одним запросом для скрытых; запросов в цикле нет.
     * $type: product | blog | page | category. В строках — поля как у meta(): свои meta_title/meta_description
     * (у страницы — title/meta_description) и их *_uk; товар — name, seo_name, price, sku, category_id;
     * категория — name, seo_name; страница — name; статья — title.
     * Возвращает [id => ['title' => ячейка, 'desc' => ячейка]], ячейка:
     *   state — ключ STATES; own — своё значение; text — что выведет сайт (своё или результат шаблона), len — его длина;
     *   uk — что на /ua/ (ключ UK_STATES, как в ukCoverage), uk_len — длина своего украинского значения (0 — его нет).
     */
    public static function listCells(string $type, array $rows): array
    {
        if (!$rows) return [];
        $raw = Settings::all();
        [$own, $tpl] = match ($type) {
            // шаблоны — как в buildRows; у товаров — как в ukCoverage: без шаблона title = название, description пуст
            'page'     => [['title', 'meta_description'], (string) Settings::get('seo.page_is_enabled', '1') !== '0'
                ? ['seo.page_meta_title', 'seo.page_meta_description'] : ['', '']],
            'category' => [['meta_title', 'meta_description'], (bool) Settings::get('seo.category_is_enabled', 1)
                ? ['seo.category_meta_title', 'seo.category_meta_description'] : ['', '']],
            'product'  => [['meta_title', 'meta_description'], array_map(static fn($k) => self::filled($raw[$k] ?? '') ? $k : '',
                ['seo.product_meta_title', 'seo.product_meta_description'])],
            default    => [['meta_title', 'meta_description'], ['', '']],
        };
        $ukTpl = array_map(static fn($k) => $k !== '' && self::hasUk($k), $tpl);
        $cats = $type === 'product' ? self::productCats($rows) : [];
        $rows = self::withText($type, $rows);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $auto = $type === 'product' ? self::auto('product', $r, $cats[$id]) : self::auto($type, $r);
            $cells = [];
            foreach (['title' => 0, 'desc' => 1] as $f => $i) {
                $mine = trim((string) ($r[$own[$i]] ?? ''));
                $made = trim($auto[$f]);
                $text = $mine !== '' ? $mine : $made;
                $uk = trim((string) ($r[$own[$i] . '_uk'] ?? ''));
                $cells[$f] = [
                    'state'  => self::state($mine, $f === 'title' ? 'title' : 'description', $made),
                    'own'    => $mine !== '',
                    'text'   => $text,
                    'len'    => mb_strlen($text),
                    'uk'     => match (true) {
                        $uk !== ''       => 'own',
                        $mine !== ''     => 'ru',
                        $text === ''     => 'none',
                        $tpl[$i] === ''  => 'tpl',
                        default          => $ukTpl[$i] ? 'tpl' : 'ru',
                    },
                    'uk_len' => mb_strlen($uk),
                ];
            }
            $out[$id] = $cells;
        }
        return $out;
    }

    /**
     * Текст страниц и статей для отрывка (description по умолчанию — Seo::excerpt), если его нет в строках списка:
     * одним запросом на все строки. Прочие типы — как есть.
     */
    private static function withText(string $type, array $rows): array
    {
        $cols = ['page' => ['pages', ['content', 'content_uk']], 'blog' => ['blog_posts', ['text_before_cut', 'text_before_cut_uk', 'text', 'text_uk']]][$type] ?? null;
        if ($cols === null || !$rows || array_key_exists($cols[1][0], reset($rows))) return $rows;
        $db = App::db();
        [$ph, $vals] = $db->in(array_map(static fn($r) => (int) $r['id'], $rows));
        $text = $db->keyed('SELECT id, ' . implode(', ', $cols[1]) . " FROM `{$cols[0]}` WHERE id IN ($ph)", $vals);
        foreach ($rows as &$r) $r += $text[(int) $r['id']] ?? array_fill_keys($cols[1], null);
        unset($r);
        return $rows;
    }

    /**
     * Условие SQL фильтра ?seo= (пороги — как у state(), длина — по TRIM, как в sql()); '' — без условия.
     * $t, $d — колонки своих title/description из кода вызывающего ('p.meta_title'), не из запроса.
     * Длина — корзинами INTERVAL, как в productStats (TRIM один раз на колонку: 1 — короче минимума, 3 — длиннее максимума;
     * NULL → -1, пусто → 0). У товаров без индекса: COUNT по 107 тыс. — ~0,1 с, «без своего title» — ~0,05 с.
     */
    public static function listWhere(string $filter, string $t, string $d): string
    {
        $empty = static fn(string $c): string => "($c IS NULL OR TRIM($c) = '')";
        $warn = static fn(string $c, int $min, int $max): string => "INTERVAL(CHAR_LENGTH(TRIM($c)), 1, $min, " . ($max + 1) . ') IN (1, 3)';
        return match ($filter) {
            'notitle' => $empty($t),
            'nodesc'  => $empty($d),
            'len'     => '(' . $warn($t, self::TITLE_MIN, self::TITLE_MAX) . ' OR ' . $warn($d, self::DESC_MIN, self::DESC_MAX) . ')',
            default   => '',
        };
    }

    /** Счётчики фильтра одним запросом: «SUM(…) AS notitle, SUM(…) AS nodesc, SUM(…) AS len» */
    public static function listCountSql(string $t, string $d): string
    {
        $out = [];
        foreach (['notitle', 'nodesc', 'len'] as $f) $out[] = 'COALESCE(SUM(' . self::listWhere($f, $t, $d) . "), 0) AS `$f`";
        return implode(', ', $out);
    }

    /** Подходит ли строка под фильтр ?seo= — для списков, которые фильтруются в PHP (ячейки из listCells) */
    public static function listMatches(array $cells, string $filter): bool
    {
        return match ($filter) {
            'notitle' => !$cells['title']['own'],
            'nodesc'  => !$cells['desc']['own'],
            'len'     => $cells['title']['state'] === 'warn' || $cells['desc']['state'] === 'warn',
            default   => true,
        };
    }

    /** Счётчики фильтра по ячейкам listCells(): ['' => всего, 'notitle' => …, 'nodesc' => …, 'len' => …] */
    public static function listCounts(array $cells): array
    {
        $n = array_fill_keys(array_keys(self::LIST_FILTERS), 0);
        foreach ($cells as $c) foreach ($n as $f => $_) if (self::listMatches($c, $f)) $n[$f]++;
        return $n;
    }

    // ======================================================================= итоги

    /** Итог «что видят поисковики» по всем адресам sitemap: сколько title/description в каждом состоянии */
    public static function tally(array $rows, array $st): array
    {
        $t = array_fill_keys(array_keys(self::STATES), 0);
        foreach ($rows as $r) {
            if (!$r['sitemap']) continue;
            $t[$r['title_state']]++;
            $t[$r['desc_state']]++;
        }
        $titles = ['none' => 0, 'warn' => $st['t_warn'], 'auto' => $st['t_empty'], 'ok' => $st['total'] - $st['t_warn'] - $st['t_empty']];
        $descTpl = self::productDescTemplate();
        $descs = ['none' => $descTpl ? 0 : $st['d_empty'], 'warn' => $st['d_warn'], 'auto' => $descTpl ? $st['d_empty'] : 0,
            'ok' => $st['total'] - $st['d_warn'] - $st['d_empty']];
        foreach ($t as $k => $_) $t[$k] += $titles[$k] + $descs[$k];
        return $t;
    }

    /** Отдельно по title и по description (для полосок): ['title' => [state => n], 'desc' => …] */
    public static function split(array $rows, array $st): array
    {
        $out = ['title' => array_fill_keys(array_keys(self::STATES), 0), 'desc' => array_fill_keys(array_keys(self::STATES), 0)];
        foreach ($rows as $r) {
            if (!$r['sitemap']) continue;
            $out['title'][$r['title_state']]++;
            $out['desc'][$r['desc_state']]++;
        }
        $descTpl = self::productDescTemplate();
        $out['title']['warn'] += $st['t_warn'];
        $out['title']['auto'] += $st['t_empty'];
        $out['title']['ok'] += $st['total'] - $st['t_warn'] - $st['t_empty'];
        $out['desc']['warn'] += $st['d_warn'];
        $out['desc'][$descTpl ? 'auto' : 'none'] += $st['d_empty'];
        $out['desc']['ok'] += $st['total'] - $st['d_warn'] - $st['d_empty'];
        return $out;
    }

    /**
     * Сколько адресов в sitemap.xml по видам (как Front\SitemapController): русская версия; украинская (/ua/…) —
     * те же адреса в отдельных файлах sitemap-ua-*.xml. Файлы: blog, site, shop-1..N по 10 000 адресов
     * (главная, категории, бренды, товары, страницы магазина), их украинские копии и карты фото товаров.
     */
    public static function sitemap(array $rows, array $st): array
    {
        $c = ['home' => 0, 'categories' => 0, 'brands' => 0, 'pages' => 0, 'blog' => 0, 'products' => $st['total']];
        $site = 0;
        foreach ($rows as $r) {
            if (!$r['sitemap']) continue;
            if ($r['kind'] === 'home') $c['home']++;
            else $c[$r['group']]++;
            if ($r['group'] === 'pages' && str_starts_with($r['path'], '/pages/')) $site++;
        }
        $c['total'] = array_sum($c);
        $shop = max(1, (int) ceil(($c['total'] - $c['blog'] - $site) / 10000));
        $c['files'] = 2 * (2 + $shop) + (int) ceil($st['total'] / 10000);
        $c['all'] = 2 * $c['total'];                        // вместе с украинской версией
        return $c;
    }

    /**
     * Покрытие украинской версии: по группам — сколько title/description на /ua/ с переводом.
     *   own  — «Свой перевод»: заполнено своё украинское значение (meta_title_uk, title_uk, «seo.….uk» у главной);
     *   tpl  — «Строится сам»: своего нет ни на одном языке, значение строится из шаблона с вариантом «.uk»
     *          или из названия / перевода интерфейса (название категории, страницы, «Відгуки»…);
     *   ru   — «Русский текст»: на /ua/ окажется русское значение — своё русское без перевода
     *          или шаблон, у которого нет украинского варианта;
     *   none — «Пусто»: мета-тега нет ни в одной версии.
     * $rows — строки русской версии (overview), $cover — покрытие товаров из productStats.
     */
    public static function ukCoverage(array $rows, array $cover): array
    {
        $zero = array_fill_keys(array_keys(self::UK_STATES), 0);
        $out = [];
        foreach (self::GROUPS as $g => $_) $out[$g] = ['n' => 0, 'title' => $zero, 'desc' => $zero];
        foreach ($rows as $r) {
            if (!$r['sitemap']) continue;
            $o = &$out[$r['group']];
            $o['n']++;
            foreach (['title' => 0, 'desc' => 1] as $f => $i) {
                $state = match (true) {
                    $r['uk'][$i]          => 'own',
                    $r[$f . '_own']       => 'ru',
                    $r[$f] === ''         => 'none',
                    $r['tpl'][$i] === ''  => 'tpl',
                    default               => self::hasUk($r['tpl'][$i]) ? 'tpl' : 'ru',
                };
                $o[$f][$state]++;
            }
            unset($o);
        }
        // товары: шаблоны seo.product_meta_*; без шаблона title = название (переводится через name_uk), description пуст
        $p = &$out['products'];
        $p['n'] = (int) $cover['total'];
        foreach (['title' => ['t', 'seo.product_meta_title'], 'desc' => ['d', 'seo.product_meta_description']] as $f => [$k, $key]) {
            $p[$f]['own'] = (int) $cover[$k . '_own'];
            $p[$f]['ru'] = (int) $cover[$k . '_ru'];
            $auto = (int) $cover[$k . '_auto'];
            $tpl = self::filled(Settings::all()[$key] ?? '');
            $state = $tpl ? (self::hasUk($key) ? 'tpl' : 'ru') : ($f === 'title' ? 'tpl' : 'none');
            $p[$f][$state] += $auto;
        }
        unset($p);
        return $out;
    }

    /** Фильтр строк: подходит ли строка под чип */
    public static function matches(array $r, string $filter): bool
    {
        return match ($filter) {
            ''       => $r['all'],
            'closed' => $r['closed'] !== '',
            default  => $r['all'] && ($r['title_state'] === $filter || $r['desc_state'] === $filter),
        };
    }

    // ======================================================================= шаблоны

    /**
     * Пример для превью шаблонов: товар (по номеру из ?sample= или последний активный с категорией),
     * его категория (для шаблонов товара и категории), первая инфо-страница и бренд с товарами.
     */
    public static function sample(int $productId = 0): array
    {
        $db = App::db();
        $cols = 'SELECT id, url, name, name_uk, sku, seo_name, seo_name_uk, price, box_qty, size, category_id, status FROM products';
        $p = $productId > 0 ? $db->row($cols . ' WHERE id = ?', [$productId]) : null;
        $p ??= $db->row($cols . ' WHERE status = 1 AND category_id IS NOT NULL ORDER BY id DESC LIMIT 1');
        $cat = $p ? self::productCategory($p) : null;
        if (!$cat) {
            $all = Catalog::categories();
            $cat = $all ? reset($all) : null;
        }
        $page = $db->row("SELECT id, url, name, name_uk, title, title_uk FROM pages WHERE status = 1 AND url NOT LIKE 'pages/%' ORDER BY sort, id LIMIT 1");
        $brand = $db->row('SELECT id, name, name_uk, url, product_count FROM brands WHERE hidden = 0 AND product_count > 0 ORDER BY product_count DESC, id LIMIT 1');
        return ['product' => $p, 'category' => $cat, 'page' => $page, 'brand' => $brand];
    }

    /**
     * Текущие SEO-шаблоны и их результат на примере — для обеих версий сайта. Группы — как во вкладке
     * «Настройки → SEO-шаблоны». used = false: шаблон хранится в настройках (перенесён из Webasyst), но витрина его
     * не применяет. uk — есть ли украинский вариант «<ключ>.uk» (иначе на /ua/ подставляется русский шаблон);
     * result_uk — результат на /ua/ на том же примере (украинские названия товара и категории, если заполнены).
     */
    public static function templates(array $sample): array
    {
        $ru = self::templateList($sample);
        $loc = static fn($r) => is_array($r) ? Lang::localize($r) : $r;
        $uk = self::inLang('uk', static fn() => self::templateList(array_map($loc, $sample)));
        $raw = Settings::all();
        foreach ($ru as $i => &$t) {
            $t['uk'] = self::hasUk($t['key']);
            $t['tpl_uk'] = $t['uk'] ? trim((string) $raw[$t['key'] . '.uk']) : '';
            $t['result_uk'] = $uk[$i]['result'];
            $t['len_uk'] = $uk[$i]['len'];
            $t['state_uk'] = $uk[$i]['state'];
        }
        unset($t);
        return $ru;
    }

    /** Шаблоны и результат на примере в текущей версии сайта (Settings::get на /ua/ берёт «….uk») */
    private static function templateList(array $sample): array
    {
        $p = $sample['product'];
        $c = $sample['category'];
        $pg = $sample['page'];
        $pVars = $p ? self::productVars($p, $c) : [];
        $cVars = self::categoryVars($c);
        $cpVars = $cVars + ['page_number' => 2];
        $gVars = ['page' => ['name' => (string) ($pg['name'] ?? 'О компании'), 'title' => '']];
        $bVars = SeoVars::brand($sample['brand'] ?? ['name' => 'Jong Golf']);
        $catOn = (bool) Settings::get('seo.category_is_enabled', 1);
        $pagOn = (bool) Settings::get('seo.category_pagination_is_enabled', 0);
        $pageOn = (string) Settings::get('seo.page_is_enabled', '1') !== '0';
        $brandOn = (string) Settings::get('seo.brand_is_enabled', '1') !== '0';
        $groups = [
            'Главная' => [['Title', 'seo.home_page_meta_title', [], 'title', true], ['Description', 'seo.home_page_meta_description', [], 'description', true]],
            'Категория' => [['Title', 'seo.category_meta_title', $cVars, 'title', $catOn], ['Description', 'seo.category_meta_description', $cVars, 'description', $catOn],
                ['H1', 'seo.category_h1', $cVars, '', false]],
            'Категория, страницы 2, 3…' => [['Title', 'seo.category_pagination_meta_title', $cpVars, 'title', $catOn && $pagOn],
                ['Description', 'seo.category_pagination_meta_description', $cpVars, 'description', $catOn && $pagOn]],
            'Товар' => [['Title', 'seo.product_meta_title', $pVars, 'title', true], ['Description', 'seo.product_meta_description', $pVars, 'description', true],
                ['H1', 'seo.product_h1', $pVars, '', true]],
            'Инфо-страница' => [['Title', 'seo.page_meta_title', $gVars, 'title', $pageOn], ['Description', 'seo.page_meta_description', $gVars, 'description', $pageOn]],
            'Бренд' => [['Title', 'seo.brand_meta_title', $bVars, 'title', $brandOn], ['Description', 'seo.brand_meta_description', $bVars, 'description', $brandOn]],
            'Отзывы о магазине /reviews/' => [['Title', 'seo.reviews_meta_title', [], 'title', true], ['Description', 'seo.reviews_meta_description', [], 'description', true]],
            'Карта сайта /sitemap/' => [['Title', 'seo.sitemap_meta_title', [], 'title', true], ['Description', 'seo.sitemap_meta_description', [], 'description', true]],
        ];
        $out = [];
        foreach ($groups as $where => $items) {
            foreach ($items as [$field, $key, $vars, $kind, $used]) {
                $tpl = trim((string) Settings::get($key, ''));
                $res = $tpl !== '' ? Seo::pick('', $key, $vars) : '';
                // страницы 2, 3… категории: витрина дописывает « | Страница N» и к результату шаблона (Seo::paginate)
                if ($res !== '' && isset($vars['page_number'])) $res .= ' | ' . t('Страница') . ' ' . $vars['page_number'];
                $out[] = ['where' => $where, 'field' => $field, 'key' => $key, 'tpl' => $tpl, 'result' => $res, 'used' => $used,
                    'len' => mb_strlen($res), 'state' => $kind !== '' ? self::lengthState($res, $kind) : ($res !== '' ? 'ok' : 'none'), 'kind' => $kind];
            }
        }
        return $out;
    }

    /** Служебное: robots.txt, адрес сайта в sitemap, предупреждения */
    public static function service(array $rows): array
    {
        $robots = self::robots();
        $base = rtrim(url('/'), '/');
        $warn = [];
        if (!preg_match('#^https://#', $base) || preg_match('#localhost|127\.0\.0\.1#', $base)) {
            $warn[] = 'Адреса в sitemap.xml и robots.txt строятся от base_url в config/config.php — сейчас ' . $base
                . '. На рабочем сервере там должно быть https://tomobuv.com.ua.';
        }
        foreach ($rows as $r) {
            if ($r['kind'] === 'home' && str_starts_with($r['closed'], 'robots.txt')) {
                $warn[] = 'robots.txt закрывает от поисковиков весь сайт (' . $r['closed'] . ').';
            }
        }
        $disallow = count(array_filter($robots['rules'], static fn($x) => $x[0] === 'disallow'));
        return ['base' => $base, 'robots_custom' => $robots['custom'], 'robots_text' => $robots['text'], 'disallow' => $disallow,
            'sitemaps' => $robots['sitemaps'], 'warn' => $warn];
    }

    // ======================================================================= robots.txt

    /** robots.txt сайта (тот же текст, что отдаёт витрина) и правила для User-agent: * */
    public static function robots(): array
    {
        return Cache::remember('seo.audit.robots', self::TTL, static function () {
            $txt = '';
            try {
                $txt = (string) self::inLang('ru', static fn() => (new \App\Controllers\Front\SitemapController())->robots()->body);
            } catch (\Throwable $e) {
                $txt = (string) Settings::get('robots_txt', '');
            }
            return ['text' => $txt, 'custom' => trim((string) Settings::get('robots_txt', '')) !== ''] + self::parseRobots($txt);
        });
    }

    /** Правила Allow/Disallow группы «*» и строки Sitemap */
    public static function parseRobots(string $txt): array
    {
        $rules = [];
        $sitemaps = [];
        $agents = [];
        $prevAgent = false;
        foreach (preg_split('/\R/', $txt) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if (!$prevAgent) $agents = [];
                $agents[] = strtolower($v);
                $prevAgent = true;
                continue;
            }
            $prevAgent = false;
            if ($k === 'sitemap') { $sitemaps[] = $v; continue; }
            if (($k === 'disallow' || $k === 'allow') && $v !== '' && in_array('*', $agents, true)) $rules[] = [$k, $v];
        }
        return ['rules' => $rules, 'sitemaps' => $sitemaps];
    }

    /** Каким правилом Disallow закрыт адрес (пусто — открыт). Как у Google: побеждает самое длинное правило, при равенстве — Allow */
    public static function blockedBy(string $path, array $rules): string
    {
        $best = '';
        $bestLen = -1;
        $bestAllow = true;
        foreach ($rules as [$type, $pattern]) {
            $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')) . '#';
            if (!@preg_match($re, $path)) continue;
            $len = strlen($pattern);
            if ($len > $bestLen || ($len === $bestLen && $type === 'allow')) {
                $best = $pattern;
                $bestLen = $len;
                $bestAllow = $type === 'allow';
            }
        }
        return $bestLen >= 0 && !$bestAllow ? $best : '';
    }
}
