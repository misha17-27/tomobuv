<?php
/**
 * Админка → «Каталог → Поставщики»: список поставщиков и страница каждого (Jong•Golf — сайт бренда jonggolf.com или API, Хит обуви — страницы сайта,
 * Tom.m — сайт бренда tomm.ua). Только администратор (SuppliersController, HitObuviController, TommController).
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\HitObuviController;
use App\Controllers\Admin\SuppliersController;
use App\Controllers\Admin\TommController;

$router->get('/admin/suppliers/', [SuppliersController::class, 'home']);
$router->get('/admin/suppliers/jonggolf/', [SuppliersController::class, 'index']);
$router->get('/admin/suppliers/jonggolf/progress/', [SuppliersController::class, 'progress']);
$router->post('/admin/suppliers/jonggolf/settings/', [SuppliersController::class, 'saveSettings']);
$router->post('/admin/suppliers/jonggolf/import-cfg/', [SuppliersController::class, 'importCfg']);
$router->post('/admin/suppliers/jonggolf/dictionary/', [SuppliersController::class, 'uploadDictionary']);
$router->post('/admin/suppliers/jonggolf/test/', [SuppliersController::class, 'test']);
$router->post('/admin/suppliers/jonggolf/start/', [SuppliersController::class, 'start']);
$router->get('/admin/suppliers/jonggolf/runs/{id}/', [SuppliersController::class, 'showRun']);
$router->post('/admin/suppliers/jonggolf/runs/{id}/step/', [SuppliersController::class, 'step']);
$router->post('/admin/suppliers/jonggolf/runs/{id}/stop/', [SuppliersController::class, 'stop']);
$router->post('/admin/suppliers/jonggolf/map/sections/', [SuppliersController::class, 'mapSections']);
$router->post('/admin/suppliers/jonggolf/map/upload/', [SuppliersController::class, 'mapUpload']);
$router->post('/admin/suppliers/jonggolf/map/save/', [SuppliersController::class, 'mapSave']);
$router->post('/admin/suppliers/jonggolf/map/add/', [SuppliersController::class, 'mapAdd']);
$router->post('/admin/suppliers/jonggolf/map/{id}/delete/', [SuppliersController::class, 'mapDelete']);
$router->get('/admin/suppliers/jonggolf/map.csv', [SuppliersController::class, 'mapCsv']);
$router->post('/admin/suppliers/jonggolf/prices/', [SuppliersController::class, 'savePrices']);

$router->get('/admin/suppliers/hitobuvi/', [HitObuviController::class, 'index']);
$router->get('/admin/suppliers/hitobuvi/progress/', [HitObuviController::class, 'progress']);
$router->post('/admin/suppliers/hitobuvi/settings/', [HitObuviController::class, 'saveSettings']);
$router->post('/admin/suppliers/hitobuvi/test/', [HitObuviController::class, 'test']);
$router->post('/admin/suppliers/hitobuvi/start/', [HitObuviController::class, 'start']);
$router->get('/admin/suppliers/hitobuvi/runs/{id}/', [HitObuviController::class, 'showRun']);
$router->post('/admin/suppliers/hitobuvi/runs/{id}/step/', [HitObuviController::class, 'step']);
$router->post('/admin/suppliers/hitobuvi/runs/{id}/stop/', [HitObuviController::class, 'stop']);
$router->post('/admin/suppliers/hitobuvi/map/save/', [HitObuviController::class, 'mapSave']);
$router->post('/admin/suppliers/hitobuvi/map/type/', [HitObuviController::class, 'mapAddType']);
$router->post('/admin/suppliers/hitobuvi/map/{id}/delete/', [HitObuviController::class, 'mapDelete']);

$router->get('/admin/suppliers/tomm/', [TommController::class, 'index']);
$router->get('/admin/suppliers/tomm/progress/', [TommController::class, 'progress']);
$router->post('/admin/suppliers/tomm/settings/', [TommController::class, 'saveSettings']);
$router->post('/admin/suppliers/tomm/test/', [TommController::class, 'test']);
$router->post('/admin/suppliers/tomm/start/', [TommController::class, 'start']);
$router->get('/admin/suppliers/tomm/runs/{id}/', [TommController::class, 'showRun']);
$router->post('/admin/suppliers/tomm/runs/{id}/step/', [TommController::class, 'step']);
$router->post('/admin/suppliers/tomm/runs/{id}/stop/', [TommController::class, 'stop']);
$router->post('/admin/suppliers/tomm/map/save/', [TommController::class, 'mapSave']);
