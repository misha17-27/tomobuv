<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Настройки сайта из таблицы settings (контакты, SEO-шаблоны, доставка…).
 * Загружаются один раз и кэшируются в файле.
 */
final class Settings
{
    private static ?array $all = null;

    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = Cache::remember('settings', 86400, static fn() => App::db()->pairs('SELECT name, value FROM settings'));
        }
        return self::$all;
    }

    /** В украинской версии сначала ищется вариант «name.uk» (например address.uk, seo.product_meta_title.uk) */
    public static function get(string $name, $default = null)
    {
        $all = self::all();
        if (Lang::isUk()) {
            $u = $all[$name . '.uk'] ?? null;
            if ($u !== null && $u !== '') return $u;
        }
        $v = $all[$name] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    /** JSON-настройка как массив */
    public static function json(string $name, array $default = []): array
    {
        $v = json_decode((string) self::get($name, ''), true);
        return is_array($v) ? $v : $default;
    }

    /** Перечитать настройки при следующем обращении (после записи в таблицу напрямую, например SeoFix) */
    public static function reset(): void
    {
        self::$all = null;
    }

    public static function set(string $name, $value): void
    {
        if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        App::db()->upsert('settings', ['name' => $name, 'value' => (string) $value], ['value']);
        self::$all = null;
        Cache::flush();
    }
}
