<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Защита от CSRF по схеме «double submit cookie»: токен лежит в cookie `csrf`
 * и должен прийти в поле _csrf формы или в заголовке X-CSRF-Token.
 * Чужой сайт не может прочитать cookie, значит не сможет подставить токен.
 * Подходит и для кэшируемых страниц: JS берёт токен из cookie (assets/js/app.js).
 * Для админки дополнительно проверяется токен в сессии (Csrf::checkSession).
 */
final class Csrf
{
    public static function token(): string
    {
        $t = $_COOKIE['csrf'] ?? '';
        if (!is_string($t) || !preg_match('/^[a-f0-9]{32}$/', $t)) {
            $t = bin2hex(random_bytes(16));
            $_COOKIE['csrf'] = $t;
            if (!headers_sent()) {
                setcookie('csrf', $t, ['expires' => time() + 86400 * 30, 'path' => '/', 'secure' => Session::https(), 'httponly' => false, 'samesite' => 'Lax']);
            }
        }
        return $t;
    }

    public static function check(): bool
    {
        $cookie = $_COOKIE['csrf'] ?? '';
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? (Request::json()['_csrf'] ?? ''));
        return is_string($cookie) && is_string($sent) && strlen($cookie) === 32 && hash_equals($cookie, $sent);
    }

    /** Токен, привязанный к сессии (админка) */
    public static function sessionToken(): string
    {
        $t = Session::get('_csrf');
        if (!$t) { $t = bin2hex(random_bytes(16)); Session::set('_csrf', $t); }
        return $t;
    }

    public static function checkSession(): bool
    {
        $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        $t = Session::get('_csrf');
        return is_string($sent) && $t && hash_equals($t, $sent);
    }
}
