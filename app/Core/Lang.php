<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Языки витрины: русский — на прежних адресах (/category/x/), украинский — с префиксом /ua/ (/ua/category/x/).
 *
 * Как это работает (без переделки каждого шаблона):
 *  - index.php вызывает Lang::detect(): снимает префикс /ua, дальше роутер видит обычные адреса;
 *  - контент: у таблиц есть колонки *_uk (name_uk, meta_title_uk, content_uk…). В украинской версии
 *    DB автоматически подставляет значение x_uk вместо x, если оно заполнено (Lang::localize) — иначе русский текст;
 *  - настройки: Settings::get('address') в украинской версии берёт 'address.uk', если он задан;
 *  - интерфейс: t('Корзина') → «Кошик» из словаря lang/uk.php (ключ — русская фраза; нет перевода — русская);
 *  - ссылки: в готовом HTML украинской страницы внутренние href/action="/…" получают префикс /ua (Lang::rewriteLinks);
 *  - кэш данных и страниц — отдельный для каждого языка.
 * Админка всегда на русском и работает с обоими наборами полей.
 */
final class Lang
{
    public const DEFAULT = 'ru';
    public const LANGS = ['ru' => '', 'uk' => '/ua'];   // язык → префикс адреса
    public const NAMES = ['ru' => 'RU', 'uk' => 'UA'];
    public const HREFLANG = ['ru' => 'ru-UA', 'uk' => 'uk-UA'];

    private static string $lang = self::DEFAULT;
    private static ?array $dict = null;

    /** Определить язык по пути и вернуть путь без префикса: /ua/category/x/ → [uk, /category/x/] */
    public static function detect(string $path): string
    {
        if ($path === '/ua' || str_starts_with($path, '/ua/')) {
            self::$lang = 'uk';
            $rest = substr($path, 3);
            return $rest === '' || $rest === false ? '/' : $rest;
        }
        self::$lang = self::DEFAULT;
        return $path;
    }

    public static function set(string $lang): void
    {
        self::$lang = isset(self::LANGS[$lang]) ? $lang : self::DEFAULT;
    }

    public static function current(): string
    {
        return self::$lang;
    }

    public static function isUk(): bool
    {
        return self::$lang === 'uk';
    }

    public static function prefix(?string $lang = null): string
    {
        return self::LANGS[$lang ?? self::$lang] ?? '';
    }

    /** Путь для языка: Lang::path('/category/x/', 'uk') → /ua/category/x/ */
    public static function path(string $path, ?string $lang = null): string
    {
        $p = self::prefix($lang);
        if ($p === '') return $path;
        return $path === '/' ? $p . '/' : $p . $path;
    }

    /** Абсолютный адрес текущей страницы на другом языке (для hreflang и переключателя) */
    public static function alternate(string $lang, ?string $pathWithQuery = null): string
    {
        if ($pathWithQuery === null) {
            // метки рекламы (utm_*, gclid…) в hreflang и переключатель не переносим — страница из кэша общая для всех
            $qs = implode('&', array_filter(explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')), static fn($pair) => $pair !== ''
                && !preg_match('/^(utm_\w+|gclid|fbclid|yclid|gbraid|wbraid|_openstat|from|ref)$/i', urldecode(explode('=', $pair, 2)[0]))));
            $pathWithQuery = self::requestPath() . ($qs !== '' ? '?' . $qs : '');
        }
        return url(self::path($pathWithQuery, $lang));
    }

    /**
     * Путь текущего запроса без префикса /ua — закодированный, как пришёл (/brand/QQ%26%D0%9F…/), а не раскодированный
     * Request::path() (/brand/QQ&Панда/): hreflang и переключатель языка совпадают с canonical, «#», «?» в имени не ломают адрес.
     */
    private static function requestPath(): string
    {
        $p = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $p = '/' . ltrim((string) preg_replace_callback("#[^A-Za-z0-9\\-._~!$&'()*+,;=:@/%]#", static fn($m) => rawurlencode($m[0]), $p), '/');
        if ($p === '/ua' || str_starts_with($p, '/ua/')) $p = substr($p, 3) ?: '/';
        return $p;
    }

    /** Абсолютный URL сайта → вариант текущего языка (canonical, og:url) */
    public static function absUrl(?string $abs): ?string
    {
        if ($abs === null || $abs === '' || !self::isUk()) return $abs;
        $base = rtrim((string) App::config('base_url', ''), '/');
        if (!str_starts_with($abs, $base . '/')) return $abs;
        $path = substr($abs, strlen($base));
        return str_starts_with($path, '/ua/') ? $abs : $base . self::path($path);
    }

    /** Перевод фразы интерфейса. Ключ — русский текст; {name} — подстановки. */
    public static function t(string $ru, array $vars = []): string
    {
        $s = $ru;
        if (self::$lang !== self::DEFAULT) {
            self::$dict ??= self::load(self::$lang);
            $s = self::$dict[$ru] ?? $ru;
        }
        if ($vars) {
            $s = strtr($s, array_combine(array_map(static fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', array_values($vars))));
        }
        return $s;
    }

    /**
     * Словарь языка: lang/uk/*.php (у каждого раздела свой файл: core.php, catalog.php, checkout.php…),
     * каждый возвращает ['Русская фраза' => 'Українська фраза']. Фразы для JS — с префиксом ключа «js:».
     */
    private static function load(string $lang): array
    {
        $dict = [];
        foreach (glob(ROOT . '/lang/' . $lang . '/*.php') ?: [] as $f) {
            $part = include $f;
            if (is_array($part)) $dict += $part;
        }
        return $dict;
    }

    /** Весь словарь текущего языка (layout передаёт в JS фразы с ключами «js:…») */
    public static function dict(): array
    {
        if (self::$lang === self::DEFAULT) return [];
        return self::$dict ??= self::load(self::$lang);
    }

    /** Подставить x_uk вместо x в строке из БД (если заполнено) — вызывает DB в украинской версии */
    public static function localize(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($k) && str_ends_with($k, '_uk')) {
                $base = substr($k, 0, -3);
                if (array_key_exists($base, $row) && $v !== null && $v !== '') $row[$base] = $v;
            }
        }
        return $row;
    }

    /**
     * Добавить префикс /ua ко внутренним ссылкам в готовом HTML (и во фрагментах HTML внутри JSON).
     * Не трогает статику, фото, админку, служебные AJAX-адреса и абсолютные ссылки.
     */
    public static function rewriteLinks(string $html): string
    {
        if (!self::isUk()) return $html;
        $skip = 'ua/|ua\\\\?["\']|assets/|wa-data/|uploads/|admin/|favicon|robots\.txt|sitemap[^/"\']*\.xml|cart/(?:add|update|remove|clear|json)/|request/|quickorder/|search/suggest/|products/cards/|/';
        return (string) preg_replace('#\b(href|action)=(\\\\?["\'])/(?!' . $skip . ')#', '$1=$2/ua/', $html);
    }
}
