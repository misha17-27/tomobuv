-- =============================================================================
-- Раздел «Кабинет и формы»: вход, регистрация, восстановление пароля, кабинет,
-- заявки, отзывы о магазине. Скрипт идемпотентный — можно запускать повторно.
-- Колонки customers.reset_token / reset_expires уже есть в schema.sql;
-- здесь только индексы под запросы раздела.
-- =============================================================================

-- Поиск клиента по хешу токена восстановления пароля (/forgotpassword/reset/?t=…)
SET @s = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `customers` ADD KEY `reset_token` (`reset_token`)',
    'SELECT 1') FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND index_name = 'reset_token');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Проверка повторной подписки на рассылку (requests: type = 'subscribe' AND email = ?)
SET @s = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `requests` ADD KEY `type_email` (`type`, `email`(64))',
    'SELECT 1') FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'requests' AND index_name = 'type_email');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
