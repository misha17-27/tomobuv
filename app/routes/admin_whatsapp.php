<?php
/** @var App\Core\Router $router */
declare(strict_types=1);

use App\Controllers\Admin\WhatsappController;

$router->any('/admin/whatsapp/', [WhatsappController::class, 'index']);
$router->post('/admin/whatsapp/test/', [WhatsappController::class, 'test']);
