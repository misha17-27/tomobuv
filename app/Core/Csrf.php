<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Защита от CSRF по схеме «double submit cookie»: токен лежит в cookie и должен прийти
 * в поле _csrf формы или в заголовке X-CSRF-Token. Чужой сайт не может прочитать cookie,
 * значит не сможет подставить токен. Подходит и для кэшируемых страниц: JS берёт токен
 * из cookie (assets/js/app.js, UI.csrf()), а сами страницы токенов и Set-Cookie не содержат.
 *
 * Cookie на HTTPS — `__Host-csrf`: браузер принимает её только с Secure, Path=/ и без Domain,
 * поэтому поддомен (или HTTP-версия сайта) не может подложить свою cookie с известным ему токеном.
 * На HTTP (локальная разработка) — прежняя `csrf`. На HTTPS обычная `csrf` не принимается.
 * Для админки дополнительно проверяется токен в сессии (Csrf::checkSession).
 */
final class Csrf
{
    public const COOKIE_HTTPS = '__Host-csrf';
    public const COOKIE_HTTP = 'csrf';

    public static function token(): string
    {
        // __Host-csrf приходит только по HTTPS и только от самого сайта — ей можно верить всегда
        $t = self::cookie(self::COOKIE_HTTPS);
        if ($t !== '') return $t;
        $https = Session::https();
        if (!$https && ($t = self::cookie(self::COOKIE_HTTP)) !== '') return $t;
        $t = bin2hex(random_bytes(16));
        $name = $https ? self::COOKIE_HTTPS : self::COOKIE_HTTP;
        $_COOKIE[$name] = $t;
        if (!headers_sent()) {
            setcookie($name, $t, ['expires' => time() + 86400 * 30, 'path' => '/', 'secure' => $https, 'httponly' => false, 'samesite' => 'Lax']);
        }
        return $t;
    }

    public static function check(): bool
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? (Request::json()['_csrf'] ?? ''));
        if (!is_string($sent) || strlen($sent) !== 32) return false;
        $host = self::cookie(self::COOKIE_HTTPS);
        if ($host !== '' && hash_equals($host, $sent)) return true;
        if (Session::https()) return false;          // обычную cookie на HTTPS мог выставить поддомен
        $plain = self::cookie(self::COOKIE_HTTP);
        return $plain !== '' && hash_equals($plain, $sent);
    }

    /** Токен из cookie без создания нового (для проверок, производных от токена) — '' если cookie нет или ей нельзя верить */
    public static function current(): string
    {
        $t = self::cookie(self::COOKIE_HTTPS);
        return $t !== '' || Session::https() ? $t : self::cookie(self::COOKIE_HTTP);
    }

    /** Значение cookie токена, если оно правильного вида (32 hex), иначе '' */
    private static function cookie(string $name): string
    {
        $t = $_COOKIE[$name] ?? '';
        return is_string($t) && preg_match('/^[a-f0-9]{32}$/D', $t) ? $t : '';
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
