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
            && !str_starts_with(Request::path(), '/admin')
            && self::hostAllowed((string) ($_SERVER['HTTP_HOST'] ?? ''), (string) App::config('base_url', ''));
    }

    /**
     * Кэшируем только запросы на свой домен (хост base_url, с www или без; порт — как в base_url или стандартный).
     * Если хостинг отдаёт сайт на любой Host (сайт по умолчанию на IP), случайными Host нельзя плодить файлы кэша:
     * такие страницы работают, но без кэша. Локально (base_url на localhost/127.0.0.1) подходит любой локальный адрес и порт.
     */
    public static function hostAllowed(string $host, string $baseUrl): bool
    {
        $req = self::splitHost($host);
        $base = parse_url($baseUrl);
        if ($req === null || !is_array($base) || empty($base['host'])) return false;
        [$bHost, $bPort] = [strtolower((string) $base['host']), $base['port'] ?? null];
        $bHost = trim($bHost, '[]');
        $local = static fn(string $h): bool => in_array($h, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($h, '.localhost');
        if ($local($bHost)) return $local($req[0]);
        $strip = static fn(string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        if ($strip($req[0]) !== $strip($bHost)) return false;
        $port = $req[1];
        return $port === null || $port === $bPort || ($bPort === null && ($port === 80 || $port === 443));
    }

    /** «Example.com:8080» → ['example.com', 8080]; «[::1]:8080» → ['::1', 8080]; некорректный Host → null */
    private static function splitHost(string $host): ?array
    {
        $host = strtolower($host);
        if ($host === '' || strlen($host) > 255) return null;
        if (!preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?$/D', $host, $m)) return null;
        return [rtrim(trim($m[1], '[]'), '.'), isset($m[2]) ? (int) $m[2] : null];
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
        // Host в ключ не входит: кэш работает только для своего домена (enabled → hostAllowed), а страница от Host
        // не зависит (абсолютные ссылки — из base_url). Иначе www/без www, регистр и порт в Host дали бы копии файлов.
        return Lang::prefix() . Request::path() . $qs . $isAjax;
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

    /** Сохранить страницу; false — кэш для этого запроса выключен (nocache, чужой Host, мусорные параметры…) */
    public static function store(string $body, int $ttl, string $contentType = 'text/html; charset=utf-8'): bool
    {
        if (!self::enabled() || !($key = self::key()) || $ttl <= 0) return false;
        $f = self::file($key);
        @mkdir(dirname($f), 0775, true);
        $meta = json_encode(['e' => time() + $ttl, 't' => $contentType, 'h' => substr(md5($body), 0, 16)]);
        $tmp = $f . '.' . getmypid() . '.tmp';
        return file_put_contents($tmp, $meta . "\n" . $body, LOCK_EX) !== false && @rename($tmp, $f);
    }
}
