<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;

/**
 * Бережный HTTP-клиент сайта поставщика (общий для загрузчиков со страниц сайта: «Хит обуви», Tom.m): только GET,
 * один поток, пауза между запросами не меньше настройки (≥ 1,5 с) — общая для админки, cron и CLI (GET_LOCK + метка
 * времени последнего запроса в storage/import/{код}/last-request), User-Agent с названием магазина и адресом сайта,
 * таймауты, robots.txt (кэш на сутки; запрещённые пути не запрашиваются), лимит запросов на запуск. Только адреса
 * самого поставщика (без редиректов на другие сайты). Наследник задаёт BASE (адрес сайта), SUPPLIER (класс адаптера —
 * папка для метки и robots.txt) и ERROR (класс ошибки).
 * Ответ 429/5xx — ошибка 'retry': запуск делает паузу 60 с с удвоением, после 3 подряд — останавливается;
 * 403, проверка Cloudflare и блок-страница сетевого фильтра — 'blocked': сразу ошибка «нужна проверка», без обхода.
 *
 * Без сети (тесты, повтор по сохранённому): $offline — папка с сохранёнными ответами (имя файла — cacheName(), рядом
 * .meta с адресом), отсутствующий ответ — ['code' => 0, 'missing' => true]. $cacheDir — то же, но недостающее
 * скачивается и сохраняется (сквозной кэш для проверок: повторно не скачивается). Cookie страниц (Tom.m:
 * products_per_page=100) входит в ключ сохранённого ответа — «адрес#cookie=…», как у кэша разбора.
 * Второй хост того же поставщика (HOSTS: фото Jong•Golf — public.jonggolf.com) — свой robots.txt, пауза и блокировка общие с BASE.
 */
class SiteClient
{
    /** Адрес сайта поставщика (https://хост) — задаёт наследник */
    public const BASE = '';
    /** Другие хосты того же поставщика, к которым можно обращаться (фото на отдельном хосте): ['public.jonggolf.com'] */
    public const HOSTS = [];
    public const UA = 'TomobuvLoader/1.0 (+https://tomobuv.com.ua)';
    public const MIN_DELAY = 1.5;
    /** Класс адаптера: папка storage/import/{код} (метка последнего запроса, robots.txt) */
    protected const SUPPLIER = Supplier::class;
    /** Класс ошибки обмена (наследник SupplierError) */
    protected const ERROR = SupplierError::class;
    private const MAX_BYTES = 20971520;

    private float $delay;
    private ?int $limit;
    private int $used = 0;
    private ?string $offline;
    private ?string $cacheDir;
    /** Правила robots.txt по хостам (на время жизни клиента) */
    private array $robots = [];
    /** Cookie запросов страниц (не фото) */
    protected ?string $cookie = null;
    /** Запросы в сеть этого процесса: [[код, секунды, адрес]] — для отчёта */
    public array $journal = [];

    public function __construct(float $delay = 2.0, ?int $limit = null, ?string $offline = null, ?string $cacheDir = null)
    {
        $this->delay = max(self::MIN_DELAY, $delay);
        $this->limit = $limit;
        $this->offline = $offline !== null ? rtrim(str_replace('\\', '/', $offline), '/') : null;
        $this->cacheDir = $cacheDir !== null ? rtrim(str_replace('\\', '/', $cacheDir), '/') : null;
    }

    /** Cookie для страниц (null — без cookie) */
    public function setCookie(?string $cookie): void
    {
        $this->cookie = $cookie !== null && $cookie !== '' ? $cookie : null;
    }

    /** Запросов в сеть сделано этим клиентом */
    public function used(): int
    {
        return $this->used;
    }

    /** Сколько запросов уже сделал запуск до этого шага (лимит — на весь запуск) */
    public function setUsed(int $n): void
    {
        $this->used = $n;
    }

    public function isOffline(): bool
    {
        return $this->offline !== null;
    }

    /** Есть ли сохранённый ответ на адрес (папка ответов или сквозной кэш) — его get() отдаст без запроса в сеть */
    public function cached(string $u, bool $plain = true): bool
    {
        $url = static::url($u);
        if ($url === null) return false;
        $key = $this->key($url, $plain);
        foreach (array_filter([$this->offline, $this->cacheDir]) as $dir) if (is_file($dir . '/' . self::cacheName($key))) return true;
        return false;
    }

