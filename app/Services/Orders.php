<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\Str;
use App\Core\View;

/**
 * Оформление заказов с витрины (корзина, «купить в 1 клик»).
 * Опт: в заказе считаются ящики, в order_items.quantity пишутся ПАРЫ (как в Webasyst),
 * price — цена за пару. Номер заказа — по шаблону settings.order_format (#100{$order.id} → #1003635).
 *
 * Языки: в заказ пишутся русские названия товаров и способов доставки/оплаты (их читает админка),
 * язык покупателя — в orders.lang; письмо покупателю уходит на этом языке, администратору — на русском.
 */
final class Orders
{
    /** Области Украины. Коды — как в Webasyst (wa_region), в orders.region хранится код. */
    public const REGIONS = [
        '02' => 'Винницкая область', '03' => 'Волынская область', '04' => 'Днепропетровская область',
        '05' => 'Донецкая область', '06' => 'Житомирская область', '07' => 'Закарпатская область',
        '08' => 'Запорожская область', '09' => 'Ивано-Франковская область', '11' => 'Киев',
        '10' => 'Киевская область', '12' => 'Кировоградская область', '01' => 'АР Крым',
        '13' => 'Луганская область', '14' => 'Львовская область', '15' => 'Николаевская область',
        '16' => 'Одесская область', '17' => 'Полтавская область', '18' => 'Ровенская область',
        '27' => 'Севастополь', '19' => 'Сумская область', '20' => 'Тернопольская область',
        '21' => 'Харьковская область', '22' => 'Херсонская область', '23' => 'Хмельницкая область',
        '24' => 'Черкасская область', '25' => 'Черниговская область', '26' => 'Черновицкая область',
    ];

    public const SOURCES = ['site' => 'Сайт', 'quickorder' => 'Купить в 1 клик'];

    // ------------------------------------------------------------------ справочники

    /**
     * Активные способы доставки из settings.shipping_methods: [code => [...]].
     * name — на языке страницы (name_uk на /ua/), name_ru — как в настройке (пишется в заказ).
     * Необязательные ключи в настройке: pickup (bool), field/field_uk (подпись поля адреса), placeholder.
     * kind: branch — отделение перевозчика, post — адрес (Укрпочта), pickup — самовывоз.
     */
    public static function shippingMethods(): array
    {
        $uk = Lang::isUk();
        $out = [];
        $addr = array_map('trim', explode(',', (string) Settings::get('address', 'Одесса, Промрынок 7 км')));
        $pickupPlace = t(implode(', ', array_slice($addr, -2)));
        foreach (Settings::json('shipping_methods') as $m) {
            if (!is_array($m) || (isset($m['status']) && !(int) $m['status'])) continue;
            $code = trim((string) ($m['code'] ?? ''));
            $name = trim((string) ($m['name'] ?? ''));
            if ($code === '' || $name === '') continue;
            $low = mb_strtolower($name);
            $pickup = isset($m['pickup']) ? (bool) $m['pickup'] : (str_contains($low, 'самовывоз') || str_contains($low, 'самовивіз'));
            $post = str_contains($low, 'укрпочт') || str_contains($low, 'укрпошт');
            $kind = $pickup ? 'pickup' : ($post ? 'post' : 'branch');
            $desc = self::loc($m, 'description');
            $field = self::loc($m, 'field');
            $ph = self::loc($m, 'placeholder');
            $out[$code] = [
                'code'        => $code,
                'name'        => $uk && trim((string) ($m['name_uk'] ?? '')) !== '' ? trim((string) $m['name_uk']) : t($name),
                'name_ru'     => $name,
                'description' => $desc !== '' ? $desc : ($pickup ? $pickupPlace : ''),
                'pickup'      => $pickup,
                'kind'        => $kind,
                'field'       => $field !== '' ? $field : match ($kind) {
                    'pickup' => t('Когда планируете забрать'),
                    'post'   => t('Адрес: индекс, улица, дом'),
                    default  => t('Номер отделения / склада'),
                },
                'placeholder' => $ph !== '' ? $ph : match ($kind) {
                    'pickup' => t('Например: завтра утром'),
                    'post'   => t('Индекс, улица, дом, квартира'),
                    default  => t('Например: 25'),
                },
                'required'    => !$pickup,
                'rate'        => $pickup ? t('бесплатно') : t('по тарифам перевозчика'),
            ];
        }
        return $out;
    }

