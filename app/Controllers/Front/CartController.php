<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\View;
use App\Services\Cart;
use App\Services\Orders;

/**
 * Корзина. /cart/ — одностраничное «Оформление заказа» (как плагин «Корзина + заказ в 1 шаг»
 * на старом сайте): позиции + форма заказа. Изменения корзины — AJAX (POST + CSRF), ответы JSON
 * в формате, который ждёт app.js (afterCart / loadCart). Страницы раздела не кэшируются.
 */
final class CartController
{
    public function index(): Response
    {
        return self::page();
    }

    /** Страница корзины/оформления; $errors и $old — после неудачной отправки формы без JS */
    public static function page(array $errors = [], array $old = [], int $status = 200): Response
    {
        $items = Cart::items();
        Cart::syncCookie();
        $user = Auth::user();
        $seo = Seo::make(t('Оформление заказа'));
        $seo->robots = 'noindex, follow';
        $seo->canonical = url('/cart/');
        $shipping = Orders::shippingMethods();
        $payment = Orders::paymentMethods();

        $html = View::render('front/cart', [
            'seo'      => $seo,
            'items'    => $items,
            'sum'      => Cart::summary(),
            'removed'  => Cart::$removed,
            'free'     => (int) Settings::get('free_shipping_boxes', 20),
            'shipping' => $shipping,
            'payment'  => $payment,
            'form'     => self::formDefaults($user, $old, $shipping, $payment),
            'errors'   => $errors,
            'user'     => $user,
            'phones'   => Settings::json('phones', []),
            'couponOn' => Orders::couponsEnabled(),
            'scripts'  => ['js/checkout.js'],
            'bodyClass' => 'page-checkout',
        ]);
        return Response::html($html, $status)->header('X-Robots-Tag', 'noindex');
    }

    /** GET /cart/json/ — содержимое корзины для выезжающей панели (app.js loadCart) */
    public function json(): Response
    {
        self::detectLang();
        $items = Cart::items();
        Cart::syncCookie();
        $out = [];
        foreach ($items as $i) {
            $out[] = [
                'id' => $i['id'], 'name' => $i['name'] . ($i['available'] ? '' : ' (' . t('нет в наличии') . ')'), 'url' => $i['link'],
                'img' => $i['img_small'], 'boxes' => $i['boxes'], 'box_qty' => $i['box_qty'], 'price' => $i['price'],
                'box_price' => $i['box_price'], 'sum' => $i['sum'], 'pairs' => $i['pairs'], 'size' => $i['size'],
                'available' => $i['available'], 'max' => $i['max_boxes'],
            ];
        }
        return Response::json(['items' => $out] + self::state())->header('X-Robots-Tag', 'noindex');
    }

    /** POST /cart/add/ — product_id, boxes (ящиков, по умолчанию 1) */
    public function add(): Response
    {
        self::detectLang();
        $pid = Request::postInt('product_id');
        return self::mutate(static fn() => Cart::add($pid, Request::postInt('boxes', 1)), t('Товар добавлен в корзину'), $pid);
    }

    /** POST /cart/update/ — product_id, boxes (0 — удалить) */
    public function update(): Response
    {
        self::detectLang();
        $pid = Request::postInt('product_id');
        $boxes = Request::postInt('boxes', 1);
        return self::mutate(static fn() => Cart::set($pid, $boxes), $boxes <= 0 ? t('Товар удалён из корзины') : '', $pid);
    }

    /** POST /cart/remove/ — product_id */
    public function remove(): Response
    {
        self::detectLang();
        $pid = Request::postInt('product_id');
        return self::mutate(static fn() => Cart::remove($pid), t('Товар удалён из корзины'), $pid);
    }

    /** POST /cart/clear/ */
    public function clear(): Response
    {
        self::detectLang();
        return self::mutate(static fn() => Cart::clear(), t('Корзина очищена'));
    }

