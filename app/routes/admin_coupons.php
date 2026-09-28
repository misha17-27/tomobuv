<?php
/**
 * Админка → «Промокоды». Конкретные адреса (new, bulk, *.json) объявлены раньше шаблонных /{id}/.
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\CouponsController;

$router->get('/admin/coupons/', [CouponsController::class, 'index']);
$router->get('/admin/coupons/lookup.json', [CouponsController::class, 'lookup']);
$router->get('/admin/coupons/generate.json', [CouponsController::class, 'generate']);
$router->post('/admin/coupons/bulk/', [CouponsController::class, 'bulk']);
$router->post('/admin/coupons/site/', [CouponsController::class, 'site']);
$router->any('/admin/coupons/new/', [CouponsController::class, 'edit']);
$router->any('/admin/coupons/{id}/', [CouponsController::class, 'edit']);
$router->get('/admin/coupons/{id}/usages/', [CouponsController::class, 'usages']);
$router->post('/admin/coupons/{id}/delete/', [CouponsController::class, 'delete']);