    /**
     * Можно ли получить адрес: сохранённый ответ есть или robots.txt хоста разрешает запрос (фото Jong•Golf: robots.txt
     * public.jonggolf.com закрывает все пути с «/product» — и /files/products/; такие фото загрузчик не запрашивает)
     */
    public function obtainable(string $u, bool $plain = true): bool
    {
        $url = static::url($u);
        return $url !== null && ($this->cached($url, $plain) || $this->allowed($url));
    }

    /** Хост сайта поставщика (нижний регистр) */
    public static function host(): string
    {
        return strtolower((string) parse_url(static::BASE, PHP_URL_HOST));
    }

    /** Полный адрес: относительный путь → https://хост/…; другой хост поставщика (HOSTS) — https://он/…; чужой сайт — null */
    public static function url(string $u): ?string
    {
        $u = trim($u);
        if ($u === '') return null;
        if (str_starts_with($u, '//')) $u = 'https:' . $u;
        if (str_starts_with($u, '/')) return static::BASE . $u;
        $p = parse_url($u);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return null;
        $host = strtolower((string) ($p['host'] ?? ''));
        $tail = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        if ($host === static::host()) return static::BASE . $tail;
        return in_array($host, static::HOSTS, true) ? 'https://' . $host . $tail : null;
    }

    /** Имя файла сохранённого ответа (как у общего кэша разбора: путь_запрос.md5[0..10].html; md5 — от ключа с cookie) */
    public static function cacheName(string $url): string
    {
        $p = parse_url($url);
        $slug = (string) preg_replace('~[^a-zA-Z0-9._-]+~', '_', ($p['path'] ?? '/') . (isset($p['query']) ? '_' . $p['query'] : ''));
        $slug = substr(trim($slug, '_'), 0, 100) ?: 'root';
        return $slug . '.' . substr(md5($url), 0, 10) . '.html';
    }

    /** Ключ сохранённого ответа: адрес, у страниц с cookie — «адрес#cookie=…» */
    protected function key(string $url, bool $plain): string
    {
        return $plain || $this->cookie === null ? $url : $url . '#cookie=' . $this->cookie;
    }

    /** Ошибка обмена класса наследника */
    protected static function err(string $msg, string $kind, int $http = 0): SupplierError
    {
        $c = static::ERROR;
        return new $c($msg, $kind, $http);
    }

