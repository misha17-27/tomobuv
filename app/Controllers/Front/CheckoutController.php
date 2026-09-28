<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Log;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\View;
use App\Services\Cart;
use App\Services\Coupons;
use App\Services\Orders;
use App\Services\Products;

/**
 * Оформление заказа. Форма живёт на /cart/ (одна страница), отправляется POST /order/.
 * Без JS — обычная отправка формы (ошибки показываются на той же странице), с JS — AJAX/JSON.
 * После заказа — редирект на /order/success/?id=…&k=подпись (без подписи заказ не показывается).
 */
final class CheckoutController
{
    private const RATE_MAX = 10;       // заказов в час с одного IP
    private const RATE_WINDOW = 3600;

    /**
     * ANY /order/: GET → форма оформления на /cart/ (на старом сайте /order/ не открывался — 404, ссылки ведут сюда же);
     * POST → оформить заказ; POST с coupon_check=1 → только проверить промокод.
     */
    public function index(): Response
    {
        if (!Request::isPost()) return Response::redirect('/cart/#order', 302);
        if (Request::post('coupon_check') !== '') return $this->couponCheck();
        return $this->place();
    }

    /** GET /checkout/ — старый адрес оформления → 301 */
    public function legacy(): Response
    {
        return Response::redirect('/cart/', 301);
    }

