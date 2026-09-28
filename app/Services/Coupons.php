<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\DB;
use App\Core\Settings;
use App\Core\Str;

/**
 * Промокоды (таблицы coupons, coupon_usages — database/migrations/coupons.sql).
 * Все правила — только здесь, чтобы корзина, оформление и админка считали скидку одинаково.
 *
 * Корзина / оформление заказа:
 *   $c = Coupons::find($code);                                      // null — такого кода нет
 *   $r = Coupons::validate($c, Cart::items(), Cart::total(), Auth::id() ?: null, $phone);
 *   // $r = ['ok' => bool, 'discount' => грн, 'error' => текст для покупателя, 'eligible' => сумма товаров под скидку, 'title' => '−10%']
 *   или короче: $r = Coupons::check($code, Cart::items(), Cart::total(), $customerId, $phone);   // + 'coupon' => строка
 *
 * После создания заказа (лучше внутри той же транзакции, что и INSERT заказа):
 *   $a = Coupons::apply($orderId, $c, $r['discount'], $customerId, $phone);   // ['ok', 'error']
 *   // !ok — промокод успел закончиться (лимит/срок): откатить заказ или оформить без скидки.
 * Отмена/удаление заказа, если скидку нужно вернуть в лимит: Coupons::release($orderId).
 * Промокод заказа для страницы заказа: Coupons::forOrder($orderId) → ['coupon_id', 'code', 'discount'] | null.
 * Тексты ошибок — для покупателя, через t() (украинские — lang/uk/coupons.php).
 */
final class Coupons
{
    public const TYPES = ['percent' => 'Процент от суммы', 'fixed' => 'Фиксированная сумма, грн'];
    public const STATES = [
        'active'    => 'Действует',
        'scheduled' => 'Запланирован',
        'expired'   => 'Истёк',
        'exhausted' => 'Исчерпан',
        'off'       => 'Выключен',
    ];
    public const CODE_MAX = 32;
    public const LIST_MAX = 300;   // элементов в одном ограничении (категорий/брендов/товаров)

    /** Символы генератора: без похожих 0/O, 1/I/L */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    /** Кириллица, похожая на латиницу (покупатель набрал код в русской/украинской раскладке «на глаз») */
    private const LOOKALIKE = ['А' => 'A', 'В' => 'B', 'Е' => 'E', 'Є' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P',
        'С' => 'C', 'Т' => 'T', 'Х' => 'X', 'І' => 'I', 'У' => 'Y', 'З' => '3'];

    private static ?array $catParents = null;

    // ================================================================== чтение

    /** Приём промокодов на сайте включён (настройка coupons_enabled, по умолчанию — да) */
    public static function enabled(): bool
    {
        return (string) Settings::get('coupons_enabled', '1') !== '0';
    }

    /** Код к виду, в котором он хранится: « sale 10 » → SALE10; '' — если в коде недопустимые символы */
    public static function normalize(string $code): string
    {
        $code = mb_strtoupper(preg_replace('/\s+/u', '', $code) ?? '');
        $code = strtr($code, self::LOOKALIKE);
        return preg_match('/^[A-Z0-9_-]{1,' . self::CODE_MAX . '}$/', $code) ? $code : '';
    }

    /** Промокод по коду (без учёта регистра и пробелов) или null */
    public static function find(string $code): ?array
    {
        $code = self::normalize($code);
        if ($code === '') return null;
        $row = App::db()->row('SELECT * FROM coupons WHERE code = ?', [$code]);
        return $row ? self::hydrate($row) : null;
    }

    public static function get(int $id): ?array
    {
        if ($id <= 0) return null;
        $row = App::db()->row('SELECT * FROM coupons WHERE id = ?', [$id]);
        return $row ? self::hydrate($row) : null;
    }