    /**
     * GET. $image — фото (без cookie, таймаут дольше); $plain — страница без cookie (карточка товара: cookie нужен только спискам).
     * @return array ['code', 'body', 'type', 'url', 'time', 'cached' => bool] (404 и прочие не-200 — как есть,
     * их решает вызывающий). @throws SupplierError retry | blocked | robots | limit | net | foreign
     */
    public function get(string $u, bool $image = false, bool $plain = false): array
    {
        $plain = $plain || $image;
        $url = static::url($u);
        if ($url === null) throw static::err('Адрес не на сайте поставщика: ' . mb_substr($u, 0, 200), 'foreign');
        $key = $this->key($url, $plain);
        foreach (array_filter([$this->offline, $this->cacheDir]) as $dir) {
            $file = $dir . '/' . self::cacheName($key);
            if (is_file($file)) {
                $meta = is_file($file . '.meta') ? json_decode((string) file_get_contents($file . '.meta'), true) : null;
                return ['code' => (int) ($meta['code'] ?? 200), 'body' => (string) file_get_contents($file), 'type' => '', 'url' => $url, 'time' => 0.0, 'cached' => true];
            }
        }
        if ($this->offline !== null) return ['code' => 0, 'body' => '', 'type' => '', 'url' => $url, 'time' => 0.0, 'cached' => true, 'missing' => true];
        if (!$this->allowed($url)) throw static::err('robots.txt поставщика запрещает адрес ' . parse_url($url, PHP_URL_PATH) . ' — не запрашиваю', 'robots');
        $r = $this->fetch($url, $image ? 45 : 30, $plain ? null : $this->cookie);   // страница обычно 1–7 с; молчащий сайт не держит шаг минутами
        if ($this->cacheDir !== null && $r['code'] === 200) {
            @mkdir($this->cacheDir, 0775, true);
            $file = $this->cacheDir . '/' . self::cacheName($key);
            file_put_contents($file, $r['body']);
            file_put_contents($file . '.meta', json_encode(['url' => $url, 'cookie' => $plain ? '' : (string) $this->cookie, 'code' => $r['code'], 'time' => date('c'), 'bytes' => strlen($r['body'])],
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        return $r;
    }

    /** Запрос в сеть: лимит, общая пауза, проверка ответа */
    private function fetch(string $url, int $timeout, ?string $cookie = null): array
    {
        if ($this->offline !== null) throw static::err('Режим без сети (сохранённые ответы) — запрос ' . mb_substr($url, 0, 200) . ' не выполняется', 'net');
        if ($this->limit !== null && $this->used >= $this->limit) throw static::err('Достигнут лимит запросов к сайту поставщика на запуск (' . $this->limit . ')', 'limit');
        if (!function_exists('curl_init')) throw static::err('На сервере нет расширения PHP curl', 'net');
        $res = null;
        $this->throttled(function () use ($url, $timeout, $cookie, &$res): void {
            $headers = [];
            $body = '';
            $ch = curl_init($url);
            $opt = [
                CURLOPT_FOLLOWLOCATION => false,                                  // редирект — как ответ (не уходим на чужие адреса)
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_USERAGENT => static::UA,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept-Language: ru,uk;q=0.8', 'Accept: text/html,image/*;q=0.9,*/*;q=0.5'],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $h) use (&$headers): int {
                    if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $headers[strtolower(trim($k))] = trim($v); }
                    return strlen($h);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $d) use (&$body): int {
                    if (strlen($body) + strlen($d) > self::MAX_BYTES) return 0;   // слишком большой ответ — прервать
                    $body .= $d;
                    return strlen($d);
                },
            ];
            if ($cookie !== null) $opt[CURLOPT_COOKIE] = $cookie;
            curl_setopt_array($ch, $opt);
            $t = microtime(true);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = $ok === false ? curl_error($ch) : '';
            curl_close($ch);
            $res = ['code' => $code, 'body' => $body, 'type' => strtolower((string) ($headers['content-type'] ?? '')), 'url' => $url,
                'time' => round(microtime(true) - $t, 2), 'cached' => false, 'headers' => $headers, 'error' => $err];
        });
        $this->used++;
        $this->journal[] = [$res['code'], $res['time'], $url];
        if ($res['error'] !== '' && $res['code'] === 0) throw static::err('Нет ответа от сайта поставщика: ' . $res['error'], 'retry', 0);
        if (self::filtered($res['body'])) throw static::err('Запрос к сайту поставщика закрыт сетевым фильтром (блок-страница, ответ ' . $res['code'] . ') — нужна проверка: загрузка остановлена, обходить фильтр загрузчик не будет', 'blocked', $res['code']);
        if (self::challenge($res['body'], $res['headers'])) throw static::err('Сайт поставщика включил проверку браузера (Cloudflare, ответ ' . $res['code'] . ') — нужна проверка: загрузка остановлена, обходить защиту загрузчик не будет', 'blocked', $res['code']);
        if ($res['code'] === 403) throw static::err('Сайт поставщика отказал в доступе (403) — нужна проверка: загрузка остановлена', 'blocked', 403);
        if ($res['code'] === 429 || $res['code'] >= 500) throw static::err('Сайт поставщика ответил ' . $res['code'] . ($res['code'] === 429 ? ' (слишком много запросов)' : ' (ошибка сервера)'), 'retry', $res['code']);
        return $res;
    }

    /** Выполнить запрос под общей блокировкой сайта с паузой от предыдущего запроса (любого процесса) */
    private function throttled(callable $fn): void
    {
        $db = App::db();
        $name = 'tomobuv_http_' . substr(md5((string) $db->value('SELECT DATABASE()') . '|' . static::host()), 0, 12);
        try { $got = (int) $db->value('SELECT GET_LOCK(?, 120)', [$name]); } catch (\PDOException) { $got = 1; }
        if ($got !== 1) throw static::err('Сайт поставщика занят другим запросом загрузчика дольше 2 мин', 'retry', 0);
        $sup = static::SUPPLIER;
        $stamp = $sup::dir() . '/last-request';
        try {
            $last = (float) @file_get_contents($stamp);
            $wait = $last + $this->delay - microtime(true);
            if ($wait > 0) usleep((int) ceil(min($wait, 60.0) * 1e6));
            try {
                $fn();
            } finally {
                @file_put_contents($stamp, sprintf('%.3F', microtime(true)));
            }
        } finally {
            try { $db->value('SELECT RELEASE_LOCK(?)', [$name]); } catch (\PDOException) {}
        }
    }

    /** Признаки защиты Cloudflare / «проверки браузера» в ответе */
    public static function challenge(string $body, array $headers): bool
    {
        if (isset($headers['cf-mitigated'])) return true;
        $head = substr($body, 0, 20000);
        return (bool) preg_match('~challenge-platform|cf-challenge|Just a moment\.\.\.|Attention Required! \| Cloudflare|cf_chl_~i', $head);
    }

    /** Блок-страница сетевого фильтра провайдера или офиса («Web Page Blocked … Category: …») вместо ответа сайта */
    public static function filtered(string $body): bool
    {
        return (bool) preg_match('~<title>\s*Web Page Blocked|Web Page Blocked!|The page you are attempting to access has been blocked~i', substr($body, 0, 20000));
    }

    // ------------------------------------------------------------------ robots.txt

    /** Разрешён ли адрес правилами robots.txt своего хоста (для «*» и TomobuvLoader; самое длинное совпадение, Allow при равенстве) */
    public function allowed(string $url): bool
    {
        $rules = $this->robotsRules(strtolower((string) parse_url($url, PHP_URL_HOST)));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $q = parse_url($url, PHP_URL_QUERY);
        if ($q !== null && $q !== false && $q !== '') $path .= '?' . $q;
        if ($path === '/robots.txt') return true;
        $best = -1; $allow = true;
        foreach ($rules as [$isAllow, $pattern]) {
            if ($pattern === '') { continue; }                               // «Disallow:» пустой — разрешено всё
            $re = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '~')) . '~';
            if (!str_starts_with($pattern, '/') && !str_starts_with($pattern, '*')) $re = '~' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '~')) . '~';
            if (preg_match($re, $path)) {
                $len = strlen($pattern);
                if ($len > $best || ($len === $best && $isAllow)) { $best = $len; $allow = $isAllow; }
            }
        }
        return $allow;
    }

    /**
     * Правила robots.txt хоста: [[allow, шаблон]] — файл на сутки в storage/import/{код}/robots.txt (основной хост)
     * или robots-{хост}.txt (другой хост поставщика из HOSTS)
     */
    private function robotsRules(?string $host = null): array
    {
        $host = $host !== null && $host !== '' && in_array($host, static::HOSTS, true) ? $host : static::host();
        if (isset($this->robots[$host])) return $this->robots[$host];
        $base = $host === static::host() ? static::BASE : 'https://' . $host;
        foreach (array_filter([$this->offline, $this->cacheDir]) as $dir) {          // сохранённый ответ (проверки) — без запроса
            $f = $dir . '/' . self::cacheName($base . '/robots.txt');
            if (is_file($f)) return $this->robots[$host] = self::parseRobots((string) file_get_contents($f));
        }
        $sup = static::SUPPLIER;
        $file = $sup::dir() . ($host === static::host() ? '/robots.txt' : '/robots-' . preg_replace('~[^a-z0-9.-]~', '_', $host) . '.txt');
        // без сети (сохранённые ответы): последняя сохранённая копия, нет её — правил нет (запросов всё равно не будет)
        if ($this->offline !== null) return $this->robots[$host] = is_file($file) ? self::parseRobots((string) file_get_contents($file)) : [];
        if (!is_file($file) || filemtime($file) < time() - 86400) {
            $r = $this->fetch($base . '/robots.txt', 30);
            if ($r['code'] === 200) file_put_contents($file, $r['body']);
            elseif ($r['code'] >= 400 && $r['code'] < 500) file_put_contents($file, '');   // нет файла — ограничений нет
            else throw static::err('robots.txt поставщика недоступен (ответ ' . $r['code'] . ') — без него не запрашиваю', 'retry', $r['code']);
        }
        return $this->robots[$host] = self::parseRobots((string) file_get_contents($file));
    }

    /**
     * Группы «User-agent: *» и «User-agent: TomobuvLoader» (своя группа важнее общей). Несколько групп «*» (у Webasyst —
     * по группе на приложение) складываются, как у поисковиков.
     */
    public static function parseRobots(string $txt): array
    {
        $groups = [];
        $agents = []; $inRules = false;
        foreach (preg_split('~\r\n|\r|\n~', $txt) ?: [] as $line) {
            $line = trim((string) preg_replace('~#.*$~', '', $line));
            if ($line === '' || !preg_match('~^([A-Za-z-]+)\s*:\s*(.*)$~', $line, $m)) continue;  // «Allow /x» без двоеточия — не правило
            $k = strtolower($m[1]); $v = trim($m[2]);
            if ($k === 'user-agent') {
                if ($inRules) { $agents = []; $inRules = false; }
                $agents[] = strtolower($v);
                continue;
            }
            if ($k !== 'allow' && $k !== 'disallow') continue;
            $inRules = true;
            foreach ($agents as $a) $groups[$a][] = [$k === 'allow', $v];
        }
        return $groups['tomobuvloader'] ?? $groups['*'] ?? [];
    }
}
