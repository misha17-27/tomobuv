<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\DB;
use App\Core\Cache;
use App\Core\Settings;

/**
 * Отчёты о продажах (админка → «Отчёты»), как экран reports в админке ARG FLEX.
 *
 * Заказы периода читаются ОДНИМ запросом по индексу `created` (orders()), их позиции — одним запросом по индексу
 * order_items.order (rollup(): строка на товар). Всё остальное — статусы, график, покупатели, клиенты, доставка/оплата,
 * ящики, топы товаров, категории, бренды, виды обуви — считается из этих двух выборок в PHP.
 * Раньше это были GROUP BY в SQL, и «за всё время» (17 тыс. товаров) они упирались во временные таблицы:
 * MAX(i.name) по товару и группировка по TRIM() длинных строк уходили на диск. Деньги складываются в копейках (int) —
 * итог точно равен SUM() по DECIMAL. Результат по периоду кэшируется на 5 минут (Cache::remember, ключ — даты периода).
 *
 * Выручка — сумма заказов кроме удалённых и возвратов (они видны только в разбивке по статусам).
 * В order_items.quantity — пары; ящики позиции = ceil(пары / box_qty). У заказов со старого сайта
 * (source = webasyst) размер ящика не сохранился (box_qty = 1), а товары почти все удалены — ящики оцениваем:
 * до 12 пар — один ящик, больше — по 8 пар (самый частый ящик в каталоге; в старых заказах 2/3 строк — ровно 8 пар).
 * Бренд удалённого товара определяется по названию позиции («Кроссовки Jong•Golf B30113-0» → Jong•Golf).
 */
final class Reports
{
    /** Периоды-таблетки: ключ ?range= → подпись */
    public const RANGES = ['7' => '7 дней', '30' => '30 дней', '90' => '90 дней', '365' => '12 месяцев', 'all' => 'Всё время'];
    public const DEFAULT_RANGE = '30';

    public const STATUSES = [
        'new' => 'Новый', 'processing' => 'В обработке', 'paid' => 'Оплачен', 'shipped' => 'Отправлен',
        'completed' => 'Выполнен', 'refunded' => 'Возврат', 'deleted' => 'Удалён',
    ];
    public const SOURCES = [
        'site' => 'Сайт', 'quickorder' => 'Купить в 1 клик', 'callback' => 'Обратный звонок', 'admin' => 'Менеджер (по телефону)', 'webasyst' => 'Старый сайт (Webasyst)',
    ];
    /** Не приносят денег: не входят в выручку, ящики и рейтинги */
    public const EXCLUDED = ['deleted', 'refunded'];

    public const MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

    /** Условие «заказ учитывается» — константа, без данных пользователя */
    private const COUNTED = "o.status NOT IN ('deleted','refunded')";
    /** Размер ящика позиции известен (сохранён в заказе нового сайта) */
    private const KNOWN = "(i.box_qty > 1 OR o.source <> 'webasyst')";
    /** Оценка ящиков, когда размер неизвестен: до 12 пар — один ящик, больше — по 8 пар */
    private const GUESS = 'IF(i.quantity = 0, 0, IF(i.quantity <= 12, 1, CEIL(i.quantity / 8)))';
    /** Ящики позиции (нужен JOIN orders o — источник заказа) */
    private const BOXES = 'IF(' . self::KNOWN . ', CEIL(i.quantity / GREATEST(i.box_qty, 1)), ' . self::GUESS . ')';
    /** Знаки, которые в названиях считаются пробелом при поиске бренда («Jong•Golf» = «jong golf») */
    private const WORD_SEP = ['•' => ' ', '.' => ' ', '-' => ' ', '_' => ' ', '/' => ' ', ',' => ' ', "'" => ' ', '"' => ' ', "\t" => ' '];
    /** Длиннее — график по месяцам */
    private const MONTHLY_AFTER_DAYS = 120;
    private const TTL = 300;
    private const TOP = 20;
    private const TOP_GROUPS = 12;

    // ================================================================ период

    /**
     * Период из запроса. Произвольный (from/to) важнее таблетки.
     * @return array{key:string,label:string,from:string,to:string,days:int,monthly:bool,query:array,prev:?array}
     */
    public static function period(string $range, string $from = '', string $to = ''): array
    {
        $today = new \DateTimeImmutable('today');
        $f = self::date($from);
        $t = self::date($to);

        if ($f || $t) {
            $t = $t ?: $today;
            $f = $f ?: $t->modify('-29 days');
            if ($f > $t) [$f, $t] = [$t, $f];
            if ($t > $today && $f <= $today) $t = $today;                 // будущих заказов нет — до сегодня
            $floor = new \DateTimeImmutable('2000-01-01');                // не строить график на тысячу лет
            $ceil = new \DateTimeImmutable('2099-12-31');
            if ($f < $floor) $f = $floor;
            if ($t > $ceil) $t = $ceil;
            if ($f > $t) $f = $t;
            $key = 'custom';
            $label = $f->format('d.m.Y') . ' — ' . $t->format('d.m.Y');
            $query = ['from' => $f->format('Y-m-d'), 'to' => $t->format('Y-m-d')];
        } else {
            $key = isset(self::RANGES[$range]) ? $range : self::DEFAULT_RANGE;
            $t = $today;
            if ($key === 'all') {
                $first = App::db()->value('SELECT MIN(created_at) FROM orders');     // индекс created
                $f = $first ? new \DateTimeImmutable(substr((string) $first, 0, 10)) : $today;
                if ($f > $t) $f = $t;
            } elseif ($key === '365') {
                // 12 полных календарных месяцев, включая текущий: столбики графика не обрезаны
                $f = $today->modify('first day of this month')->modify('-11 months');
            } else {
                $f = $today->modify('-' . ((int) $key - 1) . ' days');
            }
            $label = self::RANGES[$key];
            $query = ['range' => $key];
        }

        $days = (int) $f->diff($t)->days + 1;
        $prev = null;
        if ($key === '365') {
            $prev = ['from' => $f->modify('-1 year'), 'to' => $t->modify('-1 year'), 'label' => 'к тем же месяцам годом раньше'];
        } elseif ($key !== 'all') {
            $prev = ['from' => $f->modify('-' . $days . ' days'), 'to' => $f->modify('-1 day'),
                'label' => $days === 1 ? 'к предыдущему дню' : 'к предыдущим ' . $days . ' ' . plural($days, 'дню', 'дням', 'дням')];
        }
        if ($prev) {
            $prev['label'] .= ' (' . $prev['from']->format('d.m.Y') . ' — ' . $prev['to']->format('d.m.Y') . ')';
            $prev['from'] = $prev['from']->format('Y-m-d');
            $prev['to'] = $prev['to']->format('Y-m-d');
        }

        return [
            'key'     => $key,
            'label'   => $label,
            'from'    => $f->format('Y-m-d'),
            'to'      => $t->format('Y-m-d'),
            'days'    => $days,
            'monthly' => $days > self::MONTHLY_AFTER_DAYS,
            'query'   => $query,
            'prev'    => $prev,
        ];
    }

