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
        return self::$ver ??= self::readVersion();
    }

    /**
     * Номер версии из файла. Файл пишется атомарно (flush), но чтение всё равно может не удаться (Windows держит файл
     * во время замены) — тогда несколько повторов; не прочитали — 1 (кэш просто не найдётся, версия не откатится: flush
     * берёт не меньше текущего времени).
     */
    private static function readVersion(): int
    {
        $f = STORAGE . '/cache/version';
        for ($i = 0; $i < 5; $i++) {
            if (!is_file($f)) return 1;
            $v = (int) @file_get_contents($f);
            if ($v > 0) return $v;
            usleep(3000);
        }
        return 1;
    }

    /**
     * Сбросить весь кэш (данные + страницы): увеличиваем версию. Версия только растёт — не меньше текущего времени
     * и больше прочитанной заново из файла, поэтому неудачное чтение в одном запросе не вернёт старые записи кэша.
     */
    public static function flush(): void
    {
        $v = max(self::readVersion(), self::$ver ?? 0) + 1;
        $v = max($v, time());
        @mkdir(STORAGE . '/cache', 0775, true);
        $f = STORAGE . '/cache/version';
        $tmp = $f . '.' . getmypid() . '.tmp';
        // атомарная замена: читатели видят либо прежний номер, либо новый, но не пустой файл
        if (file_put_contents($tmp, (string) $v) === false || !@rename($tmp, $f)) {
            @unlink($tmp);
            file_put_contents($f, (string) $v, LOCK_EX);   // rename не удался (Windows: файл открыт читателем)
        }
        self::$ver = $v;
        self::$mem = [];
        \App\Services\Cloudflare::flushed();                               // страницы в Cloudflare — тоже (в конце запроса)
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

    /**
     * Уборка кэша (bin/cron.php): страницы прежних версий — папками целиком (после flush их никто не читает; раньше удалялись
     * только файлы, и каждый flush оставлял до 257 пустых папок — хостинг ограничивает число файлов), истёкшие страницы текущей
     * версии — старше 2 ч (TTL страниц — до часа), данные — старше суток (TTL данных — до суток). @return int удалено файлов
     */
    public static function gc(): int
    {
        $n = 0;
        $pages = STORAGE . '/cache/pages';
        $cur = self::readVersion();                                          // заново из файла: версия только растёт
        foreach (is_dir($pages) ? (scandir($pages) ?: []) : [] as $v) {
            if (ctype_digit($v) && (int) $v < $cur) $n += self::rmTree($pages . '/' . $v);
        }
        foreach ([[STORAGE . '/cache/data', 86400], [$pages, 7200]] as [$root, $age]) {
            if (!is_dir($root)) continue;
            $limit = time() - $age;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getMTime() < $limit && @unlink($f->getPathname())) $n++;
            }
        }
        return $n;
    }

    /** Удалить папку со всем содержимым; @return int удалено файлов */
    private static function rmTree(string $dir): int
    {
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir()) @rmdir($f->getPathname());
            elseif (@unlink($f->getPathname())) $n++;
        }
        @rmdir($dir);
        return $n;
    }
}
