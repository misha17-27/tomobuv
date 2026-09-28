<?php
/**
 * Админка → «Отчёты»: продажи за период, экспорт CSV, пересчёт без ожидания кэша (POST).
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\ReportsController;

$router->get('/admin/reports/', [ReportsController::class, 'index']);
$router->get('/admin/reports/export/', [ReportsController::class, 'export']);
$router->post('/admin/reports/refresh/', [ReportsController::class, 'refresh']);
