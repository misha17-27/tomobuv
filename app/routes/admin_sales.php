<?php
/**
 * Админка, раздел «Продажи»: заказы, клиенты, заявки, отзывы.
 * Порядок важен: конкретные адреса (new, export.csv, *.json) — раньше адресов с {id}.
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\CustomersController;
use App\Controllers\Admin\OrdersController;
use App\Controllers\Admin\RequestsController;
use App\Controllers\Admin\ReviewsController;

// ---------------------------------------------------------------- заказы
$router->get('/admin/orders/', [OrdersController::class, 'index']);
// public/index.php добавляет «/» к адресам без известного расширения — принимаем оба варианта
$router->get('/admin/orders/export.csv', [OrdersController::class, 'export']);
$router->get('/admin/orders/export.csv/', [OrdersController::class, 'export']);
$router->get('/admin/orders/products.json', [OrdersController::class, 'productSearch']);
$router->any('/admin/orders/new/', [OrdersController::class, 'create']);
$router->any('/admin/orders/{id}/', [OrdersController::class, 'show']);
$router->post('/admin/orders/{id}/status/', [OrdersController::class, 'status']);
$router->post('/admin/orders/{id}/items/', [OrdersController::class, 'items']);
$router->post('/admin/orders/{id}/comment/', [OrdersController::class, 'comment']);
$router->get('/admin/orders/{id}/print/', [OrdersController::class, 'printout']);

// ---------------------------------------------------------------- клиенты
$router->get('/admin/customers/', [CustomersController::class, 'index']);
$router->get('/admin/customers/search.json', [CustomersController::class, 'search']);
$router->any('/admin/customers/{id}/', [CustomersController::class, 'show']);

// ---------------------------------------------------------------- заявки
$router->get('/admin/requests/', [RequestsController::class, 'index']);
$router->post('/admin/requests/{id}/status/', [RequestsController::class, 'status']);

// ---------------------------------------------------------------- отзывы (товаров и о магазине)
$router->get('/admin/reviews/', [ReviewsController::class, 'index']);
$router->post('/admin/reviews/{type}/{id}/', [ReviewsController::class, 'action']);
