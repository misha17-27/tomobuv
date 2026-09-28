<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Авторизация покупателей и сотрудников (одна таблица customers, поле role).
 * Пароли: password_hash(). Пароли, перенесённые из Webasyst (md5), помечены префиксом «wa:»
 * и при первом успешном входе автоматически перехешируются.
 * Cookie `auth=1` (не секретная) нужна только JS, чтобы в кэшируемой шапке показать «Кабинет».
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::exists() ? (int) Session::get('uid', 0) : 0;
            if ($id) {
                self::$user = App::db()->row('SELECT * FROM customers WHERE id = ? AND status = 1', [$id]);
                if (!self::$user) Session::forget('uid');
            }
        }
        return self::$user;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isStaff(): bool
    {
        $u = self::user();
        return $u && in_array($u['role'], ['admin', 'manager'], true);
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u && $u['role'] === 'admin';
    }

    /**
     * Вход как на старом сайте: по e-mail (любому из адресов клиента), по логину (сотрудники) или по телефону.
     * Если одному e-mail/телефону соответствует несколько записей (в Webasyst гостевые заказы создавали
     * отдельные контакты), пароль проверяется у каждой — входим в ту, чей пароль совпал.
     */
    public static function attempt(string $login, string $password): ?array
    {
        $login = trim($login);
        if ($login === '' || $password === '') return null;
        $db = App::db();
        $ids = [];
        if (str_contains($login, '@')) {
            $email = mb_strtolower($login);
            $ids = $db->col('SELECT id FROM customers WHERE email = ? AND status = 1
                UNION SELECT e.customer_id FROM customer_emails e JOIN customers c ON c.id = e.customer_id AND c.status = 1 WHERE e.email = ?', [$email, $email]);
        } else {
            $ids = $db->col('SELECT id FROM customers WHERE login = ? AND status = 1', [$login]);
            $phone = Str::phone($login);
            if ($phone) $ids = array_merge($ids, $db->col('SELECT id FROM customers WHERE phone = ? AND status = 1', [$phone]));
        }
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 20);
        $u = null;
        if ($ids) {
            [$ph, $vals] = $db->in($ids);
            foreach ($db->all("SELECT * FROM customers WHERE id IN ($ph) AND password IS NOT NULL AND password <> ''
                ORDER BY (role <> 'customer') DESC, last_login_at DESC, id DESC", $vals) as $c) {
                if (self::verify($password, (string) $c['password'])) { $u = $c; break; }
            }
        } else {
            password_verify($password, '$2y$10$Jy6CZ7GPuPbokSOi2kKrrevxL.QwdHfW2mU0345WCGdpP19R5Xiom');   // то же время ответа, что и при проверке настоящего пароля
        }
        if (!$u) return null;
        if (str_starts_with((string) $u['password'], 'wa:') || password_needs_rehash((string) $u['password'], PASSWORD_DEFAULT)) {
            $db->update('customers', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        }
        return $u;
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '') return false;
        if (str_starts_with($hash, 'wa:')) {               // Webasyst: md5(password)
            return hash_equals(substr($hash, 3), md5($password));
        }
        return password_verify($password, $hash);
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('uid', (int) $user['id']);
        App::db()->update('customers', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        setcookie('auth', '1', ['expires' => time() + 86400 * 30, 'path' => '/', 'secure' => Session::https(), 'samesite' => 'Lax']);
        self::$user = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        Session::destroy();
        setcookie('auth', '', ['expires' => time() - 3600, 'path' => '/']);
        self::$user = null;
    }
}
