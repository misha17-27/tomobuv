-- =============================================================================
-- Раздел админки «Безопасность, сотрудники, мой аккаунт, почта, состояние системы».
-- Идемпотентно (можно выполнять повторно). MySQL 5.7+ / MariaDB 10.3+:
-- индексы добавляются через проверку information_schema.
-- Новых таблиц нет: настройки почты и список IP хранятся в settings
-- (mail.*, admin_ips), сотрудники — в customers (role admin|manager).
-- =============================================================================

SET @db = DATABASE();

-- Журнал входов (/admin/security/): WHERE action IN ('login','login_failed') ORDER BY created_at DESC
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'admin_log' AND INDEX_NAME = 'action_created') = 0,
    'ALTER TABLE `admin_log` ADD KEY `action_created` (`action`, `created_at`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- История сотрудника (/admin/users/{id}/, /admin/account/): WHERE user_id = ? ORDER BY created_at DESC
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'admin_log' AND INDEX_NAME = 'user_created') = 0,
    'ALTER TABLE `admin_log` ADD KEY `user_created` (`user_id`, `created_at`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Текущие блокировки (/admin/security/): WHERE reset_at > UNIX_TIMESTAMP()
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'rate_limits' AND INDEX_NAME = 'reset_at') = 0,
    'ALTER TABLE `rate_limits` ADD KEY `reset_at` (`reset_at`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
