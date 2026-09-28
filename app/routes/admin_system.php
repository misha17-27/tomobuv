<?php
/**
 * Админка, раздел «Система»: безопасность, сотрудники, мой аккаунт, почта (SMTP), состояние системы.
 * Порядок важен: конкретные адреса (create, probe.json) — раньше адресов с {id}.
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Admin\AccountController;
use App\Controllers\Admin\MailController;
use App\Controllers\Admin\SecurityController;
use App\Controllers\Admin\StatusController;
use App\Controllers\Admin\UsersController;

// ---------------------------------------------------------------- безопасность (только администратор)
$router->get('/admin/security/', [SecurityController::class, 'index']);
$router->post('/admin/security/unblock/', [SecurityController::class, 'unblock']);
$router->post('/admin/security/ips/', [SecurityController::class, 'saveIps']);

// ---------------------------------------------------------------- сотрудники (только администратор)
$router->get('/admin/users/', [UsersController::class, 'index']);
$router->post('/admin/users/create/', [UsersController::class, 'create']);
$router->any('/admin/users/{id}/', [UsersController::class, 'edit']);
$router->post('/admin/users/{id}/password/', [UsersController::class, 'password']);
$router->post('/admin/users/{id}/invite/', [UsersController::class, 'invite']);
$router->post('/admin/users/{id}/revoke/', [UsersController::class, 'revoke']);

// ---------------------------------------------------------------- мой аккаунт (любой сотрудник)
$router->get('/admin/account/', [AccountController::class, 'index']);
$router->post('/admin/account/profile/', [AccountController::class, 'profile']);
$router->post('/admin/account/password/', [AccountController::class, 'password']);

// ---------------------------------------------------------------- почта (смотреть — все, менять — администратор)
$router->any('/admin/mail/', [MailController::class, 'index']);

// ---------------------------------------------------------------- состояние системы
$router->get('/admin/status/', [StatusController::class, 'index']);
// public/index.php добавляет «/» к адресам без известного расширения — принимаем оба варианта
$router->get('/admin/status/probe.json', [StatusController::class, 'probe']);
$router->get('/admin/status/probe.json/', [StatusController::class, 'probe']);
$router->get('/admin/status/catalog.json', [StatusController::class, 'catalog']);
$router->get('/admin/status/catalog.json/', [StatusController::class, 'catalog']);
$router->post('/admin/status/reindex/', [StatusController::class, 'reindex']);
