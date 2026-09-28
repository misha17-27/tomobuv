<?php
/**
 * Админка → «Импорт / экспорт»: загрузка прайсов поставщиков (CSV, XLSX, XML/YML) и выгрузка товаров в CSV.
 * Конкретные адреса (upload, profiles…) объявлены раньше шаблонных /{id}/.
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\ExportController;
use App\Controllers\Admin\ImportController;

// ---------------------------------------------------------------- импорт
$router->get('/admin/import/', [ImportController::class, 'index']);
$router->post('/admin/import/upload/', [ImportController::class, 'upload']);
$router->post('/admin/import/profiles/', [ImportController::class, 'saveProfile']);
$router->post('/admin/import/profiles/{id}/delete/', [ImportController::class, 'deleteProfile']);
$router->get('/admin/import/{id}/', [ImportController::class, 'show']);
$router->post('/admin/import/{id}/', [ImportController::class, 'save']);
$router->post('/admin/import/{id}/run/', [ImportController::class, 'run']);
$router->get('/admin/import/{id}/log/', [ImportController::class, 'journal']);
$router->get('/admin/import/{id}/errors.csv', [ImportController::class, 'errorsCsv']);
$router->post('/admin/import/{id}/restart/', [ImportController::class, 'restart']);
$router->post('/admin/import/{id}/skip-images/', [ImportController::class, 'skipImages']);
$router->post('/admin/import/{id}/delete/', [ImportController::class, 'delete']);

// ---------------------------------------------------------------- экспорт
$router->get('/admin/export/', [ExportController::class, 'index']);
$router->get('/admin/export/count.json', [ExportController::class, 'count']);
$router->get('/admin/export/products.csv', [ExportController::class, 'products']);
