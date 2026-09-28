<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Reports;

/**
 * Отчёты о продажах за период (как reports в админке ARG FLEX): показатели, график, статусы,
 * лучшие товары / категории / бренды / клиенты, доставка, оплата, источники, экспорт CSV.
 * Смотреть могут все сотрудники (менеджеры тоже) — отчёт ничего не меняет.
 */
final class ReportsController extends BaseController
{
    public function index(): Response
    {
        $period = self::period();
        $report = Reports::build($period);
        $top = Request::get('top') === 'sum' ? 'sum' : 'boxes';

        $actions = '';
        if ($report['totals']['orders_all'] > 0) {
            $actions = '<a class="btn" href="' . e('/admin/reports/export/?' . http_build_query($period['query'] + ['type' => 'orders'])) . '">'
                . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/></svg>'
                . 'Экспорт CSV</a>';
        }
        return $this->render('admin/reports/index', [
            'title'   => 'Отчёты',
            'actions' => $actions,
            'period'  => $period,
            'r'       => $report,
            'top'     => $top,
            'styles'  => ['admin/reports.css'],
            'scripts' => ['admin/reports.js'],
        ]);
    }

    /**
     * CSV за выбранный период: type=orders — заказы по одному в строке (как в ARG FLEX),
     * type=summary — сводка отчёта (показатели, по дням/месяцам, топы, разбивки).
     * UTF-8 с BOM, разделитель «;» — Excel открывает без мастера импорта.
     */
    public function export(): Response
    {
        $period = self::period();
        $type = Request::get('type') === 'summary' ? 'summary' : 'orders';
        $this->log('reports_export', 'report', null, ['type' => $type, 'from' => $period['from'], 'to' => $period['to']]);

        $name = 'tomobuv-' . ($type === 'summary' ? 'report' : 'orders') . '-' . $period['from'] . '_' . $period['to'] . '.csv';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");   // BOM — чтобы Excel открыл UTF-8
        if ($type === 'summary') {
            self::summaryCsv($out, $period, Reports::build($period));
        } else {
            self::ordersCsv($out, $period);
        }
        fclose($out);
        exit;
    }

    /**
     * «Обновить сейчас»: отчёт за период пересчитывается сразу, не дожидаясь 5 минут кэша
     * (например, чтобы увидеть только что оформленный заказ). Период — из полей формы.
     */
    public function refresh(): Response
    {
        $period = Reports::period(Request::post('range'), Request::post('from'), Request::post('to'));
        Reports::forget($period);
        $this->flash('Отчёт пересчитан по текущим заказам.');
        $top = Request::post('top') === 'sum' ? ['top' => 'sum'] : [];
        return Response::redirect('/admin/reports/?' . http_build_query($period['query'] + $top));
    }

    /** Период из ?range= или ?from=&to= */
    private static function period(): array
    {
        return Reports::period(Request::get('range'), Request::get('from'), Request::get('to'));
    }

    /** Заказы периода: пачками по 500 (два запроса на пачку), сразу в поток */
    private static function ordersCsv($out, array $period): void
    {
        self::row($out, ['Номер', 'Дата', 'Статус', 'В выручке', 'Клиент', 'Телефон', 'E-mail', 'Город', 'Доставка', 'Оплата', 'Источник',
            'Ящиков (≈ для старых заказов)', 'Пар', 'Товары, грн', 'Доставка, грн', 'Скидка, грн', 'Итого, грн', 'Позиции']);
        foreach (array_chunk(Reports::orderIds($period), 500) as $chunk) {
            foreach (Reports::ordersChunk($chunk) as $o) {
                self::row($out, [
                    Reports::orderNumber((int) $o['id']),
                    date('d.m.Y H:i', strtotime((string) $o['created_at'])),
                    Reports::STATUSES[$o['status']] ?? $o['status'],
                    in_array($o['status'], Reports::EXCLUDED, true) ? 'нет' : 'да',
                    $o['name'], $o['phone'], $o['email'], $o['city'],
                    Reports::methodLabel($o['shipping_name'], $o['shipping_method'], 'shipping_methods'),
                    Reports::methodLabel($o['payment_name'], $o['payment_method'], 'payment_methods'),
                    Reports::SOURCES[$o['source']] ?? $o['source'],
                    (int) $o['boxes'], (int) $o['pairs'],
                    self::num($o['subtotal']), self::num($o['shipping_cost']), self::num($o['discount']), self::num($o['total']),
                    implode(' | ', $o['items']),
                ]);
            }
            fflush($out);
            flush();
        }
    }

