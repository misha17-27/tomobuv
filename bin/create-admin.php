<?php
/**
 * Создать сотрудника или сменить пароль: php bin/create-admin.php email пароль [admin|manager] [Имя]
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;

[, $email, $pass, $role, $name] = $argv + [null, null, null, 'admin', 'Администратор'];
if (!$email || !$pass || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($pass) < \App\Controllers\Admin\UsersController::MIN_PASSWORD || !in_array($role, ['admin', 'manager'], true)) {
    exit('Использование: php bin/create-admin.php email пароль(от ' . \App\Controllers\Admin\UsersController::MIN_PASSWORD . " символов) [admin|manager] [Имя]\n");
}
$db = App::db();
$email = mb_strtolower($email);
$id = $db->value('SELECT id FROM customers WHERE email = ?', [$email]);
$hash = password_hash($pass, PASSWORD_DEFAULT);
if ($id) {
    $db->update('customers', ['password' => $hash, 'role' => $role, 'status' => 1], 'id = ?', [$id]);
    echo "Обновлён сотрудник #$id ($email), роль $role\n";
} else {
    $id = $db->insert('customers', ['email' => $email, 'name' => $name, 'password' => $hash, 'role' => $role]);
    echo "Создан сотрудник #$id ($email), роль $role\n";
}
