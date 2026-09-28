<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\Image;
use App\Core\Lang;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Str;
use App\Core\View;
use App\Services\Coupons;

/**
 * Заказы: список с фильтрами, карточка (редактирование состава, статусы, комментарии),
 * ручной (телефонный) заказ, накладная для печати, экспорт CSV.
 * В order_items.quantity — пары; ящики = ceil(пары / box_qty).
 */
final class OrdersController extends BaseController
{
    public const STATUSES = [
        'new' => 'Новый', 'processing' => 'В обработке', 'paid' => 'Оплачен', 'shipped' => 'Отправлен',
        'completed' => 'Выполнен', 'refunded' => 'Возврат', 'deleted' => 'Удалён',
    ];
    /** Подписи вкладок списка */
    public const TABS = [
        'new' => 'Новые', 'processing' => 'В обработке', 'paid' => 'Оплачены', 'shipped' => 'Отправлены',
        'completed' => 'Выполнены', 'refunded' => 'Возвраты', 'deleted' => 'Удалённые',
    ];
    public const SOURCES = [
        'site' => 'Сайт', 'quickorder' => 'В 1 клик', 'callback' => 'Звонок', 'admin' => 'Менеджер', 'webasyst' => 'Старый сайт',
    ];
    /** Статусы, которые считаются покупкой (сумма покупок клиента, как «оплаченные» в Webasyst) */
    public const PAID = ['paid', 'shipped', 'completed'];
    /** Отменённые заказы (покупателю «Отменён» / «Возврат», в отчётах не учитываются): промокод возвращается в лимит */
    public const CANCELLED = ['deleted', 'refunded'];
    /** Области Украины: коды регионов Webasyst → название (старые заказы хранят код) */
    public const REGIONS = [
        '01' => 'АР Крым', '02' => 'Винницкая обл.', '03' => 'Волынская обл.', '04' => 'Днепропетровская обл.', '05' => 'Донецкая обл.',
        '06' => 'Житомирская обл.', '07' => 'Закарпатская обл.', '08' => 'Запорожская обл.', '09' => 'Ивано-Франковская обл.',
        '10' => 'Киевская обл.', '11' => 'г. Киев', '12' => 'Кировоградская обл.', '13' => 'Луганская обл.', '14' => 'Львовская обл.',
        '15' => 'Николаевская обл.', '16' => 'Одесская обл.', '17' => 'Полтавская обл.', '18' => 'Ровенская обл.', '19' => 'Сумская обл.',
        '20' => 'Тернопольская обл.', '21' => 'Харьковская обл.', '22' => 'Херсонская обл.', '23' => 'Хмельницкая обл.',
        '24' => 'Черкасская обл.', '25' => 'Черниговская обл.', '26' => 'Черновицкая обл.', '27' => 'г. Севастополь',
    ];
    /** Полезные поля из orders.params (старые поля Webasyst и метки нового сайта) → подпись */
    private const LEGACY = [
        'shipping_name' => 'Доставка', 'shipping_address.city' => 'Город', 'shipping_address.region' => 'Область',
        'shipping_address.oblast' => 'Область', 'shipping_address.otdelenie-pocht' => 'Отделение', 'shipping_address.street' => 'Улица, дом',
        'shipping_address.zip' => 'Индекс', 'shipping_params_1' => 'Отделение / адрес (форма доставки)', 'shipping_est_delivery' => 'Срок доставки',
        'departure_datetime' => 'Дата отправки', 'payment_name' => 'Оплата', 'coupon_id' => 'Купон (№ на старом сайте)',
        'quickorder_product' => 'Купить в 1 клик', 'quickorder_cart' => 'Купить в 1 клик (корзина)', 'request_id' => 'По заявке №',
        'landing' => 'Страница входа', 'referer_host' => 'Пришёл с сайта',
        'keyword' => 'Поисковый запрос', 'utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign',
        'utm_term' => 'utm_term', 'utm_content' => 'utm_content', 'ip' => 'IP',
    ];
    /** Служебные поля адреса Webasyst, которые не показываем */
    private const LEGACY_SKIP = ['shipping_address.country', 'shipping_address.lat', 'shipping_address.lng'];
    private const SORTS = [
        'new' => 'o.created_at DESC, o.id DESC',
        'old' => 'o.created_at ASC, o.id ASC',
        'sum' => 'o.total DESC, o.id DESC',
    ];
    private const PER_PAGE = 50;
    /**
     * «Новые заказы» — одна логика для плитки на главной, счётчика в меню и списка по ссылке из них:
     * статус «Новый» и оформлен за последние FRESH_DAYS дней, считая сегодняшний (с 00:00 — как фильтр «с» в списке).
     * Более старые «новые» (в основном необработанные заказы со старого сайта) показываются отдельной подсказкой.
     */
    public const FRESH_DAYS = 30;
    /** Больше заказов одной массовой сменой статуса не меняем — пусть сузят фильтр */
    private const BULK_MAX = 5000;

    // ============================================================ «новые заказы»

    /** Первый день окна «новых» (Y-m-d) */
    public static function freshFrom(): string
    {
        return date('Y-m-d', strtotime('-' . (self::FRESH_DAYS - 1) . ' days'));
    }

    /** Новые заказы: fresh — за окно FRESH_DAYS, stale — старше; один запрос по индексу status (status, created_at) */
    public static function newCounts(): array
    {
        $from = self::freshFrom() . ' 00:00:00';
        $r = App::db()->row("SELECT COALESCE(SUM(created_at >= ?), 0) fresh, COALESCE(SUM(created_at < ?), 0) stale FROM orders WHERE status = 'new'",
            [$from, $from]) ?? [];
        return ['fresh' => (int) ($r['fresh'] ?? 0), 'stale' => (int) ($r['stale'] ?? 0)];
    }

    /** Список «новых» за окно — ровно те, что в плитке и в меню */
    public static function freshUrl(): string
    {
        return '/admin/orders/?' . http_build_query(['status' => 'new', 'from' => self::freshFrom()]);
    }

    /** Список «новых» старше окна — для массового закрытия */
    public static function staleUrl(): string
    {
        return '/admin/orders/?' . http_build_query(['status' => 'new', 'to' => date('Y-m-d', strtotime(self::freshFrom() . ' -1 day')), 'sort' => 'old']);
    }

    // ============================================================ список

