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
 * продолжает работать (Auth::passwordChanged). Сеанс живёт до закрытия браузера; «Запомнить меня» при входе в админку
 * (решение владельца 29.10.2026) — токен входа на REMEMBER_DAYS дней (Auth::remember / fromRemember, таблица auth_tokens):
 * cookie tremember = selector:validator (httponly), в базе — sha256 валидатора; при каждом входе по токену валидатор новый,
 * прежний действует ещё REMEMBER_GRACE с (вкладки, открытые одновременно, входят в ту же сессию и получают новый cookie),
 * позже — токен удаляется: прежний валидатор (ответ с новым не дошёл или cookie скопировали) — завершается сессия, открытая
 * той заменой; валидатор старше — cookie скопировали давно: все сеансы учётной записи; в журнал — неудачный вход. Смена пароля удаляет все токены учётной
 * записи (этому устройству — новый), выход — токен устройства, отключение и снятие роли — все (UsersController::updateGuarded).
 */
final class Auth
{
    /** Ключ отпечатка пароля в сессии */
    private const PW_KEY = 'pwf';
    /** «Запомнить меня»: cookie, срок (дней, от входа с паролем), сколько секунд действует прежний валидатор после замены */
    private const REMEMBER_COOKIE = 'tremember';
    public const REMEMBER_DAYS = 30;
    private const REMEMBER_GRACE = 120;

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
        Session::forget('_csrf');                                           // токен форм — новый: известный до входа не годится
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
        // «Запомнить меня»: все токены учётной записи — удалить (и этого устройства: его cookie могли скопировать — пароль
        // меняют, когда подозревают это); у этого устройства действующий токен был — новый (другой selector и валидатор), срок прежний
        $t = self::rememberToken($id);
        self::forgetTokens($id);
        if ($t) self::remember($u, (int) strtotime((string) $t['expires_at']));
    }

    public static function logout(): void
    {
        self::forgetRemember();
        if (Session::exists() || session_status() === PHP_SESSION_ACTIVE) Session::destroy();
        self::forgetCookie();
        self::$user = null;
        self::$loaded = true;
    }

    // ======================================================================= «Запомнить меня»

    /**
     * После Auth::login: токен входа на этом устройстве на REMEMBER_DAYS дней ($expires — свой срок: новый токен после смены
     * пароля); прежний токен этого браузера удаляется
     */
    public static function remember(array $user, ?int $expires = null): void
    {
        $id = (int) $user['id'];
        if ($id <= 0) return;
        $sel = bin2hex(random_bytes(12));
        $val = bin2hex(random_bytes(32));
        $exp = $expires ?? time() + 86400 * self::REMEMBER_DAYS;
        $db = App::db();
        try {
            if (($old = self::rememberSelector()) !== null) $db->delete('auth_tokens', 'selector = ?', [$old]);
            $hash = (string) $db->value('SELECT password FROM customers WHERE id = ?', [$id]);
            $db->insert('auth_tokens', ['selector' => $sel, 'customer_id' => $id, 'hash' => hash('sha256', $val),
                'pwf' => self::fingerprint(['id' => $id, 'password' => $hash]), 'expires_at' => date('Y-m-d H:i:s', $exp),
                'created_at' => date('Y-m-d H:i:s'), 'ip' => Request::ip(), 'user_agent' => self::agent()]);
        } catch (\PDOException $e) {
            Log::error('auth_tokens: ' . $e->getMessage());               // таблицы ещё нет — вход без «запомнить»
            return;
        }
        self::rememberCookie($sel . ':' . $val, $exp);
    }

    /**
     * Вход по токену «Запомнить меня» (админка: BaseController, страница входа): в сессии никого нет, а cookie есть — проверить
     * и войти как с паролем (новая сессия, Auth::login), валидатор — новый. $roles — каким ролям можно. Пользователь или null.
     */
    public static function fromRemember(array $roles): ?array
    {
        $c = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($c === '' || self::user() !== null) return null;
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m)) { self::rememberCookie('', time() - 3600); return null; }
        [, $sel, $val] = $m;
        $db = App::db();
        // вкладки, открытые одновременно (браузер восстановил несколько страниц админки), — по очереди: первая меняет валидатор
        // и открывает сессию, остальные входят в неё (своя сессия у каждой — свой токен форм, «Сессия устарела» при сохранении)
        try {
            $locked = (int) $db->value('SELECT GET_LOCK(?, 5)', ['tremember:' . $sel]) === 1;
        } catch (\PDOException) {
            $locked = false;
        }
        try {
            return self::restore($sel, $val, $roles);
        } finally {
            if ($locked) {
                try {
                    $db->value('SELECT RELEASE_LOCK(?)', ['tremember:' . $sel]);
                } catch (\PDOException) {
                }
            }
        }
    }

    private static function restore(string $sel, string $val, array $roles): ?array
    {
        $db = App::db();
        try {
            $t = $db->row('SELECT * FROM auth_tokens WHERE selector = ?', [$sel]);
        } catch (\PDOException) {
            return null;                                                    // таблицы ещё нет (до bin/install.php)
        }
        $now = time();
        if (!$t) { self::rememberCookie('', $now - 3600); return null; }
        $h = hash('sha256', $val);
        $cid = (int) $t['customer_id'];
        $current = hash_equals((string) $t['hash'], $h);
        $grace = !$current && $t['prev_hash'] !== null && hash_equals((string) $t['prev_hash'], $h)
            && strtotime((string) $t['rotated_at']) >= $now - self::REMEMBER_GRACE;
        if (!$current && !$grace) {
            // токен больше не действует. Прежний валидатор позже окна: ответ с новым не дошёл (закрыли вкладку, обрыв связи) или
            // cookie скопировали — завершить сессию, открытую той заменой (её держит либо тот, кто скопировал, либо никто).
            // Валидатор старше или чужой — cookie скопировали давно: завершить все сеансы учётной записи
            $prev = $t['prev_hash'] !== null && hash_equals((string) $t['prev_hash'], $h);
            $db->delete('auth_tokens', 'selector = ?', [$sel]);
            $ended = $prev ? (int) self::killSession((string) $t['sid']) : self::endSessions($cid);
            self::log($cid, 'login_failed', '«Запомнить меня»: ' . ($prev ? 'прежний cookie после замены (ответ не дошёл или cookie скопировали)'
                : 'cookie использован повторно') . ' — токен удалён' . ($ended ? ', открытых сеансов завершено: ' . $ended : ''));
            self::rememberCookie('', $now - 3600);
            return null;
        }
        $u = strtotime((string) $t['expires_at']) > $now ? $db->row('SELECT * FROM customers WHERE id = ? AND status = 1', [$cid]) : null;
        // срок вышел, учётную запись отключили, роль сняли, пароль сменили — токен не действует
        if (!$u || !in_array((string) $u['role'], $roles, true) || !hash_equals(self::fingerprint($u), (string) $t['pwf'])) {
            $db->delete('auth_tokens', 'selector = ?', [$sel]);
            self::rememberCookie('', $now - 3600);
            return null;
        }
        // прежний валидатор в окне замены — запрос ушёл одновременно с первым: в его сессию (sid) и с новым валидатором
        // из неё (ответ первого мог не дойти — закрыли вкладку, пока грузилась)
        if ($grace && (string) $t['sid'] !== '' && Session::adopt((string) $t['sid'])) {
            self::$loaded = false;
            self::$user = null;
            if ((int) (self::user()['id'] ?? 0) === $cid) {
                $rm = Session::get('_rm');
                if (is_string($rm) && str_starts_with($rm, $sel . ':')) self::rememberCookie($rm, (int) strtotime((string) $t['expires_at']));
                return self::$user;
            }
            Session::fresh();                                               // ту сессию уже закрыли — своя, новая
        }
        self::login($u);
        if ($current) {
            // новый валидатор; прежний ещё REMEMBER_GRACE с — для запросов, ушедших одновременно с этим (входят в эту сессию)
            $new = bin2hex(random_bytes(32));
            $n = $db->query('UPDATE auth_tokens SET prev_hash = hash, hash = ?, rotated_at = NOW(), used_at = NOW(), sid = ?, ip = ?, user_agent = ?
                WHERE selector = ? AND hash = ?', [hash('sha256', $new), session_id() ?: null, Request::ip(), self::agent(), $sel, $h])->rowCount();
            if ($n) {
                self::rememberCookie($sel . ':' . $new, (int) strtotime((string) $t['expires_at']));
                Session::set('_rm', $sel . ':' . $new);                     // вкладкам, вошедшим в эту сессию в окне замены
            }
            self::log($cid, 'login', 'по «Запомнить меня»');
        }
        return self::$user;
    }

    /** Есть ли у браузера cookie «Запомнить меня» (выход из админки, когда сессия на сервере уже закончилась) */
    public static function remembered(): bool
    {
        return self::rememberSelector() !== null;
    }

    /** Удалить все токены «Запомнить меня» учётной записи (отключили, сняли роль сотрудника, сменили пароль) */
    public static function forgetTokens(int $uid): void
    {
        if ($uid <= 0) return;
        try {
            App::db()->delete('auth_tokens', 'customer_id = ?', [$uid]);
        } catch (\PDOException) {
            // таблицы ещё нет (до bin/install.php)
        }
    }

    /** Действующий токен этого браузера у учётной записи $uid (валидатор текущий или в окне замены, срок не вышел) или null */
    private static function rememberToken(int $uid): ?array
    {
        $c = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m)) return null;
        try {
            $t = App::db()->row('SELECT * FROM auth_tokens WHERE selector = ? AND customer_id = ?', [$m[1], $uid]);
        } catch (\PDOException) {
            return null;
        }
        if (!$t || strtotime((string) $t['expires_at']) <= time()) return null;
        $h = hash('sha256', $m[2]);
        $ok = hash_equals((string) $t['hash'], $h) || ($t['prev_hash'] !== null && hash_equals((string) $t['prev_hash'], $h)
            && strtotime((string) $t['rotated_at']) >= time() - self::REMEMBER_GRACE);
        return $ok ? $t : null;
    }

    /**
     * Завершить открытые сеансы учётной записи (кроме текущего): файлы сессий с её uid удаляются — следующий запрос
     * с такой сессией идёт как гость. Сброс пароля администратором, смена своего пароля, повторное использование cookie
     * «Запомнить меня». Не дольше 2 с (файлов сессий может быть много). Возвращает, сколько завершено.
     */
    public static function endSessions(int $uid): int
    {
        if ($uid <= 0) return 0;
        $mine = session_status() === PHP_SESSION_ACTIVE ? 'sess_' . session_id() : '';
        $re = '/(?:^|[;}])uid\|i:' . $uid . ';|s:3:"uid";i:' . $uid . ';/';
        $n = 0;
        $end = microtime(true) + 2.0;
        foreach (glob(STORAGE . '/sessions/sess_*', GLOB_NOSORT) ?: [] as $f) {
            if (microtime(true) > $end) break;
            if (basename($f) === $mine || (int) @filesize($f) > 65536) continue;
            $s = @file_get_contents($f);
            if (is_string($s) && preg_match($re, $s) && @unlink($f)) $n++;
        }
        return $n;
    }

    /** Удалить файл сессии $sid (не текущей): следующий запрос с ней идёт как гость */
    private static function killSession(string $sid): bool
    {
        if (!preg_match('/^[A-Za-z0-9,-]{16,256}$/', $sid) || (session_status() === PHP_SESSION_ACTIVE && session_id() === $sid)) return false;
        $f = STORAGE . '/sessions/sess_' . $sid;
        return is_file($f) && @unlink($f);
    }

    /** Запись в журнал входов админки (admin_log: «Безопасность») */
    private static function log(int $uid, string $action, string $details): void
    {
        try {
            App::db()->insert('admin_log', ['user_id' => $uid ?: null, 'action' => $action, 'details' => mb_substr($details, 0, 250), 'ip' => Request::ip()]);
        } catch (\PDOException) {
        }
    }

    /** Выход: токен этого браузера удалить, cookie снять */
    private static function forgetRemember(): void
    {
        if (($sel = self::rememberSelector()) !== null) {
            try {
                App::db()->delete('auth_tokens', 'selector = ?', [$sel]);
            } catch (\PDOException) {
            }
        }
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) self::rememberCookie('', time() - 3600);
    }

    /** selector из cookie «Запомнить меня» этого браузера или null */
    private static function rememberSelector(): ?string
    {
        $c = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        return preg_match('/^([a-f0-9]{24}):[a-f0-9]{64}$/', $c, $m) ? $m[1] : null;
    }

    private static function rememberCookie(string $value, int $expires): void
    {
        if (headers_sent()) return;
        setcookie(self::REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => Session::https(), 'httponly' => true, 'samesite' => 'Lax']);
        if ($value === '') unset($_COOKIE[self::REMEMBER_COOKIE]);
        else $_COOKIE[self::REMEMBER_COOKIE] = $value;
    }

    private static function agent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
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