    /** Сводка отчёта — те же цифры, что на экране, блоками с пустой строкой между ними */
    private static function summaryCsv($out, array $p, array $r): void
    {
        $t = $r['totals'];
        self::row($out, ['Отчёт Tomobuv о продажах', $p['key'] === 'custom' ? 'Произвольный период' : $p['label'],
            date('d.m.Y', strtotime($p['from'])) . ' — ' . date('d.m.Y', strtotime($p['to']))]);
        self::row($out, ['Учитываются заказы всех статусов, кроме «Удалён» и «Возврат»']);
        self::row($out, []);
        self::row($out, ['Показатель', 'Значение']);
        foreach ([
            ['Выручка, грн', self::num($t['revenue'])],
            ['Заказов', $t['orders']],
            ['Средний чек, грн', self::num($t['average'])],
            ['Ящиков' . ($t['boxes_guess'] ? ' (≈ оценка для ' . $t['boxes_guess'] . ' пар старых заказов: до 12 пар — ящик, больше — по 8 пар)' : ''), $t['boxes']],
            ['Пар', $t['pairs']],
            ['Покупателей', $t['buyers']],
            ['Новых клиентов (первый заказ в периоде)', $t['new_buyers']],
            ['Повторных заказов, % (' . $t['repeat_orders'] . ' из ' . ($t['repeat_base'] ?? $t['orders']) . ')', self::num($t['repeat_share'], 1)],
            ['Товары, грн', self::num($t['subtotal'])],
            ['Доставка, грн', self::num($t['shipping'])],
            ['Скидки, грн', self::num($t['discount'])],
            ['Удалено и возвратов (не учтено), заказов', $t['excluded']],
        ] as $line) self::row($out, $line);

        self::row($out, []);
        self::row($out, [$p['monthly'] ? 'Месяц' : 'День', 'Заказов', 'Выручка, грн']);
        foreach ($r['series'] as $k => $s) {
            self::row($out, [$p['monthly'] ? Reports::bucketLabel($k, true) : date('d.m.Y', strtotime($k)), $s['n'], self::num($s['sum'])]);
        }

        self::row($out, []);
        self::row($out, ['Статус', 'Заказов', 'Сумма, грн']);
        foreach ($r['statuses'] as $st => $s) self::row($out, [Reports::STATUSES[$st] ?? $st, $s['n'], self::num($s['sum'])]);

        foreach (['top_boxes' => 'Лучшие товары по ящикам', 'top_sum' => 'Лучшие товары по выручке'] as $key => $caption) {
            self::row($out, []);
            self::row($out, [$caption, 'Ящиков', 'Пар', 'Сумма, грн', 'Заказов', 'Ссылка']);
            foreach ($r[$key] as $x) self::row($out, [$x['name'], ($x['guess'] ? '≈' : '') . $x['boxes'], $x['pairs'], self::num($x['sum']), $x['orders'], $x['url'] ? url($x['url']) : 'удалён из каталога']);
        }

        foreach (['categories' => 'Категория', 'brands' => 'Бренд'] as $key => $caption) {
            self::row($out, []);
            self::row($out, [$caption, 'Товаров', 'Ящиков', 'Пар', 'Сумма, грн', $key === 'brands' ? 'Из них удалённых (бренд по названию)' : '']);
            $g = $r[$key];
            foreach ($g['rows'] ?? [] as $x) self::row($out, [$x['name'], $x['products'], $x['boxes'], $x['pairs'], self::num($x['sum']), $key === 'brands' ? $x['by_name'] : '']);
            foreach (['none' => $key === 'brands' ? 'Без бренда' : 'Без категории',
                      'deleted' => $key === 'brands' ? 'Бренд не определён (товар удалён)' : 'Товары удалены из каталога'] as $k => $label) {
                if (!empty($g[$k])) self::row($out, [$label, $g[$k]['products'], $g[$k]['boxes'], $g[$k]['pairs'], self::num($g[$k]['sum'])]);
            }
            if (!empty($g['more'])) self::row($out, ['Ещё ' . $g['more'] . ' с меньшей выручкой — не показаны']);
        }

        self::row($out, []);
        self::row($out, ['Вид обуви (по названию товара или категории)', 'Товаров', 'Ящиков', 'Пар', 'Сумма, грн']);
        foreach ($r['types'] as $x) self::row($out, [$x['name'], $x['products'], $x['boxes'], $x['pairs'], self::num($x['sum'])]);

        self::row($out, []);
        self::row($out, ['Клиент', 'Компания', 'Телефон', 'Город', 'Заказов', 'Ящиков', 'Сумма, грн']);
        foreach ($r['customers'] as $x) self::row($out, [$x['name'], $x['company'], $x['phone'], $x['city'], $x['orders'], $x['boxes'], self::num($x['sum'])]);

        foreach (['shipping' => 'Доставка', 'payment' => 'Оплата', 'sources' => 'Источник'] as $key => $caption) {
            self::row($out, []);
            self::row($out, [$caption, 'Заказов', 'Сумма, грн']);
            foreach ($r[$key] as $x) self::row($out, [$x['label'], $x['n'], self::num($x['sum'])]);
        }

        if (!empty($r['coupons'])) {
            self::row($out, []);
            self::row($out, ['Промокод', 'Заказов', 'Скидка, грн', 'Сумма заказов, грн']);
            foreach ($r['coupons'] as $x) self::row($out, [$x['code'] . ($x['coupon_id'] > 0 ? '' : ' (удалён)'), $x['n'], self::num($x['discount']), self::num($x['sum'])]);
        }
    }

    private static function row($out, array $cells): void
    {
        fputcsv($out, array_map([self::class, 'cell'], $cells), ';', '"', '');
    }

    /** Защита от формул в Excel: строки, начинающиеся с = + - @, экранируются апострофом */
    private static function cell($v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && !is_numeric(str_replace(',', '.', $s)) && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
        return $s;
    }

    private static function num($v, int $dec = 2): string
    {
        return number_format((float) $v, $dec, ',', '');
    }
}
