<?php
/**
 * Админка → «Каталог → Поставщики»: автозагрузка по API (Jong•Golf). Только администратор (SuppliersController).
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\SuppliersController;

$router->get('/admin/suppliers/', [SuppliersController::class, 'home']);
$router->get('/admin/suppliers/jonggolf/', [SuppliersController::class, 'index']);
$router->post('/admin/suppliers/jonggolf/settings/', [SuppliersController::class, 'saveSettings']);
$router->post('/admin/suppliers/jonggolf/import-cfg/', [SuppliersController::class, 'importCfg']);
$router->post('/admin/suppliers/jonggolf/dictionary/', [SuppliersController::class, 'uploadDictionary']);
$router->post('/admin/suppliers/jonggolf/test/', [SuppliersController::class, 'test']);
$router->post('/admin/suppliers/jonggolf/start/', [SuppliersController::class, 'start']);
$router->get('/admin/suppliers/jonggolf/runs/{id}/', [SuppliersController::class, 'showRun']);
$router->post('/admin/suppliers/jonggolf/runs/{id}/step/', [SuppliersController::class, 'step']);
$router->post('/admin/suppliers/jonggolf/runs/{id}/stop/', [SuppliersController::class, 'stop']);
$router->post('/admin/suppliers/jonggolf/map/upload/', [SuppliersController::class, 'mapUpload']);
$router->post('/admin/suppliers/jonggolf/map/save/', [SuppliersController::class, 'mapSave']);
$router->post('/admin/suppliers/jonggolf/map/add/', [SuppliersController::class, 'mapAdd']);
$router->post('/admin/suppliers/jonggolf/map/{id}/delete/', [SuppliersController::class, 'mapDelete']);
$router->get('/admin/suppliers/jonggolf/map.csv', [SuppliersController::class, 'mapCsv']);
$router->post('/admin/suppliers/jonggolf/prices/', [SuppliersController::class, 'savePrices']);