    /** Активные способы оплаты из settings.payment_methods: [code => [...]] (name — на языке страницы, name_ru — в заказ) */
    public static function paymentMethods(): array
    {
        $uk = Lang::isUk();
        $out = [];
        foreach (Settings::json('payment_methods') as $m) {
            if (!is_array($m) || (isset($m['status']) && !(int) $m['status'])) continue;
            $code = trim((string) ($m['code'] ?? ''));
            $name = trim((string) ($m['name'] ?? ''));
            if ($code === '' || $name === '') continue;
            $low = mb_strtolower($name);
            $desc = self::loc($m, 'description');
            if ($desc === '') {
                $desc = match (true) {
                    str_contains($low, 'карт')  => t('Реквизиты для оплаты пришлёт менеджер после подтверждения заказа'),
                    str_contains($low, 'налож') => t('Оплата при получении в отделении перевозчика'),
                    str_contains($low, 'налич') => t('Оплата наличными при получении'),
                    default => '',
                };
            }
            $out[$code] = [
                'code' => $code, 'name_ru' => $name, 'description' => $desc,
                'name' => $uk && trim((string) ($m['name_uk'] ?? '')) !== '' ? trim((string) $m['name_uk']) : t($name),
            ];
        }
        return $out;
    }

    /** Текстовое поле способа доставки/оплаты на языке страницы: x_uk на /ua/, иначе x (с переводом типовых фраз) */
    private static function loc(array $m, string $key): string
    {
        if (Lang::isUk()) {
            $u = trim((string) ($m[$key . '_uk'] ?? ''));
            if ($u !== '') return $u;
        }
        $v = trim((string) ($m[$key] ?? ''));
        return $v !== '' ? t($v) : '';
    }

    /** Название области на языке страницы */
    public static function regionName(?string $code): string
    {
        $code = (string) $code;
        return isset(self::REGIONS[$code]) ? t(self::REGIONS[$code]) : $code;
    }

    /** Номер заказа для покупателя: #1003635 */
    public static function number(int $id): string
    {
        $n = Seo::tpl((string) Settings::get('order_format', '#100{$order.id}'), ['order' => ['id' => $id]]);
        return $n !== '' ? $n : '#' . $id;
    }

    /** 380931234567 → +38 (093) 123-45-67 */
    public static function formatPhone(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if (preg_match('/^38(0\d{2})(\d{3})(\d{2})(\d{2})$/', $d, $m)) return "+38 ($m[1]) $m[2]-$m[3]-$m[4]";
        return $d !== '' ? '+' . $d : '';
    }

    /** Приём промокодов при оформлении (раздел «Промокоды» в админке) */
    public static function couponsEnabled(): bool
    {
        return class_exists(Coupons::class) && Coupons::enabled();
    }

    // ------------------------------------------------------------------ ссылка на заказ без входа

    /** Подпись ссылки на страницу заказа (без неё номер и состав не показываются — защита от перебора id) */
    public static function sign(int $id): string
    {
        return substr(hash_hmac('sha256', 'order:' . $id, self::signKey()), 0, 32);
    }