    public function index(): Response
    {
        $db = App::db();
        $f = self::filters();
        [$where, $params] = self::where($f, false);

        // счётчики вкладок с учётом периода и поиска (индекс status)
        $counts = array_map('intval', $db->pairs('SELECT o.status, COUNT(*) FROM orders o'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY o.status', $params));
        $all = array_sum($counts) - ($counts['deleted'] ?? 0);
        $total = $f['status'] === '' ? $all : ($counts[$f['status']] ?? 0);

        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        [$where, $params] = self::where($f);
        $ids = $total ? array_map('intval', $db->col('SELECT o.id FROM orders o WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . self::SORTS[$f['sort']] . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, $params)) : [];
        $orders = self::rowsByIds($ids);

        $qs = http_build_query(array_filter(['status' => $f['status'], 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to'],
            'source' => $f['source'], 'lang' => $f['lang'], 'sort' => $f['sort'] !== 'new' ? $f['sort'] : ''], static fn($v) => $v !== ''));
        return $this->render('admin/orders/index', [
            'title' => 'Заказы', 'f' => $f, 'counts' => $counts, 'all' => $all, 'total' => $total, 'orders' => $orders, 'pg' => $pg, 'qs' => $qs,
            'newCounts' => $f['status'] === 'new' ? self::newCounts() : null, 'bulkMax' => self::BULK_MAX,
            'shipping' => self::methods('shipping_methods'), 'payment' => self::methods('payment_methods'),
            'styles' => ['admin/sales.css'], 'scripts' => ['admin/sales.js'],
            'actions' => '<a class="btn btn-sm" href="/admin/orders/export.csv/' . ($qs ? '?' . e($qs) : '') . '">' . icon('doc') . ' Экспорт CSV</a>'
                . '<a class="btn btn-sm btn-p" href="/admin/orders/new/">' . icon('plus') . ' Новый заказ</a>',
        ]);
    }

    /** Фильтры списка из GET (всё — через белые списки) */
    private static function filters(): array
    {
        $status = Request::get('status');
        $source = Request::get('source');
        $lang = Request::get('lang');
        $sort = Request::get('sort', 'new');
        return [
            'status' => isset(self::STATUSES[$status]) ? $status : '',
            'source' => isset(self::SOURCES[$source]) ? $source : '',
            'lang'   => isset(Lang::LANGS[$lang]) ? $lang : '',
            'sort'   => isset(self::SORTS[$sort]) ? $sort : 'new',
            'q'      => mb_substr(Request::get('q'), 0, 100),
            'from'   => self::date(Request::get('from')),
            'to'     => self::date(Request::get('to')),
        ];
    }

    private static function date(string $s): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) ? $s : '';
    }

    /** Условия WHERE по фильтрам: [[условия], [параметры]] */
    private static function where(array $f, bool $withStatus = true): array
    {
        $w = []; $p = [];
        if ($withStatus) {
            if ($f['status'] !== '') { $w[] = 'o.status = ?'; $p[] = $f['status']; }
            else $w[] = "o.status <> 'deleted'";
        }
        if ($f['from'] !== '') { $w[] = 'o.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if ($f['to'] !== '') { $w[] = 'o.created_at < ?'; $p[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'; }
        if ($f['source'] !== '') { $w[] = 'o.source = ?'; $p[] = $f['source']; }
        if ($f['lang'] !== '') { $w[] = 'o.lang = ?'; $p[] = $f['lang']; }
        $q = trim($f['q']);
        if ($q !== '') {
            $digits = (string) preg_replace('/\D+/', '', $q);
            if (preg_match('/^#?\s*\d+$/u', $q)) {
                // номер заказа: #1003634, 1003634 или 3634; длинное число — ещё и часть телефона
                $ids = [(int) $digits];
                if (strlen($digits) > 3 && str_starts_with($digits, '100')) $ids[] = (int) substr($digits, 3);
                $or = ['o.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
                array_push($p, ...$ids);
                if ($q[0] !== '#' && strlen($digits) >= 5) { $or[] = 'o.phone LIKE ?'; $p[] = '%' . $digits . '%'; }
                $w[] = '(' . implode(' OR ', $or) . ')';
            } elseif (strlen($digits) >= 5 && preg_match('/^[\d\s()+\-.]+$/', $q)) {
                $w[] = 'o.phone LIKE ?'; $p[] = '%' . $digits . '%';
            } else {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $w[] = '(o.name LIKE ? OR o.email LIKE ?)'; $p[] = $like; $p[] = $like;
            }
        }
        return [$w, $p];
    }

    /** Строки заказов по списку id с сохранением порядка ($withParams — ещё orders.params, для экспорта) */
    private static function rowsByIds(array $ids, bool $withParams = false): array
    {
        if (!$ids) return [];
        [$ph, $vals] = App::db()->in($ids);
        $rows = App::db()->keyed('SELECT id, customer_id, status, total, subtotal, shipping_cost, discount, boxes, pairs, name, phone, email,
            shipping_method, shipping_name, city, region, address, payment_method, payment_name, comment, manager_comment, source, lang, created_at'
            . ($withParams ? ', params' : '') . ' FROM orders WHERE id IN (' . $ph . ')', $vals);
        $out = [];
        foreach ($ids as $id) if (isset($rows[$id])) $out[] = $rows[$id];
        return $out;
    }

    // ============================================================ карточка

    public function show(string $id): Response
    {
        $order = self::find($id);
        if (!$order) return $this->missing();
        if (Request::isPost()) return $this->saveInfo($order);

        $db = App::db();
        $oid = (int) $order['id'];
        $items = self::itemsView($db->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$oid]));
        $log = $db->all('SELECT l.*, c.name AS user_name, c.role AS user_role FROM order_log l
            LEFT JOIN customers c ON c.id = l.user_id WHERE l.order_id = ? ORDER BY l.created_at DESC, l.id DESC', [$oid]);
        $customer = $order['customer_id'] ? $db->row('SELECT id, name, phone, email, city, orders_count, total_spent, role, status, created_at
            FROM customers WHERE id = ?', [(int) $order['customer_id']]) : null;
        $params = json_decode((string) ($order['params'] ?? ''), true);
        $params = is_array($params) ? $params : [];
        $num = self::number($oid);
        // промокод: применённый (coupon_usages) или отменённый вместе с заказом (остался только в params)
        $coupon = Coupons::forOrder($oid);
        if (!$coupon && $order['source'] !== 'webasyst' && (int) ($params['coupon_id'] ?? 0) > 0 && in_array($order['status'], self::CANCELLED, true)) {
            $coupon = ['coupon_id' => (int) $params['coupon_id'], 'code' => (string) ($params['coupon_code'] ?? ''), 'discount' => null, 'released' => true];
        }

        return $this->render('admin/orders/show', [
            'title' => 'Заказ ' . $num, 'order' => $order, 'num' => $num, 'items' => $items, 'log' => $log, 'customer' => $customer, 'coupon' => $coupon,
            'legacy' => self::legacy($params, $order), 'addr' => self::address($order, $params), 'shipping' => self::methods('shipping_methods'), 'payment' => self::methods('payment_methods'),
            'styles' => ['admin/sales.css'], 'scripts' => ['admin/sales.js'],
            'back' => ['/admin/orders/', 'Все заказы'],
            'actions' => '<a class="btn btn-sm" href="/admin/orders/' . $oid . '/print/" target="_blank" rel="noopener">' . icon('doc') . ' Накладная</a>',
        ]);
    }

    /** POST /admin/orders/{id}/ — контакты, доставка, оплата */
    private function saveInfo(array $order): Response
    {
        $oid = (int) $order['id'];
        $ship = self::methods('shipping_methods');
        $pay = self::methods('payment_methods');
        $phoneRaw = Request::post('phone');
        $phone = Str::phone($phoneRaw) ?: mb_substr((string) preg_replace('/[^\d+]/', '', $phoneRaw), 0, 32);
        $emailRaw = Request::post('email');
        $email = Str::email($emailRaw);
        if ($emailRaw !== '' && $email === '') { $this->flash('Неверный e-mail.', true); return Response::redirect('/admin/orders/' . $oid . '/'); }
        $sm = Request::post('shipping_method');
        $pm = Request::post('payment_method');
        $data = [
            'name'  => mb_substr(Request::post('name'), 0, 190),
            'phone' => $phone,
            'email' => $email ?: null,
            'city'  => mb_substr(Request::post('city'), 0, 190) ?: null,
            'address' => mb_substr(Request::post('address'), 0, 500) ?: null,
            'comment' => mb_substr(Request::post('comment'), 0, 5000) ?: null,
        ];
        // способ доставки/оплаты — только из справочника (или оставить как был)
        if ($sm !== (string) $order['shipping_method'] && isset($ship[$sm])) { $data['shipping_method'] = $sm; $data['shipping_name'] = $ship[$sm]; }
        if ($pm !== (string) $order['payment_method'] && isset($pay[$pm])) { $data['payment_method'] = $pm; $data['payment_name'] = $pay[$pm]; }

        $labels = ['name' => 'имя', 'phone' => 'телефон', 'email' => 'e-mail', 'city' => 'город', 'address' => 'адрес',
            'comment' => 'комментарий клиента', 'shipping_method' => 'доставка', 'payment_method' => 'оплата'];
        $changed = [];
        foreach ($labels as $k => $label) {
            if (array_key_exists($k, $data) && (string) ($data[$k] ?? '') !== (string) ($order[$k] ?? '')) $changed[] = $label;
        }
        if (!$changed) { $this->flash('Изменений нет.'); return Response::redirect('/admin/orders/' . $oid . '/'); }
        $data['updated_at'] = date('Y-m-d H:i:s');
        App::db()->update('orders', $data, 'id = ?', [$oid]);
        App::db()->insert('order_log', ['order_id' => $oid, 'user_id' => Auth::id() ?: null, 'status_from' => $order['status'],
            'status_to' => $order['status'], 'text' => 'Изменены данные заказа: ' . implode(', ', $changed)]);
        $this->log('order_edit', 'order', $oid, $changed);
        $this->flash('Данные заказа сохранены.');
        return Response::redirect('/admin/orders/' . $oid . '/');
    }

    /** POST /admin/orders/{id}/status/ — смена статуса (+ комментарий, + письмо клиенту). AJAX или форма. */
    public function status(string $id): Response
    {
        $order = self::find($id);
        if (!$order) return $this->fail('Заказ не найден', 404);
        $oid = (int) $order['id'];
        $to = Request::post('status');
        $text = mb_substr(Request::post('comment'), 0, 2000);
        if (!isset(self::STATUSES[$to])) return $this->fail('Неизвестный статус');
        $from = (string) $order['status'];
        if ($to === $from && $text === '') return $this->done($oid, 'Статус не изменился.', ['status' => $to]);

        $db = App::db();
        // статус, промокод (вернуть в лимит при отмене) и запись в историю — одной транзакцией
        [$logId, $couponNote] = $db->transaction(static function ($db) use ($order, $oid, $from, $to, $text) {
            $note = '';
            if ($to !== $from) {
                $db->update('orders', ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$oid]);
                $note = self::couponOnStatus($order, $from, $to);
            }
            $logText = trim($text . ($note !== '' ? ($text !== '' ? "\n" : '') . $note : ''));
            $logId = $db->insert('order_log', ['order_id' => $oid, 'user_id' => Auth::id() ?: null, 'status_from' => $from, 'status_to' => $to,
                'text' => $logText !== '' ? $logText : null]);
            return [$logId, $note];
        });

        // письмо — после сохранения (ошибка почты не откатывает смену статуса)
        $notified = false;
        if (Request::post('notify') === '1' && $to !== $from) {
            $notified = self::notify($order, $to, $text);
            if ($notified) {
                $db->query("UPDATE order_log SET text = CONCAT_WS('\n', NULLIF(text, ''), ?) WHERE id = ?",
                    ['Клиент уведомлён по e-mail (' . $order['email'] . ').', $logId]);
            }
        }
        if ($to !== $from) {
            self::recalcCustomer((int) ($order['customer_id'] ?? 0));
            Cache::forget('admin.tally');   // счётчики в меню админки
            $this->log('order_status', 'order', $oid, ['from' => $from, 'to' => $to]);
        }
        $msg = $to !== $from ? 'Статус заказа: «' . self::STATUSES[$to] . '»' . ($notified ? ', клиенту отправлено письмо.' : '.') : 'Комментарий добавлен.';
        if (Request::post('notify') === '1' && $to !== $from && !$notified) $msg .= ' Письмо не отправлено (нет e-mail или ошибка почты).';
        if ($couponNote !== '') $msg .= ' ' . $couponNote;
        return $this->done($oid, $msg, ['status' => $to, 'label' => self::STATUSES[$to]]);
    }

    /**
     * Промокод при смене статуса (вызывается внутри транзакции смены статуса):
     * отмена/возврат — Coupons::release (использование удаляется, лимит освобождается);
     * восстановление отменённого заказа — промокод снова учитывается (Coupons::apply), если ещё действует.
     * Возвращает текст для истории заказа ('' — промокода нет).
     */
    private static function couponOnStatus(array $order, string $from, string $to): string
    {
        $oid = (int) $order['id'];
        $wasOff = in_array($from, self::CANCELLED, true);
        $isOff = in_array($to, self::CANCELLED, true);
        if ($isOff && !$wasOff) {
            $c = Coupons::forOrder($oid);
            return $c && Coupons::release($oid) ? 'Промокод ' . $c['code'] . ' возвращён в лимит использований.' : '';
        }
        // у заказов со старого сайта coupon_id — номер купона Webasyst, не из таблицы coupons
        if ($wasOff && !$isOff && ($order['source'] ?? '') !== 'webasyst') {
            $p = json_decode((string) ($order['params'] ?? ''), true);
            $cid = is_array($p) ? (int) ($p['coupon_id'] ?? 0) : 0;
            // промокод применяется при оформлении только со скидкой (Orders::create) — без скидки восстанавливать нечего
            if ($cid <= 0 || (float) $order['discount'] <= 0 || Coupons::forOrder($oid)) return '';
            $code = is_array($p) && !empty($p['coupon_code']) ? (string) $p['coupon_code'] : '#' . $cid;
            $r = Coupons::apply($oid, $cid, (float) $order['discount'], (int) $order['customer_id'] ?: null, (string) $order['phone']);
            return $r['ok'] ? 'Промокод ' . $code . ' снова учтён в использованиях.'
                : 'Промокод ' . $code . ' не восстановлен: ' . rtrim((string) $r['error'], '.') . '. Скидка в заказе осталась — проверьте сумму.';
        }
        return '';
    }

    /**
     * POST /admin/orders/bulk/ — массовая смена статуса: отмеченные заказы (ids[]) или все найденные по фильтрам списка (all=1;
     * фильтры — в адресе формы, как у самого списка). Каждый заказ — как при одиночной смене: промокод при отмене возвращается
     * в лимит, запись в историю, пересчёт клиента. Письма клиентам не отправляются.
     */
    public function bulk(): Response
    {
        $f = self::filters();
        $qs = http_build_query(array_filter(['status' => $f['status'], 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to'],
            'source' => $f['source'], 'lang' => $f['lang'], 'sort' => $f['sort'] !== 'new' ? $f['sort'] : ''], static fn($v) => $v !== ''));
        $back = '/admin/orders/' . ($qs !== '' ? '?' . $qs : '');
        $to = Request::post('to_status');
        if (!isset(self::STATUSES[$to])) { $this->flash('Выберите новый статус.', true); return Response::redirect($back); }
        $db = App::db();
        if (Request::post('all') === '1') {
            [$where, $params] = self::where($f);
            $ids = array_map('intval', $db->col('SELECT o.id FROM orders o WHERE ' . implode(' AND ', $where)
                . ' ORDER BY o.id LIMIT ' . (self::BULK_MAX + 1), $params));
        } else {
            $raw = $_POST['ids'] ?? [];
            $ids = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : []), static fn($i) => $i > 0)));
        }
        if (!$ids) { $this->flash(Request::post('all') === '1' ? 'По условиям списка заказов не найдено.' : 'Отметьте заказы галочками.', true); return Response::redirect($back); }
        if (count($ids) > self::BULK_MAX) {
            $this->flash('За один раз можно изменить не больше ' . number_format(self::BULK_MAX, 0, '', ' ') . ' заказов — сузьте фильтр.', true);
            return Response::redirect($back);
        }

        $changed = 0;
        $customers = [];
        $now = date('Y-m-d H:i:s');
        $uid = Auth::id() ?: null;
        $toOff = in_array($to, self::CANCELLED, true);
        foreach (array_chunk($ids, 500) as $chunk) {
            // пачка — одной транзакцией и тремя запросами; FOR UPDATE — параллельная смена статуса тех же заказов дождётся
            $db->transaction(static function ($db) use ($chunk, $to, $toOff, $now, $uid, &$changed, &$customers) {
                [$ph, $vals] = $db->in($chunk);
                $orders = $db->all('SELECT id, customer_id, status, source, discount, phone, params FROM orders
                    WHERE id IN (' . $ph . ') AND status <> ? FOR UPDATE', array_merge($vals, [$to]));
                if (!$orders) return;
                [$ph, $vals] = $db->in(array_map(static fn($o) => (int) $o['id'], $orders));
                $db->query('UPDATE orders SET status = ?, updated_at = ? WHERE id IN (' . $ph . ')', array_merge([$to, $now], $vals));
                // промокод — как при одиночной смене (couponOnStatus), но только у заказов, где он может быть:
                // отмена — у кого есть применение в coupon_usages; выход из отмены — у заказов нового сайта со скидкой
                $used = $toOff ? array_flip(array_map('intval', $db->col('SELECT order_id FROM coupon_usages WHERE order_id IN (' . $ph . ')', $vals))) : [];
                $log = [];
                foreach ($orders as $o) {
                    $from = (string) $o['status'];
                    $note = isset($used[(int) $o['id']]) || (!$toOff && in_array($from, self::CANCELLED, true) && $o['source'] !== 'webasyst' && (float) $o['discount'] > 0)
                        ? self::couponOnStatus($o, $from, $to) : '';
                    $log[] = ['order_id' => (int) $o['id'], 'user_id' => $uid, 'status_from' => $from, 'status_to' => $to,
                        'text' => 'Массовая смена статуса в списке заказов' . ($note !== '' ? "\n" . $note : '')];
                    if ((int) $o['customer_id'] > 0) $customers[(int) $o['customer_id']] = true;
                }
                $db->insertMany('order_log', $log);
                $changed += count($orders);
            });
        }
        self::recalcCustomers(array_keys($customers));
        if ($changed) {
            Cache::forget('admin.tally');   // счётчики в меню админки
            $this->log('order_bulk_status', 'order', null, ['to' => $to, 'count' => $changed, 'ids' => count($ids) <= 50 ? $ids : count($ids)]);
        }
        $skipped = count($ids) - $changed;
        $this->flash($changed
            ? 'Статус «' . self::STATUSES[$to] . '»: ' . $changed . ' ' . plural($changed, 'заказ', 'заказа', 'заказов')
                . ($skipped ? ' (ещё ' . $skipped . ' уже были в этом статусе или не найдены)' : '') . '.'
            : 'Ничего не изменилось: отмеченные заказы уже в статусе «' . self::STATUSES[$to] . '».');
        return Response::redirect($back);
    }

    /** Письмо клиенту о смене статуса — на языке, на котором оформлен заказ (orders.lang) */
    private static function notify(array $order, string $status, string $comment): bool
    {
        $email = Str::email((string) ($order['email'] ?? ''));
        if ($email === '') return false;
        $num = self::number((int) $order['id']);
        $lang = (string) ($order['lang'] ?? 'ru');
        [$subject, $html] = self::inLang($lang, static function () use ($order, $num, $status, $comment, $lang) {
            $store = (string) Settings::get('store_name', 'Tomobuv');
            $statusName = t($status === 'deleted' ? 'Отменён' : self::STATUSES[$status]);   // покупателю — как в кабинете
            $html = View::render('admin/orders/email', ['order' => $order, 'num' => $num, 'statusName' => $statusName, 'comment' => $comment,
                'store' => $store, 'lang' => $lang,
                'orderUrl' => url(Lang::path(\App\Services\Orders::successUrl((int) $order['id']), $lang))], null);
            return [t('Заказ {num}: {status}', ['num' => $num, 'status' => $statusName]) . ' — ' . $store, $html];
        });
        $ok = self::mail($email, $subject, $html, 'order' . (int) $order['id'] . '-status');
        if (!$ok) Log::error('Не отправлено письмо о статусе заказа ' . $num . ' на ' . $email . ': ' . Mailer::$lastError);
        return $ok;
    }

    /**
     * Письмо клиенту из админки. На локальной машине (env=dev без mail.dev_send) — как письма заказов:
     * не уходит адресату, а сохраняется в storage/logs/mail/.
     */
    public static function mail(string $to, string $subject, string $html, string $kind): bool
    {
        if (App::config('env') === 'dev' && !App::config('mail.dev_send', false)) {
            $dir = STORAGE . '/logs/mail';
            @mkdir($dir, 0775, true);
            $file = date('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]+/i', '-', $kind) . '.html';
            $ok = @file_put_contents($dir . '/' . $file, $html) !== false;
            Log::write('mail', 'DEV: ' . $subject . ' — сохранено в storage/logs/mail/' . $file . ' (адресат ' . $to . ')');
            return $ok;
        }
        return Mailer::send($to, $subject, $html);
    }

    /** Выполнить $fn на языке клиента (тексты писем через t()), затем вернуть язык админки */
    public static function inLang(string $lang, callable $fn)
    {
        $prev = Lang::current();
        Lang::set(isset(Lang::LANGS[$lang]) ? $lang : Lang::DEFAULT);
        try {
            return $fn();
        } finally {
            Lang::set($prev);
        }
    }

    /** Язык оформления: ru → RU, uk → UA */
    public static function langName(?string $lang): string
    {
        return Lang::NAMES[(string) $lang] ?? 'RU';
    }

    /** POST /admin/orders/{id}/items/ — состав заказа, доставка, скидка; пересчёт итогов */
    public function items(string $id): Response
    {
        $order = self::find($id);
        if (!$order) return $this->fail('Заказ не найден', 404);
        $oid = (int) $order['id'];
        $db = App::db();
        $old = $db->keyed('SELECT * FROM order_items WHERE order_id = ?', [$oid]);
        $posted = Request::postArray('items');

        $keep = []; $updates = []; $newRows = [];
        $newPids = [];
        foreach ($posted as $row) {
            if (!is_array($row)) continue;
            $iid = (int) ($row['id'] ?? 0);
            if ($iid && isset($old[$iid])) { $keep[$iid] = $row; continue; }
            if (!$iid && (int) ($row['product_id'] ?? 0) > 0) $newPids[] = (int) $row['product_id'];
        }
        $products = self::products($newPids);

        foreach ($keep as $iid => $row) {
            $it = $old[$iid];
            $bq = max(1, (int) $it['box_qty']);
            $boxes = max(1, min(9999, (int) ($row['boxes'] ?? 1)));
            $qty = (int) $it['quantity'];
            // количество ящиков не меняли — пары оставляем как были (у старых заказов бывает некратное)
            $pairs = $boxes === (int) ceil($qty / $bq) ? $qty : $boxes * $bq;
            $price = self::money($row['price'] ?? $it['price']);
            if ($pairs !== $qty || abs($price - (float) $it['price']) > 0.001) $updates[$iid] = ['quantity' => $pairs, 'price' => $price];
        }
        foreach ($posted as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0)) continue;
            $p = $products[(int) ($row['product_id'] ?? 0)] ?? null;
            if (!$p) continue;
            $bq = max(1, (int) $p['box_qty']);
            $boxes = max(1, min(9999, (int) ($row['boxes'] ?? 1)));
            $price = isset($row['price']) && $row['price'] !== '' ? self::money($row['price']) : (float) $p['price'];
            $newRows[] = ['order_id' => $oid, 'product_id' => (int) $p['id'], 'name' => mb_substr((string) $p['name'], 0, 255),
                'sku' => (string) $p['sku'], 'price' => $price, 'quantity' => $boxes * $bq, 'box_qty' => $bq];
        }
        $remove = array_diff(array_keys($old), array_keys($keep));
        if (!$keep && !$newRows) return $this->fail('В заказе должна остаться хотя бы одна позиция.');

        $shipping = self::money(Request::post('shipping_cost', (string) $order['shipping_cost']));
        $discount = self::money(Request::post('discount', (string) $order['discount']));
        $before = (float) $order['total'];

        $totals = $db->transaction(static function ($db) use ($oid, $updates, $newRows, $remove, $shipping, $discount) {
            foreach ($updates as $iid => $u) $db->update('order_items', $u, 'id = ? AND order_id = ?', [$iid, $oid]);
            if ($remove) { [$ph, $vals] = $db->in($remove); $db->query('DELETE FROM order_items WHERE order_id = ? AND id IN (' . $ph . ')', array_merge([$oid], $vals)); }
            if ($newRows) $db->insertMany('order_items', $newRows);
            return self::recalcOrder($oid, $shipping, $discount);
        });

        $parts = [];
        if ($newRows) $parts[] = 'добавлено позиций: ' . count($newRows);
        if ($remove) $parts[] = 'удалено: ' . count($remove);
        if ($updates) $parts[] = 'изменено: ' . count($updates);
        if (abs($shipping - (float) $order['shipping_cost']) > 0.001) $parts[] = 'доставка ' . price_format($shipping);
        if (abs($discount - (float) $order['discount']) > 0.001) $parts[] = 'скидка ' . price_format($discount);
        if ($parts || abs($before - $totals['total']) > 0.001) {
            $db->insert('order_log', ['order_id' => $oid, 'user_id' => Auth::id() ?: null, 'status_from' => $order['status'], 'status_to' => $order['status'],
                'text' => 'Изменён состав заказа' . ($parts ? ' (' . implode(', ', $parts) . ')' : '') . ': ' . price_format($before) . ' → ' . price_format($totals['total'])]);
            self::recalcCustomer((int) ($order['customer_id'] ?? 0));
            $this->log('order_items', 'order', $oid, $parts);
        }
        return $this->done($oid, 'Состав заказа сохранён. Итого: ' . price_format($totals['total']), $totals);
    }

    /** POST /admin/orders/{id}/comment/ — комментарий менеджера (виден только в админке) */
    public function comment(string $id): Response
    {
        $order = self::find($id);
        if (!$order) return $this->fail('Заказ не найден', 404);
        $text = mb_substr(Request::post('manager_comment'), 0, 5000);
        App::db()->update('orders', ['manager_comment' => $text !== '' ? $text : null, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $order['id']]);
        $this->log('order_comment', 'order', (int) $order['id']);
        return $this->done((int) $order['id'], 'Комментарий менеджера сохранён.');
    }

    /** GET /admin/orders/{id}/print/ — накладная (отдельная страница без макета) */
    public function printout(string $id): Response
    {
        $order = self::find($id);
        if (!$order) return $this->missing();
        $items = self::itemsView(App::db()->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [(int) $order['id']]));
        $params = json_decode((string) ($order['params'] ?? ''), true);
        $html = View::render('admin/orders/print', [
            'order' => $order, 'num' => self::number((int) $order['id']), 'items' => $items,
            'addr' => self::address($order, is_array($params) ? $params : []),
            'store' => ['name' => Settings::get('store_name', 'Tomobuv'), 'phones' => Settings::json('phones', []),
                'address' => Settings::get('address', ''), 'email' => Settings::get('store_email', ''), 'site' => rtrim((string) preg_replace('#^https?://#', '', url('/')), '/')],
        ], null);
        return Response::html($html)->header('X-Robots-Tag', 'noindex');
    }

    // ============================================================ новый заказ (телефонный)

    public function create(): Response
    {
        $ship = self::methods('shipping_methods');
        $pay = self::methods('payment_methods');
        $v = ['customer_id' => 0, 'name' => '', 'phone' => '', 'email' => '', 'city' => '', 'address' => '', 'comment' => '',
            'manager_comment' => '', 'shipping_method' => (string) array_key_first($ship), 'payment_method' => (string) array_key_first($pay),
            'shipping_cost' => '0', 'discount' => '0', 'status' => 'new', 'request_id' => 0, 'lang' => Lang::DEFAULT];
        $items = [];
        $error = '';
        $db = App::db();

        if (Request::isPost()) {
            foreach ($v as $k => $_) if (isset($_POST[$k])) $v[$k] = Request::post($k);
            $v['customer_id'] = Request::postInt('customer_id');
            $v['request_id'] = Request::postInt('request_id');
            $posted = array_filter(Request::postArray('items'), 'is_array');
            $products = self::products(array_map(static fn($r) => (int) ($r['product_id'] ?? 0), $posted));
            foreach ($posted as $r) {
                $p = $products[(int) ($r['product_id'] ?? 0)] ?? null;
                if (!$p) continue;
                $bq = max(1, (int) $p['box_qty']);
                $boxes = max(1, min(9999, (int) ($r['boxes'] ?? 1)));
                $price = isset($r['price']) && $r['price'] !== '' ? self::money($r['price']) : (float) $p['price'];
                $items[] = ['id' => 0, 'product_id' => (int) $p['id'], 'name' => (string) $p['name'], 'sku' => (string) $p['sku'],
                    'price' => $price, 'quantity' => $boxes * $bq, 'box_qty' => $bq];
            }
            $phone = Str::phone($v['phone']);
            $email = Str::email($v['email']);
            if ($v['name'] === '' && $phone === '') $error = 'Укажите имя и телефон клиента.';
            elseif ($phone === '') $error = 'Укажите телефон клиента в формате +38 (0XX) XXX-XX-XX.';
            elseif ($v['email'] !== '' && $email === '') $error = 'Неверный e-mail.';
            elseif (!$items) $error = 'Добавьте в заказ хотя бы один товар.';
            elseif (!isset($ship[$v['shipping_method']]) || !isset($pay[$v['payment_method']])) $error = 'Выберите способ доставки и оплаты.';
            if (!isset(Lang::LANGS[$v['lang']])) $v['lang'] = Lang::DEFAULT;
            if ($error === '') {
                $oid = $this->storeNew($v, $phone, $email, $items, $ship, $pay);
                $this->flash('Заказ ' . self::number($oid) . ' создан.');
                return Response::redirect('/admin/orders/' . $oid . '/');
            }
        } else {
            // предзаполнение: из заявки «в 1 клик» / звонка или со страницы клиента
            $rid = Request::getInt('request');
            $cid = Request::getInt('customer');
            if ($rid && ($r = $db->row('SELECT * FROM requests WHERE id = ?', [$rid]))) {
                $v['request_id'] = $rid;
                $v['name'] = (string) $r['name']; $v['phone'] = self::phone((string) $r['phone']); $v['email'] = (string) $r['email'];
                $v['comment'] = (string) $r['text'];
                if ($r['product_id']) {
                    $p = self::products([(int) $r['product_id']])[(int) $r['product_id']] ?? null;
                    if ($p) $items[] = ['id' => 0, 'product_id' => (int) $p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'price' => (float) $p['price'],
                        'quantity' => max(1, (int) $p['box_qty']), 'box_qty' => max(1, (int) $p['box_qty'])];
                }
                $c = $v['phone'] !== '' && Str::phone($v['phone']) ? $db->row('SELECT id FROM customers WHERE phone = ? ORDER BY id LIMIT 1', [Str::phone($v['phone'])]) : null;
                if ($c) $cid = (int) $c['id'];
            }
            if ($cid && ($c = $db->row('SELECT id, name, phone, email, city, lang FROM customers WHERE id = ?', [$cid]))) {
                $v['customer_id'] = (int) $c['id'];
                if (isset(Lang::LANGS[(string) $c['lang']])) $v['lang'] = (string) $c['lang'];
                foreach (['name', 'phone', 'email', 'city'] as $k) if ($v[$k] === '' && $c[$k] !== null) $v[$k] = $k === 'phone' ? self::phone((string) $c[$k]) : (string) $c[$k];
            }
        }
        $customer = $v['customer_id'] ? $db->row('SELECT id, name, phone, email, orders_count FROM customers WHERE id = ?', [(int) $v['customer_id']]) : null;
        return $this->render('admin/orders/new', [
            'title' => 'Новый заказ', 'v' => $v, 'items' => self::itemsView($items), 'error' => $error, 'customer' => $customer,
            'shipping' => $ship, 'payment' => $pay, 'styles' => ['admin/sales.css'], 'scripts' => ['admin/sales.js'],
            'back' => ['/admin/orders/', 'Все заказы'],
        ]);
    }

    /** Сохранить ручной заказ: клиент (найти по id/телефону/e-mail или создать), заказ, позиции, журнал */
    private function storeNew(array $v, string $phone, string $email, array $items, array $ship, array $pay): int
    {
        $db = App::db();
        return $db->transaction(function ($db) use ($v, $phone, $email, $items, $ship, $pay) {
            $cid = 0;
            if ($v['customer_id'] > 0) $cid = (int) $db->value('SELECT id FROM customers WHERE id = ?', [$v['customer_id']]);
            if (!$cid) $cid = (int) $db->value('SELECT id FROM customers WHERE phone = ? ORDER BY id LIMIT 1', [$phone]);
            if (!$cid && $email !== '') $cid = (int) $db->value('SELECT id FROM customers WHERE email = ?', [$email]);
            if (!$cid) {
                $cid = $db->insert('customers', ['name' => mb_substr($v['name'], 0, 190), 'email' => $email ?: null, 'phone' => $phone,
                    'city' => mb_substr($v['city'], 0, 190) ?: null, 'role' => 'customer', 'status' => 1, 'lang' => $v['lang']]);
            }
            $status = in_array($v['status'], ['new', 'processing', 'paid'], true) ? $v['status'] : 'new';
            $oid = $db->insert('orders', [
                'customer_id' => $cid, 'status' => $status, 'name' => mb_substr($v['name'], 0, 190), 'phone' => $phone, 'email' => $email ?: null,
                'shipping_method' => $v['shipping_method'], 'shipping_name' => $ship[$v['shipping_method']],
                'city' => mb_substr($v['city'], 0, 190) ?: null, 'address' => mb_substr($v['address'], 0, 500) ?: null,
                'payment_method' => $v['payment_method'], 'payment_name' => $pay[$v['payment_method']],
                'comment' => mb_substr($v['comment'], 0, 5000) ?: null, 'manager_comment' => mb_substr($v['manager_comment'], 0, 5000) ?: null,
                'source' => 'admin', 'lang' => $v['lang'], 'ip' => Request::ip(), 'currency' => 'UAH',
                'params' => json_encode(['created_by' => Auth::id(), 'request_id' => $v['request_id'] ?: null], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insertMany('order_items', array_map(static fn($it) => ['order_id' => $oid, 'product_id' => $it['product_id'],
                'name' => mb_substr($it['name'], 0, 255), 'sku' => $it['sku'], 'price' => $it['price'], 'quantity' => $it['quantity'],
                'box_qty' => $it['box_qty']], $items));
            self::recalcOrder($oid, self::money($v['shipping_cost']), self::money($v['discount']));
            $db->insert('order_log', ['order_id' => $oid, 'user_id' => Auth::id() ?: null, 'status_from' => null, 'status_to' => $status,
                'text' => 'Заказ создан менеджером (по телефону)' . ($v['request_id'] ? ' по заявке №' . (int) $v['request_id'] : '')]);
            if ($v['request_id']) $db->update('requests', ['status' => 'done'], 'id = ?', [(int) $v['request_id']]);
            self::recalcCustomer($cid);
            Cache::forget('admin.tally');   // счётчики в меню админки
            $this->log('order_create', 'order', $oid);
            return $oid;
        });
    }

    // ============================================================ поиск товаров (для добавления в заказ)

    /** GET /admin/orders/products.json?q= — по id, артикулу, названию/модели */
    public function productSearch(): Response
    {
        $q = trim(mb_substr(Request::get('q'), 0, 100));
        if (mb_strlen($q) < 2) return Response::json(['ok' => true, 'items' => []]);
        $db = App::db();
        $ids = [];
        if (ctype_digit($q)) $ids = array_map('intval', $db->col('SELECT id FROM products WHERE id = ?', [(int) $q]));
        $like = addcslashes($q, '%_\\');
        $ids = array_merge($ids, array_map('intval', $db->col('SELECT id FROM products WHERE sku LIKE ? ORDER BY status DESC, id DESC LIMIT 10', [$like . '%'])));
        // полнотекстовый индекс по названию и артикулу: все слова (от 3 символов) с префиксом
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [], static fn($w) => mb_strlen($w) >= 3);
        if ($words && count($ids) < 20) {
            $ft = implode(' ', array_map(static fn($w) => '+' . $w . '*', $words));
            $ids = array_merge($ids, array_map('intval', $db->col('SELECT id FROM products WHERE MATCH(name, sku) AGAINST(? IN BOOLEAN MODE)
                ORDER BY status DESC, id DESC LIMIT 20', [$ft])));
        }
        if (count($ids) < 5) {
            $ids = array_merge($ids, array_map('intval', $db->col('SELECT id FROM products WHERE name LIKE ? LIMIT 20', ['%' . $like . '%'])));
        }
        $ids = array_slice(array_values(array_unique($ids)), 0, 20);
        $rows = self::products($ids);
        $out = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) continue;
            $p = $rows[$id];
            $out[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'sku' => (string) $p['sku'], 'price' => (float) $p['price'],
                'box_qty' => max(1, (int) $p['box_qty']), 'size' => (string) $p['size'], 'status' => (int) $p['status'], 'in_stock' => (int) $p['in_stock'],
                'img' => Image::product($p, '96x96'), 'link' => '/product/' . $p['url'] . '/'];
        }
        return Response::json(['ok' => true, 'items' => $out]);
    }

    // ============================================================ экспорт CSV (потоково)

    public function export(): Response
    {
        $f = self::filters();
        [$where, $params] = self::where($f);
        $db = App::db();
        $ids = array_map('intval', $db->col('SELECT o.id FROM orders o WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . self::SORTS[$f['sort']], $params));
        $ship = self::methods('shipping_methods');
        $pay = self::methods('payment_methods');
        $this->log('orders_export', 'order', null, ['count' => count($ids)] + array_filter($f));

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d-His') . '.csv"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");   // BOM — чтобы Excel открыл UTF-8
        fputcsv($out, ['Номер', 'Дата', 'Статус', 'Клиент', 'Телефон', 'E-mail', 'Город', 'Область', 'Адрес / отделение', 'Доставка', 'Оплата',
            'Ящиков', 'Пар', 'Товары', 'Подытог', 'Доставка, грн', 'Скидка', 'Итого', 'Источник', 'Язык', 'Комментарий клиента', 'Комментарий менеджера'], ';', '"', '');
        // телефон без «+» в начале: иначе Excel примет «+38 (0XX)…» за формулу
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = self::rowsByIds($chunk, true);
            [$ph, $vals] = $db->in($chunk);
            $items = [];
            foreach ($db->all('SELECT order_id, name, quantity, box_qty FROM order_items WHERE order_id IN (' . $ph . ') ORDER BY id', $vals) as $it) {
                $items[(int) $it['order_id']][] = $it['name'] . ' — ' . (int) ceil($it['quantity'] / max(1, (int) $it['box_qty'])) . ' ящ. / ' . (int) $it['quantity'] . ' пар';
            }
            foreach ($rows as $o) {
                $p = json_decode((string) ($o['params'] ?? ''), true);
                fputcsv($out, array_map([self::class, 'csvCell'], [
                    self::number((int) $o['id']), date('d.m.Y H:i', strtotime((string) $o['created_at'])), self::STATUSES[$o['status']] ?? $o['status'],
                    $o['name'], ltrim(self::phone($o['phone']), '+'), $o['email'], $o['city'], self::region($o['region']), self::address($o, is_array($p) ? $p : []),
                    self::methodName($o['shipping_method'], $o['shipping_name'], $ship), self::methodName($o['payment_method'], $o['payment_name'], $pay),
                    (int) $o['boxes'], (int) $o['pairs'], implode(' | ', $items[(int) $o['id']] ?? []),
                    self::num($o['subtotal']), self::num($o['shipping_cost']), self::num($o['discount']), self::num($o['total']),
                    self::SOURCES[$o['source']] ?? $o['source'], self::langName($o['lang']), $o['comment'], $o['manager_comment'],
                ]), ';', '"', '');
            }
            fflush($out);
            flush();
        }
        fclose($out);
        exit;
    }

    /** Защита от формул в Excel: строки, начинающиеся с = + - @, экранируются апострофом */
    private static function csvCell($v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && !is_numeric(str_replace(',', '.', $s)) && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
        return $s;
    }

    private static function num($v): string
    {
        return number_format((float) $v, 2, ',', '');
    }

    // ============================================================ общее

    /** Номер заказа как на старом сайте: #100{id} (настройка order_format) */
    public static function number(int $id): string
    {
        $fmt = (string) Settings::get('order_format', '#100{$order.id}');
        return str_contains($fmt, '{$order.id}') ? str_replace('{$order.id}', (string) $id, $fmt) : '#100' . $id;
    }

    /** 380671234567 → +38 (067) 123-45-67 */
    public static function phone(?string $p): string
    {
        $d = (string) preg_replace('/\D+/', '', (string) $p);
        if (strlen($d) === 12 && str_starts_with($d, '380')) {
            return '+38 (' . substr($d, 2, 3) . ') ' . substr($d, 5, 3) . '-' . substr($d, 8, 2) . '-' . substr($d, 10, 2);
        }
        return (string) $p;
    }

    /** Ссылка tel: для быстрого звонка */
    public static function tel(?string $p): string
    {
        $d = (string) preg_replace('/\D+/', '', (string) $p);
        return $d === '' ? '' : 'tel:+' . (strlen($d) === 10 && $d[0] === '0' ? '38' . $d : $d);
    }

    public static function region(?string $code): string
    {
        $code = trim((string) $code);
        return self::REGIONS[$code] ?? self::REGIONS[str_pad($code, 2, '0', STR_PAD_LEFT)] ?? $code;
    }

    /** Справочник способов доставки/оплаты из настроек: [code => name] */
    public static function methods(string $setting): array
    {
        $out = [];
        foreach (Settings::json($setting, []) as $m) {
            if (!is_array($m) || empty($m['code']) || (isset($m['status']) && !(int) $m['status'])) continue;
            $out[(string) $m['code']] = trim((string) ($m['name'] ?? $m['code']));
        }
        return $out;
    }

    public static function methodName(?string $code, ?string $name, array $map): string
    {
        $name = trim((string) $name);
        return $name !== '' ? $name : ($map[(string) $code] ?? '');
    }

    /** Пересчёт итогов заказа по позициям */
    public static function recalcOrder(int $oid, float $shipping, float $discount): array
    {
        $db = App::db();
        $s = $db->row('SELECT COALESCE(SUM(price * quantity), 0) subtotal, COALESCE(SUM(quantity), 0) pairs,
            COALESCE(SUM(CEIL(quantity / GREATEST(box_qty, 1))), 0) boxes FROM order_items WHERE order_id = ?', [$oid]);
        $subtotal = round((float) $s['subtotal'], 2);
        $total = max(0, round($subtotal + $shipping - $discount, 2));
        $data = ['subtotal' => $subtotal, 'shipping_cost' => $shipping, 'discount' => $discount, 'total' => $total,
            'boxes' => (int) $s['boxes'], 'pairs' => (int) $s['pairs'], 'updated_at' => date('Y-m-d H:i:s')];
        $db->update('orders', $data, 'id = ?', [$oid]);
        return $data;
    }

    /** Пересчёт статистики клиента: всего заказов и сумма покупок (оплаченные/отправленные/выполненные) */
    public static function recalcCustomer(int $cid): void
    {
        if ($cid <= 0) return;
        [$ph, $vals] = App::db()->in(self::PAID);
        App::db()->query('UPDATE customers SET orders_count = (SELECT COUNT(*) FROM orders WHERE customer_id = ?),
            total_spent = (SELECT COALESCE(SUM(total), 0) FROM orders WHERE customer_id = ? AND status IN (' . $ph . '))
            WHERE id = ?', array_merge([$cid, $cid], $vals, [$cid]));
    }

    /** recalcCustomer() для многих клиентов — одним запросом на пачку (массовая смена статуса) */
    public static function recalcCustomers(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        if (!$ids) return;
        $db = App::db();
        [$paid, $paidVals] = $db->in(self::PAID);
        foreach (array_chunk($ids, 500) as $chunk) {
            [$ph, $vals] = $db->in($chunk);
            $db->query('UPDATE customers c SET orders_count = (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id),
                total_spent = (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.customer_id = c.id AND o.status IN (' . $paid . '))
                WHERE c.id IN (' . $ph . ')', array_merge($paidVals, $vals));
        }
    }

    /** Товары по id (для позиций заказа): [id => row] — одним запросом */
    private static function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        [$ph, $vals] = App::db()->in($ids);
        return App::db()->keyed('SELECT id, url, name, sku, price, box_qty, size, status, in_stock, image_id, image_ext
            FROM products WHERE id IN (' . $ph . ')', $vals);
    }

    /** Позиции заказа для шаблона: + фото, ссылки, ящики, сумма (товары одним запросом) */
    private static function itemsView(array $items): array
    {
        $products = self::products(array_column($items, 'product_id'));
        foreach ($items as &$it) {
            $p = $products[(int) ($it['product_id'] ?? 0)] ?? null;
            $bq = max(1, (int) $it['box_qty']);
            $it['boxes'] = (int) ceil((int) $it['quantity'] / $bq);
            $it['sum'] = (float) $it['price'] * (int) $it['quantity'];
            $it['exists'] = (bool) $p;
            $it['active'] = $p && (int) $p['status'] === 1;
            $it['link'] = $p ? '/product/' . $p['url'] . '/' : '';
            $it['img'] = $p ? Image::product($p, '96x96') : '/assets/img/no-photo.svg';
            $it['size'] = $p['size'] ?? '';
            $it['current_price'] = $p ? (float) $p['price'] : null;
        }
        unset($it);
        return $items;
    }

    /** Поле params → колонка заказа с тем же значением (такие поля не повторяем в блоке «Данные со старого сайта») */
    private const LEGACY_SAME = ['shipping_name' => 'shipping_name', 'shipping_address.city' => 'city', 'shipping_address.region' => 'region',
        'payment_name' => 'payment_name', 'shipping_address.otdelenie-pocht' => 'address', 'shipping_address.street' => 'address', 'shipping_params_1' => 'address'];

    /** Поля из params (старые поля Webasyst, метки нового сайта): [подпись => значение]; $order — чтобы не дублировать колонки заказа */
    private static function legacy(array $params, array $order = []): array
    {
        $out = [];
        $norm = static fn($v) => mb_strtolower(trim((string) $v));
        foreach (self::LEGACY as $k => $label) {
            $val = is_scalar($params[$k] ?? null) ? trim((string) $params[$k]) : '';
            if ($val === '' || ($k === 'coupon_id' && $val === '0')) continue;
            // промокод нового сайта показывается отдельным блоком (Coupons::forOrder)
            if ($k === 'coupon_id' && ($order['source'] ?? '') !== 'webasyst') continue;
            if ($order && isset(self::LEGACY_SAME[$k])) {
                $col = $norm($order[self::LEGACY_SAME[$k]] ?? '');
                // адрес: отделение/улица уже внутри orders.address; пустой адрес карточка берёт из shipping_params_1
                if ($col === $norm($val) || ($col !== '' && self::LEGACY_SAME[$k] === 'address' && str_contains($col, $norm($val)))
                    || ($k === 'shipping_params_1' && $col === '')) continue;
            }
            if ($k === 'shipping_address.region') $val = self::region($val);
            if ($k === 'quickorder_product' || $k === 'quickorder_cart') $val = 'да';
            if (!isset($out[$label])) $out[$label] = $val;
        }
        // прочие поля адреса Webasyst (разные формы доставки хранили свои поля)
        foreach ($params as $k => $v) {
            if (!is_string($k) || !str_starts_with($k, 'shipping_address.') || isset(self::LEGACY[$k]) || in_array($k, self::LEGACY_SKIP, true)) continue;
            $v = is_scalar($v) ? trim((string) $v) : '';
            if ($v !== '') $out['Адрес: ' . substr($k, 17)] = $v;
        }
        return $out;
    }

    /** Отделение / адрес: orders.address, у старых заказов без адреса — поле формы доставки Webasyst */
    private static function address(array $order, array $params): string
    {
        $a = trim((string) ($order['address'] ?? ''));
        if ($a === '' && is_scalar($params['shipping_params_1'] ?? null)) {
            $a = trim((string) $params['shipping_params_1']);
            if (ctype_digit($a)) $a = 'Отделение №' . $a;   // в форме Webasyst обычно писали только номер отделения
        }
        return $a;
    }

    private static function find(string $id): ?array
    {
        if (!ctype_digit($id)) return null;
        return App::db()->row('SELECT * FROM orders WHERE id = ?', [(int) $id]);
    }

    private static function money($v): float
    {
        if (!is_scalar($v)) return 0.0;
        $v = str_replace([' ', ','], ['', '.'], trim((string) $v));
        return is_numeric($v) ? max(0, min(99999999, round((float) $v, 2))) : 0.0;
    }

    private function missing(): Response
    {
        return Response::html($this->render('admin/forbidden', ['title' => 'Заказ не найден', 'message' => 'Такого заказа нет.'])->body, 404);
    }

    /** Ответ на действие: JSON для AJAX, иначе flash + возврат в карточку */
    private function done(int $oid, string $msg, array $extra = []): Response
    {
        if (Request::isAjax()) return Response::json(['ok' => true, 'message' => $msg] + $extra);
        $this->flash($msg);
        return Response::redirect('/admin/orders/' . $oid . '/');
    }

    private function fail(string $msg, int $code = 422): Response
    {
        if (Request::isAjax()) return Response::json(['ok' => false, 'error' => $msg], $code);
        $this->flash($msg, true);
        return $this->back('/admin/orders/');
    }
}
