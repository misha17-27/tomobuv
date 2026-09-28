<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Log;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Session;
use App\Core\Str;
use App\Core\View;

/**
 * Вход, регистрация, восстановление пароля, выход.
 * Страницы персональные: без кэша, noindex. Все POST — Csrf::check() + RateLimit.
 * customers.lang — язык, на котором клиент зарегистрировался / последний раз вошёл (письма — на нём).
 */
final class AuthController
{
    public const MIN_PASSWORD = 8;
    private const RESET_TTL = 3600;          // срок ссылки восстановления, сек

    // ------------------------------------------------------------------ вход

    public function login(): Response
    {
        $back = self::safeBack(Request::isPost() ? Request::post('back') : Request::get('back'));
        if (Auth::check()) return Response::redirect($back ?: '/my/orders/');

        $errors = [];
        $login = '';
        if (Request::isPost()) {
            $login = mb_substr(Request::post('login'), 0, 190);
            $password = (string) ($_POST['password'] ?? '');
            $ip = Request::ip();
            if (!Csrf::check()) {
                $errors['form'] = t('Страница устарела. Обновите её и попробуйте войти ещё раз.');
            } elseif ($login === '') {
                $errors['login'] = t('Укажите e-mail или телефон');
            } elseif ($password === '') {
                $errors['password'] = t('Введите пароль');
            } elseif (self::limited($kIp = 'login:ip:' . $ip, 10) || self::limited($kU = 'login:u:' . md5(self::loginKey($login)), 10)) {
                $errors['form'] = t('Слишком много попыток входа. Попробуйте через 15 минут или восстановите пароль.');
            } else {
                $user = self::findUser($login, $password);
                if ($user) {
                    self::signIn($user);
                    if ($back === '' || str_starts_with($back, '/my/')) Session::flash('ok', t('Вы вошли в личный кабинет.'));
                    return Response::redirect($back ?: '/my/orders/');
                }
                // считаем только неудачные попытки: 10 за 15 минут на IP и на логин
                RateLimit::hit($kIp, 10, 900);
                RateLimit::hit($kU, 10, 900);
                $errors['form'] = t('Неверный e-mail/телефон или пароль.');
            }
        }

        return self::page('front/login', t('Вход'), ['errors' => $errors, 'login' => $login, 'back' => $back], $errors ? 422 : 200);
    }

    // ------------------------------------------------------------------ регистрация