    /** Y-m-d → дата или null */
    private static function date(string $s): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
        return new \DateTimeImmutable($s);
    }

    /** Границы для WHERE created_at >= ? AND created_at < ? */
    private static function bounds(string $from, string $to): array
    {
        return [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
    }

    // ================================================================ отчёт

    /** Ключ кэша отчёта: даты периода, шаг графика, начало прошлого периода */
    private static function cacheKey(array $p): string
    {
        return 'reports.v5.' . $p['from'] . '.' . $p['to'] . '.' . ($p['monthly'] ? 'm' : 'd') . '.' . ($p['prev']['from'] ?? '-');
    }

    /** Пересчитать отчёт за период при следующем открытии (кнопка «Обновить сейчас») */
    public static function forget(array $p): void
    {
        Cache::forget(self::cacheKey($p));
    }

    /** Все цифры экрана за период (кэш 5 минут по ключу периода) */
    public static function build(array $p): array
    {
        return Cache::remember(self::cacheKey($p), self::TTL, static function () use ($p) {
            $t0 = microtime(true);
            $b = self::bounds($p['from'], $p['to']);
            $orders = self::orders($b);
            $statuses = self::statuses($orders);
            $totals = self::totals($statuses) + ['boxes' => 0, 'boxes_guess' => 0, 'buyers' => 0, 'new_buyers' => 0, 'repeat_orders' => 0,
                'repeat_base' => 0, 'repeat_share' => 0.0];
            $out = [
                'totals'   => $totals,
                'prev'     => null,
                'statuses' => $statuses,
                'series'   => [], 'top_boxes' => [], 'top_sum' => [], 'categories' => [], 'brands' => [], 'types' => [],
                'customers' => [], 'shipping' => [], 'payment' => [], 'sources' => [], 'coupons' => [], 'last_order' => null,
            ];
            if ($totals['orders_all'] === 0) {
                $out['last_order'] = App::db()->value('SELECT MAX(created_at) FROM orders WHERE ' . str_replace('o.', '', self::COUNTED));
            } else {
                $out['series'] = self::series($orders, $p['from'], $p['to'], $p['monthly']);
            }
            if ($totals['orders'] > 0) {
                $items = self::rollup($b, $orders);
                $out['totals']['boxes'] = array_sum($items['boxes']);
                $out['totals']['boxes_guess'] = array_sum($items['guess']);
                $out['totals'] = self::buyers($b, $orders) + $out['totals'];
                $out['top_boxes'] = self::products($items, 'boxes');
                $out['top_sum'] = self::products($items, 'sum');
                $out['categories'] = self::categories($items);
                $out['brands'] = self::brands($items);
                $out['types'] = self::types($items);
                $out['customers'] = self::customers($b, $orders, $items['by_customer']);
                unset($items);
                $out['coupons'] = self::coupons($b);
                $out = self::channels($orders) + $out;
            }
            unset($orders);
            if ($p['prev']) {
                $pb = self::bounds($p['prev']['from'], $p['prev']['to']);
                $out['prev'] = self::totals(self::statuses(self::orders($pb)));
                $out['prev']['boxes'] = $out['prev']['orders'] ? self::boxesSum($pb) : 0;
            }
            $out['built_at'] = date('Y-m-d H:i:s');
            $out['build_ms'] = (int) round((microtime(true) - $t0) * 1000);
            return $out;
        });
    }

    /**
     * Заказы периода (все статусы, включая удалённые) — одна выборка по индексу created, строк столько же, сколько заказов.
     * Деньги — в копейках (int, CAST в SQL): сумма копеек точно равна SUM() по DECIMAL, а /100 даёт то же число, что (float) SUM().
     * counted — заказ учитывается в выручке; own_box — у позиций заказа свой размер ящика (не заказ старого сайта).
     * @return list<array{id:int,customer_id:?int,status:string,total:int,subtotal:int,shipping_cost:int,discount:int,pairs:int,created_at:string,
     *   source:string,shipping_name:?string,shipping_method:?string,payment_name:?string,payment_method:?string,counted:int,own_box:int}>
     */
    private static function orders(array $b): array
    {
        return self::unbuffered(static fn(DB $db) => $db->query("SELECT o.id, o.customer_id, o.status, CAST(o.total * 100 AS SIGNED) total,
                CAST(o.subtotal * 100 AS SIGNED) subtotal, CAST(o.shipping_cost * 100 AS SIGNED) shipping_cost, CAST(o.discount * 100 AS SIGNED) discount,
                o.pairs, o.created_at, o.source, o.shipping_name, o.shipping_method, o.payment_name, o.payment_method,
                " . self::COUNTED . " counted, o.source <> 'webasyst' own_box
            FROM orders o WHERE o.created_at >= ? AND o.created_at < ?", $b)->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Выполнить $fn(DB) в режиме соединения без буфера драйвера: строки больших выборок («за всё время» — 20 тыс. позиций,
     * 3,7 тыс. заказов) не копируются в память дважды — так в полтора раза быстрее. Внутри $fn каждый запрос нужно дочитать
     * до конца (fetchAll или цикл fetch + closeCursor), прежде чем выполнять следующий. Режим возвращается в finally.
     */
    private static function unbuffered(callable $fn)
    {
        $db = App::db();
        $pdo = $db->pdo();
        $was = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            return $fn($db);
        } finally {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $was);
        }
    }

    /** Копейки → гривны (то же число, что (float) SUM() по DECIMAL) */
    private static function money(int $cents): float
    {
        return $cents / 100;
    }

    /** Заказы периода по статусам: [status => [n, sum, subtotal, shipping, discount, pairs]] — все статусы, включая удалённые */
    private static function statuses(array $orders): array
    {
        $acc = [];
        foreach ($orders as $o) {
            $s = &$acc[$o['status']];
            $s ??= ['n' => 0, 'sum' => 0, 'subtotal' => 0, 'shipping' => 0, 'discount' => 0, 'pairs' => 0];
            $s['n']++;
            $s['sum'] += $o['total'];
            $s['subtotal'] += $o['subtotal'];
            $s['shipping'] += $o['shipping_cost'];
            $s['discount'] += $o['discount'];
            $s['pairs'] += $o['pairs'];
            unset($s);
        }
        $out = [];
        foreach ($acc as $st => $s) {
            $out[(string) $st] = [
                'n' => $s['n'], 'sum' => self::money($s['sum']), 'subtotal' => self::money($s['subtotal']), 'shipping' => self::money($s['shipping']),
                'discount' => self::money($s['discount']), 'pairs' => $s['pairs'],
            ];
        }
        // порядок — как в справочнике статусов, неизвестные в конце (между собой — по алфавиту)
        $keys = array_flip(array_keys(self::STATUSES));
        uksort($out, static fn($a, $b) => (($keys[$a] ?? 99) <=> ($keys[$b] ?? 99)) ?: strcmp((string) $a, (string) $b));
        return $out;
    }

    /** Итоги из разбивки по статусам (без удалённых и возвратов) */
    private static function totals(array $statuses): array
    {
        $t = ['orders_all' => 0, 'orders' => 0, 'excluded' => 0, 'excluded_sum' => 0.0, 'revenue' => 0.0, 'subtotal' => 0.0,
              'shipping' => 0.0, 'discount' => 0.0, 'pairs' => 0, 'average' => 0.0];
        foreach ($statuses as $st => $r) {
            $t['orders_all'] += $r['n'];
            if (in_array($st, self::EXCLUDED, true)) {
                $t['excluded'] += $r['n'];
                $t['excluded_sum'] += $r['sum'];
                continue;
            }
            $t['orders'] += $r['n'];
            $t['revenue'] += $r['sum'];
            $t['subtotal'] += $r['subtotal'];
            $t['shipping'] += $r['shipping'];
            $t['discount'] += $r['discount'];
            $t['pairs'] += $r['pairs'];
        }
        $t['average'] = $t['orders'] ? round($t['revenue'] / $t['orders'], 2) : 0.0;
        return $t;
    }

    /** Ящики учтённых заказов периода (для сравнения с прошлым периодом) */
    private static function boxesSum(array $b): int
    {
        return (int) App::db()->value('SELECT SUM(' . self::BOXES . ') FROM orders o JOIN order_items i ON i.order_id = o.id
            WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED, $b);
    }

    /**
     * Покупатели периода: всего, новые (первый учтённый заказ клиента — в периоде), доля повторных заказов
     * (заказ не первый у клиента). Первый заказ клиента — по индексу customer (customer_id, created_at).
     */
    private static function buyers(array $b, array $orders): array
    {
        $first = [];      // клиент → первый учтённый заказ (среди заказов периода)
        $mine = [];       // учтённые заказы периода с клиентом: [клиент, дата]
        foreach ($orders as $o) {
            if (!$o['counted'] || $o['customer_id'] === null) continue;
            $cid = $o['customer_id'];
            $mine[] = [$cid, $o['created_at']];
            if (!isset($first[$cid]) || strcmp($o['created_at'], $first[$cid]) < 0) $first[$cid] = $o['created_at'];
        }
        // заказы до начала периода: первый заказ клиента мог быть раньше (индекс customer (customer_id, created_at));
        // если раньше периода заказов нет вовсе (как «за всё время»), искать нечего
        $db = App::db();
        $earlier = $first && $db->value('SELECT 1 FROM orders WHERE created_at < ? LIMIT 1', [$b[0]]);
        foreach ($earlier ? array_chunk(array_keys($first), 5000) : [] as $chunk) {
            [$ph, $vals] = $db->in($chunk);
            foreach ($db->pairs('SELECT o.customer_id, MIN(o.created_at) FROM orders o
                    WHERE o.customer_id IN (' . $ph . ') AND o.created_at < ? AND ' . self::COUNTED . ' GROUP BY o.customer_id',
                    array_merge($vals, [$b[0]])) as $cid => $at) {
                $first[(int) $cid] = (string) $at;
            }
        }
        $new = $repeat = 0;
        foreach ($first as $at) if (strcmp($at, $b[0]) >= 0) $new++;
        foreach ($mine as [$cid, $at]) if (strcmp($at, $first[$cid]) > 0) $repeat++;
        $with = count($mine);
        return [
            'buyers'        => count($first),
            'new_buyers'    => $new,
            'repeat_orders' => $repeat,
            'repeat_base'   => $with,                        // заказы с клиентом — от них считается доля (без клиента повторность не узнать)
            'repeat_share'  => $with ? round($repeat / $with * 100, 1) : 0.0,
        ];
    }

    /** Выручка и число заказов по дням/месяцам; все корзины периода, включая пустые */
    private static function series(array $orders, string $from, string $to, bool $monthly): array
    {
        $len = $monthly ? 7 : 10;                             // «2026-09» или «2026-09-05» из created_at
        $acc = [];
        foreach ($orders as $o) {
            if (!$o['counted']) continue;
            $k = substr($o['created_at'], 0, $len);
            $acc[$k] ??= [0, 0];
            $acc[$k][0]++;
            $acc[$k][1] += $o['total'];
        }
        $got = [];
        foreach ($acc as $k => [$n, $sum]) $got[(string) $k] = ['sum' => self::money($sum), 'n' => $n];

        $out = [];
        $cur = new \DateTimeImmutable($monthly ? substr($from, 0, 7) . '-01' : $from);
        $end = new \DateTimeImmutable($monthly ? substr($to, 0, 7) . '-01' : $to);
        $step = $monthly ? '+1 month' : '+1 day';
        $guard = 0;
        while ($cur <= $end && $guard++ < 5000) {
            $k = $cur->format($monthly ? 'Y-m' : 'Y-m-d');
            $out[$k] = $got[$k] ?? ['sum' => 0.0, 'n' => 0];
            $cur = $cur->modify($step);
        }
        return $out;
    }

    /**
     * Проданные товары периода — по колонкам, строка i = товар (id по возрастанию, как раньше давал GROUP BY):
     * pid, name, pairs, sum (копейки), boxes, guess (пар с оценкой ящиков); cards — [pid => [category_id, brand_id, i]]
     * только у товаров, которые есть в каталоге; by_customer — ящики по клиентам (для «Лучших клиентов»); bounds — период.
     * Позиции учтённых заказов читаются одним запросом по индексу order_items.order (пакетами по 5000 заказов;
     * если в период попадает больше половины заказов магазина, как «за всё время», — одним проходом по таблице) и сворачиваются в PHP:
     * GROUP BY с MAX(i.name) на 17 тыс. товаров строил временную таблицу на диске. Колонки, а не массив на товар —
     * разбивки ниже считают суммы через array_sum(). Число заказов с товаром нужно только топу — оно считается там (orderCounts).
     * Название товара — MAX(name), как раньше: если у товара в периоде были разные названия (редко), MAX берётся
     * отдельным запросом только по этим товарам. Карточки (категория, бренд) — пакетами по 5000 id (за всё время —
     * одним JOIN по индексу order_items.product); id удалённых ещё на старом сайте товаров лежат вне диапазона id каталога — их не ищем.
     */
    private static function rollup(array $b, array $orders): array
    {
        $db = App::db();
        $own = $cust = [];                                   // учтённый заказ → свой размер ящика; заказ → клиент
        foreach ($orders as $o) {
            if (!$o['counted']) continue;
            $own[$o['id']] = $o['own_box'];
            if ($o['customer_id'] !== null) $cust[$o['id']] = $o['customer_id'];
        }
        $sql = 'SELECT i.order_id, IFNULL(i.product_id, 0), i.quantity, CAST(i.price * i.quantity * 100 AS SIGNED), i.box_qty, i.name FROM order_items i';
        // в периоде больше половины заказов магазина (как «за всё время») — один проход по всей таблице позиций быстрее
        // списков id; строки других периодов, удалённых заказов и возвратов отсеиваются по $own
        $whole = count($own) * 2 > (int) $db->value('SELECT COUNT(*) FROM orders');
        $batches = $whole ? [null] : array_chunk(array_keys($own), 5000);

        // $g[pid] = [пар, копеек, ящиков, пар с оценкой ящиков, название]
        $g = $multi = $byCustomer = [];
        // позиции читаются построчно, без буфера драйвера (unbuffered()): 20 тыс. строк «за всё время» не собираются в массив массивов
        self::unbuffered(static function (DB $db) use ($sql, $batches, $own, $cust, &$g, &$multi, &$byCustomer): void {
            foreach ($batches as $chunk) {
                if ($chunk === null) {
                    $st = $db->query($sql);
                } else {
                    [$ph, $vals] = $db->in($chunk);
                    $st = $db->query($sql . ' WHERE i.order_id IN (' . $ph . ')', $vals);
                }
                while (($r = $st->fetch(\PDO::FETCH_NUM)) !== false) {
                    [$oid, $pid, $q, $cents, $bq, $name] = $r;
                    if (!isset($own[$oid])) continue;            // удалённый заказ или возврат (при проходе по всей таблице)
                    // ящики позиции — как BOXES: свой размер ящика или оценка для старых заказов (GUESS)
                    $known = $bq > 1 || $own[$oid];
                    $bx = $known ? ($bq > 1 ? intdiv($q + $bq - 1, $bq) : $q) : ($q === 0 ? 0 : ($q <= 12 ? 1 : intdiv($q + 7, 8)));
                    if (isset($cust[$oid])) $byCustomer[$cust[$oid]] = ($byCustomer[$cust[$oid]] ?? 0) + $bx;
                    if (!isset($g[$pid])) {
                        $g[$pid] = [$q, $cents, $bx, $known ? 0 : $q, $name];
                        continue;
                    }
                    $x = &$g[$pid];
                    $x[0] += $q;
                    $x[1] += $cents;
                    $x[2] += $bx;
                    if (!$known) $x[3] += $q;
                    if ($x[4] !== $name) $multi[$pid] = true;
                    unset($x);
                }
                $st->closeCursor();
            }
        });
        unset($own, $cust);
        ksort($g);

        // у товара в периоде разные названия — MAX(name) по правилам сравнения базы, как раньше
        foreach (array_chunk(array_keys($multi), 1000) as $chunk) {
            [$ph, $vals] = $db->in($chunk);
            foreach ($db->pairs('SELECT i.product_id, MAX(i.name) FROM orders o JOIN order_items i ON i.order_id = o.id
                    WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED . ' AND i.product_id IN (' . $ph . ')
                    GROUP BY i.product_id', array_merge($b, $vals)) as $pid => $name) {
                if (isset($g[(int) $pid])) $g[(int) $pid][4] = (string) $name;
            }
        }

        $pids = array_keys($g);
        $range = $db->row('SELECT MIN(id) lo, MAX(id) hi FROM products') ?? [];
        $lo = (int) ($range['lo'] ?? 0);
        $hi = (int) ($range['hi'] ?? 0);
        $found = [];
        if ($whole) {
            // большая часть заказов магазина: товары каталога среди проданных — одним запросом по индексу order_items.product,
            // без списка из 10 тыс. id (лишние — проданные только в других периодах или удалённых заказах — отсеются ниже)
            $found = self::unbuffered(static fn(DB $db) => $db->query('SELECT DISTINCT i.product_id, p.category_id, p.brand_id
                FROM order_items i JOIN products p ON p.id = i.product_id WHERE i.product_id BETWEEN ? AND ?', [$lo, $hi])->fetchAll(\PDO::FETCH_NUM));
        } else {
            $ids = [];
            foreach ($pids as $id) {
                if ($id > 0 && $id >= $lo && $id <= $hi) $ids[] = $id;
            }
            foreach (array_chunk($ids, 5000) as $chunk) {
                [$ph, $vals] = $db->in($chunk);
                array_push($found, ...$db->query('SELECT id, category_id, brand_id FROM products WHERE id IN (' . $ph . ')', $vals)->fetchAll(\PDO::FETCH_NUM));
            }
        }
        $cards = [];
        foreach ($found as $r) {
            if (isset($g[(int) $r[0]])) $cards[(int) $r[0]] = [(int) $r[1], (int) $r[2]];
        }
        unset($found);
        if ($cards) {
            ksort($cards);
            $pos = array_flip($pids);
            foreach ($cards as $id => &$c) $c[2] = $pos[$id];
            unset($c, $pos);
        }
        return [
            'pid'         => $pids,
            'name'        => array_column($g, 4),
            'pairs'       => array_column($g, 0),
            'sum'         => array_column($g, 1),
            'boxes'       => array_column($g, 2),
            'guess'       => array_column($g, 3),
            'cards'       => $cards,
            'by_customer' => $byCustomer,
            'bounds'      => $b,
        ];
    }

    /** Заказов с товаром в периоде (COUNT(DISTINCT order_id)) — только для товаров топа: [pid => n] */
    private static function orderCounts(array $b, array $pids): array
    {
        if (!$pids) return [];
        $db = App::db();
        [$ph, $vals] = $db->in($pids);
        return array_map('intval', $db->pairs('SELECT i.product_id, COUNT(DISTINCT i.order_id) FROM order_items i JOIN orders o ON o.id = i.order_id
            WHERE i.product_id IN (' . $ph . ') AND o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED . '
            GROUP BY i.product_id', array_merge($vals, $b)));
    }

    /** Топ товаров по ящикам или по выручке (из свёртки) + данные карточек для ссылок */
    private static function products(array $it, string $by): array
    {
        $other = $by === 'sum' ? 'boxes' : 'sum';                    // белый список
        // кандидаты — не меньше TOP-го по величине значения (сортировать все 17 тыс. товаров «за всё время» незачем)
        $col = $it[$by];
        $idx = array_keys($col);
        if (count($idx) > self::TOP * 50) {
            // порог — TOP-е значение среди каждого 16-го товара: TOP-е значение всех товаров не меньше него,
            // поэтому все, кто выше порога, попадают в кандидаты (с равными — тоже); кандидатов — сотни, а не тысячи
            $sample = [];
            for ($i = 0, $n = count($col); $i < $n; $i += 16) $sample[] = $col[$i];
            rsort($sample);
            $min = $sample[self::TOP - 1] ?? PHP_INT_MIN;
            $idx = [];
            foreach ($col as $i => $v) if ($v >= $min) $idx[] = $i;
        }
        $main = $second = [];
        foreach ($idx as $i) {
            $main[] = $col[$i];
            $second[] = $it[$other][$i];
        }
        array_multisort($main, SORT_DESC, $second, SORT_DESC, $idx);  // при равных — по id товара
        $top = array_slice($idx, 0, self::TOP);
        $counts = self::orderCounts($it['bounds'], array_map(static fn($i) => $it['pid'][$i], $top));

        $ids = [];
        foreach ($top as $i) if (isset($it['cards'][$it['pid'][$i]])) $ids[] = $it['pid'][$i];
        $cards = [];
        if ($ids) {
            $db = App::db();
            [$ph, $vals] = $db->in($ids);
            $cards = $db->keyed('SELECT p.id, p.name, p.url, p.status, p.sku, p.box_qty, c.name category
                FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id IN (' . $ph . ')', $vals);
        }
        $out = [];
        foreach ($top as $i) {
            $pid = $it['pid'][$i];
            $p = $cards[$pid] ?? null;
            $out[] = [
                'id'       => $pid,
                'name'     => $p ? (string) $p['name'] : self::cleanName($it['name'][$i]),
                'exists'   => $p !== null,
                'url'      => $p ? '/product/' . $p['url'] . '/' : '',
                'hidden'   => $p && !(int) $p['status'],
                'sizes'    => $p ? (string) $p['sku'] : '',
                'box_qty'  => $p ? (int) $p['box_qty'] : 0,
                'category' => $p ? (string) ($p['category'] ?? '') : '',
                'boxes'    => $it['boxes'][$i],
                'pairs'    => $it['pairs'][$i],
                'sum'      => self::money($it['sum'][$i]),
                'orders'   => $counts[$pid] ?? 0,
                'guess'    => $it['guess'][$i] > 0,
            ];
        }
        return $out;
    }

    /** Пустая строка разбивки (sum — в копейках до aggOut()) */
    private static function agg(): array
    {
        return ['boxes' => 0, 'pairs' => 0, 'sum' => 0, 'products' => 0, 'by_name' => 0];
    }

    /**
     * Строки разбивки: $keys — [i товара свёртки => ключ группы]; группы идут в порядке первого появления ключа
     * (от этого зависит порядок при равных суммах). Один проход без вызова функции на товар — «за всё время» их 17 тыс.
     */
    private static function group(array $it, array $keys): array
    {
        $boxes = $it['boxes'];
        $pairs = $it['pairs'];
        $sum = $it['sum'];
        $g = [];
        foreach ($keys as $i => $k) {
            if (!isset($g[$k])) {
                $g[$k] = ['boxes' => $boxes[$i], 'pairs' => $pairs[$i], 'sum' => $sum[$i], 'products' => 1, 'by_name' => 0];
                continue;
            }
            $r = &$g[$k];
            $r['boxes'] += $boxes[$i];
            $r['pairs'] += $pairs[$i];
            $r['sum'] += $sum[$i];
            $r['products']++;
            unset($r);
        }
        return $g;
    }

    /** Строка разбивки для экрана: копейки → гривны */
    private static function aggOut(?array $row): ?array
    {
        if ($row !== null) $row['sum'] = self::money($row['sum']);
        return $row;
    }

    /**
     * Строка «удалены из каталога»: вся свёртка минус товары каталога — без обхода 17 тыс. удалённых товаров.
     * null — удалённых нет.
     */
    private static function deletedAgg(array $it): ?array
    {
        $row = ['boxes' => array_sum($it['boxes']), 'pairs' => array_sum($it['pairs']), 'sum' => array_sum($it['sum']),
            'products' => count($it['pid']), 'by_name' => 0];
        foreach ($it['cards'] as $c) {
            $row['boxes'] -= $it['boxes'][$c[2]];
            $row['pairs'] -= $it['pairs'][$c[2]];
            $row['sum'] -= $it['sum'][$c[2]];
            $row['products']--;
        }
        return $row['products'] > 0 ? $row : null;
    }

    /**
     * Категории проданных товаров (products.category_id).
     * @return array{rows:list<array>,none:?array,deleted:?array,total:float,more:int}
     */
    private static function categories(array $it): array
    {
        $keys = [];
        foreach ($it['cards'] as [$cat, , $i]) $keys[$i] = $cat > 0 ? $cat : '';   // товары каталога, по возрастанию id; '' — без категории
        $g = self::group($it, $keys);
        $none = $g[''] ?? null;
        unset($g['']);
        uasort($g, static fn($a, $b) => $b['sum'] <=> $a['sum']);
        $more = max(0, count($g) - self::TOP_GROUPS);
        $g = array_slice($g, 0, self::TOP_GROUPS, true);

        $names = [];
        if ($g) {
            $db = App::db();
            [$ph, $vals] = $db->in(array_keys($g));
            $names = $db->keyed('SELECT c.id, c.name, c.url, c.status, pc.name parent FROM categories c
                LEFT JOIN categories pc ON pc.id = c.parent_id WHERE c.id IN (' . $ph . ')', $vals);
        }
        $rows = [];
        foreach ($g as $id => $row) {
            $n = $names[$id] ?? null;
            $rows[] = self::aggOut($row) + [
                'id'     => (int) $id,
                'name'   => $n ? (string) $n['name'] : 'Категория #' . $id . ' (удалена)',
                'parent' => (string) ($n['parent'] ?? ''),
                'url'    => $n ? Catalog::categoryUrl($n) : '',
                'admin'  => $n ? '/admin/categories/' . (int) $id . '/' : '',
                'hidden' => $n ? !(int) $n['status'] : true,
            ];
        }
        return ['rows' => $rows, 'none' => self::aggOut($none), 'deleted' => self::aggOut(self::deletedAgg($it)),
            'total' => self::money(array_sum($it['sum'])), 'more' => $more];
    }

    /**
     * Бренды проданных товаров: из каталога (products.brand_id), а у удалённых товаров — по названию позиции.
     * @return array{rows:list<array>,none:?array,deleted:?array,total:float,more:int,by_name:int}
     */
    private static function brands(array $it): array
    {
        // ключ группы товара i (по возрастанию id — порядок групп при равных суммах как раньше):
        // id бренда; '' — товар каталога без бренда; '-' — удалённый товар, бренд по названию не найден
        $cards = $it['cards'];
        $keys = $gone = [];
        foreach ($it['pid'] as $i => $pid) {
            if (isset($cards[$pid])) {
                $keys[$i] = $cards[$pid][1] > 0 ? $cards[$pid][1] : '';
            } else {
                $keys[$i] = '-';
                $gone[$i] = $it['name'][$i];
            }
        }
        $named = self::brandIds($gone, self::brandMap());    // i → бренд, найденный по названию
        unset($gone);
        foreach ($named as $i => $id) $keys[$i] = $id;
        $g = self::group($it, $keys);
        foreach ($named as $id) $g[$id]['by_name']++;
        $byName = count($named);
        $none = $g[''] ?? null;
        $deleted = $g['-'] ?? null;
        unset($g[''], $g['-'], $keys, $named);
        uasort($g, static fn($a, $b) => $b['sum'] <=> $a['sum']);
        $more = max(0, count($g) - self::TOP_GROUPS);
        $g = array_slice($g, 0, self::TOP_GROUPS, true);

        $all = Catalog::brands();
        $rows = [];
        foreach ($g as $id => $row) {
            $n = $all[$id] ?? null;
            $rows[] = self::aggOut($row) + [
                'id'     => (int) $id,
                'name'   => $n ? (string) $n['name'] : 'Бренд #' . $id . ' (удалён)',
                'parent' => '',
                'url'    => $n ? Catalog::brandUrl($n) : '',
                'admin'  => $n ? '/admin/brands/' . (int) $id . '/' : '',
                'hidden' => $n ? (bool) (int) $n['hidden'] : true,
            ];
        }
        return ['rows' => $rows, 'none' => self::aggOut($none), 'deleted' => self::aggOut($deleted),
            'total' => self::money(array_sum($it['sum'])), 'more' => $more, 'by_name' => $byName];
    }

    /** Нормализованное имя бренда → id (без регистра и знаков: «Jong•Golf» = «jong golf») */
    private static function brandMap(): array
    {
        $map = [];
        foreach (Catalog::brands() as $id => $b) {
            $k = implode(' ', self::words((string) $b['name'], 9));
            if (mb_strlen($k) >= 2 && preg_match('/\p{L}/u', $k)) $map[$k] ??= (int) $id;
        }
        return $map;
    }

    /** Первые слова названия в нижнем регистре, без знаков и хвоста «(…)»: «Кроссовки Jong•Golf B3-0 (…)» → [кроссовки, jong, golf, b3, 0] */
    private static function words(string $s, int $limit): array
    {
        $s = mb_strtolower(strtr(self::head($s), self::WORD_SEP));
        $out = [];
        foreach (explode(' ', $s) as $w) {
            if ($w === '') continue;
            $out[] = $w;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** Начало названия, где ищется бренд: до «(» и не длиннее 160 байт */
    private static function head(string $s): string
    {
        $cut = strpos($s, '(');
        return substr($s, 0, $cut === false ? 160 : min($cut, 160));
    }

    /**
     * Бренд по названию позиции: самое длинное (до 3 слов) совпадение со справочником брендов среди первых слов —
     * название пишется как «Вид Бренд Артикул» («Зимняя обувь KLF B643-2», «Tom.m 5207C»).
     */
    public static function brandByName(string $name, array $map): ?int
    {
        $w = self::words($name, 5);
        $n = count($w);
        for ($len = 3; $len >= 1; $len--) {
            for ($i = 0; $i <= 2 && $i + $len <= $n; $i++) {
                $k = $len === 1 ? $w[$i] : ($len === 2 ? $w[$i] . ' ' . $w[$i + 1] : $w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2]);
                if (isset($map[$k])) return $map[$k];
            }
        }
        return null;
    }

    /**
     * brandByName() для пачки названий [ключ => название] → [ключ => id бренда] (не найденных в ответе нет), с тем же результатом.
     * Быстрее на 17 тыс. названий «за всё время»: знаки заменяются и регистр понижается одной строкой на всю пачку,
     * первые пять слов каждого названия (до «(») достаёт одно регулярное выражение, а проверки по справочнику развёрнуты
     * в том же порядке, что в brandByName(): сначала три слова, потом два, потом одно; при равной длине — левее.
     * Названия длиннее 160 байт (там words() обрезает строку) и пачки с разделителем внутри названия — через brandByName().
     */
    private static function brandIds(array $names, array $map): array
    {
        $out = [];
        if (!$names) return $out;
        $joined = implode("\x1E", $names);
        // хвосты «(…)» отрезаются до замены знаков и регистра — строка вдвое короче
        $n = substr_count($joined, "\x1E") === count($names) - 1
            ? preg_match_all('/(?:^|\x1E) *([^ (\x1E]*) *([^ (\x1E]*) *([^ (\x1E]*) *([^ (\x1E]*) *([^ (\x1E]*)[^\x1E]*/',
                mb_strtolower(strtr((string) preg_replace('/\([^\x1E]*/', '', $joined), self::WORD_SEP)), $m)
            : 0;
        unset($joined);
        if ($n !== count($names)) {
            foreach ($names as $k => $s) {
                $id = self::brandByName((string) $s, $map);
                if ($id !== null) $out[$k] = $id;
            }
            return $out;
        }
        [, $a, $b, $c, $d, $e] = $m;
        unset($m);
        $st2 = $st3 = [];                                    // первые слова ключей из двух и из трёх слов
        foreach ($map as $key => $id) {
            $sp = substr_count((string) $key, ' ');
            if ($sp === 1) $st2[strstr((string) $key, ' ', true)] = true;
            elseif ($sp === 2) $st3[strstr((string) $key, ' ', true)] = true;
        }
        $j = -1;
        foreach ($names as $k => $name) {
            $j++;
            if (strlen((string) $name) > 160) {
                $id = self::brandByName((string) $name, $map);
                if ($id !== null) $out[$k] = $id;
                continue;
            }
            // слова идут подряд: пустое — дальше слов нет
            $w0 = $a[$j];
            $w1 = $b[$j];
            $w2 = $c[$j];
            $w3 = $d[$j];
            $w4 = $e[$j];
            if ($w2 !== '') {
                if (isset($st3[$w0], $map[$x = $w0 . ' ' . $w1 . ' ' . $w2])) { $out[$k] = $map[$x]; continue; }
                if ($w3 !== '') {
                    if (isset($st3[$w1], $map[$x = $w1 . ' ' . $w2 . ' ' . $w3])) { $out[$k] = $map[$x]; continue; }
                    if ($w4 !== '' && isset($st3[$w2], $map[$x = $w2 . ' ' . $w3 . ' ' . $w4])) { $out[$k] = $map[$x]; continue; }
                }
            }
            if ($w1 !== '') {
                if (isset($st2[$w0], $map[$x = $w0 . ' ' . $w1])) { $out[$k] = $map[$x]; continue; }
                if ($w2 !== '') {
                    if (isset($st2[$w1], $map[$x = $w1 . ' ' . $w2])) { $out[$k] = $map[$x]; continue; }
                    if ($w3 !== '' && isset($st2[$w2], $map[$x = $w2 . ' ' . $w3])) { $out[$k] = $map[$x]; continue; }
                }
            }
            if (isset($map[$w0])) $out[$k] = $map[$w0];
            elseif (isset($map[$w1])) $out[$k] = $map[$w1];
            elseif (isset($map[$w2])) $out[$k] = $map[$w2];
        }
        return $out;
    }

    /**
     * Виды обуви по началу названия позиции — работает и для товаров, удалённых из каталога.
     * Новые товары часто названы одним артикулом («65186E») — для них вид берётся из названия категории каталога.
     */
    private static function types(array $it): array
    {
        $memo = $typed = $need = [];
        foreach ($it['name'] as $i => $name) {
            // вид — в первых двух словах: то же, что implode(' ', array_slice(explode(' ', trim($name), 3), 0, 2))
            $head = trim($name);
            $sp = strpos($head, ' ');
            if ($sp !== false && ($sp2 = strpos($head, ' ', $sp + 1)) !== false) $head = substr($head, 0, $sp2);
            $typed[$i] = $memo[$head] ??= self::typeOf($head);
        }
        foreach ($it['cards'] as $c) {
            if ($typed[$c[2]] === '' && $c[0] > 0) $need[$c[0]][] = $c[2];
        }
        if ($need) {
            $db = App::db();
            [$ph, $vals] = $db->in(array_keys($need));
            foreach ($db->pairs('SELECT id, name FROM categories WHERE id IN (' . $ph . ')', $vals) as $cid => $cname) {
                $t = self::typeOf((string) $cname);
                if ($t !== '') foreach ($need[(int) $cid] ?? [] as $i) $typed[$i] = $t;
            }
        }
        $g = self::group($it, $typed);
        uasort($g, static fn($a, $b) => $b['sum'] <=> $a['sum']);
        $rows = [];
        $rest = null;
        foreach ($g as $name => $row) {
            if (count($rows) < self::TOP_GROUPS && $name !== '') { $rows[] = self::aggOut($row) + ['name' => $name]; continue; }
            $rest ??= self::agg() + ['name' => 'Другие и вид не определён'];
            foreach (['boxes', 'pairs', 'sum', 'products'] as $k) $rest[$k] += $row[$k];
        }
        if ($rest) $rows[] = self::aggOut($rest) + ['rest' => true];
        return $rows;
    }

    /** «Зимняя обувь KLF B643-2» → «Зимняя обувь», «кроссовки Xifa…» → «Кроссовки», «Tom.m 5207C» → '' */
    public static function typeOf(string $name): string
    {
        $w = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_strtolower((string) ($w[0] ?? ''));
        if ($first === '' || !preg_match('/^[\p{Cyrillic}\-]+$/u', $first)) return '';
        // прилагательное + существительное: «Резиновые сапоги», «Детская обувь»
        if (isset($w[1]) && preg_match('/(ая|яя|ые|ие|ий|ый|ой|ое|ее)$/u', $first) && preg_match('/^[\p{Cyrillic}]+$/u', $w[1])) {
            $first .= ' ' . mb_strtolower($w[1]);
        }
        return mb_strtoupper(mb_substr($first, 0, 1)) . mb_substr($first, 1);
    }

    /**
     * Лучшие клиенты по сумме заказов (из выборки заказов периода); ящики — из свёртки позиций (rollup),
     * имя и телефон из заказа (MAX, если в карточке клиента пусто) — одним запросом только по этим клиентам.
     */
    private static function customers(array $b, array $orders, array $boxesBy): array
    {
        $g = [];
        foreach ($orders as $o) {
            if (!$o['counted'] || $o['customer_id'] === null) continue;
            $x = &$g[$o['customer_id']];
            $x ??= ['orders' => 0, 'sum' => 0, 'last_at' => ''];
            $x['orders']++;
            $x['sum'] += $o['total'];
            if (strcmp($o['created_at'], $x['last_at']) > 0) $x['last_at'] = $o['created_at'];
            unset($x);
        }
        // ORDER BY sum DESC, orders DESC LIMIT TOP; при равных — по id клиента (так их отдавал и прежний GROUP BY)
        $ids = array_keys($g);
        $sums = array_column($g, 'sum');
        $cnt = array_column($g, 'orders');
        array_multisort($sums, SORT_DESC, $cnt, SORT_DESC, $ids, SORT_ASC);
        $top = [];
        foreach (array_slice($ids, 0, self::TOP) as $id) $top[$id] = $g[$id];
        $g = $top;

        $db = App::db();
        $people = $fromOrders = [];
        if ($g) {
            [$ph, $vals] = $db->in(array_keys($g));
            $people = $db->keyed('SELECT id, name, company, phone, city FROM customers WHERE id IN (' . $ph . ')', $vals);
            $fromOrders = $db->keyed('SELECT o.customer_id, MAX(o.name) order_name, MAX(o.phone) order_phone FROM orders o
                WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED . ' AND o.customer_id IN (' . $ph . ')
                GROUP BY o.customer_id', array_merge($b, $vals));
        }
        $out = [];
        foreach ($g as $id => $r) {
            $id = (int) $id;
            $c = $people[$id] ?? null;
            $fo = $fromOrders[$id] ?? ['order_name' => '', 'order_phone' => ''];
            $name = trim((string) ($c['name'] ?? '')) ?: trim((string) $fo['order_name']) ?: 'Клиент #' . $id;
            $out[] = [
                'id'      => $id,
                'exists'  => $c !== null,
                'name'    => $name,
                'company' => trim((string) ($c['company'] ?? '')),
                'phone'   => (string) (($c['phone'] ?? '') ?: $fo['order_phone']),
                'city'    => trim((string) ($c['city'] ?? '')),
                'orders'  => $r['orders'],
                'boxes'   => (int) ($boxesBy[$id] ?? 0),
                'sum'     => self::money($r['sum']),
                'last_at' => $r['last_at'],
            ];
        }
        return $out;
    }

    /**
     * Способы доставки и оплаты (название из заказа, иначе — из справочника настроек по коду) и источники заказов —
     * один проход по заказам периода. Сначала — свёртка по (доставка, код, оплата, код, источник), как давал GROUP BY:
     * от неё зависит подпись («самого частого написания»); группировка по TRIM() длинных строк в SQL шла через временную таблицу.
     * @return array{shipping:list<array>,payment:list<array>,sources:list<array>}
     */
    private static function channels(array $orders): array
    {
        $acc = [];
        foreach ($orders as $o) {
            if (!$o['counted']) continue;
            // TRIM() в SQL убирает только пробелы
            $k = [trim((string) $o['shipping_name'], ' '), (string) $o['shipping_method'], trim((string) $o['payment_name'], ' '),
                (string) $o['payment_method'], (string) $o['source']];
            $key = implode("\x1F", $k);
            $acc[$key] ??= ['sn' => $k[0], 'sc' => $k[1], 'pn' => $k[2], 'pc' => $k[3], 'source' => $k[4], 'n' => 0, 'sum' => 0];
            $acc[$key]['n']++;
            $acc[$key]['sum'] += $o['total'];
        }
        // порядок групп — как у GROUP BY (по возрастанию, без учёта регистра)
        uksort($acc, static fn($a, $b) => strcmp(mb_strtolower($a), mb_strtolower($b)) ?: strcmp($a, $b));
        $rows = [];
        foreach ($acc as $r) $rows[] = ['sum' => self::money($r['sum'])] + $r;
        $out = ['shipping' => [], 'payment' => [], 'sources' => []];
        foreach ($rows as $r) {
            foreach ([['shipping', 'sn', 'sc', 'shipping_methods'], ['payment', 'pn', 'pc', 'payment_methods']] as [$what, $nameCol, $codeCol, $setting]) {
                $label = self::methodLabel((string) $r[$nameCol], (string) $r[$codeCol], $setting) ?: 'Не указано';
                $k = mb_strtolower($label);                    // «Новая почта» и «Новая Почта» — одно и то же
                $out[$what][$k] ??= ['label' => $label, 'n' => 0, 'sum' => 0.0, 'top' => 0];
                if ((int) $r['n'] > $out[$what][$k]['top']) {  // подпись — самого частого написания
                    $out[$what][$k]['label'] = $label;
                    $out[$what][$k]['top'] = (int) $r['n'];
                }
                $out[$what][$k]['n'] += (int) $r['n'];
                $out[$what][$k]['sum'] += (float) $r['sum'];
            }
            $src = (string) $r['source'];
            $out['sources'][$src] ??= ['key' => $src, 'label' => self::SOURCES[$src] ?? $src, 'n' => 0, 'sum' => 0.0];
            $out['sources'][$src]['n'] += (int) $r['n'];
            $out['sources'][$src]['sum'] += (float) $r['sum'];
        }
        foreach ($out as &$list) {
            usort($list, static fn($a, $b) => $b['n'] <=> $a['n'] ?: $b['sum'] <=> $a['sum']);
        }
        unset($list);
        return $out;
    }

    /**
     * Промокоды в учтённых заказах периода (как «Discount codes used» в ARG FLEX): заказов, скидка, сумма заказов.
     * Группа — по коду: у удалённого промокода coupon_id = 0, история остаётся по коду.
     * Таблицы coupon_usages нет (миграция раздела «Промокоды» не выполнена) — пустой список.
     * @return list<array{code:string,coupon_id:int,n:int,discount:float,sum:float}>
     */
    private static function coupons(array $b): array
    {
        try {
            $rows = App::db()->all('SELECT u.code, MAX(u.coupon_id) coupon_id, COUNT(*) n, SUM(u.discount) discount, SUM(o.total) sum
                FROM orders o JOIN coupon_usages u ON u.order_id = o.id
                WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED . '
                GROUP BY u.code ORDER BY n DESC, sum DESC LIMIT ' . self::TOP_GROUPS, $b);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') return [];      // нет таблицы
            throw $e;
        }
        return array_map(static fn($r) => [
            'code'      => (string) $r['code'],
            'coupon_id' => (int) $r['coupon_id'],
            'n'         => (int) $r['n'],
            'discount'  => (float) $r['discount'],
            'sum'       => (float) $r['sum'],
        ], $rows);
    }

    // ================================================================ экспорт

    /** id заказов периода (все статусы) в порядке даты — для потокового CSV */
    public static function orderIds(array $p): array
    {
        return array_map('intval', App::db()->col('SELECT o.id FROM orders o WHERE o.created_at >= ? AND o.created_at < ? ORDER BY o.created_at, o.id',
            self::bounds($p['from'], $p['to'])));
    }

    /** Заказы и их позиции пачкой (один запрос на заказы и один на позиции) */
    public static function ordersChunk(array $ids): array
    {
        if (!$ids) return [];
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        $orders = $db->keyed('SELECT o.id, o.created_at, o.status, o.name, o.phone, o.email, o.city, o.shipping_method, o.shipping_name,
                o.payment_method, o.payment_name, o.source, o.pairs, o.subtotal, o.shipping_cost, o.discount, o.total, o.customer_id
            FROM orders o WHERE o.id IN (' . $ph . ') ORDER BY o.created_at, o.id', $vals);
        foreach ($orders as &$o) { $o['items'] = []; $o['boxes'] = 0; }
        unset($o);
        foreach ($db->all('SELECT i.order_id, i.name, i.quantity, ' . self::BOXES . ' boxes
                FROM order_items i JOIN orders o ON o.id = i.order_id
                WHERE i.order_id IN (' . $ph . ') ORDER BY i.id', $vals) as $it) {
            $oid = (int) $it['order_id'];
            if (!isset($orders[$oid])) continue;
            $orders[$oid]['boxes'] += (int) $it['boxes'];
            $q = (int) $it['quantity'];
            $orders[$oid]['items'][] = self::cleanName((string) $it['name']) . ' — ' . (int) $it['boxes'] . ' ящ. / ' . $q . ' ' . plural($q, 'пара', 'пары', 'пар');
        }
        return $orders;
    }

    /** Название способа доставки/оплаты: из заказа, иначе — из справочника настроек по коду */
    public static function methodLabel(?string $name, ?string $code, string $setting): string
    {
        static $maps = [];
        $name = trim((string) $name);
        if ($name !== '') return $name;
        if (!isset($maps[$setting])) {
            $maps[$setting] = [];
            foreach (Settings::json($setting, []) as $m) {
                if (is_array($m) && !empty($m['code'])) $maps[$setting][(string) $m['code']] = trim((string) ($m['name'] ?? $m['code']));
            }
        }
        return $maps[$setting][(string) $code] ?? '';
    }

    // ================================================================ мелочи

    /** «Туфли X-1 (Туфли X-1)» (так Webasyst писал товар + артикул) → «Туфли X-1» */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (preg_match('/^(.+?)\s*\((.+)\)$/u', $name, $m) && mb_strtolower(trim($m[1])) === mb_strtolower(trim($m[2]))) {
            return trim($m[1]);
        }
        return $name;
    }

    /** Номер заказа как на старом сайте: #100{id} (настройка order_format) */
    public static function orderNumber(int $id): string
    {
        $fmt = (string) Settings::get('order_format', '#100{$order.id}');
        return str_contains($fmt, '{$order.id}') ? str_replace('{$order.id}', (string) $id, $fmt) : '#100' . $id;
    }

    /** Подпись корзины графика: 2026-09-05 → «5 сен 2026», 2026-09 → «сен 2026» */
    public static function bucketLabel(string $k, bool $monthly, bool $withYear = true): string
    {
        $ts = strtotime($monthly ? $k . '-01' : $k);
        $m = self::MONTHS[(int) date('n', $ts) - 1];
        $s = $monthly ? $m : (int) date('j', $ts) . ' ' . $m;
        return $withYear ? $s . ' ' . date('Y', $ts) : $s;
    }

    /** Изменение к прошлому периоду, %: null — сравнивать не с чем */
    public static function delta(float $now, float $before): ?float
    {
        if ($before <= 0) return null;
        return round(($now - $before) / $before * 100, 1);
    }
}
