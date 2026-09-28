-- =============================================================================
-- Админка, раздел «Продажи» (заказы, клиенты, заявки, отзывы).
-- Идемпотентно: можно выполнять повторно (MySQL 5.7+ / MariaDB 10.3+).
--   mysql … tomobuv < database/migrations/admin-sales.sql
-- =============================================================================

-- Ответ магазина на отзыв о товаре (на витрине выводится под отзывом)
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `product_reviews` ADD COLUMN `response` TEXT NULL AFTER `status`',
  'DO 0') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_reviews' AND COLUMN_NAME = 'response');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `product_reviews` ADD COLUMN `response_at` DATETIME NULL AFTER `response`',
  'DO 0') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_reviews' AND COLUMN_NAME = 'response_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Модерация отзывов: список по статусу, новые сверху
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `product_reviews` ADD KEY `status_created` (`status`, `created_at`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_reviews' AND INDEX_NAME = 'status_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Заявки: вкладка «Все типы» с фильтром по статусу и бейдж «новых» в меню
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `requests` ADD KEY `status_created` (`status`, `created_at`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'status_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Клиенты: сортировка «последние зарегистрированные» и фильтр по статусу
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `customers` ADD KEY `created` (`created_at`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Клиенты: сортировки списка «по сумме покупок», «по количеству заказов», «последний вход» (LIMIT 50 без filesort)
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `customers` ADD KEY `spent` (`total_spent`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'spent');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `customers` ADD KEY `orders_cnt` (`orders_count`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'orders_cnt');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `customers` ADD KEY `last_login` (`last_login_at`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'last_login');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Заявки: вкладка «Все» (новые сверху), вкладка типа без фильтра статуса, заявки клиента по телефону
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `requests` ADD KEY `created` (`created_at`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `requests` ADD KEY `type_created` (`type`, `created_at`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'type_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `requests` ADD KEY `phone` (`phone`, `created_at`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'phone');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Заказы: фильтр «язык оформления» (RU/UA) в списке
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD KEY `lang_created` (`lang`, `created_at`)',
  'DO 0') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'lang_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
