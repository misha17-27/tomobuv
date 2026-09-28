<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Catalog;
use App\Services\Coupons;

/**
 * Промокоды (как «Discount codes» в админке ARG FLEX): список с вкладками по состоянию,
 * форма с генератором кода, условиями, сроком, лимитами и ограничениями по категориям/брендам/товарам,
 * статистика применений. Правила скидки — в App\Services\Coupons.
 * Менеджер видит всё, изменять (создавать, выключать, удалять) может только администратор.
 */
final class CouponsController extends BaseController
{
    private const PER_PAGE = 50;
    public const TABS = ['active' => 'Активные', 'expired' => 'Истёкшие', 'off' => 'Выключенные', 'all' => 'Все'];
    /** Условия вкладок (NOW() — в часовом поясе PHP, см. Core\DB) */
    private const TAB_SQL = [
        'active'  => 'c.status = 1 AND (c.expires_at IS NULL OR c.expires_at >= NOW()) AND (c.usage_limit = 0 OR c.used < c.usage_limit)',
        'expired' => 'c.status = 1 AND ((c.expires_at IS NOT NULL AND c.expires_at < NOW()) OR (c.usage_limit > 0 AND c.used >= c.usage_limit))',
        'off'     => 'c.status = 0',
        'all'     => '1 = 1',
    ];
    public const SORTS = [
        'new'     => ['Сначала новые', 'c.id DESC'],
        'code'    => ['По коду А→Я', 'c.code ASC'],
        'used'    => ['Больше применений', 'c.used DESC, c.id DESC'],
        'expires' => ['Скоро истекают', 'c.expires_at IS NULL, c.expires_at ASC, c.id DESC'],
    ];
    public const ORDER_STATUSES = ['new' => 'Новый', 'processing' => 'В обработке', 'paid' => 'Оплачен', 'shipped' => 'Отправлен',
        'completed' => 'Выполнен', 'refunded' => 'Возврат', 'deleted' => 'Удалён'];
    private const BULK = ['enable' => 'Включить', 'disable' => 'Выключить', 'delete' => 'Удалить'];

    // ================================================================== список