    public function signup(): Response
    {
        $back = self::safeBack(Request::isPost() ? Request::post('back') : Request::get('back'));
        if (Auth::check()) return Response::redirect($back ?: '/my/orders/');

        $errors = [];
        $d = ['firstname' => '', 'lastname' => '', 'phone' => '', 'email' => ''];
        if (Request::isPost()) {
            foreach ($d as $k => $_) $d[$k] = mb_substr(Request::post($k), 0, $k === 'email' ? 190 : 100);
            $password = (string) ($_POST['password'] ?? '');
            $password2 = (string) ($_POST['password2'] ?? '');

            if (Request::post('website') !== '') {          // honeypot — бот, тихо на пустую форму
                return Response::redirect('/signup/');
            }
            if (!Csrf::check()) {
                $errors['form'] = t('Страница устарела. Обновите её и повторите регистрацию.');
            } elseif (!RateLimit::hit('signup:' . Request::ip(), 10, 3600)) {
                $errors['form'] = t('Слишком много попыток регистрации. Попробуйте позже.');
            } else {
                $db = App::db();
                $phone = Str::phone($d['phone']);
                $email = Str::email($d['email']);
                if ($d['phone'] === '') $errors['phone'] = t('Укажите телефон');
                elseif ($phone === '') $errors['phone'] = t('Проверьте номер телефона: например, +38 (093) 275-30-70');
                if ($d['email'] === '') $errors['email'] = t('Укажите e-mail');
                elseif ($email === '') $errors['email'] = t('Проверьте e-mail: например, name@gmail.com');
                if (mb_strlen($password) < self::MIN_PASSWORD) $errors['password'] = t('Пароль — не меньше {n} символов', ['n' => self::MIN_PASSWORD]);
                elseif (mb_strlen($password) > 200) $errors['password'] = t('Слишком длинный пароль');
                if (!isset($errors['password']) && $password !== $password2) $errors['password2'] = t('Пароли не совпадают');

                if (!isset($errors['email'])) {
                    // основной e-mail клиента или дополнительный (из старых контактов Webasyst — по нему тоже входят)
                    $ex = $db->row('SELECT id, password FROM customers WHERE email = ? LIMIT 1', [$email])
                        ?? $db->row("SELECT c.id, c.password FROM customer_emails e JOIN customers c ON c.id = e.customer_id AND c.status = 1
                            WHERE e.email = ? ORDER BY (c.password IS NOT NULL AND c.password <> '') DESC LIMIT 1", [$email]);
                    if ($ex) {
                        // e-mail из старых заказов без пароля — предлагаем восстановить пароль и получить историю заказов
                        $errors['email'] = (string) $ex['password'] !== ''
                            ? t('Этот e-mail уже зарегистрирован. Войдите или восстановите пароль.')
                            : t('На этот e-mail уже оформлялись заказы. Чтобы открыть кабинет с историей заказов, восстановите пароль.');
                        $errors['email_exists'] = '1';
                    }
                }
                if (!isset($errors['phone']) && $db->value("SELECT id FROM customers WHERE phone = ? AND password IS NOT NULL AND password <> '' LIMIT 1", [$phone])) {
                    $errors['phone'] = t('Этот телефон уже зарегистрирован. Войдите по телефону и паролю или восстановите пароль.');
                }

                if (!$errors) {
                    $name = trim($d['firstname'] . ' ' . $d['lastname']);
                    try {
                        $id = $db->insert('customers', [
                            'name' => $name, 'firstname' => $d['firstname'], 'lastname' => $d['lastname'],
                            'email' => $email, 'phone' => $phone, 'password' => password_hash($password, PASSWORD_DEFAULT),
                            'role' => 'customer', 'status' => 1, 'lang' => Lang::current(), 'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    } catch (\PDOException $e) {
                        if ($e->getCode() !== '23000') throw $e;
                        $id = 0;
                        $errors['email'] = t('Этот e-mail уже зарегистрирован. Войдите или восстановите пароль.');
                    }
                    if ($id) {
                        $user = $db->row('SELECT * FROM customers WHERE id = ?', [$id]);
                        self::signIn($user);
                        if ($back === '' || str_starts_with($back, '/my/')) Session::flash('ok', t('Регистрация завершена. Добро пожаловать в личный кабинет!'));
                        return Response::redirect($back ?: '/my/orders/');
                    }
                }
            }
        }

        return self::page('front/signup', t('Регистрация'), ['errors' => $errors, 'd' => $d, 'back' => $back], $errors ? 422 : 200);
    }

    // ------------------------------------------------------------------ восстановление пароля

    public function forgot(): Response
    {
        $errors = [];
        $email = '';
        $sent = false;
        if (Request::isPost()) {
            $email = mb_substr(Request::post('email'), 0, 190);
            if (Request::post('website') !== '') {
                $sent = true;                                // honeypot: делаем вид, что всё хорошо
            } elseif (!Csrf::check()) {
                $errors['form'] = t('Страница устарела. Обновите её и попробуйте ещё раз.');
            } elseif (Str::email($email) === '') {
                $errors['email'] = $email === '' ? t('Укажите e-mail') : t('Проверьте e-mail: например, name@gmail.com');
            } elseif (!RateLimit::hit('forgot:ip:' . Request::ip(), 5, 900)
                || !RateLimit::hit('forgot:e:' . md5(Str::email($email)), 3, 3600)) {
                $errors['form'] = t('Слишком много запросов. Попробуйте через 15 минут.');
            } else {
                self::sendResetLink(Str::email($email));
                $sent = true;                                // ответ одинаковый — не раскрываем, есть ли такой e-mail
            }
        }
        return self::page('front/forgot', t('Восстановление пароля'), ['errors' => $errors, 'email' => $email, 'sent' => $sent], $errors ? 422 : 200);
    }

    public function reset(): Response
    {
        $token = Request::isPost() ? Request::post('t') : Request::get('t');
        $user = self::userByToken($token);
        $errors = [];

        if ($user && Request::isPost()) {
            $password = (string) ($_POST['password'] ?? '');
            $password2 = (string) ($_POST['password2'] ?? '');
            if (!Csrf::check()) {
                $errors['form'] = t('Страница устарела. Обновите её и попробуйте ещё раз.');
            } elseif (!RateLimit::hit('reset:' . Request::ip(), 10, 900)) {
                $errors['form'] = t('Слишком много попыток. Попробуйте через 15 минут.');
            } elseif (mb_strlen($password) < self::MIN_PASSWORD) {
                $errors['password'] = t('Пароль — не меньше {n} символов', ['n' => self::MIN_PASSWORD]);
            } elseif (mb_strlen($password) > 200) {
                $errors['password'] = t('Слишком длинный пароль');
            } elseif ($password !== $password2) {
                $errors['password2'] = t('Пароли не совпадают');
            } else {
                App::db()->update('customers', ['password' => password_hash($password, PASSWORD_DEFAULT),
                    'reset_token' => null, 'reset_expires' => null], 'id = ?', [$user['id']]);
                $user = App::db()->row('SELECT * FROM customers WHERE id = ?', [$user['id']]);
                self::signIn($user);
                // приглашение сотрудника из админки ведёт на эту же страницу — после пароля сразу в админку
                if (in_array($user['role'], ['admin', 'manager'], true)) return Response::redirect('/admin/');
                Session::flash('ok', t('Пароль изменён. Вы вошли в личный кабинет.'));
                return Response::redirect('/my/orders/');
            }
        }

        // токен в адресе не должен уйти на сторонние сайты через Referer;
        // устаревшая/чужая ссылка — понятная страница с кодом 404 (как Webasyst на неверный ключ восстановления)
        return self::page('front/reset', t('Восстановление пароля'), [
            'errors' => $errors, 'token' => $user ? $token : '', 'valid' => (bool) $user,
            'who' => $user ? (string) ($user['email'] ?? '') : '',
        ], $errors ? 422 : ($user ? 200 : 404))->header('Referrer-Policy', 'no-referrer');
    }

    public function logout(): Response
    {
        Auth::logout();
        return Response::redirect('/');
    }

    // ------------------------------------------------------------------ общие помощники

    /** Параметр ?back= — только локальный путь этого сайта (защита от open redirect) */
    public static function safeBack(string $back): string
    {
        $back = trim($back);
        if ($back === '' || strlen($back) > 500) return '';
        if (!preg_match('#^/(?![/\\\\])[^\s\x00-\x1f\\\\]*$#', $back)) return '';
        if (preg_match('#^(/ua)?/(login|signup|logout|forgotpassword)/#', $back)) return '';
        return $back;
    }

    /** Логин для счётчика попыток: телефон в любом написании (093…, +38 093…, 38093…) — один и тот же ключ */
    private static function loginKey(string $login): string
    {
        $l = mb_strtolower(trim($login));
        return !str_contains($l, '@') && ($p = Str::phone($l)) !== '' ? $p : $l;
    }

    /** Исчерпан ли лимит (без увеличения счётчика) — для учёта только неудачных попыток входа */
    private static function limited(string $key, int $max): bool
    {
        $r = App::db()->row('SELECT hits, reset_at FROM rate_limits WHERE k = ?', [substr($key, 0, 100)]);
        return $r && (int) $r['reset_at'] >= time() && (int) $r['hits'] >= $max;
    }

    /**
     * Вход по e-mail (основному или дополнительному), логину или телефону — всё делает Auth::attempt:
     * у одного телефона/e-mail в старой базе бывает несколько контактов, входим в тот, чей пароль подошёл;
     * md5-пароли Webasyst перехешируются при первом входе.
     */
    private static function findUser(string $login, string $password): ?array
    {
        return Auth::attempt($login, $password);
    }

    /** Вход + язык клиента + перенос корзины гостя (Cart пишет другой раздел — вызываем, только если он есть) */
    public static function signIn(array $user): void
    {
        Auth::login($user);
        if ((string) ($user['lang'] ?? '') !== Lang::current()) {
            App::db()->update('customers', ['lang' => Lang::current()], 'id = ?', [$user['id']]);
        }
        $cart = 'App\\Services\\Cart';
        if (class_exists($cart) && method_exists($cart, 'onLogin')) {
            try {
                $cart::onLogin((int) $user['id']);
            } catch (\Throwable $e) {
                Log::error('Cart::onLogin: ' . $e->getMessage());
            }
        }
    }

    /**
     * Письмо со ссылкой — на языке клиента (customers.lang), ссылка ведёт на ту же языковую версию.
     * Ищем по основному e-mail, затем по дополнительным (customer_emails) — по ним тоже входят в кабинет;
     * письмо уходит на тот адрес, который ввёл покупатель.
     */
    private static function sendResetLink(string $email): void
    {
        $db = App::db();
        $cols = 'c.id, c.name, c.firstname, c.email, c.lang, c.role';
        $u = $db->row("SELECT $cols FROM customers c WHERE c.email = ? AND c.status = 1 LIMIT 1", [$email])
            ?? $db->row("SELECT $cols FROM customer_emails e JOIN customers c ON c.id = e.customer_id AND c.status = 1
                WHERE e.email = ? ORDER BY (c.password IS NOT NULL AND c.password <> '') DESC, c.last_login_at DESC, c.id DESC LIMIT 1", [$email]);
        if (!$u) return;
        $raw = bin2hex(random_bytes(32));
        $db->update('customers', ['reset_token' => hash('sha256', $raw),
            'reset_expires' => date('Y-m-d H:i:s', time() + self::RESET_TTL)], 'id = ?', [$u['id']]);

        $was = Lang::current();
        $lang = isset(Lang::LANGS[(string) $u['lang']]) ? (string) $u['lang'] : Lang::DEFAULT;
        Lang::set($lang);
        DB::$localize = Lang::isUk();
        try {
            $link = url(Lang::path('/forgotpassword/reset/', $lang) . '?t=' . $raw);
            $subject = t('Восстановление пароля — Tomobuv');
            $html = View::render('emails/layout', [
                'title' => $subject, 'preheader' => t('Ссылка для смены пароля действует 1 час.'),
                'content' => View::render('emails/password-reset', ['user' => $u, 'link' => $link], null),
            ], null);
        } finally {
            Lang::set($was);
            DB::$localize = Lang::isUk();
        }
        RequestController::deferMail($email, $subject, $html, 'password-reset-' . $u['id'], 'письмо восстановления пароля, клиент #' . $u['id']);
    }

    private static function userByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        return App::db()->row('SELECT id, email, name FROM customers WHERE reset_token = ? AND reset_expires > ? AND status = 1 LIMIT 1',
            [hash('sha256', $token), date('Y-m-d H:i:s')]);
    }

    /** Страница формы: noindex, без кэша */
    private static function page(string $tpl, string $title, array $data, int $status = 200): Response
    {
        $seo = Seo::make($title);
        $seo->robots = 'noindex, follow';
        $html = View::render($tpl, $data + ['seo' => $seo, 'scripts' => ['js/account.js'], 'bodyClass' => 'pg-auth']);
        return Response::html($html, $status);
    }

    // ------------------------------------------------------------------ для шаблонов форм

    /** Атрибуты ошибочного поля: class-суффикс « err» и aria-* */
    public static function inv(array $errors, string $k): string
    {
        return isset($errors[$k]) ? ' err" aria-invalid="true" aria-describedby="e-' . e($k) : '';
    }

    /** Текст ошибки под полем */
    public static function err(array $errors, string $k): string
    {
        return isset($errors[$k]) ? '<div class="errtxt" id="e-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
    }
}
