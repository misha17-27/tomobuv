<?php
declare(strict_types=1);

namespace App\Core;

/** Доступ к конфигу и общим сервисам (ленивое подключение к БД). */
final class App
{
    private static array $config = [];
    private static ?DB $db = null;

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    /** App::config('db.host') */
    public static function config(string $key, $default = null)
    {
        $v = self::$config;
        foreach (explode('.', $key) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $default;
            $v = $v[$k];
        }
        return $v;
    }

    public static function db(): DB
    {
        if (self::$db === null) {
            self::$db = new DB(self::config('db'));
        }
        return self::$db;
    }

    public static function isDebug(): bool
    {
        return (bool) self::config('debug', false);
    }
}