    public function index(): Response
    {
        $db = App::db();
        $status = Request::get('status', 'active');
        if (!isset(self::TABS[$status])) $status = 'active';
        $sort = Request::get('sort', 'new');
        if (!isset(self::SORTS[$sort])) $sort = 'new';
        $q = mb_substr(Request::get('q'), 0, 64);

        $cnt = $db->row('SELECT COUNT(*) n, COALESCE(SUM(c.status = 0), 0) off_n, COALESCE(SUM(' . self::TAB_SQL['expired'] . '), 0) exp_n FROM coupons c');
        $counts = ['all' => (int) $cnt['n'], 'off' => (int) $cnt['off_n'], 'expired' => (int) $cnt['exp_n']];
        $counts['active'] = $counts['all'] - $counts['off'] - $counts['expired'];

        $where = [self::TAB_SQL[$status]];
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $code = Coupons::normalize($q);
            $where[] = '(c.code LIKE ? OR c.comment LIKE ?' . ($code !== '' ? ' OR c.code LIKE ?' : '') . ')';
            array_push($params, $like, $like);
            if ($code !== '') $params[] = '%' . addcslashes($code, '%_\\') . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $db->value("SELECT COUNT(*) FROM coupons c WHERE $sqlWhere", $params);
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $rows = $db->all("SELECT c.* FROM coupons c WHERE $sqlWhere ORDER BY " . self::SORTS[$sort][1] . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, $params);
        $coupons = array_map([Coupons::class, 'hydrate'], $rows);

        // скидки по промокодам страницы — одним запросом
        $given = [];
        if ($coupons) {
            [$ph, $vals] = $db->in(array_column($coupons, 'id'));
            $given = $db->pairs("SELECT coupon_id, SUM(discount) FROM coupon_usages WHERE coupon_id IN ($ph) GROUP BY coupon_id", $vals);
        }

        // сводка за 30 дней
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $s = $db->row('SELECT COUNT(*) n, COALESCE(SUM(u.discount), 0) disc, COALESCE(SUM(o.total), 0) sum
            FROM coupon_usages u LEFT JOIN orders o ON o.id = u.order_id WHERE u.created_at >= ?', [$since]);

        return $this->render('admin/coupons/index', [
            'title' => 'Промокоды',
            'coupons' => $coupons, 'counts' => $counts, 'status' => $status, 'sort' => $sort, 'q' => $q,
            'pg' => $pg, 'total' => $total, 'given' => $given,
            'names' => $this->names($coupons),
            'month' => ['n' => (int) $s['n'], 'disc' => (float) $s['disc'], 'sum' => (float) $s['sum']],
            'siteOn' => Coupons::enabled(), 'canEdit' => Auth::isAdmin(), 'bulk' => self::BULK,
            'actions' => Auth::isAdmin() ? '<a class="btn btn-p" href="/admin/coupons/new/">+ Новый промокод</a>' : '',
            'styles' => ['admin/coupons.css'], 'scripts' => ['admin/coupons.js'],
        ]);
    }

    /** Массовые действия: ids[] + action (enable | disable | delete) */
    public function bulk(): Response
    {
        if (!Auth::isAdmin()) return $this->denied('/admin/coupons/');
        $action = Request::post('action');
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', Request::postArray('ids')), static fn($x) => $x > 0))), 0, 1000);
        if (!isset(self::BULK[$action]) || !$ids) {
            $this->flash('Отметьте промокоды и выберите действие.', true);
            return $this->back('/admin/coupons/');
        }
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        if ($action === 'delete') {
            $n = $db->transaction(static function ($db) use ($ph, $vals) {
                $db->query("UPDATE coupon_usages SET coupon_id = 0 WHERE coupon_id IN ($ph)", $vals);   // история заказов остаётся (по коду)
                return $db->query("DELETE FROM coupons WHERE id IN ($ph)", $vals)->rowCount();
            });
            $msg = 'Удалено промокодов: ' . $n . '.';
        } else {
            $on = $action === 'enable' ? 1 : 0;
            $n = $db->query("UPDATE coupons SET status = ?, updated_at = NOW() WHERE status <> ? AND id IN ($ph)", array_merge([$on, $on], $vals))->rowCount();
            $msg = ($on ? 'Включено' : 'Выключено') . ' промокодов: ' . $n . '.';
        }
        $this->log('coupon_bulk_' . $action, 'coupon', null, ['ids' => $ids, 'changed' => $n]);
        $this->flash($msg);
        return $this->back('/admin/coupons/');
    }

    /** Включить/выключить приём промокодов на сайте целиком (настройка coupons_enabled) */
    public function site(): Response
    {
        if (!Auth::isAdmin()) return $this->denied('/admin/coupons/');
        $on = Request::post('on') === '1';
        Settings::set('coupons_enabled', $on ? '1' : '0');
        $this->log('coupons_site_' . ($on ? 'on' : 'off'), 'settings');
        $this->flash($on ? 'Приём промокодов на сайте включён.' : 'Приём промокодов на сайте выключен — покупатели не смогут применить ни один код.');
        return $this->back('/admin/coupons/');
    }

    // ================================================================== форма

    public function edit(string $id = ''): Response
    {
        $db = App::db();
        $isNew = $id === '';
        if (!$isNew && !ctype_digit($id)) return $this->missing();
        $coupon = $isNew ? null : Coupons::get((int) $id);
        if (!$isNew && !$coupon) return $this->missing();
        $canEdit = Auth::isAdmin();
        if ($isNew && !$canEdit) return $this->denied('/admin/coupons/');   // менеджеру — только просмотр существующих

        if (!$coupon) {
            $coupon = self::blank();
            $copy = Request::getInt('copy');
            if ($copy && ($src = Coupons::get($copy))) {
                $coupon = ['id' => 0, 'code' => '', 'used' => 0, 'status' => 1, 'created_at' => null, 'updated_at' => null] + $src;
                $coupon['comment'] = $src['comment'];
            }
            $coupon['code'] = Coupons::generate();
        }
        $errors = [];
        $savedCode = (string) $coupon['code'];   // в заголовке — сохранённый код, даже если в форме ввели неверный

        if (Request::isPost()) {
            if (!$canEdit) return $this->denied($isNew ? '/admin/coupons/' : '/admin/coupons/' . (int) $id . '/');
            [$data, $errors] = $this->collect($coupon);
            if (!$errors) {
                $data['updated_at'] = date('Y-m-d H:i:s');
                try {
                    if ($isNew) {
                        $data['created_at'] = $data['updated_at'];
                        $cid = $db->insert('coupons', $data);
                        $this->log('coupon_create', 'coupon', $cid, ['code' => $data['code']]);
                        $this->flash('Промокод ' . $data['code'] . ' создан.');
                    } else {
                        $cid = (int) $coupon['id'];
                        $db->update('coupons', $data, 'id = ?', [$cid]);
                        $this->log('coupon_update', 'coupon', $cid, ['code' => $data['code']]);
                        $this->flash('Промокод ' . $data['code'] . ' сохранён.');
                    }
                    return Response::redirect('/admin/coupons/' . $cid . '/');
                } catch (\PDOException $e) {
                    // тот же код успели сохранить параллельно (UNIQUE code) — показываем как ошибку поля, а не 500
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                    $errors['code'] = 'Такой код уже есть — придумайте другой';
                }
            }
            $coupon = Coupons::hydrate($data + $coupon);
        }

        $uses = $isNew ? null : $db->row('SELECT COUNT(*) n, COALESCE(SUM(discount), 0) disc, MAX(created_at) last FROM coupon_usages WHERE coupon_id = ?', [(int) $coupon['id']]);
        return $this->render('admin/coupons/edit', [
            'title' => $isNew ? 'Новый промокод' : 'Промокод ' . $savedCode,
            'back' => ['/admin/coupons/', 'Все промокоды'],
            'c' => $coupon, 'isNew' => $isNew, 'errors' => $errors, 'canEdit' => $canEdit,
            'picked' => $this->picked($coupon), 'uses' => $uses, 'siteOn' => Coupons::enabled(),
            'actions' => !$isNew && $canEdit ? '<a class="btn" href="/admin/coupons/new/?copy=' . (int) $coupon['id'] . '">Создать копию</a>' : '',
            'styles' => ['admin/coupons.css'], 'scripts' => ['admin/coupons.js'],
        ]);
    }

    public function delete(string $id): Response
    {
        if (!Auth::isAdmin()) return $this->denied('/admin/coupons/' . (int) $id . '/');
        $c = ctype_digit($id) ? Coupons::get((int) $id) : null;
        if (!$c) return $this->missing();
        App::db()->transaction(static function ($db) use ($c) {
            $db->query('UPDATE coupon_usages SET coupon_id = 0 WHERE coupon_id = ?', [$c['id']]);
            $db->delete('coupons', 'id = ?', [$c['id']]);
        });
        $this->log('coupon_delete', 'coupon', $c['id'], ['code' => $c['code'], 'used' => $c['used']]);
        $this->flash('Промокод ' . $c['code'] . ' удалён. В заказах, где он применён, скидка осталась.');
        return Response::redirect('/admin/coupons/');
    }

    // ================================================================== применения

    public function usages(string $id): Response
    {
        $c = ctype_digit($id) ? Coupons::get((int) $id) : null;
        if (!$c) return $this->missing();
        $db = App::db();
        $sum = $db->row('SELECT COUNT(*) n, COALESCE(SUM(u.discount), 0) disc, COALESCE(SUM(o.total), 0) total,
                COALESCE(SUM(o.boxes), 0) boxes, COUNT(DISTINCT COALESCE(u.customer_id, u.phone, u.id)) clients, MIN(u.created_at) first
            FROM coupon_usages u LEFT JOIN orders o ON o.id = u.order_id WHERE u.coupon_id = ?', [$c['id']]);
        $pg = new Paginator((int) $sum['n'], self::PER_PAGE, Request::page());
        $rows = $db->all('SELECT u.order_id, u.customer_id, u.phone, u.discount, u.created_at,
                o.id o_id, o.name, o.phone o_phone, o.total, o.boxes, o.pairs, o.status
            FROM coupon_usages u LEFT JOIN orders o ON o.id = u.order_id
            WHERE u.coupon_id = ? ORDER BY u.created_at DESC, u.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, [$c['id']]);

        // применения по дням за 30 дней (график)
        $raw = $db->pairs('SELECT DATE(created_at) d, COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND created_at >= ? GROUP BY d',
            [$c['id'], date('Y-m-d', strtotime('-29 days'))]);
        $byDay = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $byDay[$d] = (int) ($raw[$d] ?? 0);
        }
        if (!array_sum($byDay)) $byDay = [];

        return $this->render('admin/coupons/usages', [
            'title' => 'Промокод ' . $c['code'], 'back' => ['/admin/coupons/', 'Все промокоды'],
            'c' => $c, 'sum' => $sum, 'rows' => $rows, 'pg' => $pg, 'byDay' => $byDay,
            'styles' => ['admin/coupons.css'], 'scripts' => ['admin/coupons.js'],
        ]);
    }

    // ================================================================== JSON для формы

    /** Поиск для ограничений: kind=category|brand|product, q → [{id, name, sub}] (до 20) */
    public function lookup(): Response
    {
        $kind = Request::get('kind');
        $q = mb_substr(Request::get('q'), 0, 80);
        $out = match ($kind) {
            'category' => $this->findCategories($q),
            'brand'    => $this->findBrands($q),
            'product'  => $this->findProducts($q),
            default    => null,
        };
        if ($out === null) return Response::json(['ok' => false, 'error' => 'Неизвестный тип'], 422);
        return Response::json(['ok' => true, 'items' => $out]);
    }

    /** Новый свободный код для кнопки «Сгенерировать» */
    public function generate(): Response
    {
        $prefix = Coupons::normalize(mb_substr(Request::get('prefix'), 0, 12));
        return Response::json(['ok' => true, 'code' => Coupons::generate(8, $prefix !== '' ? rtrim($prefix, '-') . '-' : '')]);
    }

    private function findCategories(string $q): array
    {
        $out = [];
        $ql = mb_strtolower($q);
        foreach (self::categoryPaths() as $id => [$path, $active]) {
            if ($ql !== '' && !str_contains(mb_strtolower($path), $ql)) continue;
            $out[] = ['id' => $id, 'name' => $path, 'sub' => $active ? '' : 'скрыта'];
            if (count($out) >= 20) break;
        }
        return $out;
    }

    private function findBrands(string $q): array
    {
        $db = App::db();
        $rows = $q === ''
            ? $db->all('SELECT id, name, product_count, hidden FROM brands ORDER BY product_count DESC, name LIMIT 20')
            : $db->all('SELECT id, name, product_count, hidden FROM brands WHERE name LIKE ? ORDER BY name LIKE ? DESC, product_count DESC, name LIMIT 20',
                ['%' . addcslashes($q, '%_\\') . '%', addcslashes($q, '%_\\') . '%']);
        return array_map(static fn($b) => ['id' => (int) $b['id'], 'name' => $b['name'],
            'sub' => number_format((int) $b['product_count'], 0, '', ' ') . ' ' . plural((int) $b['product_count'], 'товар', 'товара', 'товаров') . ((int) $b['hidden'] ? ' · скрыт' : '')], $rows);
    }

    private function findProducts(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2 && !ctype_digit($q)) return [];
        $db = App::db();
        $ids = [];
        if (ctype_digit($q) && strlen($q) <= 10) $ids = $db->col('SELECT id FROM products WHERE id = ?', [(int) $q]);
        $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE sku LIKE ? LIMIT 20', [addcslashes($q, '%_\\') . '%']));
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn($x) => mb_strlen($x) >= 3);
        if (count($ids) < 20 && $words) {
            $against = implode(' ', array_map(static fn($x) => '+' . $x . '*', array_slice($words, 0, 6)));
            $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE MATCH(name, sku) AGAINST (? IN BOOLEAN MODE) ORDER BY status DESC, id DESC LIMIT 20', [$against]));
        }
        if (!$ids) {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $ids = $db->col('SELECT id FROM products WHERE name LIKE ? ORDER BY id DESC LIMIT 20', [$like]);
        }
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 20);
        if (!$ids) return [];
        [$ph, $vals] = $db->in($ids);
        $rows = $db->keyed("SELECT id, name, sku, price, box_qty, status FROM products WHERE id IN ($ph)", $vals);
        $out = [];
        foreach ($ids as $pid) {
            if (!isset($rows[$pid])) continue;
            $r = $rows[$pid];
            $out[] = ['id' => $pid, 'name' => $r['name'],
                'sub' => trim(($r['sku'] !== '' ? 'арт. ' . $r['sku'] . ' · ' : '') . price_format($r['price']) . '/пара × ' . (int) $r['box_qty'] . ((int) $r['status'] ? '' : ' · скрыт'))];
        }
        return $out;
    }

    // ================================================================== служебное

    private static function blank(): array
    {
        return Coupons::hydrate(['id' => 0, 'code' => '', 'type' => 'percent', 'value' => 5, 'min_sum' => 0, 'min_boxes' => 0, 'max_discount' => 0,
            'starts_at' => null, 'expires_at' => null, 'usage_limit' => 0, 'used' => 0, 'per_customer_limit' => 0,
            'category_ids' => [], 'brand_ids' => [], 'product_ids' => [], 'status' => 1, 'comment' => null,
            'created_at' => null, 'updated_at' => null]);
    }

    /** Данные формы → [строка для БД, ошибки [поле => текст]] */
    private function collect(array $old): array
    {
        $db = App::db();
        $e = [];
        $num = static function (string $k): ?float {
            $v = str_replace([' ', ','], ['', '.'], Request::post($k));
            if ($v === '') return 0.0;
            return is_numeric($v) ? (float) $v : null;
        };
        $int = static function (string $k) use ($num): ?int {
            $v = $num($k);
            return $v === null || $v < 0 || floor($v) !== $v ? null : (int) $v;
        };

        $raw = Request::post('code');
        $code = Coupons::normalize($raw);
        if ($raw === '') $e['code'] = 'Укажите код или нажмите «Сгенерировать»';
        elseif ($code === '') $e['code'] = 'Код — латинские буквы, цифры, «-» и «_», до ' . Coupons::CODE_MAX . ' символов';
        elseif ((int) $db->value('SELECT id FROM coupons WHERE code = ? AND id <> ?', [$code, (int) $old['id']])) $e['code'] = 'Такой код уже есть — придумайте другой';

        $type = Request::post('type') === 'fixed' ? 'fixed' : 'percent';
        $value = $num('value');
        if ($value === null || $value <= 0) $e['value'] = 'Укажите размер скидки больше нуля';
        elseif ($type === 'percent' && $value > 100) $e['value'] = 'Процент — не больше 100';
        elseif ($value > 10000000) $e['value'] = 'Слишком большая сумма';

        $maxDisc = $type === 'percent' ? $num('max_discount') : 0.0;
        if ($maxDisc === null || $maxDisc < 0) { $e['max_discount'] = 'Укажите сумму в гривнах или 0'; $maxDisc = 0.0; }
        $minSum = $num('min_sum');
        if ($minSum === null || $minSum < 0 || $minSum > 100000000) { $e['min_sum'] = 'Укажите сумму в гривнах или 0'; $minSum = 0.0; }
        $minBoxes = $int('min_boxes');
        if ($minBoxes === null || $minBoxes > 100000) { $e['min_boxes'] = 'Целое число ящиков или 0'; $minBoxes = 0; }
        $limit = $int('usage_limit');
        if ($limit === null || $limit > 10000000) { $e['usage_limit'] = 'Целое число или 0 — без ограничения'; $limit = 0; }
        $perCustomer = $int('per_customer_limit');
        if ($perCustomer === null || $perCustomer > 100000) { $e['per_customer_limit'] = 'Целое число или 0 — без ограничения'; $perCustomer = 0; }

        $date = static function (string $k, string $time) use (&$e): ?string {
            $v = Request::post($k);
            if ($v === '') return null;
            $d = \DateTime::createFromFormat('!Y-m-d', $v);
            if (!$d || $d->format('Y-m-d') !== $v || (int) $d->format('Y') < 2000 || (int) $d->format('Y') > 2100) {
                $e[$k] = 'Дата в формате ДД.ММ.ГГГГ';
                return null;
            }
            return $v . ' ' . $time;
        };
        $starts = $date('starts_at', '00:00:00');
        $expires = $date('expires_at', '23:59:59');
        if ($starts && $expires && $expires < $starts) $e['expires_at'] = 'Дата окончания раньше даты начала';

        // ограничения — только существующие id
        $pick = static function (string $k, string $table) use ($db, &$e): array {
            $ids = Coupons::ids(Request::postArray($k));
            if (count(Request::postArray($k)) > Coupons::LIST_MAX) $e[$k] = 'Не больше ' . Coupons::LIST_MAX . ' элементов';
            if (!$ids) return [];
            [$ph, $vals] = $db->in($ids);
            $have = array_flip(array_map('intval', $db->col("SELECT id FROM `$table` WHERE id IN ($ph)", $vals)));
            return array_values(array_filter($ids, static fn($id) => isset($have[$id])));
        };
        $cats = $pick('category_ids', 'categories');
        $brands = $pick('brand_ids', 'brands');
        $products = $pick('product_ids', 'products');

        $data = [
            'code' => $code !== '' ? $code : mb_substr($raw, 0, Coupons::CODE_MAX), 'type' => $type, 'value' => round((float) $value, 2),
            'min_sum' => round($minSum, 2), 'min_boxes' => $minBoxes, 'max_discount' => round((float) $maxDisc, 2),
            'starts_at' => $starts, 'expires_at' => $expires, 'usage_limit' => $limit, 'per_customer_limit' => $perCustomer,
            'category_ids' => $cats ? json_encode($cats) : null, 'brand_ids' => $brands ? json_encode($brands) : null,
            'product_ids' => $products ? json_encode($products) : null,
            'status' => Request::post('status') === '1' ? 1 : 0,
            'comment' => ($cm = mb_substr(trim((string) preg_replace('/\s+/u', ' ', Request::post('comment'))), 0, 500)) !== '' ? $cm : null,
        ];
        // счётчик применений меняет только оформление заказа (Coupons::apply/release); форма его не перезаписывает —
        // иначе заказ, оформленный между открытием и сохранением формы, пропал бы из счётчика. Обнуление — явной галочкой.
        if ((int) $old['id'] === 0 || Request::post('reset_used') === '1') $data['used'] = 0;
        return [$data, $e];
    }

    /** Названия выбранных ограничений для формы: ['category' => [[id, name, sub]], 'brand' => …, 'product' => …] */
    private function picked(array $c): array
    {
        $out = ['category' => [], 'brand' => [], 'product' => []];
        if ($c['category_ids']) {
            $paths = self::categoryPaths();
            foreach ($c['category_ids'] as $id) {
                $out['category'][] = ['id' => $id, 'name' => $paths[$id][0] ?? 'Категория #' . $id . ' (удалена)', 'sub' => ''];
            }
        }
        $db = App::db();
        if ($c['brand_ids']) {
            [$ph, $vals] = $db->in($c['brand_ids']);
            $names = $db->pairs("SELECT id, name FROM brands WHERE id IN ($ph)", $vals);
            foreach ($c['brand_ids'] as $id) $out['brand'][] = ['id' => $id, 'name' => $names[$id] ?? 'Бренд #' . $id . ' (удалён)', 'sub' => ''];
        }
        if ($c['product_ids']) {
            [$ph, $vals] = $db->in($c['product_ids']);
            $rows = $db->keyed("SELECT id, name, sku FROM products WHERE id IN ($ph)", $vals);
            foreach ($c['product_ids'] as $id) {
                $r = $rows[$id] ?? null;
                $out['product'][] = ['id' => $id, 'name' => $r ? $r['name'] . ($r['sku'] !== '' ? ' · ' . $r['sku'] : '') : 'Товар #' . $id . ' (удалён)', 'sub' => ''];
            }
        }
        return $out;
    }

    /** Имена ограничений для строк списка: ['category' => [id => путь], 'brand' => [id => имя], 'product' => [id => имя]] */
    private function names(array $coupons): array
    {
        $cats = $brands = $products = [];
        foreach ($coupons as $c) {
            foreach ($c['category_ids'] as $id) $cats[$id] = true;
            foreach ($c['brand_ids'] as $id) $brands[$id] = true;
            foreach ($c['product_ids'] as $id) $products[$id] = true;
        }
        $out = ['category' => [], 'brand' => [], 'product' => []];
        if ($cats) {
            $paths = self::categoryPaths();
            foreach (array_keys($cats) as $id) $out['category'][$id] = $paths[$id][0] ?? '#' . $id;
        }
        if ($brands) {
            $all = Catalog::brands();
            foreach (array_keys($brands) as $id) $out['brand'][$id] = $all[$id]['name'] ?? '#' . $id;
        }
        if ($products) {
            $db = App::db();
            [$ph, $vals] = $db->in(array_keys($products));
            $out['product'] = $db->pairs("SELECT id, name FROM products WHERE id IN ($ph)", $vals);
        }
        return $out;
    }

    /** Все категории (и скрытые): [id => [«Родитель › Категория», активна]] в порядке дерева */
    private static function categoryPaths(): array
    {
        static $paths = null;
        if ($paths !== null) return $paths;
        $rows = App::db()->keyed('SELECT id, parent_id, name, status FROM categories ORDER BY lft, sort, id');
        $paths = [];
        foreach ($rows as $id => $r) {
            $chain = [nice_case((string) $r['name'])];
            $p = (int) $r['parent_id'];
            $guard = 0;
            while ($p && isset($rows[$p]) && $guard++ < 10) {
                array_unshift($chain, nice_case((string) $rows[$p]['name']));
                $p = (int) $rows[$p]['parent_id'];
            }
            $paths[(int) $id] = [implode(' › ', $chain), (int) $r['status'] === 1];
        }
        return $paths;
    }

    private function denied(string $to): Response
    {
        if (Request::isAjax()) return Response::json(['ok' => false, 'error' => 'Изменять промокоды может только администратор'], 403);
        $this->flash('Изменять промокоды может только администратор.', true);
        return Response::redirect($to);
    }

    private function missing(): Response
    {
        $r = $this->render('admin/forbidden', ['title' => 'Промокод не найден', 'message' => 'Такого промокода нет — возможно, его уже удалили.',
            'back' => ['/admin/coupons/', 'Все промокоды']]);
        $r->status = 404;
        return $r;
    }
}
