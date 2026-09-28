<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Сессия запускается ТОЛЬКО когда нужна (вход, оформление заказа, админка).
 * Кэшируемые страницы витрины сессию не открывают — нет блокировок файла сессии
 * и лишних Set-Cookie.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
        @mkdir(STORAGE . '/sessions', 0770, true);
        session_save_path(STORAGE . '/sessions');
        session_name('tsid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', '86400');
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    /** Есть ли у посетителя cookie сессии (без её запуска) */
    public static function exists(): bool
    {
        return !empty($_COOKIE['tsid']);
    }

    public static function get(string $key, $default = null)
    {
        if (!self::exists() && session_status() !== PHP_SESSION_ACTIVE) return $default;
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /** Одноразовое сообщение (показывается на следующей странице) */
    public static function flash(string $key, $value = null)
    {
        if ($value !== null) { self::set('_flash_' . $key, $value); return null; }
        $v = self::get('_flash_' . $key);
        if ($v !== null) self::forget('_flash_' . $key);
        return $v;
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
        setcookie('tsid', '', ['expires' => time() - 3600, 'path' => '/']);
    }

    public static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
