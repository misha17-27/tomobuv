<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Файловый кэш данных (работает на любом хостинге без Redis).
 * Значения сохраняются как PHP-файлы с var_export → читаются через OPcache почти бесплатно.
 *
 *   $tree = Cache::remember('categories.tree', 3600, fn() => …);
 *   Cache::forget('categories.tree');
 *   Cache::flush();                 // очистить всё (вызывается после изменений в админке)
 *
 * Все ключи автоматически «версионируются» (Cache::version()): смена версии мгновенно
 * делает весь кэш данных и страниц устаревшим без удаления тысяч файлов.
 */
final class Cache
{
    private static array $mem = [];
    private static ?int $ver = null;

    private static function dir(): string
    {
        return STORAGE . '/cache/data';
    }

    private static function file(string $key): string
    {
        $h = md5($key);
        return self::dir() . '/' . substr($h, 0, 2) . '/' . $h . '.php';
    }

    public static function version(): int
    {
        if (self::$ver === null) {
            $f = STORAGE . '/cache/version';
            self::$ver = is_file($f) ? (int) file_get_contents($f) : 1;
        }
        return self::$ver;
    }

    /** Сбросить весь кэш (данные + страницы): просто увеличиваем версию */
    public static function flush(): void
    {
        $v = self::version() + 1;
        @mkdir(STORAGE . '/cache', 0775, true);
        file_put_contents(STORAGE . '/cache/version', (string) $v, LOCK_EX);
        self::$ver = $v;
        self::$mem = [];
    }

    public static function get(string $key, $default = null)
    {
        $vk = self::version() . ':' . Lang::current() . ':' . $key;
        if (array_key_exists($vk, self::$mem)) return self::$mem[$vk];
        $f = self::file($vk);
        if (!is_file($f)) return $default;
        $data = @include $f;
        if (!is_array($data) || ($data['e'] !== 0 && $data['e'] < time())) return $default;
        return self::$mem[$vk] = $data['v'];
    }

    public static function set(string $key, $value, int $ttl = 3600): void
    {
        $vk = self::version() . ':' . Lang::current() . ':' . $key;
        self::$mem[$vk] = $value;
        $f = self::file($vk);
        @mkdir(dirname($f), 0775, true);
        $tmp = $f . '.' . getmypid() . '.tmp';
        $code = '<?php return ' . var_export(['e' => $ttl > 0 ? time() + $ttl : 0, 'v' => $value], true) . ';';
        if (file_put_contents($tmp, $code, LOCK_EX) !== false) {
            @rename($tmp, $f);   // атомарная замена — без «полузаписанных» файлов при параллельных запросах
            if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
        }
    }

    public static function remember(string $key, int $ttl, callable $fn)
    {
        $miss = new \stdClass();
        $v = self::get($key, $miss);
        if ($v !== $miss) return $v;
        $v = $fn();
        self::set($key, $v, $ttl);
        return $v;
    }

    public static function forget(string $key): void
    {
        $vk = self::version() . ':' . Lang::current() . ':' . $key;
        unset(self::$mem[$vk]);
        @unlink(self::file($vk));
    }

    /** Удалить файлы старых версий кэша (запускается из bin/cron.php) */
    public static function gc(): int
    {
        $n = 0;
        $limit = time() - 86400;
        foreach ([STORAGE . '/cache/data', STORAGE . '/cache/pages'] as $root) {
            if (!is_dir($root)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getMTime() < $limit) { @unlink($f->getPathname()); $n++; }
            }
        }
        return $n;
    }
}
