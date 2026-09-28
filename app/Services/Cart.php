<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Auth;
use App\Core\Session;

/**
 * Серверная корзина: таблица cart_items, ключ — httponly-cookie cart_token (32 hex, 90 дней).
 * Количество хранится в ЯЩИКАХ; сумма позиции = цена пары × пар в ящике × ящиков.
 *
 * После каждого изменения ставится открытая cookie cart = "{ящиков}:{сумма_грн}" —
 * по ней app.js рисует счётчик и сумму в шапке кэшируемых страниц (без запроса к серверу).
 *
 * Ошибки (товара нет, лимиты) — \InvalidArgumentException с понятным покупателю текстом (на языке страницы).
 */
final class Cart
{
    public const MAX_BOXES = 999;   // ящиков одной позиции
    public const MAX_LINES = 300;   // позиций в корзине
    private const TOKEN_COOKIE = 'cart_token';
    private const TTL = 86400 * 90;

    private static ?string $token = null;
    /** [product_id => ящиков] в порядке добавления */
    private static ?array $lines = null;
    private static ?array $items = null;
    /** Сколько позиций удалено при последней загрузке (товар снят с продажи) */
    public static int $removed = 0;

    // ------------------------------------------------------------------ чтение

    /** Токен корзины из cookie; $create — выдать новый, если нет */
    public static function token(bool $create = false): ?string
    {
        if (self::$token === null) {
            $t = $_COOKIE[self::TOKEN_COOKIE] ?? '';
            if (is_string($t) && preg_match('/^[a-f0-9]{32}$/', $t)) self::$token = $t;
        }
        if (self::$token === null && $create) {
            self::$token = bin2hex(random_bytes(16));
            self::touchToken();
        }
        return self::$token;
    }

    /** Сырые строки корзины: [product_id => ящиков] */
    public static function lines(): array
    {
        if (self::$lines === null) {
            $t = self::token();
            self::$lines = [];
            if ($t !== null) {
                foreach (App::db()->pairs('SELECT product_id, boxes FROM cart_items WHERE token = ? ORDER BY created_at, product_id', [$t]) as $pid => $b) {
                    self::$lines[(int) $pid] = (int) $b;
                }
            }
        }
        return self::$lines;
    }

    /**
     * Позиции корзины: карточки товаров (Products::cards — только опубликованные) +
     * boxes, pairs, sum, available (в наличии), max_boxes (ограничение остатка).
     * Позиции со снятыми с продажи товарами удаляются из корзины.
     */
    public static function items(): array
    {
        if (self::$items !== null) return self::$items;
        $lines = self::lines();
        if (!$lines) return self::$items = [];
        $items = [];
        foreach (Products::cards(array_keys($lines)) as $p) {
            $boxes = $lines[$p['id']];
            $p['boxes'] = $boxes;
            $p['max_boxes'] = self::maxBoxes($p);
            // остаток задан и меньше ящика — как «нет в наличии» (не войдёт в заказ; так же страница товара и повтор заказа)
            $p['available'] = (bool) $p['in_stock'] && $p['max_boxes'] > 0;
            $p['pairs'] = $p['box_qty'] * $boxes;
            $p['sum'] = $p['available'] ? round($p['box_price'] * $boxes, 2) : 0.0;
            $items[$p['id']] = $p;
        }
        $gone = array_values(array_diff(array_keys($lines), array_keys($items)));
        if ($gone) {
            [$ph, $vals] = App::db()->in($gone);
            App::db()->query("DELETE FROM cart_items WHERE token = ? AND product_id IN ($ph)", array_merge([self::token()], $vals));
            foreach ($gone as $id) unset(self::$lines[$id]);
            self::$removed = count($gone);
        }
        return self::$items = array_values($items);
    }

    /** Итоги по товарам в наличии: count (ящиков), pairs, total (грн), lines (позиций) */
    public static function summary(): array
    {
        $s = ['count' => 0, 'pairs' => 0, 'total' => 0.0, 'lines' => 0];
        foreach (self::items() as $i) {
            if (!$i['available']) continue;
            $s['count'] += $i['boxes'];
            $s['pairs'] += $i['pairs'];
            $s['total'] += $i['sum'];
            $s['lines']++;
        }
        $s['total'] = round($s['total'], 2);
        return $s;
    }

    /** Ящиков в корзине */
    public static function count(): int
    {
        return self::summary()['count'];
    }

    /** Пар в корзине */
    public static function pairs(): int
    {
        return self::summary()['pairs'];
    }

