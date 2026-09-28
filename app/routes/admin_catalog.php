<?php
/**
 * Админка → «Каталог»: товары, категории, бренды, характеристики.
 * Конкретные адреса (new, bulk, search.json…) объявлены раньше шаблонных /{id}/.
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\BrandsController;
use App\Controllers\Admin\CategoriesController;
use App\Controllers\Admin\FeaturesController;
use App\Controllers\Admin\ProductsController;

// ---------------------------------------------------------------- товары
$router->get('/admin/products/', [ProductsController::class, 'index']);
$router->get('/admin/products/search.json', [ProductsController::class, 'search']);
$router->get('/admin/products/slug.json', [ProductsController::class, 'slug']);
$router->post('/admin/products/bulk/', [ProductsController::class, 'bulk']);
$router->any('/admin/products/new/', [ProductsController::class, 'create']);
$router->any('/admin/products/{id}/', [ProductsController::class, 'edit']);
$router->post('/admin/products/{id}/delete/', [ProductsController::class, 'delete']);
$router->post('/admin/products/{id}/images/', [ProductsController::class, 'upload']);
$router->post('/admin/products/{id}/images/sort/', [ProductsController::class, 'sortImages']);
$router->post('/admin/products/{id}/images/{iid}/delete/', [ProductsController::class, 'deleteImage']);
$router->post('/admin/products/{id}/images/{iid}/main/', [ProductsController::class, 'mainImage']);

// ---------------------------------------------------------------- категории
$router->get('/admin/categories/', [CategoriesController::class, 'index']);
$router->post('/admin/categories/move/', [CategoriesController::class, 'move']);
$router->any('/admin/categories/new/', [CategoriesController::class, 'create']);
$router->any('/admin/categories/{id}/', [CategoriesController::class, 'edit']);
$router->post('/admin/categories/{id}/delete/', [CategoriesController::class, 'delete']);

// ---------------------------------------------------------------- бренды
$router->get('/admin/brands/', [BrandsController::class, 'index']);
$router->any('/admin/brands/new/', [BrandsController::class, 'create']);
$router->any('/admin/brands/{id}/', [BrandsController::class, 'edit']);
$router->post('/admin/brands/{id}/delete/', [BrandsController::class, 'delete']);

// ---------------------------------------------------------------- характеристики
$router->get('/admin/features/', [FeaturesController::class, 'index']);
$router->any('/admin/features/new/', [FeaturesController::class, 'create']);
$router->any('/admin/features/{id}/', [FeaturesController::class, 'edit']);
$router->get('/admin/features/{id}/values.json', [FeaturesController::class, 'valuesJson']);
$router->post('/admin/features/{id}/values/', [FeaturesController::class, 'values']);
$router->post('/admin/features/{id}/delete/', [FeaturesController::class, 'delete']);
