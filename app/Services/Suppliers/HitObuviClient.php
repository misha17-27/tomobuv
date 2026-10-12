<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;

/**
 * Бережный HTTP-клиент hit-obuvi.com.ua: только GET, один поток, пауза между запросами не меньше настройки (≥ 1,5 с) —
 * общая для админки, cron и CLI (GET_LOCK + метка времени последнего запроса в storage/import/hitobuvi/last-request),
 * User-Agent с названием магазина и адресом сайта, таймауты, robots.txt (кэш на сутки; запрещённые пути не запрашиваются),
 * лимит запросов на запуск. Только адреса самого поставщика (без редиректов на другие сайты).
 * Ответ 429/5xx — HitObuviError('retry'): запуск делает паузу 60 с с удвоением, после 3 подряд — останавливается;
 * 403 и проверка Cloudflare — HitObuviError('blocked'): сразу ошибка «нужна проверка», без попыток обхода.
 *
 * Без сети (тесты, повтор по сохранённому): $offline — папка с сохранёнными ответами (имя файла — cacheName(), рядом
 * .meta с адресом), отсутствующий ответ — ['code' => 0, 'missing' => true]. $cacheDir — то же, но недостающее
 * скачивается и сохраняется (сквозной кэш для проверок: повторно не скачивается).
 */
final class HitObuviClient
{
    public const BASE = 'https://hit-obuvi.com.ua';
    public const UA = 'TomobuvLoader/1.0 (+https://tomobuv.com.ua)';
    public const MIN_DELAY = 1.5;
    private const MAX_BYTES = 20971520;

    private float $delay;
    private ?int $limit;
    private int $used = 0;
    private ?string $offline;
    private ?string $cacheDir;
    private ?array $robots = null;
    /** Запросы в сеть этого процесса: [[код, секунды, адрес]] — для отчёта */
    public array $journal = [];