    /** Сумма корзины, грн: Σ price × box_qty × boxes */
    public static function total(): float
    {
        return self::summary()['total'];
    }

    /** Одна позиция корзины (после изменения — для ответа JSON) */
    public static function item(int $productId): ?array
    {
        foreach (self::items() as $i) if ($i['id'] === $productId) return $i;
        return null;
    }

    // ------------------------------------------------------------------ изменение

    public static function add(int $productId, int $boxes = 1): void
    {
        if ($boxes < 1) throw new \InvalidArgumentException(t('Укажите количество ящиков'));
        $p = self::product($productId);
        $lines = self::lines();
        $have = $lines[$productId] ?? 0;
        if (!$have && count($lines) >= self::MAX_LINES) {
            throw new \InvalidArgumentException(t('В корзине уже {n} позиций — это максимум для одного заказа. Оформите заказ или удалите лишнее.', ['n' => self::MAX_LINES]));
        }
        $new = $have + $boxes;
        if ($new > self::MAX_BOXES && $have) {
            throw new \InvalidArgumentException(t('В корзине уже {n} ящ. этого товара — больше {max} ящиков одной позиции заказать нельзя', ['n' => $have, 'max' => self::MAX_BOXES]));
        }
        self::checkQty($p, $new);
        self::write($productId, $new);
    }

    /** Установить количество ящиков; 0 и меньше — удалить позицию */
    public static function set(int $productId, int $boxes): void
    {
        if ($boxes <= 0) { self::remove($productId); return; }
        $p = self::product($productId);
        $lines = self::lines();
        if (!isset($lines[$productId]) && count($lines) >= self::MAX_LINES) {
            throw new \InvalidArgumentException(t('В корзине уже {n} позиций — это максимум для одного заказа. Оформите заказ или удалите лишнее.', ['n' => self::MAX_LINES]));
        }
        self::checkQty($p, $boxes);
        self::write($productId, $boxes);
    }

    public static function remove(int $productId): void
    {
        $t = self::token();
        if ($t !== null) App::db()->delete('cart_items', 'token = ? AND product_id = ?', [$t, $productId]);
        self::changed();
    }

    public static function clear(): void
    {
        $t = self::token();
        if ($t !== null) App::db()->delete('cart_items', 'token = ?', [$t]);
        self::changed();
    }

    /**
     * Вход покупателя (вызывает раздел кабинета после Auth::login): корзина гостя
     * сливается с корзиной клиента с других устройств (по товару — большее количество),
     * позиции привязываются к клиенту.
     */
    public static function onLogin(int $customerId): void
    {
        if ($customerId <= 0) return;
        $db = App::db();
        $token = self::token(true);
        // корзина на этом устройстве принадлежит другому клиенту (общий компьютер) — начинаем свою
        $foreign = (int) $db->value('SELECT COUNT(*) FROM cart_items WHERE token = ? AND customer_id IS NOT NULL AND customer_id <> ?', [$token, $customerId]);
        if ($foreign) {
            self::$token = null;
            self::$lines = null;
            $_COOKIE[self::TOKEN_COOKIE] = '';
            $token = self::token(true);
        }
        $other = $db->all('SELECT product_id, boxes, created_at FROM cart_items WHERE customer_id = ? AND token <> ? ORDER BY created_at, product_id', [$customerId, $token]);
        $db->transaction(static function ($db) use ($token, $customerId, $other) {
            if ($other) {
                $now = date('Y-m-d H:i:s');
                $rows = [];
                foreach ($db->all('SELECT product_id, boxes, created_at FROM cart_items WHERE token = ? ORDER BY created_at, product_id', [$token]) as $r) {
                    $rows[(int) $r['product_id']] = ['boxes' => (int) $r['boxes'], 'created_at' => $r['created_at']];
                }
                foreach ($other as $r) {
                    $pid = (int) $r['product_id'];
                    if (isset($rows[$pid])) $rows[$pid]['boxes'] = max($rows[$pid]['boxes'], (int) $r['boxes']);
                    else $rows[$pid] = ['boxes' => (int) $r['boxes'], 'created_at' => $r['created_at']];
                }
                $rows = array_slice($rows, 0, self::MAX_LINES, true);
                $db->query('DELETE FROM cart_items WHERE customer_id = ? AND token <> ?', [$customerId, $token]);
                $db->query('DELETE FROM cart_items WHERE token = ?', [$token]);
                $insert = [];
                foreach ($rows as $pid => $r) {
                    $insert[] = ['token' => $token, 'product_id' => $pid, 'boxes' => min(self::MAX_BOXES, max(1, $r['boxes'])),
                        'customer_id' => $customerId, 'created_at' => $r['created_at'], 'updated_at' => $now];
                }
                $db->insertMany('cart_items', $insert);
            } else {
                $db->query('UPDATE cart_items SET customer_id = ? WHERE token = ?', [$customerId, $token]);
            }
        });
        self::touchToken();
        self::changed();
    }

