<?php
/**
 * Админка: контент и настройки — страницы, блог, баннеры, редиректы, настройки, загрузка картинок.
 * Конкретные адреса (new, sort, import) объявлены раньше шаблонных ({id}).
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin;

// Информационные страницы
$router->get('/admin/pages/', [Admin\PagesController::class, 'index']);
$router->any('/admin/pages/new/', [Admin\PagesController::class, 'edit']);
$router->post('/admin/pages/{id}/delete/', [Admin\PagesController::class, 'delete']);
$router->any('/admin/pages/{id}/', [Admin\PagesController::class, 'edit']);

// Блог
$router->get('/admin/blog/', [Admin\BlogController::class, 'index']);
$router->any('/admin/blog/new/', [Admin\BlogController::class, 'edit']);
$router->post('/admin/blog/{id}/delete/', [Admin\BlogController::class, 'delete']);
$router->any('/admin/blog/{id}/', [Admin\BlogController::class, 'edit']);

// Баннеры главной
$router->get('/admin/banners/', [Admin\BannersController::class, 'index']);
$router->post('/admin/banners/sort/', [Admin\BannersController::class, 'sort']);
$router->any('/admin/banners/new/', [Admin\BannersController::class, 'edit']);
$router->post('/admin/banners/{id}/delete/', [Admin\BannersController::class, 'delete']);
$router->post('/admin/banners/{id}/toggle/', [Admin\BannersController::class, 'toggle']);
$router->any('/admin/banners/{id}/', [Admin\BannersController::class, 'edit']);

// Редиректы
$router->get('/admin/redirects/', [Admin\RedirectsController::class, 'index']);
$router->post('/admin/redirects/add/', [Admin\RedirectsController::class, 'add']);
$router->post('/admin/redirects/import/', [Admin\RedirectsController::class, 'import']);
$router->get('/admin/redirects/export/', [Admin\RedirectsController::class, 'export']);
$router->post('/admin/redirects/delete/', [Admin\RedirectsController::class, 'deleteMany']);
$router->post('/admin/redirects/{id}/delete/', [Admin\RedirectsController::class, 'delete']);

// Настройки (вкладки)
$router->any('/admin/settings/', [Admin\SettingsController::class, 'index']);
$router->any('/admin/settings/{tab}/', [Admin\SettingsController::class, 'index']);

// Загрузка картинок для редактора и форм (JSON {ok, url})
$router->post('/admin/upload/', [Admin\UploadController::class, 'store']);