    public function __construct(float $delay = 2.0, ?int $limit = null, ?string $offline = null, ?string $cacheDir = null)
    {
        $this->delay = max(self::MIN_DELAY, $delay);
        $this->limit = $limit;
        $this->offline = $offline !== null ? rtrim(str_replace('\\', '/', $offline), '/') : null;
        $this->cacheDir = $cacheDir !== null ? rtrim(str_replace('\\', '/', $cacheDir), '/') : null;
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

    /** Полный адрес: относительный путь → https://hit-obuvi.com.ua/…; чужой сайт — null */
    public static function url(string $u): ?string
    {
        $u = trim($u);
        if ($u === '') return null;
        if (str_starts_with($u, '//')) $u = 'https:' . $u;
        if (str_starts_with($u, '/')) return self::BASE . $u;
        $p = parse_url($u);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || strtolower((string) ($p['host'] ?? '')) !== 'hit-obuvi.com.ua') return null;
        return self::BASE . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /** Имя файла сохранённого ответа (как у общего кэша разбора: путь_запрос.md5[0..10].html) */
    public static function cacheName(string $url): string
    {
        $p = parse_url($url);
        $slug = (string) preg_replace('~[^a-zA-Z0-9._-]+~', '_', ($p['path'] ?? '/') . (isset($p['query']) ? '_' . $p['query'] : ''));
        $slug = substr(trim($slug, '_'), 0, 100) ?: 'root';
        return $slug . '.' . substr(md5($url), 0, 10) . '.html';
    }

    /**
     * GET. @return array ['code', 'body', 'type', 'url', 'time', 'cached' => bool] (404 и прочие не-200 — как есть,
     * их решает вызывающий). @throws HitObuviError retry | blocked | robots | limit | net | foreign
     */
    public function get(string $u, bool $image = false): array
    {
        $url = self::url($u);
        if ($url === null) throw new HitObuviError('Адрес не на сайте поставщика: ' . mb_substr($u, 0, 200), 'foreign');
        foreach (array_filter([$this->offline, $this->cacheDir]) as $dir) {
            $file = $dir . '/' . self::cacheName($url);
            if (is_file($file)) {
                $meta = is_file($file . '.meta') ? json_decode((string) file_get_contents($file . '.meta'), true) : null;
                return ['code' => (int) ($meta['code'] ?? 200), 'body' => (string) file_get_contents($file), 'type' => '', 'url' => $url, 'time' => 0.0, 'cached' => true];
            }
        }
        if ($this->offline !== null) return ['code' => 0, 'body' => '', 'type' => '', 'url' => $url, 'time' => 0.0, 'cached' => true, 'missing' => true];
        if (!$this->allowed($url)) throw new HitObuviError('robots.txt поставщика запрещает адрес ' . parse_url($url, PHP_URL_PATH) . ' — не запрашиваю', 'robots');
        $r = $this->fetch($url, $image ? 60 : 90);
        if ($this->cacheDir !== null && $r['code'] === 200) {
            @mkdir($this->cacheDir, 0775, true);
            $file = $this->cacheDir . '/' . self::cacheName($url);
            file_put_contents($file, $r['body']);
            file_put_contents($file . '.meta', json_encode(['url' => $url, 'code' => $r['code'], 'time' => date('c'), 'bytes' => strlen($r['body'])], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        return $r;
    }

    /** Запрос в сеть: лимит, общая пауза, проверка ответа */
    private function fetch(string $url, int $timeout): array
    {
        if ($this->limit !== null && $this->used >= $this->limit) throw new HitObuviError('Достигнут лимит запросов к сайту поставщика на запуск (' . $this->limit . ')', 'limit');
        if (!function_exists('curl_init')) throw new HitObuviError('На сервере нет расширения PHP curl', 'net');
        $res = null;
        $this->throttled(function () use ($url, $timeout, &$res): void {
            $headers = [];
            $body = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,                                  // редирект — как ответ (не уходим на чужие адреса)
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_USERAGENT => self::UA,
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
            ]);
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
        if ($res['error'] !== '' && $res['code'] === 0) throw new HitObuviError('Нет ответа от сайта поставщика: ' . $res['error'], 'retry', 0);
        if (HitObuviParser::challenge($res['body'], $res['headers'])) throw new HitObuviError('Сайт поставщика включил проверку браузера (Cloudflare, ответ ' . $res['code'] . ') — нужна проверка: загрузка остановлена, обходить защиту загрузчик не будет', 'blocked', $res['code']);
        if ($res['code'] === 403) throw new HitObuviError('Сайт поставщика отказал в доступе (403) — нужна проверка: загрузка остановлена', 'blocked', 403);
        if ($res['code'] === 429 || $res['code'] >= 500) throw new HitObuviError('Сайт поставщика ответил ' . $res['code'] . ($res['code'] === 429 ? ' (слишком много запросов)' : ' (ошибка сервера)'), 'retry', $res['code']);
        return $res;
    }

    /** Выполнить запрос под общей блокировкой с паузой от предыдущего запроса (любого процесса) */
    private function throttled(callable $fn): void
    {
        $db = App::db();
        $name = 'tomobuv_hit_http_' . substr(md5((string) $db->value('SELECT DATABASE()')), 0, 12);
        try { $got = (int) $db->value('SELECT GET_LOCK(?, 120)', [$name]); } catch (\PDOException) { $got = 1; }
        if ($got !== 1) throw new HitObuviError('Сайт поставщика занят другим запросом загрузчика дольше 2 мин', 'retry', 0);
        $stamp = HitObuvi::dir() . '/last-request';
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

    // ------------------------------------------------------------------ robots.txt

    /** Разрешён ли адрес правилами robots.txt (для «*» и TomobuvLoader; самое длинное совпадение, Allow при равенстве) */
    public function allowed(string $url): bool
    {
        $rules = $this->robotsRules();
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

    /** Правила robots.txt: [[allow, шаблон]] — файл на сутки в storage/import/hitobuvi/robots.txt */
    private function robotsRules(): array
    {
        if ($this->robots !== null) return $this->robots;
        foreach (array_filter([$this->offline, $this->cacheDir]) as $dir) {          // сохранённый ответ (проверки) — без запроса
            $f = $dir . '/' . self::cacheName(self::BASE . '/robots.txt');
            if (is_file($f)) return $this->robots = self::parseRobots((string) file_get_contents($f));
        }
        $file = HitObuvi::dir() . '/robots.txt';
        if (!is_file($file) || filemtime($file) < time() - 86400) {
            $r = $this->fetch(self::BASE . '/robots.txt', 30);
            if ($r['code'] === 200) file_put_contents($file, $r['body']);
            elseif ($r['code'] >= 400 && $r['code'] < 500) file_put_contents($file, '');   // нет файла — ограничений нет
            else throw new HitObuviError('robots.txt поставщика недоступен (ответ ' . $r['code'] . ') — без него не запрашиваю', 'retry', $r['code']);
        }
        return $this->robots = self::parseRobots((string) file_get_contents($file));
    }

    /** Группы «User-agent: *» и «User-agent: TomobuvLoader» (своя группа важнее общей) */
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