    /**
     * Язык ответа для AJAX-адресов без префикса /ua (app.js шлёт запросы корзины на /cart/add/ и т.п.):
     * параметр lang=uk или страница, с которой пришёл запрос (Referer того же сайта), — /ua/…
     */
    public static function detectLang(): void
    {
        if (Lang::isUk()) return;
        $want = Request::post('lang') ?: Request::get('lang');
        if ($want === '') {
            $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $host = (string) parse_url($ref, PHP_URL_HOST);
            $self = (string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
            $path = (string) parse_url($ref, PHP_URL_PATH);
            if ($host !== '' && strcasecmp($host, $self) === 0 && ($path === '/ua' || str_starts_with($path, '/ua/'))) $want = 'uk';
        }
        if ($want === 'uk') {
            Lang::set('uk');
            DB::$localize = true;
        }
    }

    // ------------------------------------------------------------------ служебное

    private static function mutate(callable $fn, string $message, int $pid = 0): Response
    {
        if (!Csrf::check()) {
            return Response::json(['ok' => false, 'error' => t('Страница устарела. Обновите её и повторите действие.')], 403);
        }
        try {
            $fn();
        } catch (\InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()] + self::state($pid), 422);
        }
        return Response::json(['ok' => true, 'message' => $message] + self::state($pid));
    }

    /** Итоги корзины для JSON-ответов: {count, total, pairs, lines, free_left[, item]} */
    private static function state(int $pid = 0): array
    {
        $s = Cart::summary();
        $out = ['count' => $s['count'], 'total' => $s['total'], 'pairs' => $s['pairs'], 'lines' => $s['lines'],
            'free_left' => max(0, (int) Settings::get('free_shipping_boxes', 20) - $s['count'])];
        if ($pid > 0) {
            $i = Cart::item($pid);
            $out['item'] = $i ? ['id' => $i['id'], 'boxes' => $i['boxes'], 'box_qty' => $i['box_qty'], 'pairs' => $i['pairs'], 'sum' => $i['sum'], 'max' => $i['max_boxes']] : null;
        }
        return $out;
    }

    /** Значения формы: после ошибки — введённые; для вошедшего — из профиля и последнего заказа */
    private static function formDefaults(?array $user, array $old, array $shipping, array $payment): array
    {
        $f = ['name' => '', 'phone' => '', 'email' => '', 'region' => '', 'city' => '', 'address' => '',
            'shipping' => (string) array_key_first($shipping), 'payment' => (string) array_key_first($payment), 'comment' => '',
            'coupon' => '', 'agree' => false];
        if ($old) {
            foreach ($f as $k => $v) {
                if ($k === 'agree') { $f[$k] = !empty($old['agree']); continue; }
                if (isset($old[$k]) && is_scalar($old[$k])) $f[$k] = mb_substr((string) $old[$k], 0, 2000);
            }
            return $f;
        }
        if ($user) {
            $f['name'] = trim((string) ($user['name'] ?: trim($user['firstname'] . ' ' . $user['lastname'])));
            $f['phone'] = Orders::formatPhone($user['phone'] ?? '');
            $f['email'] = (string) ($user['email'] ?? '');
            $f['city'] = (string) ($user['city'] ?? '');
            $last = App::db()->row('SELECT shipping_method, payment_method, region, city, address FROM orders
                WHERE customer_id = ? AND shipping_method IS NOT NULL ORDER BY created_at DESC LIMIT 1', [(int) $user['id']]);
            if ($last) {
                if (isset($shipping[$last['shipping_method']])) {
                    $f['shipping'] = (string) $last['shipping_method'];
                    if (!$shipping[$f['shipping']]['pickup']) $f['address'] = (string) $last['address'];
                }
                if (isset($payment[$last['payment_method']])) $f['payment'] = (string) $last['payment_method'];
                if ($last['city']) $f['city'] = (string) $last['city'];
                if (isset(Orders::REGIONS[(string) $last['region']])) $f['region'] = (string) $last['region'];
            }
        }
        return $f;
    }
}
