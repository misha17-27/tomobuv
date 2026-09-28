<?php
/**
 * Админка → «Изображения» (медиатека public/uploads/) и JSON для пикера картинок (public/assets/admin/media.js).
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\MediaController;

$router->get('/admin/media/', [MediaController::class, 'index']);
$router->get('/admin/media/file/', [MediaController::class, 'file']);          // ?f=2026/09/foto.jpg
$router->get('/admin/media/list.json', [MediaController::class, 'listJson']);  // ?page=&q=&folder=
$router->get('/admin/media/usage.json', [MediaController::class, 'usageJson']); // ?f=
$router->post('/admin/media/upload/', [MediaController::class, 'upload']);     // file | files[] → {ok, url, name, width, height}
$router->post('/admin/media/delete/', [MediaController::class, 'delete']);     // f | f[], force=1 (только администратор)
