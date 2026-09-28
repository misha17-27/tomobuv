<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Str;
use App\Core\View;

/**
 * Сотрудники (только администратор) = учётные записи customers с ролью admin | manager.
 * Создание (или повышение существующего покупателя), роль, доступ, сброс пароля, лишение доступа.
 * Нельзя понизить, отключить или лишить доступа себя и последнего действующего администратора.
 * Сгенерированный пароль показывается один раз (одноразовое сообщение сессии).
 */
final class UsersController extends BaseController
{
    protected const MANAGER_ALLOWED = false;

    public const ROLES = ['admin' => 'Администратор', 'manager' => 'Менеджер'];

    /** Что может роль — подсказки в формах */
    public const ROLE_HINTS = [
        'admin'   => 'Полный доступ: всё, включая настройки, почту, сотрудников и безопасность.',
        'manager' => 'Заказы, заявки, клиенты, отчёты, товары, каталог и контент. Недоступны разделы «Сотрудники» и «Безопасность»; '
            . '«Настройки», «Почта», WhatsApp и промокоды — только просмотр, без изменений; нельзя перестраивать индекс каталога и удалять изображения.',
    ];

    /** Вкладки списка: ключ → [подпись, условие SQL] */
    private const TABS = [
        'all'     => ['Все', ''],
        'admin'   => ['Администраторы', "role = 'admin' AND status = 1"],
        'manager' => ['Менеджеры', "role = 'manager' AND status = 1"],
        'off'     => ['Отключены', 'status = 0'],
    ];

    public const MIN_PASSWORD = 10;
    private const INVITE_TTL = 72 * 3600;   // ссылка «задать пароль» в приглашении

    // ------------------------------------------------------------------ список

    public function index(): Response
    {
        return $this->listPage();
    }

