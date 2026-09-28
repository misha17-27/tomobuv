<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Str;
use App\Core\View;

/**
 * Клиенты (и сотрудники — та же таблица customers, поле role).
 * Роль меняет только администратор; менеджер не может редактировать, блокировать
 * и сбрасывать пароль сотрудникам (защита от захвата учётной записи администратора).
 */
final class CustomersController extends BaseController
{
    public const ROLES = ['customer' => 'Покупатель', 'manager' => 'Менеджер', 'admin' => 'Администратор'];
    private const SORTS = [
        'new'    => 'created_at DESC, id DESC',
        'spent'  => 'total_spent DESC, id DESC',
        'orders' => 'orders_count DESC, id DESC',
        'login'  => 'last_login_at DESC, id DESC',
        'name'   => 'name ASC, id ASC',
    ];
    private const FILTERS = ['all' => 'Все', 'buyers' => 'С заказами', 'staff' => 'Сотрудники', 'blocked' => 'Заблокированные'];
    private const PER_PAGE = 50;
    /** Заказов на странице карточки клиента */
    private const ORDERS_PER_PAGE = 30;
    /** Срок ссылки смены пароля, созданной менеджером, сек */
    private const LINK_TTL = 86400;

    public function index(): Response
    {
        $db = App::db();
        $q = mb_substr(Request::get('q'), 0, 100);
        $filter = Request::get('filter', 'all');
        if (!isset(self::FILTERS[$filter])) $filter = 'all';
        $sort = Request::get('sort', 'new');
        if (!isset(self::SORTS[$sort])) $sort = 'new';

        [$w, $p] = self::where($q);
        if ($filter === 'staff') $w[] = "role IN ('admin','manager')";
        elseif ($filter === 'blocked') $w[] = 'status = 0';
        elseif ($filter === 'buyers') $w[] = 'orders_count > 0';
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';

        $total = (int) $db->value('SELECT COUNT(*) FROM customers' . $where, $p);
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $rows = $total ? $db->all('SELECT id, name, company, email, phone, city, role, status, orders_count, total_spent, created_at, last_login_at
            FROM customers' . $where . ' ORDER BY ' . self::SORTS[$sort] . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, $p) : [];

        return $this->render('admin/customers/index', [
            'title' => 'Клиенты', 'rows' => $rows, 'pg' => $pg, 'total' => $total, 'q' => $q, 'filter' => $filter, 'sort' => $sort,
            'filters' => self::FILTERS, 'styles' => ['admin/sales.css'], 'scripts' => ['admin/sales.js'],
            'actions' => '<a class="btn btn-sm btn-p" href="/admin/orders/new/">' . icon('plus') . ' Новый заказ</a>',
        ]);
    }

    /** Условия поиска по имени / телефону / e-mail / id */
    private static function where(string $q): array
    {
        $q = trim($q);
        if ($q === '') return [[], []];
        $digits = (string) preg_replace('/\D+/', '', $q);
        if (str_contains($q, '@')) return [['email LIKE ?'], ['%' . addcslashes(mb_strtolower($q), '%_\\') . '%']];
        if (preg_match('/^#?\d{1,7}$/', $q)) return [['(id = ? OR phone LIKE ?)'], [(int) $digits, '%' . $digits . '%']];
        if (strlen($digits) >= 5 && preg_match('/^[\d\s()+\-.]+$/', $q)) return [['phone LIKE ?'], ['%' . $digits . '%']];
        $like = '%' . addcslashes($q, '%_\\') . '%';
        return [['(name LIKE ? OR email LIKE ? OR company LIKE ? OR city LIKE ?)'], [$like, $like, $like, $like]];
    }

    /** GET /admin/customers/search.json?q= — для нового заказа */
    public function search(): Response
    {
        $q = trim(mb_substr(Request::get('q'), 0, 100));
        if (mb_strlen($q) < 2) return Response::json(['ok' => true, 'items' => []]);
        [$w, $p] = self::where($q);
        $rows = App::db()->all('SELECT id, name, phone, email, city, lang, orders_count, status FROM customers WHERE ' . implode(' AND ', $w)
            . ' ORDER BY orders_count DESC, id DESC LIMIT 12', $p);
        $items = array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'phone' => (string) $r['phone'],
            'phone_f' => OrdersController::phone($r['phone']), 'email' => (string) $r['email'], 'city' => (string) $r['city'], 'lang' => (string) $r['lang'],
            'orders_count' => (int) $r['orders_count'], 'blocked' => !(int) $r['status']], $rows);
        return Response::json(['ok' => true, 'items' => $items]);
    }

    /** Карточка клиента; POST — действия (action = save | block | unblock | password | link | recalc) */
    public function show(string $id): Response
    {
        if (!ctype_digit($id)) return $this->missing();
        $db = App::db();
        $c = $db->row('SELECT * FROM customers WHERE id = ?', [(int) $id]);
        if (!$c) return $this->missing();
        $cid = (int) $c['id'];

        if (Request::isPost()) return $this->act($c);

        [$ph, $vals] = $db->in(OrdersController::PAID);
        $stats = $db->row("SELECT COUNT(*) cnt, COALESCE(SUM(CASE WHEN status <> 'deleted' THEN total END), 0) sum_all,
            COALESCE(SUM(CASE WHEN status IN ($ph) THEN total END), 0) sum_paid, COALESCE(SUM(CASE WHEN status <> 'deleted' THEN boxes END), 0) boxes,
            MAX(created_at) last_at FROM orders WHERE customer_id = ?", array_merge($vals, [$cid]));
        // заказы клиента — постранично (у оптовиков бывают сотни заказов), индекс customer (customer_id, created_at)
        $opg = new Paginator((int) $stats['cnt'], self::ORDERS_PER_PAGE, Request::page());
        $orders = $db->all('SELECT id, status, total, boxes, pairs, name, phone, shipping_method, shipping_name, city, payment_method, payment_name,
            source, lang, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . self::ORDERS_PER_PAGE . ' OFFSET ' . $opg->offset, [$cid]);
        $requests = $c['phone'] ? $db->all('SELECT id, type, status, text, created_at FROM requests WHERE phone = ? ORDER BY created_at DESC LIMIT 20', [$c['phone']]) : [];

        return $this->render('admin/customers/show', [
            'title' => ($c['name'] !== '' ? $c['name'] : 'Клиент #' . $cid), 'c' => $c, 'orders' => $orders, 'opg' => $opg, 'stats' => $stats, 'requests' => $requests,
            'canEdit' => self::canManage($c), 'isAdmin' => Auth::isAdmin(), 'isSelf' => $cid === Auth::id(),
            'shipping' => OrdersController::methods('shipping_methods'), 'payment' => OrdersController::methods('payment_methods'),
            'tempPass' => \App\Core\Session::flash('temp_pass'), 'resetLink' => \App\Core\Session::flash('reset_link'),
            'styles' => ['admin/sales.css'], 'scripts' => ['admin/sales.js'],
            'back' => ['/admin/customers/', 'Все клиенты'],
            'actions' => '<a class="btn btn-sm btn-p" href="/admin/orders/new/?customer=' . $cid . '">' . icon('plus') . ' Новый заказ</a>',
        ]);
    }

    /** Менеджер может менять только покупателей; сотрудников — только администратор */
    private static function canManage(array $c): bool
    {
        return Auth::isAdmin() || $c['role'] === 'customer';
    }

    private function act(array $c): Response
    {
        $cid = (int) $c['id'];
        $back = Response::redirect('/admin/customers/' . $cid . '/');
        $action = Request::post('action');
        if (!self::canManage($c)) { $this->flash('Изменять данные сотрудников может только администратор.', true); return $back; }
        $db = App::db();
        $self = $cid === Auth::id();

        switch ($action) {
            case 'save':
                $email = Str::email(Request::post('email'));
                if (Request::post('email') !== '' && $email === '') { $this->flash('Неверный e-mail.', true); return $back; }
                if ($email !== '' && $db->value('SELECT id FROM customers WHERE email = ? AND id <> ?', [$email, $cid])) {
                    $this->flash('Этот e-mail уже указан у другого клиента.', true); return $back;
                }
                $phoneRaw = Request::post('phone');
                $phone = Str::phone($phoneRaw);
                if ($phoneRaw !== '' && $phone === '') { $this->flash('Неверный телефон.', true); return $back; }
                $data = [
                    'name' => mb_substr(Request::post('name'), 0, 190), 'firstname' => mb_substr(Request::post('firstname'), 0, 100),
                    'lastname' => mb_substr(Request::post('lastname'), 0, 100), 'company' => mb_substr(Request::post('company'), 0, 190),
                    'email' => $email ?: null, 'phone' => $phone ?: null, 'city' => mb_substr(Request::post('city'), 0, 190) ?: null,
                    'note' => mb_substr(Request::post('note'), 0, 5000) ?: null,
                ];
                if ($data['name'] === '') $data['name'] = trim($data['firstname'] . ' ' . $data['lastname']);
                // роль: только администратор и не себе (чтобы не лишиться доступа)
                $role = Request::post('role');
                if (Auth::isAdmin() && !$self && isset(self::ROLES[$role]) && $role !== $c['role']) {
                    $data['role'] = $role;
                    $this->log('customer_role', 'customer', $cid, ['from' => $c['role'], 'to' => $role]);
                }
                $db->update('customers', $data, 'id = ?', [$cid]);
                $this->log('customer_edit', 'customer', $cid);
                $this->flash('Данные клиента сохранены.');
                return $back;

            case 'block':
            case 'unblock':
                if ($self) { $this->flash('Нельзя заблокировать свою учётную запись.', true); return $back; }
                $db->update('customers', ['status' => $action === 'block' ? 0 : 1], 'id = ?', [$cid]);
                $this->log('customer_' . $action, 'customer', $cid);
                $this->flash($action === 'block' ? 'Клиент заблокирован: вход на сайт и в кабинет закрыт.' : 'Клиент разблокирован.');
                return $back;

            case 'password':
                $pass = self::tempPassword();
                $db->update('customers', ['password' => password_hash($pass, PASSWORD_DEFAULT), 'reset_token' => null, 'reset_expires' => null], 'id = ?', [$cid]);
                $this->log('customer_password', 'customer', $cid);
                $sent = Request::post('send') === '1' && $c['email'] && self::mailPassword($c, $pass, '');
                \App\Core\Session::flash('temp_pass', $pass);
                $this->flash('Установлен временный пароль' . ($sent ? ' и отправлен клиенту на ' . $c['email'] : '')
                    . (Request::post('send') === '1' && !$sent ? '. Письмо не отправлено (нет e-mail или ошибка почты)' : '')
                    . '. Передайте его клиенту — повторно он не показывается.', Request::post('send') === '1' && !$sent);
                return $back;

            case 'link':
                // ссылка на страницу смены пароля витрины (/forgotpassword/reset/?t=…), старый пароль продолжает работать до смены
                $raw = bin2hex(random_bytes(32));
                $db->update('customers', ['reset_token' => hash('sha256', $raw), 'reset_expires' => date('Y-m-d H:i:s', time() + self::LINK_TTL)], 'id = ?', [$cid]);
                $link = url(Lang::path('/forgotpassword/reset/?t=' . $raw, self::lang($c)));
                $this->log('customer_reset_link', 'customer', $cid);
                $sent = Request::post('send') === '1' && $c['email'] && self::mailPassword($c, '', $link);
                \App\Core\Session::flash('reset_link', $link);
                $this->flash('Создана ссылка для смены пароля (действует ' . (self::LINK_TTL / 3600) . ' ч)' . ($sent ? ' и отправлена клиенту на ' . $c['email'] : '')
                    . (Request::post('send') === '1' && !$sent ? '. Письмо не отправлено (нет e-mail или ошибка почты)' : '') . '.', Request::post('send') === '1' && !$sent);
                return $back;

            case 'recalc':
                OrdersController::recalcCustomer($cid);
                $this->flash('Статистика клиента пересчитана по заказам.');
                return $back;
        }
        $this->flash('Неизвестное действие.', true);
        return $back;
    }

    /** Язык клиента для писем и ссылок: ru | uk */
    private static function lang(array $c): string
    {
        $l = (string) ($c['lang'] ?? 'ru');
        return isset(Lang::LANGS[$l]) ? $l : Lang::DEFAULT;
    }

    /** Письмо клиенту: временный пароль ($pass) или ссылка смены пароля ($link) — на языке клиента */
    private static function mailPassword(array $c, string $pass, string $link): bool
    {
        $lang = self::lang($c);
        [$subject, $html] = OrdersController::inLang($lang, static function () use ($c, $pass, $link, $lang) {
            $store = (string) Settings::get('store_name', 'Tomobuv');
            $html = View::render('admin/customers/email-password', ['c' => $c, 'lang' => $lang, 'pass' => $pass, 'link' => $link,
                'hours' => (int) (self::LINK_TTL / 3600)], null);
            return [($pass !== '' ? t('Новый пароль') : t('Смена пароля')) . ' — ' . $store, $html];
        });
        $ok = OrdersController::mail((string) $c['email'], $subject, $html, 'customer' . (int) $c['id'] . ($pass !== '' ? '-password' : '-reset-link'));
        if (!$ok) Log::error('Не отправлено письмо с паролем клиенту #' . $c['id'] . ': ' . Mailer::$lastError);
        return $ok;
    }

    /** Временный пароль без похожих символов (0/O, 1/l) */
    private static function tempPassword(int $len = 10): string
    {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
        return $s;
    }

    private function missing(): Response
    {
        return Response::html($this->render('admin/forbidden', ['title' => 'Клиент не найден', 'message' => 'Такого клиента нет.'])->body, 404);
    }
}
