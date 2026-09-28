<?php
/**
 * Маршруты админки (/admin/…). Базовые — здесь, разделы — в app/routes/admin_*.php
 * (каждый раздел в своём файле: products, orders, content, settings, import…).
 * Доступ проверяется в App\Controllers\Admin\BaseController.
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin;

$router->any('/admin/login/', [Admin\AuthController::class, 'login']);
$router->any('/admin/logout/', [Admin\AuthController::class, 'logout']);   // выход только с токеном: POST или ?t=
$router->get('/admin/', [Admin\DashboardController::class, 'index']);
$router->post('/admin/cache/clear/', [Admin\DashboardController::class, 'clearCache']);

foreach (glob(APP . '/routes/admin_*.php') ?: [] as $f) {
    require $f;
}

// всё прочее под /admin — 404 внутри админки (а не страница витрины)
$router->any('/admin/{rest*}', [Admin\DashboardController::class, 'notFound']);
