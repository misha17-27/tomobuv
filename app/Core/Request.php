<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?string $path = null;

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

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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
