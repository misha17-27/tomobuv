<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/** Вход в админку (не наследует BaseController — страница доступна без входа). */
final class AuthController
{
    public function login(): Response
    {
        $ips = BaseController::allowedIps();
        if ($ips && !in_array(Request::ip(), $ips, true)) return Response::text('Доступ запрещён', 'text/plain; charset=utf-8', 403);
        Session::start();
        $back = Request::get('back', '/admin/');
        if (!str_starts_with($back, '/admin/') || str_starts_with($back, '/admin/login')) $back = '/admin/';
        if (Auth::isStaff()) return Response::redirect($back);
        $error = '';
        if (Request::isPost()) {
            if (!Csrf::checkSession()) {
                $error = 'Сессия устарела — попробуйте ещё раз.';
            } elseif (!RateLimit::hit('admin-login:' . Request::ip(), 10, 900)
                // и на учётную запись: перебор пароля с разных IP (10 неудачных попыток за 15 минут)
                || RateLimit::exceeded($kUser = 'admin-login:u:' . md5(mb_strtolower(trim(Request::post('login')))), 10)) {
                $error = 'Слишком много попыток. Подождите 15 минут.';
            } else {
                $u = Auth::attempt(Request::post('login'), (string) ($_POST['password'] ?? ''));
                if ($u && in_array($u['role'], ['admin', 'manager'], true)) {
                    Auth::login($u);
                    App::db()->insert('admin_log', ['user_id' => $u['id'], 'action' => 'login', 'ip' => Request::ip()]);
                    return Response::redirect($back);
                }
                RateLimit::hit($kUser, 10, 900);
                App::db()->insert('admin_log', ['user_id' => $u['id'] ?? null, 'action' => 'login_failed',
                    'details' => mb_substr(Request::post('login'), 0, 100), 'ip' => Request::ip()]);
                $error = 'Неверный логин или пароль.';
            }
        }
        return Response::html(View::render('admin/login', ['error' => $error, 'login' => Request::post('login')], null));
    }

    public function logout(): Response
    {
        Auth::logout();
        return Response::redirect('/admin/login/');
    }
}