    private function listPage(array $form = [], array $errors = [], int $status = 200): Response
    {
        $db = App::db();
        $tab = Request::get('tab', 'all');
        if (!isset(self::TABS[$tab])) $tab = 'all';
        $q = mb_substr(Request::get('q'), 0, 100);

        $c = $db->row("SELECT COUNT(*) n, SUM(role = 'admin' AND status = 1) a, SUM(role = 'manager' AND status = 1) m, SUM(status = 0) off,
            SUM(status = 1 AND (last_login_at IS NULL OR last_login_at < ?)) stale
            FROM customers WHERE role IN ('admin','manager')", [date('Y-m-d H:i:s', time() - 180 * 86400)]) ?: [];
        $counts = ['all' => (int) ($c['n'] ?? 0), 'admin' => (int) ($c['a'] ?? 0), 'manager' => (int) ($c['m'] ?? 0), 'off' => (int) ($c['off'] ?? 0)];

        $w = ["role IN ('admin','manager')"];
        $p = [];
        if (self::TABS[$tab][1] !== '') $w[] = self::TABS[$tab][1];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $digits = preg_replace('/\D+/', '', $q);
            array_push($p, $like, $like, $digits !== '' && strlen($digits) >= 3 ? '%' . $digits . '%' : $like);
        }
        $rows = $db->all('SELECT id, name, email, phone, role, status, last_login_at, created_at, (password LIKE \'wa:%\') legacy
            FROM customers WHERE ' . implode(' AND ', $w) . " ORDER BY status DESC, role = 'admin' DESC, name ASC, id ASC LIMIT 500", $p);

        // IP последнего входа — одним запросом по индексу user_created
        $lastIp = [];
        if ($rows) {
            [$ph, $ids] = $db->in(array_map(static fn($r) => (int) $r['id'], $rows));
            $lastIp = $db->pairs("SELECT a.user_id, a.ip FROM admin_log a
                JOIN (SELECT user_id, MAX(id) id FROM admin_log WHERE action = 'login' AND user_id IN ($ph) GROUP BY user_id) m ON m.id = a.id", $ids);
        }

        return self::status($this->render('admin/users/index', [
            'title' => 'Сотрудники', 'rows' => $rows, 'counts' => $counts, 'stale' => (int) ($c['stale'] ?? 0),
            'tab' => $tab, 'q' => $q, 'tabs' => self::TABS, 'lastIp' => $lastIp, 'me' => Auth::id(),
            'form' => $form + ['email' => '', 'name' => '', 'phone' => '', 'role' => 'manager', 'invite' => ''],
            'errors' => $errors,
            'styles' => ['admin/system.css'], 'scripts' => ['admin/system.js'],
            'actions' => '<a class="btn btn-sm btn-p" href="#new">+ Добавить сотрудника</a>',
        ]), $status);
    }

    // ------------------------------------------------------------------ создание

    /** POST /admin/users/create/ — новый сотрудник (или покупатель с этим e-mail получает роль) */
    public function create(): Response
    {
        $db = App::db();
        $form = [
            'email'  => mb_strtolower(mb_substr(Request::post('email'), 0, 190)),
            'name'   => mb_substr(Request::post('name'), 0, 190),
            'phone'  => mb_substr(Request::post('phone'), 0, 32),
            'role'   => Request::post('role', 'manager'),
            'invite' => Request::post('invite') === '1' ? '1' : '',
        ];
        $password = self::rawPost('password');
        $errors = [];
        if (isset($_POST['password']) && !is_string($_POST['password'])) $errors['password'] = 'Неверное значение пароля.';
        $email = Str::email($form['email']);
        if ($email === '') $errors['email'] = 'Укажите e-mail — это логин для входа.';
        if (!isset(self::ROLES[$form['role']])) $errors['role'] = 'Выберите роль.';
        $phone = '';
        if ($form['phone'] !== '') {
            $phone = Str::phone($form['phone']);
            if ($phone === '') $errors['phone'] = 'Телефон в формате +38 0XX XXX XX XX или оставьте пустым.';
        }
        if ($password !== '' && ($e = self::passwordError($password))) $errors['password'] = $e;

        $existing = $email !== '' ? $db->row('SELECT id, name, role, status FROM customers WHERE email = ?', [$email]) : null;
        if ($existing && in_array($existing['role'], ['admin', 'manager'], true)) {
            $errors['email'] = 'Этот e-mail уже у сотрудника «' . ($existing['name'] ?: $email) . '» (#' . (int) $existing['id'] . ').';
        }
        if (!$errors && $phone !== '' && ($dup = self::phoneOwner($phone, (int) ($existing['id'] ?? 0)))) {
            $errors['phone'] = 'Этот телефон уже указан у учётной записи #' . $dup . ' — вход по телефону стал бы неоднозначным. Оставьте поле пустым.';
        }
        if ($errors) return $this->listPage($form, $errors, 422);

        $generated = $password === '';
        if ($generated) $password = self::generatePassword();
        $name = $form['name'] !== '' ? $form['name'] : ((string) ($existing['name'] ?? '') ?: (string) strstr($email, '@', true));
        $data = ['name' => $name, 'role' => $form['role'], 'status' => 1, 'password' => password_hash($password, PASSWORD_DEFAULT),
            'reset_token' => null, 'reset_expires' => null];
        if ($phone !== '') $data['phone'] = $phone;

        if ($existing) {
            $id = (int) $existing['id'];
            $db->update('customers', $data, 'id = ?', [$id]);
            $this->log('staff_create', 'staff', $id, ['role' => $form['role'], 'from_customer' => true]);
            $msg = 'Покупатель «' . $name . '» (#' . $id . ') стал сотрудником: ' . mb_strtolower(self::ROLES[$form['role']]) . '. Пароль заменён.';
        } else {
            $id = $db->insert('customers', $data + ['email' => $email, 'created_at' => date('Y-m-d H:i:s')]);
            $this->log('staff_create', 'staff', $id, ['role' => $form['role']]);
            $msg = 'Сотрудник «' . $name . '» добавлен: ' . mb_strtolower(self::ROLES[$form['role']]) . '.';
        }
        \App\Core\Cache::forget('admin.tally');

        if ($form['invite'] === '1') {
            [$ok, $sent] = self::sendInvite($id);
            if ($ok) $msg .= ' ' . $sent; else $this->flash($sent, true);
        }
        $this->flash($msg);
        if ($generated) Session::flash('sys_newpass', ['id' => $id, 'password' => $password, 'email' => $email]);
        return Response::redirect('/admin/users/' . $id . '/');
    }

    // ------------------------------------------------------------------ карточка

    /** GET /admin/users/{id}/ — карточка; POST — сохранить имя, e-mail, телефон, роль, доступ */
    public function edit(string $id): Response
    {
        $u = self::find($id);
        if (!$u) return $this->missing($id);
        if (Request::isPost()) return $this->save($u);
        return $this->card($u);
    }

    private function card(array $u, array $errors = [], array $form = [], int $status = 200): Response
    {
        $db = App::db();
        $uid = (int) $u['id'];
        // входы и неудачные попытки: при неверном пароле user_id в журнале пуст — ищем по введённому e-mail/логину/телефону
        $logins = SecurityController::loginEvents($u, 12);
        $did = $db->all("SELECT action, entity, entity_id, details, created_at FROM admin_log WHERE user_id = ? AND action NOT IN ('login','login_failed')
            ORDER BY created_at DESC, id DESC LIMIT 12", [$uid]);
        $about = $db->all("SELECT a.action, a.details, a.created_at, c.name, c.email FROM admin_log a LEFT JOIN customers c ON c.id = a.user_id
            WHERE a.entity = 'staff' AND a.entity_id = ? AND a.action LIKE 'staff\\_%' ORDER BY a.created_at DESC, a.id DESC LIMIT 12", [$uid]);
        $stats = $db->row("SELECT COUNT(*) n, MAX(created_at) last FROM admin_log WHERE user_id = ? AND action = 'login'", [$uid]) ?: [];
        $newPass = Session::flash('sys_newpass');
        if (!is_array($newPass) || (int) ($newPass['id'] ?? 0) !== $uid) $newPass = null;

        return self::status($this->render('admin/users/edit', [
            'title' => $u['name'] !== '' ? $u['name'] : (string) $u['email'], 'u' => $u, 'isSelf' => $uid === Auth::id(),
            'lastAdmin' => $u['role'] === 'admin' && (int) $u['status'] === 1 && self::otherAdmins($uid) === 0,
            'logins' => $logins, 'did' => $did, 'about' => $about, 'loginCount' => (int) ($stats['n'] ?? 0),
            'newPass' => $newPass, 'errors' => $errors, 'form' => $form + $u,
            'back' => ['/admin/users/', 'Все сотрудники'],
            'styles' => ['admin/system.css'], 'scripts' => ['admin/system.js'],
        ]), $status);
    }

    private function save(array $u): Response
    {
        $db = App::db();
        $uid = (int) $u['id'];
        $self = $uid === Auth::id();
        $form = [
            'name'  => mb_substr(Request::post('name'), 0, 190),
            'email' => mb_strtolower(mb_substr(Request::post('email'), 0, 190)),
            'phone' => mb_substr(Request::post('phone'), 0, 32),
            'role'  => Request::post('role', (string) $u['role']),
            'status' => Request::post('status') === '1' ? 1 : 0,
        ];
        $errors = [];
        if ($form['name'] === '') $errors['name'] = 'Укажите имя — его видно в журнале и заказах.';
        $email = Str::email($form['email']);
        if ($email === '') $errors['email'] = 'Укажите правильный e-mail — это логин для входа.';
        elseif ($db->value('SELECT id FROM customers WHERE email = ? AND id <> ?', [$email, $uid])) $errors['email'] = 'Этот e-mail уже занят другой учётной записью.';
        elseif ($self && $email !== (string) $u['email']) $errors['email'] = 'Свой e-mail (логин) меняйте в «Мой аккаунт» — там нужен текущий пароль.';
        $phone = null;
        if ($form['phone'] !== '') {
            $phone = Str::phone($form['phone']);
            if ($phone === '') $errors['phone'] = 'Телефон в формате +38 0XX XXX XX XX или оставьте пустым.';
            elseif ($dup = self::phoneOwner($phone, $uid)) $errors['phone'] = 'Этот телефон уже у учётной записи #' . $dup . ' — вход по телефону стал бы неоднозначным.';
        }
        if (!isset(self::ROLES[$form['role']])) $errors['role'] = 'Выберите роль.';

        // себя и последнего администратора не понижаем и не отключаем
        $losesAdmin = $u['role'] === 'admin' && (int) $u['status'] === 1 && ($form['role'] !== 'admin' || !$form['status']);
        if ($self && ($form['role'] !== $u['role'] || !$form['status'])) {
            $errors['role'] = 'Свою роль и доступ изменить нельзя — попросите другого администратора.';
        } elseif ($losesAdmin && self::otherAdmins($uid) === 0) {
            $errors['role'] = 'Это единственный действующий администратор — сначала назначьте администратором кого-то ещё.';
        }
        if ($errors) return $this->card($u, $errors, $form, 422);

        $data = ['name' => $form['name'], 'email' => $email, 'phone' => $phone, 'role' => $form['role'], 'status' => $form['status']];
        $changed = [];
        foreach ($data as $k => $v) {
            if ((string) $u[$k] === (string) $v) continue;
            $changed[$k] = in_array($k, ['role', 'status'], true) ? ['from' => $u[$k], 'to' => $v] : true;   // личные данные в журнал не пишем
        }
        if (!$changed) { $this->flash('Изменений нет.'); return Response::redirect('/admin/users/' . $uid . '/'); }
        $db->update('customers', $data, 'id = ?', [$uid]);
        $this->log('staff_update', 'staff', $uid, $changed);
        \App\Core\Cache::forget('admin.tally');
        $msg = [];
        if (isset($changed['status'])) $msg[] = $form['status'] ? 'Доступ открыт — сотрудник снова может войти.' : 'Доступ закрыт — сотрудник вышел из админки и не сможет войти.';
        if (isset($changed['role'])) $msg[] = 'Роль изменена: ' . mb_strtolower(self::ROLES[$form['role']]) . '. Действует сразу.';
        $this->flash($msg ? implode(' ', $msg) : 'Сохранено.');
        return Response::redirect('/admin/users/' . $uid . '/');
    }

    /** POST /admin/users/{id}/password/ — новый пароль сотруднику (введённый или сгенерированный) */
    public function password(string $id): Response
    {
        $u = self::find($id);
        if (!$u) return $this->missing($id);
        $uid = (int) $u['id'];
        if ($uid === Auth::id()) {
            $this->flash('Свой пароль меняйте в разделе «Мой аккаунт» — там нужен текущий пароль.', true);
            return Response::redirect('/admin/account/#password');
        }
        $password = self::rawPost('password');
        if (isset($_POST['password']) && !is_string($_POST['password'])) return $this->card($u, ['password' => 'Неверное значение пароля.'], [], 422);
        if ($password !== '' && ($e = self::passwordError($password))) {
            return $this->card($u, ['password' => $e], [], 422);
        }
        $generated = $password === '';
        if ($generated) $password = self::generatePassword();
        App::db()->update('customers', ['password' => password_hash($password, PASSWORD_DEFAULT), 'reset_token' => null, 'reset_expires' => null], 'id = ?', [$uid]);
        $ended = self::endSessions($uid);           // пароль сбрасывают, когда его могли узнать — открытые сеансы со старым паролем закрываем
        $this->log('staff_password', 'staff', $uid, ['generated' => $generated, 'sessions_ended' => $ended]);
        if ($generated) Session::flash('sys_newpass', ['id' => $uid, 'password' => $password, 'email' => (string) $u['email']]);
        $this->flash('Пароль изменён. Старый больше не подходит' . ($ended ? ', открытые сеансы сотрудника (' . $ended . ') завершены' : '')
            . ($generated ? ' — передайте новый пароль сотруднику лично.' : '.'));
        return Response::redirect('/admin/users/' . $uid . '/');
    }

    /** POST /admin/users/{id}/invite/ — письмо со ссылкой «задать пароль» (на 72 часа) */
    public function invite(string $id): Response
    {
        $u = self::find($id);
        if (!$u) return $this->missing($id);
        if (!(int) $u['status']) {
            $this->flash('Сначала откройте сотруднику доступ.', true);
            return Response::redirect('/admin/users/' . (int) $u['id'] . '/');
        }
        if (!\App\Core\RateLimit::hit('mailtest:' . Auth::id(), 10, 3600)) {
            $this->flash('Слишком много писем подряд — подождите час.', true);
            return Response::redirect('/admin/users/' . (int) $u['id'] . '/');
        }
        [$ok, $msg] = self::sendInvite((int) $u['id']);
        $this->flash($msg, !$ok);
        return Response::redirect('/admin/users/' . (int) $u['id'] . '/');
    }

    /** POST /admin/users/{id}/revoke/ — лишить доступа к админке (роль «покупатель», учётная запись и заказы остаются) */
    public function revoke(string $id): Response
    {
        $u = self::find($id);
        if (!$u) return $this->missing($id);
        $uid = (int) $u['id'];
        if ($uid === Auth::id()) {
            $this->flash('Себя лишить доступа нельзя — попросите другого администратора.', true);
            return Response::redirect('/admin/users/' . $uid . '/');
        }
        if ($u['role'] === 'admin' && (int) $u['status'] === 1 && self::otherAdmins($uid) === 0) {
            $this->flash('Это единственный действующий администратор — его нельзя лишить доступа.', true);
            return Response::redirect('/admin/users/' . $uid . '/');
        }
        App::db()->update('customers', ['role' => 'customer', 'reset_token' => null, 'reset_expires' => null], 'id = ?', [$uid]);
        $this->log('staff_revoke', 'staff', $uid, ['from' => $u['role']]);
        \App\Core\Cache::forget('admin.tally');
        $this->flash('«' . ($u['name'] ?: $u['email']) . '» больше не сотрудник: вход в админку закрыт сразу. Учётная запись покупателя и заказы сохранены.');
        return Response::redirect('/admin/users/');
    }

    // ------------------------------------------------------------------ помощники

    private static function status(Response $r, int $code): Response
    {
        $r->status = $code;
        return $r;
    }

    private static function find(string $id): ?array
    {
        if (!ctype_digit($id)) return null;
        return App::db()->row("SELECT id, name, email, login, phone, role, status, created_at, last_login_at, orders_count,
            (password LIKE 'wa:%') legacy, (password IS NULL OR password = '') nopass, reset_expires
            FROM customers WHERE id = ? AND role IN ('admin','manager')", [(int) $id]);
    }

    private function missing(string $id): Response
    {
        $cid = ctype_digit($id) ? (int) $id : 0;
        $isCustomer = $cid && App::db()->value("SELECT 1 FROM customers WHERE id = ? AND role = 'customer'", [$cid]);
        return self::status($this->render('admin/forbidden', [
            'title' => 'Сотрудник не найден',
            'message' => $isCustomer ? 'Учётная запись #' . $cid . ' — покупатель, а не сотрудник. Чтобы дать ей доступ в админку, добавьте сотрудника с её e-mail.' : 'Такого сотрудника нет.',
            'back' => ['/admin/users/', 'Все сотрудники'],
        ]), 404);
    }

    /** Сколько ещё действующих администраторов, кроме $exceptId */
    public static function otherAdmins(int $exceptId): int
    {
        return (int) App::db()->value("SELECT COUNT(*) FROM customers WHERE role = 'admin' AND status = 1 AND id <> ?", [$exceptId]);
    }

    /** id другой учётной записи с этим телефоном (вход по телефону берёт первую) */
    private static function phoneOwner(string $phone, int $exceptId): int
    {
        return (int) App::db()->value('SELECT id FROM customers WHERE phone = ? AND id <> ? AND status = 1 ORDER BY id LIMIT 1', [$phone, $exceptId]);
    }

    /**
     * Завершить сеансы учётной записи: удалить её файлы сессий (storage/sessions, см. Core\Session).
     * Ищем «uid|i:ID;» (session.serialize_handler=php) и «s:3:"uid";i:ID;» (php_serialize). Обход ограничен 2 секундами;
     * занятый файл (идёт запрос) или файл, который не удалось удалить, пропускаем. Текущий сеанс не трогаем.
     * Возвращает число завершённых сеансов.
     */
    public static function endSessions(int $uid): int
    {
        if ($uid <= 0) return 0;
        $mine = session_status() === PHP_SESSION_ACTIVE ? 'sess_' . session_id() : '';
        $re = '/(?:^|[;}])uid\|i:' . $uid . ';|s:3:"uid";i:' . $uid . ';/';
        $n = 0;
        $end = microtime(true) + 2.0;
        foreach (glob(STORAGE . '/sessions/sess_*', GLOB_NOSORT) ?: [] as $f) {
            if (microtime(true) > $end) break;
            if (basename($f) === $mine || (int) @filesize($f) > 65536) continue;
            $s = @file_get_contents($f);
            if (is_string($s) && preg_match($re, $s) && @unlink($f)) $n++;
        }
        return $n;
    }

    /** Пароль из формы как есть (без trim); массив вместо строки (password[]=…) — пустая строка */
    public static function rawPost(string $key): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    public static function passwordError(string $p): string
    {
        if (mb_strlen($p) < self::MIN_PASSWORD) return 'Пароль — не меньше ' . self::MIN_PASSWORD . ' символов (или оставьте пустым, чтобы сгенерировать).';
        if (mb_strlen($p) > 200) return 'Слишком длинный пароль.';
        if (trim($p) !== $p) return 'Пароль не должен начинаться или заканчиваться пробелом.';
        if (preg_match('/^(.)\1+$/u', $p) || preg_match('/^(0123456789|1234567890|qwertyuiop|password|пароль)/iu', $p)) return 'Слишком простой пароль.';
        return '';
    }

    /** 14 символов без похожих (0/O, 1/l/I) — удобно продиктовать */
    public static function generatePassword(int $len = 14): string
    {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $s = '';
            for ($i = 0; $i < $len; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
        } while (!preg_match('/\d/', $s) || !preg_match('/[a-z]/', $s) || !preg_match('/[A-Z]/', $s));
        return $s;
    }

    /**
     * Приглашение: логин, адрес админки и ссылка «задать свой пароль» (форма восстановления пароля сайта, 72 часа).
     * Пароль в письме не отправляется. Локально (env=dev без mail.dev_send) письмо сохраняется в storage/logs/mail/.
     * @return array{0: bool, 1: string}
     */
    private static function sendInvite(int $id): array
    {
        $db = App::db();
        $u = $db->row('SELECT id, name, email, role FROM customers WHERE id = ?', [$id]);
        if (!$u || !$u['email']) return [false, 'Приглашение не отправлено: у сотрудника нет e-mail.'];
        $raw = bin2hex(random_bytes(32));
        $db->update('customers', ['reset_token' => hash('sha256', $raw), 'reset_expires' => date('Y-m-d H:i:s', time() + self::INVITE_TTL)], 'id = ?', [$id]);
        $store = (string) \App\Core\Settings::get('store_name', 'Tomobuv');
        $title = 'Доступ в админку ' . $store;
        try {
            $content = View::render('admin/users/invite-email', [
                'u' => $u, 'role' => self::ROLES[$u['role']] ?? $u['role'], 'store' => $store,
                'link' => url('/forgotpassword/reset/?t=' . $raw), 'admin' => url('/admin/'), 'hours' => (int) (self::INVITE_TTL / 3600),
                'from' => (string) (Auth::user()['name'] ?? ''),
            ], null);
            $html = View::render('emails/layout', ['title' => $title, 'content' => $content, 'preheader' => 'Вам открыт доступ в админку. Задайте пароль по ссылке.'], null);
        } catch (\Throwable $e) {
            Log::error('Приглашение сотрудника #' . $id . ': ' . $e->getMessage());
            return [false, 'Приглашение не отправлено: ошибка шаблона письма.'];
        }
        if (App::config('env') === 'dev' && !App::config('mail.dev_send', false)) {
            $dir = STORAGE . '/logs/mail';
            @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/' . date('Ymd-His') . '-staff' . $id . '-invite.html', $html);
            $db->insert('admin_log', ['user_id' => Auth::id(), 'action' => 'staff_invite', 'entity' => 'staff', 'entity_id' => $id, 'details' => 'dev', 'ip' => Request::ip()]);
            Log::write('mail', 'Приглашение сотрудника #' . $id . ' сохранено в storage/logs/mail (локальная разработка)');
            return [true, 'Приглашение сохранено в storage/logs/mail (локальная разработка, адресат ' . $u['email'] . ').'];
        }
        $db->insert('admin_log', ['user_id' => Auth::id(), 'action' => 'staff_invite', 'entity' => 'staff', 'entity_id' => $id, 'ip' => Request::ip()]);
        if (Mailer::send((string) $u['email'], $title, $html)) {
            return [true, 'Приглашение отправлено на ' . $u['email'] . ' — ссылка действует ' . (int) (self::INVITE_TTL / 3600) . ' часа.'];
        }
        Log::write('mail', 'Не удалось отправить приглашение сотруднику #' . $id . ': ' . Mailer::$lastError);
        return [false, 'Приглашение не отправлено: ' . (Mailer::$lastError ?: 'ошибка почты') . '. Проверьте раздел «Почта (SMTP)».'];
    }
}
