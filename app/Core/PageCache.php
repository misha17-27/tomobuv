<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Кэш готовых HTML-страниц витрины — главный ускоритель на обычном хостинге.
 *
 * Страницы витрины не содержат персональных данных: корзина, избранное, сравнение,
 * валюта и «вход в кабинет» рисуются в браузере из cookie (assets/js/app.js).
 * Поэтому одна и та же страница подходит всем посетителям и отдаётся из файла
 * за 1–3 мс — ещё до подключения к базе данных.
 *
 * Контроллер помечает ответ как кэшируемый: Response::html($html)->cache(3600).
 * Сброс — Cache::flush() (меняет версию, старые файлы удалит bin/cron.php).
 */
final class PageCache
{
    /** Параметры запроса, влияющие на содержимое. Остальные (utm_*, gclid, fbclid…) игнорируются. */
    private const ALLOWED_QUERY = ['page', 'sort', 'order', 'query', 'q', 'price_min', 'price_max', 'in_stock', 'f', 'view', 'letter', 'ids'];
    /**
     * Вид значений этих параметров. Другое значение (?sort=abc123, ?page=x…) страницу не меняет — такой адрес
     * не кэшируется: иначе перебором мусорных значений можно было бы плодить файлы кэша и забить диск.
     */
    private const QUERY_FORMAT = [
        'page' => '/^[1-9]\d{0,5}$/', 'sort' => '/^(create_datetime|price|name)$/', 'order' => '/^(asc|desc)$/i',
        'view' => '/^(grid|table)$/', 'in_stock' => '/^[01]$/', 'letter' => '/^\X$/u', 'ids' => '/^\d{1,10}(,\d{1,10}){0,19}$/',
        'price_min' => '/^[\d ]{1,12}([.,]\d{1,2})?$/', 'price_max' => '/^[\d ]{1,12}([.,]\d{1,2})?$/',
    ];

    public static function enabled(): bool
    {
        return (bool) App::config('cache.pages', true)
            && PHP_SAPI !== 'cli'
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && empty($_COOKIE['nocache'])
            && !str_starts_with(Request::path(), '/admin');
    }

    public static function key(): ?string
    {
        $q = $_GET;
        foreach (array_keys($q) as $k) {
            $k = (string) $k;
            if (isset(self::QUERY_FORMAT[$k]) && (!is_string($q[$k]) || !preg_match(self::QUERY_FORMAT[$k], $q[$k]))) return null;
            if (in_array($k, self::ALLOWED_QUERY, true) || preg_match('/^f\d+$/', $k)) continue;
            // рекламные метки на содержимое не влияют — одна и та же страница в кэше
            if (preg_match('/^(utm_\w+|gclid|fbclid|yclid|gbraid|wbraid|_openstat|from|ref)$/i', $k)) { unset($q[$k]); continue; }
            return null;   // прочие параметры (фильтры ?brand[]=…, _balance_type и т.п.) — страница не кэшируется
        }
        ksort($q);
        $qs = $q ? '?' . http_build_query($q) : '';
        if (strlen($qs) > 500) return null;   // слишком сложные фильтры не кэшируем
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') ? ':ajax' : '';
        return ($_SERVER['HTTP_HOST'] ?? '') . Lang::prefix() . Request::path() . $qs . $isAjax;
    }

    private static function file(string $key): string
    {
        $h = md5($key);
        return STORAGE . '/cache/pages/' . Cache::version() . '/' . substr($h, 0, 2) . '/' . $h . '.html';
    }

    /** Отдать страницу из кэша, если есть. Вызывается в самом начале public/index.php. */
    public static function serve(): bool
    {
        if (!self::enabled() || !($key = self::key())) return false;
        $f = self::file($key);
        if (!is_file($f)) return false;
        $fh = @fopen($f, 'rb');
        if (!$fh) return false;
        $meta = json_decode((string) fgets($fh), true);
        if (!is_array($meta) || ($meta['e'] ?? 0) < time()) { fclose($fh); return false; }
        $etag = '"' . ($meta['h'] ?? '') . '"';
        header('Content-Type: ' . ($meta['t'] ?? 'text/html; charset=utf-8'));
        Response::securityHeaders();
        header('X-Cache: HIT');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=0, must-revalidate');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            fclose($fh);
            return true;
        }
        fpassthru($fh);
        fclose($fh);
        return true;
    }

    public static function store(string $body, int $ttl, string $contentType = 'text/html; charset=utf-8'): void
    {
        if (!self::enabled() || !($key = self::key()) || $ttl <= 0) return;
        $f = self::file($key);
        @mkdir(dirname($f), 0775, true);
        $meta = json_encode(['e' => time() + $ttl, 't' => $contentType, 'h' => substr(md5($body), 0, 16)]);
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $meta . "\n" . $body, LOCK_EX) !== false) @rename($tmp, $f);
    }
}