    /** Привести cookie cart ("{ящиков}:{сумма}") в соответствие с корзиной */
    public static function syncCookie(): void
    {
        $s = self::summary();
        $val = $s['count'] > 0 ? $s['count'] . ':' . (int) round($s['total']) : '';
        $cur = $_COOKIE['cart'] ?? '';
        if (!is_string($cur)) $cur = '';
        if ($cur === $val) return;
        if ($val === '') {
            self::cookie('cart', '', time() - 3600, false);
        } else {
            self::cookie('cart', $val, time() + self::TTL, false);
        }
    }

    // ------------------------------------------------------------------ проверки

    /** Товар для корзины/заказа: опубликован и в наличии, иначе понятная ошибка */
    public static function product(int $productId): array
    {
        $p = $productId > 0 ? App::db()->row('SELECT id, name, name_uk, status, in_stock, stock, box_qty FROM products WHERE id = ?', [$productId]) : null;
        if (!$p || (int) $p['status'] !== 1) throw new \InvalidArgumentException(t('Товар не найден или снят с продажи'));
        if (!(int) $p['in_stock']) throw new \InvalidArgumentException(t('Товара «{name}» сейчас нет в наличии', ['name' => $p['name']]));
        $p['box_qty'] = max(1, (int) $p['box_qty']);
        return $p;
    }

    /** Сколько ящиков можно заказать (остаток stock хранится в парах; NULL — без ограничения) */
    public static function maxBoxes(array $p): int
    {
        if (!isset($p['stock']) || $p['stock'] === null || $p['stock'] === '') return self::MAX_BOXES;
        $stock = (int) $p['stock'];
        if ($stock <= 0) return 0;
        return max(1, min(self::MAX_BOXES, intdiv($stock, max(1, (int) $p['box_qty']))));
    }

    public static function checkQty(array $p, int $boxes): void
    {
        if ($boxes < 1) throw new \InvalidArgumentException(t('Укажите количество ящиков'));
        if ($boxes > self::MAX_BOXES) throw new \InvalidArgumentException(t('Не больше {max} ящиков одной позиции', ['max' => self::MAX_BOXES]));
        $max = self::maxBoxes($p);
        if ($boxes > $max) {
            throw new \InvalidArgumentException(t('Товара «{name}» в наличии только {n} {boxes}', ['name' => $p['name'], 'n' => $max, 'boxes' => self::boxesWord($max)]));
        }
    }

    /** «ящик / ящика / ящиков» на языке страницы (для сообщений) */
    public static function boxesWord(int $n): string
    {
        return t(plural($n, 'ящик', 'ящика', 'ящиков'));
    }

    // ------------------------------------------------------------------ служебное

    private static function write(int $productId, int $boxes): void
    {
        $token = self::token(true);
        $uid = Auth::id();
        $db = App::db();
        $db->query('INSERT INTO cart_items (token, product_id, boxes, customer_id, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(6), NOW())
            ON DUPLICATE KEY UPDATE boxes = VALUES(boxes), customer_id = COALESCE(VALUES(customer_id), customer_id), updated_at = NOW()',
            [$token, $productId, $boxes, $uid ?: null]);
        // корзина «живая» целиком: bin/cron.php удаляет строки без изменений 60 дней
        if (array_diff_key(self::lines(), [$productId => 1])) $db->query('UPDATE cart_items SET updated_at = NOW() WHERE token = ?', [$token]);
        self::touchToken();
        self::changed();
    }

    /** Сбросить память запроса и обновить cookie cart */
    private static function changed(): void
    {
        self::$lines = null;
        self::$items = null;
        self::syncCookie();
    }

    /** Продлить httponly-cookie с токеном ещё на 90 дней */
    private static function touchToken(): void
    {
        if (self::$token !== null) self::cookie(self::TOKEN_COOKIE, self::$token, time() + self::TTL, true);
    }

    private static function cookie(string $name, string $value, int $expires, bool $httpOnly): void
    {
        if ($expires < time()) unset($_COOKIE[$name]); else $_COOKIE[$name] = $value;
        if (headers_sent() || PHP_SAPI === 'cli') return;
        setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => Session::https(), 'httponly' => $httpOnly, 'samesite' => 'Lax']);
    }
}
