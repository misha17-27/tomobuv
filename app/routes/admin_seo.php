<?php
/**
 * Админка → «SEO»: обзор того, что видят поисковики (title/description всех адресов sitemap.xml),
 * SEO-шаблоны на примере, robots.txt и sitemap.xml. Редактирование — на экранах товаров/категорий/страниц
 * и в «Настройки → SEO-шаблоны».
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\SeoController;

$router->get('/admin/seo/', [SeoController::class, 'index']);
$router->post('/admin/seo/refresh/', [SeoController::class, 'refresh']);