    /** Строка из БД → типизированный массив (списки ограничений — массивы int) */
    public static function hydrate(array $c): array
    {
        foreach (['id', 'min_boxes', 'usage_limit', 'used', 'per_customer_limit', 'status'] as $k) $c[$k] = (int) ($c[$k] ?? 0);
        foreach (['value', 'min_sum', 'max_discount'] as $k) $c[$k] = round((float) ($c[$k] ?? 0), 2);
        foreach (['category_ids', 'brand_ids', 'product_ids'] as $k) $c[$k] = self::ids($c[$k] ?? null);
        $c['type'] = ($c['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $c['code'] = (string) ($c['code'] ?? '');
        foreach (['starts_at', 'expires_at', 'comment'] as $k) $c[$k] = isset($c[$k]) && $c[$k] !== '' ? (string) $c[$k] : null;
        return $c;
    }

    /** JSON-список id (или массив) → уникальные положительные int */
    public static function ids($v): array
    {
        if (is_string($v)) $v = json_decode($v, true);
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $x) {
            if (is_numeric($x) && (int) $x > 0) $out[(int) $x] = (int) $x;
        }
        return array_slice(array_values($out), 0, self::LIST_MAX);
    }

    /** Состояние: active | scheduled | expired | exhausted | off */
    public static function state(array $c, ?int $now = null): string
    {
        $now ??= time();
        if (!(int) $c['status']) return 'off';
        if (!empty($c['expires_at']) && strtotime((string) $c['expires_at']) < $now) return 'expired';
        if ((int) $c['usage_limit'] > 0 && (int) $c['used'] >= (int) $c['usage_limit']) return 'exhausted';
        if (!empty($c['starts_at']) && strtotime((string) $c['starts_at']) > $now) return 'scheduled';
        return 'active';
    }

    /** Коротко о скидке: «−10%», «−500 грн.» */
    public static function label(array $c): string
    {
        if (($c['type'] ?? 'percent') === 'percent') {
            return '−' . rtrim(rtrim(number_format((float) $c['value'], 2, '.', ''), '0'), '.') . '%';
        }
        return '−' . price_format((float) $c['value']);
    }

    // ================================================================== проверка корзины

    /** find() + validate() одним вызовом; в ответе ещё 'coupon' (строка промокода или null) */
    public static function check(string $code, array $cartItems, float $subtotal, ?int $customerId = null, ?string $phone = null): array
    {
        if (trim($code) === '') return self::fail(t('Введите промокод')) + ['coupon' => null];
        $c = self::find($code);
        if (!$c) return self::fail(t('Такого промокода нет — проверьте, правильно ли он введён')) + ['coupon' => null];
        return self::validate($c, $cartItems, $subtotal, $customerId, $phone) + ['coupon' => $c];
    }

    /**
     * Действует ли промокод на корзину и какая скидка.
     *
     * @param array      $coupon     строка из find()/get()
     * @param array      $cartItems  позиции корзины: Cart::items() (карточки Products::cards + boxes, sum, available)
     *                               или любые ['id', 'price', 'box_qty', 'boxes', 'brand_id', 'category_id'(, 'sum', 'available')]
     * @param float      $subtotal   сумма товаров корзины, грн (Cart::total())
     * @param int|null   $customerId вошедший клиент (для лимита «на клиента»)
     * @param string|null $phone     телефон покупателя, если уже известен (для лимита «на клиента»)
     * @return array{ok: bool, discount: float, error: string, eligible: float, title: string}
     */
    public static function validate(array $coupon, array $cartItems, float $subtotal, ?int $customerId = null, ?string $phone = null): array
    {
        if (!self::enabled()) return self::fail(t('Промокоды сейчас не принимаются'));
        $c = self::hydrate($coupon);
        $state = self::state($c);
        if ($state !== 'active') return self::fail(self::stateError($c, $state));

        if ($c['per_customer_limit'] > 0 && self::usedBy($c['id'], $customerId, $phone) >= $c['per_customer_limit']) {
            return self::fail($c['per_customer_limit'] === 1 ? t('Вы уже использовали этот промокод') : t('Вы уже использовали этот промокод максимальное число раз'));
        }

        // позиции в наличии
        $lines = [];
        $boxes = 0;
        foreach ($cartItems as $i) {
            if (!is_array($i) || (isset($i['available']) && !$i['available'])) continue;
            $b = (int) ($i['boxes'] ?? 0);
            $id = (int) ($i['id'] ?? $i['product_id'] ?? 0);
            if ($b <= 0 || $id <= 0) continue;
            $sum = isset($i['sum']) ? (float) $i['sum'] : (float) ($i['price'] ?? 0) * max(1, (int) ($i['box_qty'] ?? 1)) * $b;
            $lines[] = ['id' => $id, 'brand_id' => (int) ($i['brand_id'] ?? 0), 'category_id' => (int) ($i['category_id'] ?? 0), 'sum' => $sum];
            $boxes += $b;
        }
        if (!$lines) return self::fail(t('Корзина пуста'));

        if ($c['min_sum'] > 0 && $subtotal + 0.001 < $c['min_sum']) {
            return self::fail(t('Промокод действует для заказа от {sum} — добавьте товаров ещё на {left}',
                ['sum' => price_format($c['min_sum']), 'left' => price_format(ceil($c['min_sum'] - $subtotal))]));
        }
        if ($c['min_boxes'] > 0 && $boxes < $c['min_boxes']) {
            return self::fail(t('Промокод действует для заказа от {n} ящ. — в корзине {have} ящ.', ['n' => $c['min_boxes'], 'have' => $boxes]));
        }

        $ok = self::eligibleIds($c, $lines);
        $eligible = 0.0;
        foreach ($lines as $l) if (isset($ok[$l['id']])) $eligible += $l['sum'];
        $eligible = round($eligible, 2);
        if ($eligible <= 0) return self::fail(t('Промокод не действует на товары в вашей корзине'));

        $discount = $c['type'] === 'percent' ? $eligible * min(100, max(0, $c['value'])) / 100 : $c['value'];
        if ($c['type'] === 'percent' && $c['max_discount'] > 0) $discount = min($discount, $c['max_discount']);
        // целые гривны: цены и итоги на сайте, в письмах и в админке показываются без копеек (price_format) —
        // 7% от 2 160 = 151,20 дало бы «скидка 151 грн., итого 2 009 грн.» при 2 008,80 в заказе
        $cap = min($eligible, $subtotal);
        $discount = round(max(0, min($discount, $cap)));
        if ($discount > $cap) $discount = floor($cap);
        $discount = (float) $discount;
        if ($discount <= 0) return self::fail(t('Промокод не даёт скидки на этот заказ'));

        return ['ok' => true, 'discount' => $discount, 'error' => '', 'eligible' => $eligible, 'title' => self::label($c)];
    }

    /** Текст для покупателя, почему промокод сейчас не действует */
    public static function stateError(array $c, string $state): string
    {
        return match ($state) {
            'off'       => t('Промокод не действует'),
            'expired'   => t('Срок действия промокода истёк'),
            'exhausted' => t('Промокод уже использован максимальное число раз'),
            'scheduled' => t('Промокод начнёт действовать {date}', ['date' => date('d.m.Y', strtotime((string) $c['starts_at']))]),
            default     => '',
        };
    }

    /**
     * Какие товары корзины попадают под скидку: [product_id => true].
     * Нет ограничений — все. Иначе: товар из списка «товары» — всегда; прочие — если подходят
     * под ВСЕ заданные условия «категории» (с подкатегориями и динамическими) и «бренды».
     */
    public static function eligibleIds(array $c, array $lines): array
    {
        $out = [];
        $cats = $c['category_ids'];
        $brands = array_flip($c['brand_ids']);
        $products = array_flip($c['product_ids']);
        if (!$cats && !$brands && !$products) {
            foreach ($lines as $l) $out[$l['id']] = true;
            return $out;
        }
        $inCats = [];
        if ($cats) {
            $tree = self::withChildren($cats);
            $check = [];
            foreach ($lines as $l) {
                if (isset($tree[$l['category_id']])) $inCats[$l['id']] = true; else $check[] = $l['id'];
            }
            if ($check) {
                $db = App::db();
                [$pph, $pv] = $db->in(array_values(array_unique($check)));
                [$cph, $cv] = $db->in(array_keys($tree));
                [$sph, $sv] = $db->in($cats);
                // прямые связи товар↔категория (включая подкатегории) + индекс витрины (динамические категории)
                foreach ($db->col("SELECT product_id FROM category_products WHERE product_id IN ($pph) AND category_id IN ($cph)
                    UNION SELECT product_id FROM catalog_index WHERE product_id IN ($pph) AND category_id IN ($sph)",
                    array_merge($pv, $cv, $pv, $sv)) as $pid) {
                    $inCats[(int) $pid] = true;
                }
            }
        }
        foreach ($lines as $l) {
            if (isset($products[$l['id']])) { $out[$l['id']] = true; continue; }
            if (!$cats && !$brands) continue;                         // ограничено только списком товаров
            if ($cats && !isset($inCats[$l['id']])) continue;
            if ($brands && !isset($brands[$l['brand_id']])) continue;
            $out[$l['id']] = true;
        }
        return $out;
    }

    /** Категории вместе со всеми подкатегориями: [id => true] */
    private static function withChildren(array $ids): array
    {
        if (self::$catParents === null) {
            self::$catParents = [];
            foreach (App::db()->pairs('SELECT id, parent_id FROM categories') as $id => $parent) self::$catParents[(int) $parent][] = (int) $id;
        }
        $out = [];
        $stack = array_values($ids);
        while ($stack) {
            $id = (int) array_pop($stack);
            if (isset($out[$id])) continue;
            $out[$id] = true;
            foreach (self::$catParents[$id] ?? [] as $child) $stack[] = $child;
        }
        return $out;
    }

    /** Сколько раз промокод применил клиент (по id клиента или телефону) */
    public static function usedBy(int $couponId, ?int $customerId, ?string $phone): int
    {
        $phone = self::phoneKey($phone);
        $customerId = $customerId && $customerId > 0 ? $customerId : null;
        if (!$customerId && $phone === null) return 0;
        $where = []; $params = [$couponId];
        if ($customerId) { $where[] = 'customer_id = ?'; $params[] = $customerId; }
        if ($phone !== null) { $where[] = 'phone = ?'; $params[] = $phone; }
        return (int) App::db()->value('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND (' . implode(' OR ', $where) . ')', $params);
    }

    /** Телефон в едином виде 380XXXXXXXXX (как в заказах) или null */
    public static function phoneKey(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') return null;
        $p = Str::phone($phone);
        if ($p === '') $p = substr((string) preg_replace('/\D+/', '', $phone), 0, 20);
        return $p !== '' ? $p : null;
    }

    // ================================================================== применение к заказу

    /**
     * Записать применение промокода к заказу: coupon_usages + used++.
     * Под блокировкой строки промокода заново проверяются срок, общий лимит и лимит на клиента —
     * два одновременных заказа не превысят usage_limit. Повторный вызов для того же заказа ничего не меняет.
     * Если транзакция уже открыта (оформление заказа) — работает внутри неё, иначе открывает свою.
     *
     * @param int       $orderId
     * @param array|int $coupon   строка промокода или его id
     * @param float     $discount скидка заказа по этому промокоду, грн (из validate())
     * @return array{ok: bool, error: string}
     */
    public static function apply(int $orderId, array|int $coupon, float $discount, ?int $customerId = null, ?string $phone = null): array
    {
        $couponId = is_array($coupon) ? (int) ($coupon['id'] ?? 0) : $coupon;
        if ($orderId <= 0 || $couponId <= 0) return ['ok' => false, 'error' => t('Промокод не найден')];
        $phone = self::phoneKey($phone);
        $customerId = $customerId && $customerId > 0 ? $customerId : null;
        $discount = round(max(0, $discount), 2);

        $run = static function (DB $db) use ($orderId, $couponId, $discount, $customerId, $phone): array {
            $row = $db->row('SELECT * FROM coupons WHERE id = ? FOR UPDATE', [$couponId]);
            if (!$row) return ['ok' => false, 'error' => t('Промокод не найден')];
            $c = self::hydrate($row);
            $prev = $db->row('SELECT coupon_id FROM coupon_usages WHERE order_id = ?', [$orderId]);
            if ($prev) {
                return (int) $prev['coupon_id'] === $couponId ? ['ok' => true, 'error' => '', 'repeat' => true]
                    : ['ok' => false, 'error' => t('К заказу уже применён другой промокод')];
            }
            $state = self::state($c);
            if ($state !== 'active') return ['ok' => false, 'error' => self::stateError($c, $state)];
            if ($c['per_customer_limit'] > 0 && self::usedBy($couponId, $customerId, $phone) >= $c['per_customer_limit']) {
                return ['ok' => false, 'error' => t('Вы уже использовали этот промокод')];
            }
            // условие в UPDATE — вторая защита от превышения лимита
            $n = $db->query('UPDATE coupons SET used = used + 1 WHERE id = ? AND status = 1 AND (usage_limit = 0 OR used < usage_limit)', [$couponId])->rowCount();
            if ($n !== 1) return ['ok' => false, 'error' => t('Промокод уже использован максимальное число раз')];
            $db->insert('coupon_usages', ['coupon_id' => $couponId, 'code' => $c['code'], 'order_id' => $orderId,
                'customer_id' => $customerId, 'phone' => $phone, 'discount' => $discount, 'created_at' => date('Y-m-d H:i:s')]);
            return ['ok' => true, 'error' => ''];
        };

        $db = App::db();
        return $db->pdo()->inTransaction() ? $run($db) : $db->transaction($run);
    }

    /** Отменить применение (заказ удалён/отменён): запись удаляется, used−1. true — было что отменять */
    public static function release(int $orderId): bool
    {
        $run = static function (DB $db) use ($orderId): bool {
            $u = $db->row('SELECT id, coupon_id FROM coupon_usages WHERE order_id = ? FOR UPDATE', [$orderId]);
            if (!$u) return false;
            $db->delete('coupon_usages', 'id = ?', [(int) $u['id']]);
            if ((int) $u['coupon_id'] > 0) $db->query('UPDATE coupons SET used = IF(used > 0, used - 1, 0) WHERE id = ?', [(int) $u['coupon_id']]);
            return true;
        };
        $db = App::db();
        return $db->pdo()->inTransaction() ? $run($db) : $db->transaction($run);
    }

    /** Промокод, применённый к заказу: ['coupon_id', 'code', 'discount', 'created_at'] или null */
    public static function forOrder(int $orderId): ?array
    {
        $r = App::db()->row('SELECT coupon_id, code, discount, created_at FROM coupon_usages WHERE order_id = ?', [$orderId]);
        if (!$r) return null;
        return ['coupon_id' => (int) $r['coupon_id'], 'code' => (string) $r['code'], 'discount' => (float) $r['discount'], 'created_at' => $r['created_at']];
    }

    // ================================================================== служебное

    /** Новый свободный код: 8 символов без похожих букв/цифр, с необязательным префиксом (SALE-…) */
    public static function generate(int $length = 8, string $prefix = ''): string
    {
        $prefix = self::normalize($prefix);
        $length = max(4, min(16, $length));
        $db = App::db();
        for ($try = 0; $try < 20; $try++) {
            $s = '';
            for ($i = 0; $i < $length; $i++) $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            $code = substr($prefix . $s, 0, self::CODE_MAX);
            if (!$db->value('SELECT 1 FROM coupons WHERE code = ?', [$code])) return $code;
        }
        return substr($prefix . strtoupper(bin2hex(random_bytes(6))), 0, self::CODE_MAX);
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'discount' => 0.0, 'error' => $error, 'eligible' => 0.0, 'title' => ''];
    }
}
