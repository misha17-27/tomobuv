<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?string $path = null;
    private static ?string $ip = null;

    /** Путь без query-строки, всегда с ведущим «/»: /category/x/ */
    public static function path(): string
    {
        if (self::$path === null) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $p = parse_url($uri, PHP_URL_PATH);
            $p = is_string($p) ? rawurldecode($p) : '/';
            self::$path = '/' . ltrim($p, '/');
        }
        return self::$path;
    }

    /** Подменить путь (index.php снимает языковой префикс /ua) */
    public static function setPath(string $path): void
    {
        self::$path = $path;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /** GET-параметр строкой (обрезанный) */
    public static function get(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = $_GET[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** POST-параметр строкой */
    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function postInt(string $key, int $default = 0): int
    {
        $v = $_POST[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function postArray(string $key): array
    {
        $v = $_POST[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    /** Тело JSON-запроса */
    public static function json(): array
    {
        static $data = null;
        if ($data === null) {
            $data = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($data)) $data = [];
        }
        return $data;
    }

    /**
     * IP посетителя — единственная точка для лимитов (RateLimit), журналов, заказов и доступа к админке по IP.
     * Обычно это REMOTE_ADDR. Если сайт за Cloudflare или прокси хостинга (nginx перед Apache), REMOTE_ADDR — адрес
     * прокси: тогда его адреса/подсети перечисляются в config 'trusted_proxies', и только от них принимаются
     * X-Forwarded-For / CF-Connecting-IP. От остальных эти заголовки игнорируются — подделать их может кто угодно.
     */
    public static function ip(): string
    {
        return self::$ip ??= self::clientIp($_SERVER, (array) App::config('trusted_proxies', []));
    }

    /** Адрес соединения (REMOTE_ADDR, нормализованный) — при прокси это адрес прокси; для диагностики («Состояние системы») */
    public static function remoteAddr(): string
    {
        return self::normalizeIp((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?? '0.0.0.0';
    }

    /** IP посетителя по заголовкам запроса и списку доверенных прокси (без глобального состояния — удобно проверять) */
    public static function clientIp(array $server, array $trusted): string
    {
        $remote = self::normalizeIp((string) ($server['REMOTE_ADDR'] ?? '')) ?? '0.0.0.0';
        if (!$trusted || !self::ipMatches($remote, $trusted)) return $remote;
        // X-Forwarded-For: «клиент, прокси1, прокси2» — каждый прокси дописывает справа адрес, от которого получил запрос.
        // Идём справа налево, пока адреса доверенные; первый недоверенный — посетитель (всё левее мог написать он сам).
        $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $last = null;
            foreach (array_slice(array_reverse(explode(',', $xff)), 0, 20) as $part) {
                $ip = self::normalizeIp($part);
                if ($ip === null) continue;                 // мусор в заголовке не учитываем
                if (!self::ipMatches($ip, $trusted)) return $ip;
                $last = $ip;
            }
            if ($last !== null) return $last;               // вся цепочка — доверенные адреса
        }
        // Cloudflare присылает и X-Forwarded-For; CF-Connecting-IP — запасной вариант, если X-Forwarded-For нет.
        // Порядок важен: прокси хостинга, пропускающий чужой CF-Connecting-IP, всегда ставит X-Forwarded-For.
        return self::normalizeIp((string) ($server['HTTP_CF_CONNECTING_IP'] ?? '')) ?? $remote;
    }

    /** Корректный IP в каноническом виде («0:0:0:0:0:0:0:1» → «::1», как хранит список IP админки) или null */
    public static function normalizeIp(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || strlen($v) > 64) return null;
        if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $v, $m)) $v = $m[1];          // [2001:db8::1]:443
        elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $v, $m)) $v = $m[1];     // 203.0.113.5:51234
        if (filter_var($v, FILTER_VALIDATE_IP) === false) return null;
        $bin = @inet_pton($v);
        return $bin === false ? null : (string) inet_ntop($bin);
    }

    /** Входит ли IP в список адресов и подсетей: ['203.0.113.7', '173.245.48.0/20', '2400:cb00::/32'] */
    public static function ipMatches(string $ip, array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        foreach ($list as $rule) {
            if (!is_string($rule) || ($rule = trim($rule)) === '') continue;
            [$net, $bits] = array_pad(explode('/', $rule, 2), 2, null);
            $netBin = @inet_pton(trim($net));
            if ($netBin === false) continue;               // ошибка в конфиге — правило пропускается
            $a = $bin;
            // IPv4, записанный как IPv6 (::ffff:203.0.113.7), сравниваем с IPv4-правилами как IPv4
            if (strlen($a) === 16 && strlen($netBin) === 4 && strncmp($a, str_repeat("\0", 10) . "\xff\xff", 12) === 0) $a = substr($a, 12);
            if (strlen($a) !== strlen($netBin)) continue;
            $max = strlen($a) * 8;
            $n = $bits === null ? $max : (preg_match('/^\d{1,3}$/', trim($bits)) ? (int) $bits : -1);
            if ($n < 0 || $n > $max) continue;
            $full = intdiv($n, 8);
            if (strncmp($a, $netBin, $full) !== 0) continue;
            if ($n % 8 === 0) return true;
            $mask = (0xff << (8 - $n % 8)) & 0xff;
            if ((ord($a[$full]) & $mask) === (ord($netBin[$full]) & $mask)) return true;
        }
        return false;
    }

    public static function page(): int
    {
        return max(1, min(10000, self::getInt('page', 1)));
    }

    /** Текущий URL с изменёнными параметрами: Request::withQuery(['page' => 2]) */
    public static function withQuery(array $changes, ?string $path = null): string
    {
        $q = $_GET;
        foreach ($changes as $k => $v) {
            if ($v === null || $v === '' || $v === false) unset($q[$k]); else $q[$k] = $v;
        }
        $qs = http_build_query($q);
        return ($path ?? self::path()) . ($qs ? '?' . $qs : '');
    }
}
