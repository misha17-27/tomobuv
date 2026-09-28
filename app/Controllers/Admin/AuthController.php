<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Front\AuthController as FrontAuth;
use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Вход в админку и выход (не наследует BaseController — страницы доступны без входа).
 * Лимит перебора считает только неудачные попытки: 10 за 15 минут с одного IP (сотрудники одного офиса
 * за общим NAT своими успешными входами друг друга не блокируют) и 10 за 15 минут на один логин
 * (перебор пароля с разных IP).
 */
final class AuthController
{
    private const LIMIT = 10;
    private const WINDOW = 900;

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
            } elseif (RateLimit::exceeded($kIp = 'admin-login:' . Request::ip(), self::LIMIT)
                // и на учётную запись; телефон в любом написании (093…, +38 093…) — один ключ
                || RateLimit::exceeded($kUser = 'admin-login:u:' . md5(FrontAuth::loginKey(Request::post('login'))), self::LIMIT)) {
                $error = 'Слишком много неудачных попыток. Подождите 15 минут.';
            } else {
                $u = Auth::attempt(Request::post('login'), UsersController::rawPost('password'));
                if ($u && in_array($u['role'], ['admin', 'manager'], true)) {
                    Auth::login($u);
                    App::db()->insert('admin_log', ['user_id' => $u['id'], 'action' => 'login', 'ip' => Request::ip()]);
                    return Response::redirect($back);
                }
                // считаем только неудачные попытки — успешный вход счётчики не увеличивает
                RateLimit::hit($kIp, self::LIMIT, self::WINDOW);
                RateLimit::hit($kUser, self::LIMIT, self::WINDOW);
                App::db()->insert('admin_log', ['user_id' => $u['id'] ?? null, 'action' => 'login_failed',
                    'details' => mb_substr(Request::post('login'), 0, 100), 'ip' => Request::ip()]);
                $error = 'Неверный логин или пароль.';
            }
        }
        return Response::html(View::render('admin/login', ['error' => $error, 'login' => Request::post('login')], null));
    }

    /**
     * Выход — только с токеном: POST с токеном сессии (кнопка на странице подтверждения) или
     * GET /admin/logout/?t=… (ссылки «Выйти» в макете админки, Auth::logoutToken(true)).
     * GET без токена (чужая страница, <img src="/admin/logout/">, старая закладка) не разлогинивает:
     * вошедшему — страница с кнопкой «Выйти», остальных — на страницу входа.
     */
    public function logout(): Response
    {
        if (!Session::exists()) return Response::redirect('/admin/login/');
        $ok = Request::isPost() ? Csrf::checkSession() : Auth::checkLogoutToken(Request::get('t'), true);
        if ($ok) {
            Auth::logout();
            return Response::redirect('/admin/login/');
        }
        if (!Auth::check()) return Response::redirect('/admin/login/');
        return Response::html(View::render('admin/login', [
            'logout' => true, 'stale' => Request::isPost(), 'user' => Auth::user(), 'error' => '', 'login' => '',
        ], null), Request::isPost() ? 422 : 200);
    }
}