    /**
     * GET /order/success/?id=…&k=… — «Спасибо, заказ оформлен».
     * Ссылка из письма открывается и позже: заказ старше часа показывается нейтрально — «Заказ #…» и текущий статус.
     */
    public function success(): Response
    {
        $id = Request::getInt('id');
        if (!Orders::checkSign($id, Request::get('k')) || !($order = Orders::find($id))) return Response::notFound();
        $fresh = time() - (int) strtotime((string) $order['created_at']) < 3600;
        $seo = Seo::make($fresh ? t('Заказ оформлен') : t('Заказ {n}', ['n' => $order['number']]));
        $seo->robots = 'noindex, nofollow';
        $html = View::render('front/order-success', [
            'seo'    => $seo,
            'fresh'  => $fresh,
            'order'  => $order,
            'user'   => Auth::user(),
            'phones' => Settings::json('phones', []),
            'free'   => (int) Settings::get('free_shipping_boxes', 20),
            'bodyClass' => 'page-checkout',
        ]);
        return Response::html($html)->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * POST /quickorder/ — «Купить в 1 клик»: product_id, boxes, name, phone → заказ с source=quickorder.
     * Без product_id — вся корзина. Ответ: {ok, message, order_id, number, redirect}.
     */
    public function quick(): Response
    {
        CartController::detectLang();
        if (!Csrf::check()) return Response::json(['ok' => false, 'error' => t('Страница устарела. Обновите её и отправьте заказ ещё раз.')], 403);
        if (Request::post('website') !== '') {
            Log::info('quickorder: honeypot, ip ' . Request::ip());
            return Response::json(['ok' => false, 'error' => t('Не удалось отправить заказ')], 400);
        }
        [$d, $errors] = Orders::validate($_POST, true);
        if ($errors) return Response::json(['ok' => false, 'error' => (string) reset($errors), 'errors' => $errors], 422);

        $pid = Request::postInt('product_id');
        $fromCart = $pid <= 0;
        $lock = $fromCart ? self::lock() : null;   // заказ всей корзины — под блокировкой, как в place()
        try {
            try {
                if ($fromCart) {
                    $lines = self::cartLines();
                } else {
                    $boxes = max(1, Request::postInt('boxes', 1));
                    $p = Cart::product($pid);
                    Cart::checkQty($p, $boxes);
                    $card = Products::cards([$pid])[0] ?? null;
                    if (!$card) throw new \InvalidArgumentException(t('Товар не найден или снят с продажи'));
                    $lines = [['p' => $card, 'boxes' => $boxes]];
                }
            } catch (\InvalidArgumentException $e) {
                return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            if (!RateLimit::hit('order:' . Request::ip(), self::RATE_MAX, self::RATE_WINDOW)) {
                return Response::json(['ok' => false, 'error' => self::rateMessage()], 429);
            }
            try {
                $order = Orders::create($d, $lines, 'quickorder', $fromCart ? ['quickorder_cart' => 1] : ['quickorder_product' => $pid]);
            } catch (\Throwable $e) {
                Log::error('quickorder: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                return Response::json(['ok' => false, 'error' => self::failMessage()], 500);
            }
            if ($fromCart) Cart::clear();
        } finally {
            self::unlock($lock);
        }
        return Response::json([
            'ok' => true, 'order_id' => $order['id'], 'number' => $order['number'], 'redirect' => lurl(Orders::successUrl($order['id'])),
            'message' => t('Спасибо! Ваш заказ успешно оформлен. Мы свяжемся с вами в ближайшее время. Номер вашего заказа {n}.', ['n' => $order['number']]),
        ]);
    }

    // ------------------------------------------------------------------ оформление из корзины

    private function place(): Response
    {
        $ajax = Request::isAjax();
        $fail = static function (string $error, array $errors = [], int $code = 422) use ($ajax): Response {
            if ($ajax) return Response::json(['ok' => false, 'error' => $error, 'errors' => (object) $errors], $code);
            return CartController::page($errors + ['_form' => $error], $_POST, $code);
        };
        if (!Csrf::check()) return $fail(t('Страница устарела. Обновите её и отправьте заказ ещё раз.'), [], 403);
        if (Request::post('website') !== '') {
            Log::info('checkout: honeypot, ip ' . Request::ip());
            return $fail(t('Не удалось оформить заказ'), [], 400);
        }
        [$d, $errors] = Orders::validate($_POST);
        // двойной клик / повторная отправка формы без JS: второй запрос ждёт первый и видит уже пустую корзину
        $lock = self::lock();
        try {
            $lines = self::cartLines();
        } catch (\InvalidArgumentException $e) {
            self::unlock($lock);
            // корзина пуста, потому что первый из двух одинаковых запросов только что оформил заказ, — ведём на его страницу
            $last = !Cart::items() && Cart::token() !== null ? (int) Cache::get('order.by_cart.' . Cart::token(), 0) : 0;
            if ($last > 0) return self::done($last, $ajax);
            return $fail($e->getMessage(), [], 422);
        }
        try {
            return $this->placeLocked($d, $errors, $lines, $fail, $ajax);
        } finally {
            self::unlock($lock);
        }
    }

    /** Продолжение place() под блокировкой корзины: промокод, лимит, создание заказа */
    private function placeLocked(array $d, array $errors, array $lines, callable $fail, bool $ajax): Response
    {
        $coupon = null;
        $code = Request::post('coupon');
        if ($code !== '' && Orders::couponsEnabled()) {
            $r = Coupons::check($code, Cart::items(), Cart::total(), Auth::id() ?: null, $d['phone'] !== '' ? $d['phone'] : null);
            if ($r['ok']) $coupon = ['coupon' => $r['coupon'], 'discount' => $r['discount']];
            else $errors['coupon'] = t($r['error']);
        }
        if ($errors) return $fail(t('Проверьте выделенные поля формы'), $errors);
        if (!RateLimit::hit('order:' . Request::ip(), self::RATE_MAX, self::RATE_WINDOW)) return $fail(self::rateMessage(), [], 429);

        try {
            $order = Orders::create($d, $lines, 'site', [], $coupon);
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage(), $coupon ? ['coupon' => $e->getMessage()] : []);
        } catch (\Throwable $e) {
            Log::error('checkout: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return $fail(self::failMessage(), [], 500);
        }
        if (($t = Cart::token()) !== null) Cache::set('order.by_cart.' . $t, (int) $order['id'], 600);
        Cart::clear();
        return self::done((int) $order['id'], $ajax);
    }

    /** Ответ после оформления: JSON со ссылкой (AJAX) или 303 на страницу заказа */
    private static function done(int $id, bool $ajax): Response
    {
        $url = Orders::successUrl($id);
        if ($ajax) {
            $n = Orders::number($id);
            return Response::json(['ok' => true, 'order_id' => $id, 'number' => $n, 'redirect' => lurl($url),
                'message' => t('Заказ {n} оформлен', ['n' => $n])]);
        }
        return Response::redirect($url, 303);
    }

    /** POST /order/ coupon_check=1, coupon, phone — проверить промокод и пересчитать итог (без создания заказа) */
    private function couponCheck(): Response
    {
        if (!Csrf::check()) return Response::json(['ok' => false, 'error' => t('Страница устарела. Обновите её и повторите действие.')], 403);
        if (!Orders::couponsEnabled()) return Response::json(['ok' => false, 'error' => t('Промокоды сейчас не принимаются')], 422);
        if (!RateLimit::hit('coupon:' . Request::ip(), 30, 600)) {
            return Response::json(['ok' => false, 'error' => t('Слишком много попыток. Попробуйте через несколько минут.')], 429);
        }
        $code = Request::post('coupon');
        $subtotal = Cart::total();
        $r = Coupons::check($code, Cart::items(), $subtotal, Auth::id() ?: null, Request::post('phone') ?: null);
        if (!$r['ok']) return Response::json(['ok' => false, 'error' => t($r['error']), 'subtotal' => $subtotal, 'total' => $subtotal]);
        return Response::json([
            'ok' => true, 'code' => $r['coupon']['code'], 'title' => $r['title'], 'discount' => $r['discount'],
            'subtotal' => $subtotal, 'total' => round($subtotal - $r['discount'], 2),
            'message' => t('Промокод {code} применён: скидка {sum}', ['code' => $r['coupon']['code'], 'sum' => price_format($r['discount'])]),
        ]);
    }

    /** Блокировка корзины на время оформления (MySQL GET_LOCK, ждём до 10 с); null — корзины нет или блокировка недоступна */
    private static function lock(): ?string
    {
        $t = Cart::token();
        if ($t === null) return null;
        $name = 'tomobuv_order_' . $t;
        try {
            return (int) App::db()->value('SELECT GET_LOCK(?, 10)', [$name]) === 1 ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function unlock(?string $name): void
    {
        if ($name === null) return;
        try {
            App::db()->value('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (\Throwable $e) {
        }
    }

    /** Позиции корзины для заказа: только товары в наличии, с проверкой остатков */
    private static function cartLines(): array
    {
        $lines = [];
        foreach (Cart::items() as $i) {
            if (!$i['available']) continue;
            if ($i['boxes'] > $i['max_boxes']) {
                throw new \InvalidArgumentException(t('Товара «{name}» в наличии только {n} {boxes} — уменьшите количество в корзине',
                    ['name' => $i['name'], 'n' => $i['max_boxes'], 'boxes' => Cart::boxesWord($i['max_boxes'])]));
            }
            $lines[] = ['p' => $i, 'boxes' => $i['boxes']];
        }
        if (!$lines) throw new \InvalidArgumentException(t('Корзина пуста — добавьте товары, чтобы оформить заказ'));
        return $lines;
    }

    private static function rateMessage(): string
    {
        $phone = Settings::json('phones', [''])[0] ?? '';
        return t('Слишком много заказов за короткое время. Попробуйте позже или позвоните нам') . ($phone ? ': ' . $phone : '');
    }

    private static function failMessage(): string
    {
        $phone = Settings::json('phones', [''])[0] ?? '';
        return t('Не удалось оформить заказ из-за ошибки на сервере. Попробуйте ещё раз или позвоните нам') . ($phone ? ': ' . $phone : '');
    }
}
