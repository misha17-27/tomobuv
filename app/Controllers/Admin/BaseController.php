<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * База для всех контроллеров админки.
 *  - доступ только сотрудникам (role admin/manager), опционально — только с IP из config admin_ips;
 *  - каждый POST проверяется CSRF-токеном сессии (поле _token, выводит $this->tokenField() / csrf в layout);
 *  - $this->render('admin/products/index', [...]) — шаблон в макете админки;
 *  - $this->flash('Сохранено') + $this->back() / Response::redirect(...).
 * Наследники вызывают parent::__construct() неявно (конструктор без параметров).
 */
abstract class BaseController
{
    /** Разделы, доступные менеджеру (не админу) */
    protected const MANAGER_ALLOWED = true;

    /** Разрешённые IP для админки: config admin_ips + настройка admin_ips (JSON-список, раздел «Безопасность») */
    public static function allowedIps(): array
    {
        $ips = array_merge((array) App::config('admin_ips', []), \App\Core\Settings::json('admin_ips', []));
        return array_values(array_unique(array_filter(array_map('trim', $ips))));
    }

    public function __construct()
    {
        $ips = self::allowedIps();
        if ($ips && !in_array(Request::ip(), $ips, true)) {
            http_response_code(403);
            exit('Доступ запрещён');
        }
        Session::start();
        if (!Auth::isStaff()) {
            if (Request::isAjax()) { Response::json(['ok' => false, 'error' => 'Требуется вход'], 401)->send(); exit; }
            Response::redirect('/admin/login/?back=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/admin/'))->send();
            exit;
        }
        if (!static::MANAGER_ALLOWED && !Auth::isAdmin()) {
            Response::html($this->render('admin/forbidden', ['title' => 'Нет доступа'])->body, 403)->send();
            exit;
        }
        // тело запроса больше post_max_size — PHP отбрасывает $_POST/$_FILES целиком (и токен тоже)
        if (Request::isPost() && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $max = (int) round(self::iniBytes((string) ini_get('post_max_size')) / 1048576);
            $msg = t('Файл слишком большой (максимум {n} МБ)', ['n' => $max]);
            if (Request::isAjax()) { Response::json(['ok' => false, 'error' => $msg], 413)->send(); exit; }
            Session::flash('error', $msg);
            Response::redirect(self::safeBack('/admin/'))->send();
            exit;
        }
        if (Request::isPost() && !Csrf::checkSession()) {
            if (Request::isAjax()) { Response::json(['ok' => false, 'error' => 'Сессия устарела, обновите страницу'], 419)->send(); exit; }
            Session::flash('error', 'Сессия устарела — повторите действие.');
            Response::redirect(self::safeBack('/admin/'))->send();
            exit;
        }
    }

    protected function render(string $template, array $data = []): Response
    {
        $data += ['title' => 'Админка', 'user' => Auth::user(), 'flash' => Session::flash('ok'), 'flashError' => Session::flash('error')];
        return Response::html(View::render($template, $data, 'admin'));
    }

    protected function flash(string $msg, bool $error = false): void
    {
        Session::flash($error ? 'error' : 'ok', $msg);
    }

    protected function back(string $fallback = '/admin/'): Response
    {
        return Response::redirect(self::safeBack($fallback));
    }

    /** Возврат на предыдущую страницу админки: только этот же сайт и только /admin/… (защита от открытого редиректа) */
    public static function safeBack(string $fallback = '/admin/'): string
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $u = $ref !== '' ? parse_url($ref) : false;
        if (!$u || empty($u['host'])) return $fallback;
        $host = $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        if (strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) return $fallback;
        $path = (string) ($u['path'] ?? '');
        if (!str_starts_with($path, '/admin/') || str_contains($path, '//')) return $fallback;
        return $path . (isset($u['query']) ? '?' . $u['query'] : '');
    }

    /** Запись в журнал действий */
    protected function log(string $action, ?string $entity = null, ?int $id = null, $details = null): void
    {
        App::db()->insert('admin_log', ['user_id' => Auth::id(), 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'details' => $details === null ? null : (is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'ip' => Request::ip()]);
    }

    /** '8M' → байты */
    public static function iniBytes(string $v): int
    {
        $n = (int) $v;
        return match (strtoupper(substr(trim($v), -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
    }

    public static function tokenField(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Csrf::sessionToken()) . '">';
    }
}
