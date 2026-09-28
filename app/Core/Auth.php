<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Авторизация покупателей и сотрудников (одна таблица customers, поле role).
 * Пароли: password_hash(). Пароли, перенесённые из Webasyst (md5), помечены префиксом «wa:»
 * и при первом успешном входе автоматически перехешируются.
 * Cookie `auth=1` (не секретная) нужна только JS, чтобы в кэшируемой шапке показать «Кабинет».
 *
 * Сеанс привязан к паролю: при входе в сессию пишется отпечаток хеша пароля (pwf), при каждой загрузке
 * пользователя он сверяется с базой. Пароль сменили (кабинет, восстановление, админка, сброс администратором) —
 * все остальные сеансы этой учётной записи завершаются на следующем запросе; свой сеанс после смены пароля
 * продолжает работать (Auth::passwordChanged). «Запомнить меня» нет — сеанс живёт до закрытия браузера.
 */
final class Auth
{
    /** Ключ отпечатка пароля в сессии */
    private const PW_KEY = 'pwf';

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::exists() ? (int) Session::get('uid', 0) : 0;
            if ($id) {
                $u = App::db()->row('SELECT * FROM customers WHERE id = ? AND status = 1', [$id]);
                if ($u) {
                    $fp = Session::get(self::PW_KEY);
                    if ($fp === null) {
                        Session::set(self::PW_KEY, self::fingerprint($u));   // сеанс, открытый до появления отпечатков
                    } elseif (!is_string($fp) || !hash_equals(self::fingerprint($u), $fp)) {
                        $u = null;                                          // пароль сменили — этот сеанс завершён
                    }
                }
                if (!$u) self::drop();
                self::$user = $u;
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
            // md5 из Webasyst → bcrypt; отпечаток в сессии (Auth::login) берётся уже от нового хеша — вход не сбрасывается.
            // Запись — только если в базе ещё старый хеш: при одновременном входе с двух устройств оба перехешируют,
            // но записывает первый, второй берёт его хеш из базы. Иначе второй затирал бы хеш первого, и сеанс первого
            // завершился бы на следующем запросе (отпечаток pwf от хеша, которого в базе уже нет).
            $new = password_hash($password, PASSWORD_DEFAULT);
            if ($db->update('customers', ['password' => $new], 'id = ? AND password = ?', [$u['id'], $u['password']])) {
                $u['password'] = $new;
            } else {
                $cur = (string) $db->value('SELECT password FROM customers WHERE id = ?', [$u['id']]);
                if (!self::verify($password, $cur)) return null;          // пароль тем временем сменили — старым не входим
                $u['password'] = $cur;
            }
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
        // отпечаток — от хеша, который сейчас в базе (после перехеширования «wa:» при входе он уже новый)
        $hash = App::db()->value('SELECT password FROM customers WHERE id = ?', [$user['id']]);
        Session::set(self::PW_KEY, self::fingerprint(['id' => $user['id'], 'password' => $hash]));
        App::db()->update('customers', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        setcookie('auth', '1', ['expires' => time() + 86400 * 30, 'path' => '/', 'secure' => Session::https(), 'samesite' => 'Lax']);
        $user['password'] = $hash;
        self::$user = $user;
        self::$loaded = true;
    }

    /**
     * Свой пароль изменён в этом сеансе (кабинет, «Мой аккаунт»): новый идентификатор сессии и отпечаток нового хеша.
     * Текущий сеанс продолжает работать, остальные сеансы учётной записи завершатся на следующем запросе.
     */
    public static function passwordChanged(int $id): void
    {
        if ($id <= 0 || (int) Session::get('uid', 0) !== $id) return;
        $u = App::db()->row('SELECT * FROM customers WHERE id = ? AND status = 1', [$id]);
        if (!$u) return;
        Session::regenerate();
        Session::set(self::PW_KEY, self::fingerprint($u));
        self::$user = $u;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        if (Session::exists() || session_status() === PHP_SESSION_ACTIVE) Session::destroy();
        self::forgetCookie();
        self::$user = null;
        self::$loaded = true;
    }

    /**
     * Токен ссылки выхода (/logout/?t=…, /admin/logout/?t=…) — защита от выхода по чужой ссылке или картинке.
     * Производный от CSRF-токена (витрина — cookie csrf, админка — токен сессии): сам токен форм в адрес,
     * журналы сервера и историю браузера не попадает. Вшивать только в некэшируемые страницы (кабинет, админка).
     */
    public static function logoutToken(bool $admin = false): string
    {
        return self::logoutHash($admin ? Csrf::sessionToken() : Csrf::token());
    }

    /** Верный ли токен выхода из адреса (новый токен при проверке не создаётся) */
    public static function checkLogoutToken(string $t, bool $admin = false): bool
    {
        $base = $admin ? Session::get('_csrf') : Csrf::current();
        if (!is_string($base) || strlen($base) !== 32 || !preg_match('/^[a-f0-9]{32}$/', $t)) return false;
        return hash_equals(self::logoutHash($base), $t);
    }

    private static function logoutHash(string $base): string
    {
        return substr(hash_hmac('sha256', 'logout', $base), 0, 32);
    }

    /**
     * Отпечаток пароля для сессии: HMAC от id и хеша пароля (сам хеш в файл сессии не пишем).
     * Меняется при любой записи нового пароля в customers — на любом пути, включая сброс администратором.
     */
    private static function fingerprint(array $u): string
    {
        $key = (string) App::config('app_key', '');
        return substr(hash_hmac('sha256', (int) $u['id'] . '|' . (string) ($u['password'] ?? ''), $key !== '' ? $key : 'tomobuv-session'), 0, 32);
    }

    /** Сеанс больше не действует: забыть вошедшего (сама сессия — корзина гостя и т.п. — остаётся) */
    private static function drop(): void
    {
        Session::forget('uid');
        Session::forget(self::PW_KEY);
        self::forgetCookie();
    }

    /** Снять cookie-подсказку «вошёл» (шапка витрины перестанет показывать «Кабинет») */
    public static function forgetCookie(): void
    {
        if (!headers_sent() && isset($_COOKIE['auth'])) {
            setcookie('auth', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => Session::https(), 'samesite' => 'Lax']);
        }
    }
}
