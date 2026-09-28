<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Str;

/**
 * «Мой аккаунт» (любой сотрудник): имя, e-mail, телефон; смена пароля с вводом текущего.
 * Смена e-mail (логина) тоже требует текущий пароль. Неверный текущий пароль — счётчик admin-pw:{id}
 * (10 попыток за 15 минут), после смены пароля — новый идентификатор сессии.
 */
final class AccountController extends BaseController
{
    private const LIMIT = 10;
    private const WINDOW = 900;

    public function index(): Response
    {
        return $this->page();
    }

    private function page(array $errors = [], array $form = [], int $status = 200): Response
    {
        $u = self::me();
        unset($u['password']);                          // хеш пароля в шаблон не передаём
        // при неверном пароле user_id в журнале пуст — попытки ищем и по введённому e-mail/логину/телефону
        $events = SecurityController::loginEvents($u, 10);
        $failed30 = SecurityController::failedSince($u, date('Y-m-d H:i:s', time() - 30 * 86400));
        $r = $this->render('admin/account/index', [
            'title' => 'Мой аккаунт', 'u' => $u, 'events' => $events, 'failed30' => $failed30, 'ip' => Request::ip(),
            'errors' => $errors, 'form' => $form + $u, 'isAdmin' => Auth::isAdmin(),
            'styles' => ['admin/system.css'], 'scripts' => ['admin/system.js'],
        ]);
        $r->status = $status;
        return $r;
    }

    /** POST /admin/account/profile/ — имя, e-mail, телефон */
    public function profile(): Response
    {
        $u = self::me();
        $uid = (int) $u['id'];
        $db = App::db();
        $form = [
            'name'  => mb_substr(Request::post('name'), 0, 190),
            'email' => mb_strtolower(mb_substr(Request::post('email'), 0, 190)),
            'phone' => mb_substr(Request::post('phone'), 0, 32),
        ];
        $errors = [];
        if ($form['name'] === '') $errors['name'] = 'Укажите имя — его видно в журнале и истории заказов.';
        $email = Str::email($form['email']);
        if ($email === '') $errors['email'] = 'Укажите правильный e-mail — это ваш логин.';
        elseif ($db->value('SELECT id FROM customers WHERE email = ? AND id <> ?', [$email, $uid])) $errors['email'] = 'Этот e-mail уже занят другой учётной записью.';
        $phone = null;
        if ($form['phone'] !== '') {
            $phone = Str::phone($form['phone']);
            if ($phone === '') $errors['phone'] = 'Телефон в формате +38 0XX XXX XX XX или оставьте пустым.';
            elseif ($db->value('SELECT id FROM customers WHERE phone = ? AND id <> ? AND status = 1 LIMIT 1', [$phone, $uid])) {
                $errors['phone'] = 'Этот телефон уже у другой учётной записи — вход по телефону стал бы неоднозначным.';
            }
        }
        $emailChanged = $email !== '' && $email !== (string) $u['email'];
        if (!$errors && $emailChanged) {
            if ($e = $this->checkCurrent($u, UsersController::rawPost('current'))) $errors['current'] = $e;
        }
        if ($errors) return $this->page($errors, $form, 422);

        $data = ['name' => $form['name'], 'email' => $email, 'phone' => $phone];
        $changed = [];
        foreach ($data as $k => $v) if ((string) $u[$k] !== (string) $v) $changed[] = $k;
        if (!$changed) { $this->flash('Изменений нет.'); return Response::redirect('/admin/account/'); }
        $db->update('customers', $data, 'id = ?', [$uid]);
        $this->log('account_profile', 'staff', $uid, ['fields' => $changed]);
        $this->flash($emailChanged ? 'Сохранено. Теперь входите с e-mail ' . $email . '.' : 'Профиль сохранён.');
        return Response::redirect('/admin/account/');
    }

    /** POST /admin/account/password/ — текущий + новый (дважды) */
    public function password(): Response
    {
        $u = self::me();
        $uid = (int) $u['id'];
        $new = UsersController::rawPost('password');
        $new2 = UsersController::rawPost('password2');
        $errors = [];
        if ($e = $this->checkCurrent($u, UsersController::rawPost('current'))) $errors['pw_current'] = $e;
        elseif ($e = UsersController::passwordError($new)) $errors['password'] = str_replace(' (или оставьте пустым, чтобы сгенерировать)', '', $e);
        elseif ($new !== $new2) $errors['password2'] = 'Пароли не совпадают.';
        elseif (\App\Core\Auth::verify($new, (string) $u['password'])) $errors['password'] = 'Новый пароль совпадает с текущим.';
        if ($errors) return $this->page($errors, [], 422);

        App::db()->update('customers', ['password' => password_hash($new, PASSWORD_DEFAULT), 'reset_token' => null, 'reset_expires' => null], 'id = ?', [$uid]);
        App::db()->delete('rate_limits', 'k = ?', ['admin-pw:' . $uid]);
        Session::regenerate();                          // новый идентификатор сессии: старый (если его подсмотрели) больше не действует
        $ended = UsersController::endSessions($uid);    // другие открытые сеансы (другой браузер, чужое устройство) завершаются
        $this->log('account_password', 'staff', $uid, $ended ? ['sessions_ended' => $ended] : null);
        $this->flash('Пароль изменён. В следующий раз входите с новым паролем.' . ($ended ? ' Другие открытые сеансы (' . $ended . ') завершены.' : ''));
        return Response::redirect('/admin/account/');
    }

    // ------------------------------------------------------------------ помощники

    /** Текущий сотрудник со всеми полями (включая хеш пароля — только для проверки, в шаблон не выводится) */
    private static function me(): array
    {
        return App::db()->row('SELECT id, name, email, login, phone, role, password, created_at, last_login_at, (password LIKE \'wa:%\') legacy
            FROM customers WHERE id = ?', [Auth::id()]) ?? [];
    }

    /** Проверка текущего пароля с ограничением попыток; '' — верно */
    private function checkCurrent(array $u, string $current): string
    {
        $k = 'admin-pw:' . (int) $u['id'];
        $row = App::db()->row('SELECT hits, reset_at FROM rate_limits WHERE k = ?', [$k]);
        if ($row && (int) $row['hits'] >= self::LIMIT && (int) $row['reset_at'] > time()) {
            return 'Слишком много неверных попыток. Подождите ' . max(1, (int) ceil(((int) $row['reset_at'] - time()) / 60)) . ' мин.';
        }
        if ($current === '') return 'Введите текущий пароль.';
        if (Auth::verify($current, (string) $u['password'])) return '';
        RateLimit::hit($k, self::LIMIT, self::WINDOW);
        $this->log('account_password_failed', 'staff', (int) $u['id']);
        return 'Текущий пароль неверный.';
    }
}
