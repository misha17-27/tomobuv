<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Image;
use App\Core\Paginator;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Str;
use App\Core\View;

/**
 * Личный кабинет покупателя: мои заказы, заказ, профиль.
 * Только для вошедших (иначе — на /login/?back=…), только свои данные, без кэша, noindex.
 */
final class AccountController
{
    private const PER_PAGE = 20;

    /** Статусы заказа (как в Webasyst); на витрине выводить через statusName() — там перевод */
    public const STATUSES = [
        'new' => 'Новый', 'processing' => 'В обработке', 'paid' => 'Оплачен', 'shipped' => 'Отправлен',
        'completed' => 'Выполнен', 'refunded' => 'Возврат', 'deleted' => 'Отменён',
    ];

    public function index(): Response
    {
        if (!Auth::check()) return self::toLogin('/my/orders/');
        return Response::redirect('/my/orders/');
    }

    public function orders(): Response
    {
        if (!Auth::check()) return self::toLogin();
        $db = App::db();
        $uid = Auth::id();
        $total = (int) $db->value('SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$uid]);
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $orders = $total ? $db->all('SELECT id, status, total, currency, boxes, pairs, shipping_name, created_at FROM orders
            WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $pg->offset . ', ' . $pg->perPage, [$uid]) : [];

        return self::page('front/account-orders', t('Мои заказы'), [
            'orders' => $orders, 'pg' => $pg, 'total' => $total, 'active' => 'orders',
        ]);
    }

    public function order(string $id): Response
    {
        if (!Auth::check()) return self::toLogin();
        if (!ctype_digit($id) || strlen($id) > 10) return Response::notFound();
        $db = App::db();
        // только свой заказ; чужой или несуществующий — 404 (не раскрываем, что такой номер есть)
        $order = $db->row('SELECT * FROM orders WHERE id = ? AND customer_id = ?', [(int) $id, Auth::id()]);
        if (!$order) return Response::notFound();

        // позиции + текущее состояние товара (для ссылок, фото и «Повторить заказ») — одним запросом
        $items = $db->all('SELECT oi.id, oi.product_id, oi.name, oi.sku, oi.price, oi.quantity, oi.box_qty,
                p.url, p.image_id, p.image_ext, p.status AS p_status, p.in_stock, p.stock, p.box_qty AS p_box_qty
            FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ? ORDER BY oi.id', [$order['id']]);
        $cart = 'App\\Services\\Cart';
        $cartMax = class_exists($cart) && method_exists($cart, 'maxBoxes');
        foreach ($items as &$it) {
            $bq = max(1, (int) $it['box_qty']);
            $it['pairs'] = (int) $it['quantity'];
            $it['boxes'] = (int) ceil($it['pairs'] / $bq);
            $it['sum'] = (float) $it['price'] * $it['pairs'];
            $pbq = max(1, (int) ($it['p_box_qty'] ?? $bq));
            // сколько ящиков можно положить в корзину: правило корзины (stock — пар, NULL — без ограничений)
            $maxBoxes = $cartMax ? $cart::maxBoxes(['stock' => $it['stock'], 'box_qty' => $pbq])
                : ($it['stock'] === null ? 999 : intdiv(max(0, (int) $it['stock']), $pbq));
            $it['available'] = $it['url'] !== null && (int) $it['p_status'] === 1 && (int) $it['in_stock'] === 1 && $maxBoxes > 0;
            $it['link'] = $it['url'] !== null && (int) $it['p_status'] === 1 ? '/product/' . $it['url'] . '/' : '';
            $it['img'] = $it['url'] !== null
                ? Image::url((int) $it['product_id'], $it['image_id'] ? (int) $it['image_id'] : null, $it['image_ext'], '96x96')
                : '/assets/img/no-photo.svg';
            // для «Повторить заказ»: ящиков по текущей кратности товара, не больше остатка
            $it['repeat_boxes'] = min($maxBoxes, max(1, (int) round($it['pairs'] / $pbq)));
        }
        unset($it);

        // история: только смены статуса (служебные записи и тексты уведомлений покупателю не показываем)
        $log = $db->all('SELECT status_from, status_to, created_at FROM order_log WHERE order_id = ? ORDER BY created_at, id', [$order['id']]);
        $history = [];
        foreach ($log as $l) {
            $to = (string) $l['status_to'];
            if ($to === '' || !isset(self::STATUSES[$to])) continue;
            if ($l['status_from'] !== null && $l['status_from'] === $to) continue;
            $last = end($history);
            if ($last && $last['status'] === $to) continue;
            $history[] = ['status' => $to, 'at' => $l['created_at']];
        }
        if (!$history) $history[] = ['status' => 'new', 'at' => $order['created_at']];

        return self::page('front/account-order', t('Заказ {n}', ['n' => self::number((int) $order['id'])]), [
            'order' => $order, 'items' => $items, 'history' => $history, 'active' => 'order',
        ]);
    }

    public function profile(): Response
    {
        if (!Auth::check()) return self::toLogin();
        $u = Auth::user();
        $errors = [];
        $pwErrors = [];
        $d = [
            'firstname' => (string) $u['firstname'], 'lastname' => (string) $u['lastname'], 'company' => (string) $u['company'],
            'phone' => self::phoneView((string) $u['phone']), 'email' => (string) $u['email'], 'city' => (string) $u['city'],
        ];
        if ($d['firstname'] === '' && $d['lastname'] === '') $d['firstname'] = (string) $u['name'];

        if (Request::isPost()) {
            $act = Request::post('act');
            if (!Csrf::check()) {
                $err = t('Страница устарела. Обновите её и сохраните ещё раз.');
                if ($act === 'password') $pwErrors['form'] = $err; else $errors['form'] = $err;
            } elseif ($act === 'password') {
                $pwErrors = self::changePassword($u);
                if (!$pwErrors) {
                    Session::flash('ok', t('Пароль изменён.'));
                    return Response::redirect('/my/profile/');
                }
            } else {
                foreach ($d as $k => $_) $d[$k] = mb_substr(Request::post($k), 0, in_array($k, ['email', 'company', 'city'], true) ? 190 : 100);
                $errors = self::saveProfile($u, $d);
                if (!$errors) {
                    Session::flash('ok', t('Данные сохранены.'));
                    return Response::redirect('/my/profile/');
                }
            }
        }

        return self::page('front/account-profile', t('Профиль'), [
            'd' => $d, 'errors' => $errors, 'pwErrors' => $pwErrors, 'active' => 'profile', 'hasPassword' => (string) $u['password'] !== '',
            'minPassword' => AuthController::minPassword((string) $u['role']),
        ], ($errors || $pwErrors) ? 422 : 200);
    }

    // ------------------------------------------------------------------ сохранение

    private static function saveProfile(array $u, array $d): array
    {
        $db = App::db();
        $e = [];
        $phone = $d['phone'] !== '' ? Str::phone($d['phone']) : '';
        $email = $d['email'] !== '' ? Str::email($d['email']) : '';
        if ($d['phone'] !== '' && $phone === '') $e['phone'] = t('Проверьте номер телефона: например, +38 (093) 275-30-70');
        if ($d['email'] !== '' && $email === '') $e['email'] = t('Проверьте e-mail: например, name@gmail.com');
        if (!$e && $phone === '' && $email === '') $e['phone'] = t('Укажите телефон или e-mail — по ним вы входите в кабинет');
        if (!isset($e['email']) && $email !== '' && $db->value('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1', [$email, $u['id']])) {
            $e['email'] = t('Этот e-mail уже используется другим покупателем');
        }
        if (!isset($e['phone']) && $phone !== '' && $phone !== (string) $u['phone']
            && $db->value("SELECT id FROM customers WHERE phone = ? AND id <> ? AND password IS NOT NULL AND password <> '' LIMIT 1", [$phone, $u['id']])) {
            $e['phone'] = t('Этот телефон уже зарегистрирован в другом кабинете');
        }
        if ($e) return $e;

        $name = trim($d['firstname'] . ' ' . $d['lastname']);
        try {
            $db->update('customers', [
                'firstname' => $d['firstname'], 'lastname' => $d['lastname'], 'name' => $name !== '' ? $name : (string) $u['name'],
                'company' => $d['company'], 'city' => $d['city'] !== '' ? $d['city'] : null,
                'phone' => $phone !== '' ? $phone : null, 'email' => $email !== '' ? $email : null,
            ], 'id = ?', [$u['id']]);
        } catch (\PDOException $ex) {
            if ($ex->getCode() !== '23000') throw $ex;
            return ['email' => t('Этот e-mail уже используется другим покупателем')];
        }
        return [];
    }

    private static function changePassword(array $u): array
    {
        $raw = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        $cur = $raw('current');
        $new = $raw('password');
        $new2 = $raw('password2');
        $e = [];
        if ((string) $u['password'] !== '' && !RateLimit::hit('pwchange:' . $u['id'], 10, 900)) {
            return ['current' => t('Слишком много попыток. Попробуйте через 15 минут.')];
        }
        if ((string) $u['password'] !== '' && ($cur === '' || !Auth::verify($cur, (string) $u['password']))) {
            $e['current'] = $cur === '' ? t('Введите текущий пароль') : t('Текущий пароль указан неверно');
        }
        // сотрудник, зашедший в кабинет витрины, — пароль по правилам админки (не короче 10)
        if (($pe = AuthController::passwordError($new, (string) $u['role'], true)) !== '') $e['password'] = $pe;
        elseif ($new !== $new2) $e['password2'] = t('Пароли не совпадают');
        if ($e) return $e;
        App::db()->update('customers', ['password' => password_hash($new, PASSWORD_DEFAULT), 'reset_token' => null, 'reset_expires' => null],
            'id = ?', [$u['id']]);
        // новый идентификатор сессии и отпечаток нового пароля: этот сеанс остаётся, остальные (другие устройства) завершатся
        Auth::passwordChanged((int) $u['id']);
        return [];
    }

    // ------------------------------------------------------------------ помощники

    /** Номер заказа как на старом сайте: #100{id} */
    public static function number(int $id): string
    {
        $fmt = (string) Settings::get('order_format', '#100{$order.id}');
        return str_contains($fmt, '{$order.id}') ? str_replace('{$order.id}', (string) $id, $fmt) : '#100' . $id;
    }

    public static function statusName(string $s): string
    {
        return match ($s) {
            'new' => t('Новый'), 'processing' => t('В обработке'), 'paid' => t('Оплачен'), 'shipped' => t('Отправлен'),
            'completed' => t('Выполнен'), 'refunded' => t('Возврат'), 'deleted' => t('Отменён'),
            default => $s,
        };
    }

    /** Сумма заказа в валюте заказа (исторические суммы не пересчитываем) */
    public static function money($v, string $currency = 'UAH'): string
    {
        return $currency === 'UAH' || $currency === '' ? price_format($v) : number_format((float) $v, 2, '.', ' ') . ' ' . $currency;
    }

    /** 380932753070 → +38 (093) 275-30-70 */
    public static function phoneView(string $p): string
    {
        return preg_match('/^38(0\d{2})(\d{3})(\d{2})(\d{2})$/', $p, $m) ? "+38 ($m[1]) $m[2]-$m[3]-$m[4]" : $p;
    }

    /** «12 мая 2018, 22:27» (на украинской версии — «12 травня 2018, 22:27») */
    public static function date(?string $dt, bool $time = true): string
    {
        if (!$dt || !($ts = strtotime($dt))) return '';
        $m = ['', t('января'), t('февраля'), t('марта'), t('апреля'), t('мая'), t('июня'), t('июля'), t('августа'),
            t('сентября'), t('октября'), t('ноября'), t('декабря')];
        return (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts)] . ' ' . date('Y', $ts) . ($time ? ', ' . date('H:i', $ts) : '');
    }

    /** На вход с возвратом на текущую страницу (путь без языкового префикса — редирект сам получит /ua) */
    private static function toLogin(?string $back = null): Response
    {
        if ($back === null) {
            $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
            $back = Request::path() . ($qs !== '' ? '?' . $qs : '');
        }
        $back = AuthController::safeBack($back) ?: '/my/orders/';
        return Response::redirect('/login/?back=' . rawurlencode($back));
    }

    private static function page(string $tpl, string $title, array $data, int $status = 200): Response
    {
        $seo = Seo::make($title);
        $seo->robots = 'noindex, nofollow';
        $html = View::render($tpl, $data + [
            'seo' => $seo, 'scripts' => ['js/account.js'], 'bodyClass' => 'pg-account',
            'flash' => Session::flash('ok'),
        ]);
        return Response::html($html, $status);
    }
}
