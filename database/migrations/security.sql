-- =============================================================================
-- Вход, пароли, сессии: признак приглашения сотрудника.
-- customers.reset_token / reset_expires общие для приглашения (72 часа, админка → «Сотрудники»)
-- и восстановления пароля (1 час, витрина). invite_expires пишет только приглашение — тем же значением,
-- что и reset_expires. Приглашение действует, пока invite_expires = reset_expires и срок не истёк:
-- запрос восстановления или ссылка из карточки клиента перезаписывают reset_expires — и приглашение
-- в карточке сотрудника больше не показывается (App\Controllers\Admin\UsersController::inviteUntil).
-- Идемпотентно; MySQL 5.7+ / MariaDB 10.3+ (проверка через information_schema).
-- Выполняется и автоматически: UsersController::ensureSchema().
-- =============================================================================

SET @db = DATABASE();

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'invite_expires') = 0, 'ALTER TABLE `customers` ADD COLUMN `invite_expires` DATETIME NULL AFTER `reset_expires`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