    /**
     * Секрет подписи: app_key из config.php. Если он не задан или остался из примера (CHANGE_ME, короче 32 символов),
     * подпись по известному ключу подделал бы кто угодно — тогда используется случайный ключ, созданный один раз
     * в storage/app.key (папка закрыта от веба). Раздел «Безопасность» админки всё равно просит задать свой app_key.
     */
    private static function signKey(): string
    {
        static $key = null;
        if ($key !== null) return $key;
        $cfg = (string) App::config('app_key', '');
        if ($cfg !== 'CHANGE_ME' && strlen($cfg) >= 32) return $key = $cfg;
        $f = STORAGE . '/app.key';
        $k = is_file($f) ? trim((string) @file_get_contents($f)) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $k)) {
            $k = bin2hex(random_bytes(32));
            if (@file_put_contents($f, $k, LOCK_EX) === false) Log::error('Orders: не удалось сохранить storage/app.key');
        }
        return $key = $k;
    }

    public static function checkSign(int $id, string $k): bool
    {
        return $id > 0 && preg_match('/^[a-f0-9]{32}$/', $k) === 1 && hash_equals(self::sign($id), $k);
    }

    /** /order/success/?id=…&k=… ($lang — добавить префикс языка, например для ссылки в письме) */
    public static function successUrl(int $id, ?string $lang = null): string
    {
        $path = '/order/success/?id=' . $id . '&k=' . self::sign($id);
        return $lang !== null ? Lang::path($path, $lang) : $path;
    }

    // ------------------------------------------------------------------ проверка формы

    /**
     * Проверка данных покупателя. $quick — «купить в 1 клик» (телефон обязателен, имя — по желанию).
     * @return array{0: array, 1: array} [чистые данные, ошибки [поле => текст]]
     */
    public static function validate(array $in, bool $quick = false): array
    {
        $s = static fn(string $k, int $max) => mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_scalar($in[$k] ?? null) ? (string) $in[$k] : '')), 0, $max);
        $text = static fn(string $k) => mb_substr(trim(str_replace("\r", '', is_scalar($in[$k] ?? null) ? (string) $in[$k] : '')), 0, 2000);
        $e = [];
        $d = ['name' => $s('name', 190), 'email' => '', 'city' => '', 'region' => '', 'address' => '', 'shipping' => '', 'payment' => '', 'comment' => ''];

        if ($d['name'] === '') { if (!$quick) $e['name'] = t('Укажите имя'); }
        elseif (mb_strlen($d['name']) < 2) $e['name'] = t('Имя слишком короткое');
        elseif (mb_strlen($d['name']) > 100) $e['name'] = t('Имя слишком длинное');

        $rawPhone = $s('phone', 40);
        $d['phone'] = Str::phone($rawPhone);
        if (preg_match('/^80\d{9}$/', $d['phone'])) $d['phone'] = '3' . $d['phone'];   // старая запись «8 093 …»
        if ($rawPhone === '') $e['phone'] = t('Укажите телефон');
        elseif ($d['phone'] === '' || (str_starts_with($d['phone'], '380') && !preg_match('/^380[1-9]\d{8}$/', $d['phone']))) {
            $e['phone'] = t('Проверьте номер телефона: +38 (0XX) XXX-XX-XX');
        }

        $rawEmail = $s('email', 190);
        if ($rawEmail !== '') {
            $d['email'] = Str::email($rawEmail);
            if ($d['email'] === '') $e['email'] = t('Проверьте e-mail — например, name@gmail.com');
        }
        if ($quick) {
            $d['comment'] = $text('comment');
            return [$d, $e];
        }

        $ship = self::shippingMethods();
        $d['shipping'] = $s('shipping', 64);
        $m = $ship[$d['shipping']] ?? null;
        if (!$m) $e['shipping'] = t('Выберите способ доставки');

        $d['payment'] = $s('payment', 64);
        if (!isset(self::paymentMethods()[$d['payment']])) $e['payment'] = t('Выберите способ оплаты');

        $pickup = $m && $m['pickup'];
        $region = $s('region', 8);
        $d['region'] = !$pickup && isset(self::REGIONS[$region]) ? $region : '';
        $d['city'] = $pickup ? '' : $s('city', 100);
        if (!$pickup && $m && $d['city'] === '') $e['city'] = t('Укажите город или населённый пункт');

        $d['address'] = $s('address', 255);
        if ($m && $m['required'] && $d['address'] === '') {
            $e['address'] = $m['kind'] === 'post' ? t('Укажите адрес доставки') : t('Укажите номер отделения или склада');
        }

        $d['comment'] = $text('comment');
        if (empty($in['agree'])) $e['agree'] = t('Подтвердите согласие с условиями, чтобы оформить заказ');
        return [$d, $e];
    }

    // ------------------------------------------------------------------ создание заказа

    /**
     * Создать заказ (одна транзакция): клиент (найти по телефону/e-mail или создать без пароля),
     * orders, order_items, order_log, промокод (coupon_usages), customers.orders_count. Письма уходят после ответа браузеру.
     *
     * @param array  $d      данные из validate()
     * @param array  $lines  [['p' => карточка Products::cards(), 'boxes' => N], …]
     * @param string $source site | quickorder
     * @param array  $params дополнительные параметры для orders.params
     * @param ?array $coupon ['coupon' => строка промокода, 'discount' => грн] из Coupons::check()
     * @return array ['id', 'number', 'total', 'discount', 'boxes', 'pairs', 'customer_id']
     * @throws \InvalidArgumentException промокод перестал действовать (текст — для покупателя)
     */
    public static function create(array $d, array $lines, string $source = 'site', array $params = [], ?array $coupon = null): array
    {
        if (!$lines) throw new \InvalidArgumentException(t('Корзина пуста'));
        $db = App::db();
        $ship = $d['shipping'] !== '' ? (self::shippingMethods()[$d['shipping']] ?? null) : null;
        $pay = $d['payment'] !== '' ? (self::paymentMethods()[$d['payment']] ?? null) : null;

        // в заказ — русские названия (pairs() не подставляет *_uk), на /ua/ карточки уже переведены
        [$ph, $vals] = $db->in(array_map(static fn($l) => (int) $l['p']['id'], $lines));
        $ruNames = $db->pairs("SELECT id, name FROM products WHERE id IN ($ph)", $vals);

        $items = [];
        $boxes = 0; $pairs = 0; $subtotal = 0.0;
        foreach ($lines as $l) {
            $p = $l['p'];
            $b = (int) $l['boxes'];
            $bq = max(1, (int) $p['box_qty']);
            $price = round((float) $p['price'], 2);
            $items[] = [
                'product_id' => (int) $p['id'], 'name' => mb_substr((string) ($ruNames[$p['id']] ?? $p['name']), 0, 255),
                'sku' => mb_substr((string) ($p['sku'] ?? ''), 0, 255), 'price' => $price, 'quantity' => $b * $bq, 'box_qty' => $bq,
            ];
            $boxes += $b;
            $pairs += $b * $bq;
            $subtotal += $price * $b * $bq;
        }
        $subtotal = round($subtotal, 2);
        $discount = $coupon && !empty($coupon['coupon']) ? round(min($subtotal, max(0, (float) ($coupon['discount'] ?? 0))), 2) : 0.0;
        $total = round($subtotal - $discount, 2);

        $address = $d['address'];
        if ($ship && $ship['pickup'] && $address !== '') $address = 'Когда заберут: ' . $address;
        $params = array_filter($params + self::trackingParams() + [
            'storefront'  => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'region_name' => self::REGIONS[$d['region']] ?? '',
            'pickup'      => $ship && $ship['pickup'] ? 1 : '',
            'coupon_id'   => $discount > 0 ? (int) $coupon['coupon']['id'] : '',
            'coupon_code' => $discount > 0 ? (string) $coupon['coupon']['code'] : '',
        ], static fn($v) => $v !== '' && $v !== null);

        $now = date('Y-m-d H:i:s');
        $order = $db->transaction(static function (DB $db) use ($d, $items, $boxes, $pairs, $subtotal, $discount, $total, $ship, $pay, $address, $source, $params, $now, $coupon) {
            $customerId = self::resolveCustomer($d);
            $id = $db->insert('orders', [
                'customer_id' => $customerId, 'status' => 'new',
                'total' => $total, 'subtotal' => $subtotal, 'shipping_cost' => 0, 'discount' => $discount, 'currency' => 'UAH',
                'boxes' => $boxes, 'pairs' => $pairs,
                'name' => $d['name'], 'phone' => $d['phone'], 'email' => $d['email'] !== '' ? $d['email'] : null,
                'shipping_method' => $ship['code'] ?? null, 'shipping_name' => $ship['name_ru'] ?? null,
                'city' => $d['city'] !== '' ? $d['city'] : null, 'region' => $d['region'] !== '' ? $d['region'] : null,
                'address' => $address !== '' ? $address : null,
                'payment_method' => $pay['code'] ?? null, 'payment_name' => $pay['name_ru'] ?? null,
                'comment' => $d['comment'] !== '' ? $d['comment'] : null,
                'source' => $source, 'ip' => Request::ip(), 'lang' => Lang::current(),
                'params' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($items as &$it) $it = ['order_id' => $id] + $it;
            unset($it);
            $db->insertMany('order_items', $items);
            if ($discount > 0) {
                // под блокировкой промокода: лимиты могли закончиться, пока покупатель заполнял форму
                $a = Coupons::apply($id, $coupon['coupon'], $discount, $customerId, $d['phone']);
                if (!$a['ok']) throw new \InvalidArgumentException(t((string) $a['error']));
            }
            $log = [['order_id' => $id, 'user_id' => $customerId, 'status_from' => null, 'status_to' => 'new',
                'text' => $source === 'quickorder' ? 'Заказ оформлен через «Купить в 1 клик»' : 'Заказ оформлен на сайте', 'created_at' => $now]];
            if ($discount > 0) {
                $log[] = ['order_id' => $id, 'user_id' => $customerId, 'status_from' => 'new', 'status_to' => 'new',
                    'text' => 'Промокод ' . $coupon['coupon']['code'] . ': скидка ' . price_format($discount), 'created_at' => $now];
            }
            $db->insertMany('order_log', $log);
            // только счётчик заказов: сумму покупок (total_spent) считают по оплаченным/выполненным заказам
            if ($customerId) $db->query('UPDATE customers SET orders_count = orders_count + 1 WHERE id = ?', [$customerId]);
            return ['id' => $id, 'customer_id' => $customerId];
        });

        $order += ['number' => self::number($order['id']), 'total' => $total, 'discount' => $discount, 'boxes' => $boxes, 'pairs' => $pairs];
        self::notifyLater($order['id']);
        return $order;
    }

    /** Клиент заказа: вошедший; иначе найденный по телефону, затем по e-mail; иначе новый (без пароля) */
    private static function resolveCustomer(array $d): ?int
    {
        $db = App::db();
        $u = Auth::user();
        if ($u) {
            $upd = [];
            if (empty($u['phone']) && $d['phone'] !== '') $upd['phone'] = $d['phone'];
            if (empty($u['city']) && $d['city'] !== '') $upd['city'] = $d['city'];
            if ($upd) $db->update('customers', $upd, 'id = ?', [$u['id']]);
            return (int) $u['id'];
        }
        // Гость — только к записи БЕЗ пароля (контакт из прошлых гостевых заказов). Телефон и e-mail при регистрации
        // не подтверждаются: иначе можно зарегистрироваться с чужим номером/адресом и видеть в «Моих заказах»
        // заказы, которые человек оформит без входа. Заказ гостя с номером зарегистрированного клиента — новый контакт
        // (так было и в Webasyst); в кабинете видны заказы, оформленные после входа.
        $guest = "(password IS NULL OR password = '')";
        $c = $db->row("SELECT id, name, email, city FROM customers WHERE phone = ? AND $guest ORDER BY status DESC, id LIMIT 1", [$d['phone']]);
        if (!$c && $d['email'] !== '') {
            // основной e-mail или дополнительный (customer_emails — адреса, перенесённые из Webasyst)
            $c = $db->row("SELECT id, name, email, city FROM customers WHERE email = ? AND $guest LIMIT 1", [$d['email']])
                ?? $db->row("SELECT c.id, c.name, c.email, c.city FROM customer_emails e JOIN customers c ON c.id = e.customer_id
                    WHERE e.email = ? AND (c.password IS NULL OR c.password = '') ORDER BY c.status DESC, c.id LIMIT 1", [$d['email']]);
        }
        if ($c) {
            // гость не подтвердил, что это его контакт: e-mail клиенту НЕ дописываем — иначе любой, зная чужой телефон,
            // привязал бы свой адрес и через «Забыли пароль?» вошёл бы в чужой кабинет. E-mail остаётся в самом заказе.
            $upd = [];
            if (trim((string) $c['name']) === '' && $d['name'] !== '') $upd['name'] = $d['name'];
            if (empty($c['city']) && $d['city'] !== '') $upd['city'] = $d['city'];
            if ($upd) $db->update('customers', $upd, 'id = ?', [$c['id']]);
            return (int) $c['id'];
        }
        $row = ['name' => $d['name'], 'phone' => $d['phone'], 'email' => $d['email'] !== '' ? $d['email'] : null,
            'city' => $d['city'] !== '' ? $d['city'] : null, 'password' => null, 'role' => 'customer', 'status' => 1,
            'lang' => Lang::current(), 'created_at' => date('Y-m-d H:i:s')];
        try {
            return $db->insert('customers', $row);
        } catch (\PDOException $e) {
            if ($row['email'] === null || $e->getCode() !== '23000') throw $e;
            $row['email'] = null;   // e-mail заняли параллельно — создаём без него
            return $db->insert('customers', $row);
        }
    }

    /** UTM-метки и точка входа из cookie (если их сохранил сайт): utm (JSON или query-строка), utm_*, gclid, fbclid, landing, referer */
    private static function trackingParams(): array
    {
        $out = [];
        $raw = $_COOKIE['utm'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $v = json_decode($raw, true);
            if (!is_array($v)) parse_str($raw, $v);
            foreach ((array) $v as $k => $val) if (is_string($k) && is_scalar($val)) $out[$k] = $val;
        }
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'landing', 'referer'] as $k) {
            if (isset($_COOKIE[$k]) && is_string($_COOKIE[$k]) && $_COOKIE[$k] !== '') $out[$k] = $_COOKIE[$k];
        }
        $clean = [];
        foreach ($out as $k => $v) {
            if (preg_match('/^(utm_(source|medium|campaign|term|content)|gclid|fbclid|landing|referer)$/', (string) $k)) {
                $clean[$k] = mb_substr(trim((string) $v), 0, 255);
            }
        }
        return array_filter($clean, static fn($v) => $v !== '');
    }

    // ------------------------------------------------------------------ чтение

    /**
     * Заказ с позициями (страница «заказ оформлен», письма) на текущем языке:
     * на /ua/ названия товаров — из карточек (name_uk), способы доставки/оплаты и область — переведены.
     */
    public static function find(int $id): ?array
    {
        $db = App::db();
        $o = $db->row('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) return null;
        $items = $db->all('SELECT id, product_id, name, sku, price, quantity, box_qty FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
        $cards = [];
        foreach (Products::cards(array_filter(array_column($items, 'product_id'))) as $p) $cards[$p['id']] = $p;
        foreach ($items as &$it) {
            $p = $cards[(int) $it['product_id']] ?? null;
            $it['box_qty'] = max(1, (int) $it['box_qty']);
            $it['quantity'] = (int) $it['quantity'];
            $it['price'] = (float) $it['price'];
            $it['boxes'] = (int) ceil($it['quantity'] / $it['box_qty']);
            $it['sum'] = round($it['price'] * $it['quantity'], 2);
            $it['box_price'] = $it['price'] * $it['box_qty'];
            if ($p && Lang::isUk()) $it['name'] = $p['name'];
            $it['link'] = $p['link'] ?? '';
            $it['img'] = $p['img_small'] ?? '/assets/img/no-photo.svg';
            $it['size'] = $p['size'] ?? '';
            $it['brand'] = $p['brand'] ?? '';
        }
        unset($it);
        $o['items'] = $items;
        $o['number'] = self::number((int) $o['id']);
        $o['params'] = json_decode((string) $o['params'], true) ?: [];
        $o['region_name'] = $o['region'] ? self::regionName($o['region']) : '';
        $o['shipping_title'] = (string) (self::shippingMethods()[(string) $o['shipping_method']]['name'] ?? $o['shipping_name'] ?? '');
        $o['payment_title'] = (string) (self::paymentMethods()[(string) $o['payment_method']]['name'] ?? $o['payment_name'] ?? '');
        $o['discount'] = (float) $o['discount'];
        $o['coupon_code'] = (string) ($o['params']['coupon_code'] ?? '');
        $o['lang'] = isset(Lang::LANGS[(string) ($o['lang'] ?? '')]) ? (string) $o['lang'] : Lang::DEFAULT;
        return $o;
    }

    // ------------------------------------------------------------------ уведомления

    /** Отправить письма после ответа браузеру (покупатель не ждёт SMTP) */
    private static function notifyLater(int $id): void
    {
        register_shutdown_function(static function () use ($id) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            @ignore_user_abort(true);
            self::notify($id);
        });
    }

    /**
     * Письма администратору (на русском) и покупателю (на языке заказа, если указан e-mail).
     * Ошибки почты не ломают заказ — пишутся в лог и в историю заказа.
     */
    public static function notify(int $id): void
    {
        $prevLang = Lang::current();
        $prevLoc = DB::$localize;
        try {
            $log = [];
            self::useLang('ru');
            $o = self::find($id);
            if (!$o) return;
            $admin = trim(Mailer::adminEmail());
            if ($admin !== '') {
                $subject = 'Новый заказ ' . $o['number'];
                $html = self::email('emails/order-admin', $subject, ['order' => $o, 'adminUrl' => url('/admin/orders/' . $id . '/')]);
                $log[] = self::deliver($admin, $subject, $html, $id, 'admin', 'Уведомление «Новый заказ» администратору');
            }
            // WhatsApp владельцу/менеджеру (номера и шлюз — «Настройки → WhatsApp» в админке)
            $log[] = WhatsApp::notifyOrder($o);
            if (!empty($o['email'])) {
                self::useLang($o['lang']);
                $oc = $o['lang'] === 'ru' ? $o : self::find($id);
                $subject = t('Заказ {n} оформлен', ['n' => $oc['number']]);
                $html = self::email('emails/order-customer', $subject, ['order' => $oc, 'orderUrl' => url(self::successUrl($id, $oc['lang']))]);
                $log[] = self::deliver((string) $oc['email'], $subject, $html, $id, 'customer', 'Уведомление «Заказ оформлен» покупателю');
            }
            $rows = [];
            foreach (array_filter($log) as $text) $rows[] = ['order_id' => $id, 'user_id' => null, 'status_from' => 'new', 'status_to' => 'new', 'text' => $text, 'created_at' => date('Y-m-d H:i:s')];
            if ($rows) App::db()->insertMany('order_log', $rows);
        } catch (\Throwable $e) {
            Log::error('Письма по заказу #' . $id . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            Lang::set($prevLang);
            DB::$localize = $prevLoc;
        }
    }

    /** Переключить язык на время подготовки письма (тексты t(), названия *_uk из БД) */
    private static function useLang(string $lang): void
    {
        Lang::set($lang);
        DB::$localize = Lang::isUk();
    }

    private static function email(string $template, string $title, array $data): string
    {
        $content = View::render($template, $data, null);
        return View::render('emails/layout', $data + ['content' => $content, 'title' => $title], null);
    }

    /**
     * Отправка письма. На локальной машине (env=dev) письма не уходят реальным адресатам,
     * а сохраняются в storage/logs/mail/ (включить отправку: mail.dev_send = true).
     */
    private static function deliver(string $to, string $subject, string $html, int $orderId, string $kind, string $what): string
    {
        if (App::config('env') === 'dev' && !App::config('mail.dev_send', false)) {
            $dir = STORAGE . '/logs/mail';
            @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/' . date('Ymd-His') . '-order' . $orderId . '-' . $kind . '.html', $html);
            return $what . ' сохранено в storage/logs/mail (локальная разработка, адресат ' . $to . ')';
        }
        if (Mailer::send($to, $subject, $html)) return $what . ' отправлено ' . $to;
        Log::error('Не удалось отправить письмо по заказу #' . $orderId . ' на ' . $to . ': ' . Mailer::$lastError);
        return $what . ': ошибка отправки на ' . $to;
    }
}
