<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Settings;

/**
 * Отчёты о продажах (админка → «Отчёты»), как экран reports в админке ARG FLEX.
 *
 * Всё считается SQL-агрегатами (GROUP BY) по диапазону orders.created_at — индекс `created`,
 * позиции подтягиваются по индексу order_items.order. Позиции читаются ОДИН раз (rollup(): строка на товар),
 * из этой свёртки в PHP собираются ящики, топы товаров, категории, бренды и виды обуви.
 * Результат по периоду кэшируется на 5 минут (Cache::remember, ключ — даты периода).
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
            $statuses = self::statuses($b);
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
                $out['series'] = self::series($b, $p['from'], $p['to'], $p['monthly']);
            }
            if ($totals['orders'] > 0) {
                $items = self::rollup($b);
                foreach ($items as $x) {
                    $out['totals']['boxes'] += $x['boxes'];
                    $out['totals']['boxes_guess'] += $x['guess'];
                }
                $out['totals'] = self::buyers($b) + $out['totals'];
                $out['top_boxes'] = self::products($items, 'boxes');
                $out['top_sum'] = self::products($items, 'sum');
                $out['categories'] = self::categories($items);
                $out['brands'] = self::brands($items);
                $out['types'] = self::types($items);
                unset($items);
                $out['customers'] = self::customers($b);
                $out['coupons'] = self::coupons($b);
                $out = self::channels($b) + $out;
            }
            if ($p['prev']) {
                $pb = self::bounds($p['prev']['from'], $p['prev']['to']);
                $out['prev'] = self::totals(self::statuses($pb));
                $out['prev']['boxes'] = $out['prev']['orders'] ? self::boxesSum($pb) : 0;
            }
            $out['built_at'] = date('Y-m-d H:i:s');
            $out['build_ms'] = (int) round((microtime(true) - $t0) * 1000);
            return $out;
        });
    }

    /** Заказы периода по статусам: [status => [n, sum, subtotal, shipping, discount, pairs]] — все статусы, включая удалённые */
    private static function statuses(array $b): array
    {
        $rows = App::db()->all('SELECT o.status, COUNT(*) n, SUM(o.total) sum, SUM(o.subtotal) subtotal, SUM(o.shipping_cost) shipping,
                SUM(o.discount) discount, SUM(o.pairs) pairs
            FROM orders o WHERE o.created_at >= ? AND o.created_at < ? GROUP BY o.status', $b);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = [
                'n' => (int) $r['n'], 'sum' => (float) $r['sum'], 'subtotal' => (float) $r['subtotal'], 'shipping' => (float) $r['shipping'],
                'discount' => (float) $r['discount'], 'pairs' => (int) $r['pairs'],
            ];
        }
        // порядок — как в справочнике статусов, неизвестные в конце
        $keys = array_flip(array_keys(self::STATUSES));
        uksort($out, static fn($a, $b) => ($keys[$a] ?? 99) <=> ($keys[$b] ?? 99));
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
    private static function buyers(array $b): array
    {
        $r = App::db()->row('SELECT COUNT(DISTINCT o.customer_id) buyers,
                COUNT(DISTINCT IF(f.first_at >= ?, o.customer_id, NULL)) new_buyers,
                COUNT(*) with_customer,
                SUM(o.created_at > f.first_at) repeat_orders
            FROM orders o
            JOIN (SELECT customer_id, MIN(created_at) first_at FROM orders o WHERE ' . self::COUNTED . ' AND customer_id IS NOT NULL GROUP BY customer_id) f
              ON f.customer_id = o.customer_id
            WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED, [$b[0], $b[0], $b[1]]) ?? [];
        $with = (int) ($r['with_customer'] ?? 0);
        return [
            'buyers'        => (int) ($r['buyers'] ?? 0),
            'new_buyers'    => (int) ($r['new_buyers'] ?? 0),
            'repeat_orders' => (int) ($r['repeat_orders'] ?? 0),
            'repeat_base'   => $with,                        // заказы с клиентом — от них считается доля (без клиента повторность не узнать)
            'repeat_share'  => $with ? round((int) $r['repeat_orders'] / $with * 100, 1) : 0.0,
        ];
    }

    /** Выручка и число заказов по дням/месяцам; все корзины периода, включая пустые */
    private static function series(array $b, string $from, string $to, bool $monthly): array
    {
        $expr = $monthly ? "DATE_FORMAT(o.created_at, '%Y-%m')" : 'DATE(o.created_at)';        // белый список
        $rows = App::db()->all('SELECT ' . $expr . ' k, COUNT(*) n, SUM(o.total) sum FROM orders o
            WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED . ' GROUP BY k', $b);
        $got = [];
        foreach ($rows as $r) $got[(string) $r['k']] = ['sum' => (float) $r['sum'], 'n' => (int) $r['n']];

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
     * Проданные товары периода — строка на товар, один проход по позициям учтённых заказов.
     * Карточки товаров (категория, бренд) — пакетами по 5000 id; id удалённых ещё на старом сайте
     * товаров лежат вне диапазона id каталога — их не ищем.
     * @return list<array{pid:int,name:string,pairs:int,sum:float,orders:int,boxes:int,guess:int,exists:bool,category_id:int,brand_id:int}>
     */
    private static function rollup(array $b): array
    {
        $db = App::db();
        $rows = $db->all('SELECT i.product_id pid, MAX(i.name) name, SUM(i.quantity) pairs, SUM(i.price * i.quantity) sum,
                COUNT(DISTINCT i.order_id) orders,
                SUM(' . self::BOXES . ') boxes, SUM(IF(' . self::KNOWN . ', 0, i.quantity)) guess
            FROM orders o JOIN order_items i ON i.order_id = o.id
            WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED . '
            GROUP BY i.product_id', $b);

        $range = $db->row('SELECT MIN(id) lo, MAX(id) hi FROM products') ?? [];
        $lo = (int) ($range['lo'] ?? 0);
        $hi = (int) ($range['hi'] ?? 0);
        $ids = [];
        foreach ($rows as $r) {
            $id = (int) $r['pid'];
            if ($id > 0 && $id >= $lo && $id <= $hi) $ids[] = $id;
        }
        $cards = [];
        foreach (array_chunk($ids, 5000) as $chunk) {
            [$ph, $vals] = $db->in($chunk);
            foreach ($db->all('SELECT id, category_id, brand_id FROM products WHERE id IN (' . $ph . ')', $vals) as $r) {
                $cards[(int) $r['id']] = $r;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['pid'];
            $c = $cards[$id] ?? null;
            $out[] = [
                'pid'         => $id,
                'name'        => (string) $r['name'],
                'pairs'       => (int) $r['pairs'],
                'sum'         => (float) $r['sum'],
                'orders'      => (int) $r['orders'],
                'boxes'       => (int) $r['boxes'],
                'guess'       => (int) $r['guess'],                     // пар, у которых ящики оценены
                'exists'      => $c !== null,
                'category_id' => $c ? (int) $c['category_id'] : 0,
                'brand_id'    => $c ? (int) $c['brand_id'] : 0,
            ];
        }
        return $out;
    }

    /** Топ товаров по ящикам или по выручке (из свёртки) + данные карточек для ссылок */
    private static function products(array $items, string $by): array
    {
        $other = $by === 'sum' ? 'boxes' : 'sum';                    // белый список
        $main = array_column($items, $by);
        $second = array_column($items, $other);
        $idx = array_keys($items);
        array_multisort($main, SORT_DESC, $second, SORT_DESC, $idx);
        $top = [];
        foreach (array_slice($idx, 0, self::TOP) as $i) $top[] = $items[$i];

        $ids = [];
        foreach ($top as $x) if ($x['exists']) $ids[] = $x['pid'];
        $cards = [];
        if ($ids) {
            $db = App::db();
            [$ph, $vals] = $db->in($ids);
            $cards = $db->keyed('SELECT p.id, p.name, p.url, p.status, p.sku, p.box_qty, c.name category
                FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id IN (' . $ph . ')', $vals);
        }
        $out = [];
        foreach ($top as $x) {
            $p = $cards[$x['pid']] ?? null;
            $out[] = [
                'id'       => $x['pid'],
                'name'     => $p ? (string) $p['name'] : self::cleanName($x['name']),
                'exists'   => $p !== null,
                'url'      => $p ? '/product/' . $p['url'] . '/' : '',
                'hidden'   => $p && !(int) $p['status'],
                'sizes'    => $p ? (string) $p['sku'] : '',
                'box_qty'  => $p ? (int) $p['box_qty'] : 0,
                'category' => $p ? (string) ($p['category'] ?? '') : '',
                'boxes'    => $x['boxes'],
                'pairs'    => $x['pairs'],
                'sum'      => $x['sum'],
                'orders'   => $x['orders'],
                'guess'    => $x['guess'] > 0,
            ];
        }
        return $out;
    }

    /** Пустая строка разбивки */
    private static function agg(): array
    {
        return ['boxes' => 0, 'pairs' => 0, 'sum' => 0.0, 'products' => 0, 'by_name' => 0];
    }

    private static function add(array &$row, array $x, bool $byName = false): void
    {
        $row['boxes'] += $x['boxes'];
        $row['pairs'] += $x['pairs'];
        $row['sum'] += $x['sum'];
        $row['products']++;
        if ($byName) $row['by_name']++;
    }

    /**
     * Категории проданных товаров (products.category_id).
     * @return array{rows:list<array>,none:?array,deleted:?array,total:float,more:int}
     */
    private static function categories(array $items): array
    {
        $g = [];
        $none = $deleted = null;
        $total = 0.0;
        foreach ($items as $x) {
            $total += $x['sum'];
            if (!$x['exists']) { $deleted ??= self::agg(); self::add($deleted, $x); continue; }
            if ($x['category_id'] <= 0) { $none ??= self::agg(); self::add($none, $x); continue; }
            $g[$x['category_id']] ??= self::agg();
            self::add($g[$x['category_id']], $x);
        }
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
            $rows[] = $row + [
                'id'     => (int) $id,
                'name'   => $n ? (string) $n['name'] : 'Категория #' . $id . ' (удалена)',
                'parent' => (string) ($n['parent'] ?? ''),
                'url'    => $n ? Catalog::categoryUrl($n) : '',
                'admin'  => $n ? '/admin/categories/' . (int) $id . '/' : '',
                'hidden' => $n ? !(int) $n['status'] : true,
            ];
        }
        return ['rows' => $rows, 'none' => $none, 'deleted' => $deleted, 'total' => $total, 'more' => $more];
    }

    /**
     * Бренды проданных товаров: из каталога (products.brand_id), а у удалённых товаров — по названию позиции.
     * @return array{rows:list<array>,none:?array,deleted:?array,total:float,more:int,by_name:int}
     */
    private static function brands(array $items): array
    {
        $map = self::brandMap();
        $g = [];
        $none = $deleted = null;
        $total = 0.0;
        $byName = 0;
        foreach ($items as $x) {
            $total += $x['sum'];
            if ($x['exists']) {
                if ($x['brand_id'] <= 0) { $none ??= self::agg(); self::add($none, $x); continue; }
                $g[$x['brand_id']] ??= self::agg();
                self::add($g[$x['brand_id']], $x);
                continue;
            }
            $id = self::brandByName($x['name'], $map);
            if ($id === null) { $deleted ??= self::agg(); self::add($deleted, $x); continue; }
            $g[$id] ??= self::agg();
            self::add($g[$id], $x, true);
            $byName++;
        }
        uasort($g, static fn($a, $b) => $b['sum'] <=> $a['sum']);
        $more = max(0, count($g) - self::TOP_GROUPS);
        $g = array_slice($g, 0, self::TOP_GROUPS, true);

        $all = Catalog::brands();
        $rows = [];
        foreach ($g as $id => $row) {
            $n = $all[$id] ?? null;
            $rows[] = $row + [
                'id'     => (int) $id,
                'name'   => $n ? (string) $n['name'] : 'Бренд #' . $id . ' (удалён)',
                'parent' => '',
                'url'    => $n ? Catalog::brandUrl($n) : '',
                'admin'  => $n ? '/admin/brands/' . (int) $id . '/' : '',
                'hidden' => $n ? (bool) (int) $n['hidden'] : true,
            ];
        }
        return ['rows' => $rows, 'none' => $none, 'deleted' => $deleted, 'total' => $total, 'more' => $more, 'by_name' => $byName];
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
        $cut = strpos($s, '(');
        $s = substr($s, 0, $cut === false ? 160 : min($cut, 160));      // бренд — в начале названия
        $s = mb_strtolower(strtr($s, ['•' => ' ', '.' => ' ', '-' => ' ', '_' => ' ', '/' => ' ', ',' => ' ', "'" => ' ', '"' => ' ', "\t" => ' ']));
        $out = [];
        foreach (explode(' ', $s) as $w) {
            if ($w === '') continue;
            $out[] = $w;
            if (count($out) >= $limit) break;
        }
        return $out;
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
     * Виды обуви по началу названия позиции — работает и для товаров, удалённых из каталога.
     * Новые товары часто названы одним артикулом («65186E») — для них вид берётся из названия категории каталога.
     */
    private static function types(array $items): array
    {
        $g = $memo = $typed = $need = [];
        foreach ($items as $i => $x) {
            $head = implode(' ', array_slice(explode(' ', trim($x['name']), 3), 0, 2));   // вид — в первых двух словах
            $typed[$i] = $memo[$head] ??= self::typeOf($head);
            if ($typed[$i] === '' && $x['exists'] && $x['category_id'] > 0) $need[$x['category_id']][] = $i;
        }
        if ($need) {
            $db = App::db();
            [$ph, $vals] = $db->in(array_keys($need));
            foreach ($db->pairs('SELECT id, name FROM categories WHERE id IN (' . $ph . ')', $vals) as $cid => $cname) {
                $t = self::typeOf((string) $cname);
                if ($t !== '') foreach ($need[(int) $cid] ?? [] as $i) $typed[$i] = $t;
            }
        }
        foreach ($items as $i => $x) {
            $g[$typed[$i]] ??= self::agg();
            self::add($g[$typed[$i]], $x);
        }
        uasort($g, static fn($a, $b) => $b['sum'] <=> $a['sum']);
        $rows = [];
        $rest = null;
        foreach ($g as $name => $row) {
            if (count($rows) < self::TOP_GROUPS && $name !== '') { $rows[] = $row + ['name' => $name]; continue; }
            $rest ??= self::agg() + ['name' => 'Другие и вид не определён'];
            foreach (['boxes', 'pairs', 'sum', 'products'] as $k) $rest[$k] += $row[$k];
        }
        if ($rest) $rows[] = $rest + ['rest' => true];
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

    /** Лучшие клиенты по сумме заказов; ящики — вторым запросом только по этим клиентам */
    private static function customers(array $b): array
    {
        $db = App::db();
        $rows = $db->all('SELECT o.customer_id, COUNT(*) orders, SUM(o.total) sum, MAX(o.created_at) last_at, MAX(o.name) order_name, MAX(o.phone) order_phone
            FROM orders o
            WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED . ' AND o.customer_id IS NOT NULL
            GROUP BY o.customer_id ORDER BY sum DESC, orders DESC LIMIT ' . self::TOP, $b);
        $ids = array_map(static fn($r) => (int) $r['customer_id'], $rows);
        $people = $boxes = [];
        if ($ids) {
            [$ph, $vals] = $db->in($ids);
            $people = $db->keyed('SELECT id, name, company, phone, city FROM customers WHERE id IN (' . $ph . ')', $vals);
            $boxes = $db->pairs('SELECT o.customer_id, SUM(' . self::BOXES . ') FROM orders o JOIN order_items i ON i.order_id = o.id
                WHERE o.created_at >= ? AND o.created_at < ? AND ' . self::COUNTED . ' AND o.customer_id IN (' . $ph . ')
                GROUP BY o.customer_id', array_merge($b, $vals));
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['customer_id'];
            $c = $people[$id] ?? null;
            $name = trim((string) ($c['name'] ?? '')) ?: trim((string) $r['order_name']) ?: 'Клиент #' . $id;
            $out[] = [
                'id'      => $id,
                'exists'  => $c !== null,
                'name'    => $name,
                'company' => trim((string) ($c['company'] ?? '')),
                'phone'   => (string) (($c['phone'] ?? '') ?: $r['order_phone']),
                'city'    => trim((string) ($c['city'] ?? '')),
                'orders'  => (int) $r['orders'],
                'boxes'   => (int) ($boxes[$id] ?? 0),
                'sum'     => (float) $r['sum'],
                'last_at' => (string) $r['last_at'],
            ];
        }
        return $out;
    }

    /**
     * Способы доставки и оплаты (название из заказа, иначе — из справочника настроек по коду) и источники заказов —
     * один проход по заказам периода.
     * @return array{shipping:list<array>,payment:list<array>,sources:list<array>}
     */
    private static function channels(array $b): array
    {
        $rows = App::db()->all("SELECT TRIM(IFNULL(o.shipping_name, '')) sn, IFNULL(o.shipping_method, '') sc,
                TRIM(IFNULL(o.payment_name, '')) pn, IFNULL(o.payment_method, '') pc, o.source, COUNT(*) n, SUM(o.total) sum
            FROM orders o WHERE o.created_at >= ? AND o.created_at < ? AND " . self::COUNTED . ' GROUP BY sn, sc, pn, pc, o.source', $b);
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
