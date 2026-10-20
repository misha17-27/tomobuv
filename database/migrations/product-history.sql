-- =============================================================================
-- Даты и история товара в админке (docs/ARCHITECTURE.md → «Даты и история товара»).
--   products.source — откуда товар: webasyst (перенос), import (импорт прайса), manual (создан в админке);
--                     загрузчики поставщиков его не пишут — источник по products.supplier (код из Suppliers::LIST).
--   индекс products.created — сортировка списка товаров «по дате загрузки»;
--   индекс admin_log.entity — история товара (WHERE entity = 'product' AND entity_id = ? ORDER BY created_at DESC).
-- Идемпотентно (можно выполнять повторно), MySQL 5.7+ / MariaDB 10.3+: колонки и индексы — через information_schema.
-- Выполняется и автоматически: App\Services\ProductHistory::ensureSchema().
-- =============================================================================

SET @db = DATABASE();

-- Колонка добавляется последней: в MariaDB 10.3 это мгновенно (без перестройки таблицы на 100 тыс. товаров)
SET @add = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'source');
SET @s = IF(@add, 'ALTER TABLE `products` ADD COLUMN `source` VARCHAR(16) NULL', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Уже заведённые товары — один раз, когда колонка только что добавлена (@add), по журналам:
--   manual — есть «Новый товар» в журнале админки в пределах часа от даты создания;
--   import — товар создан заданием импорта (import_seen.created);
--   загрузчик поставщика — остаётся NULL (источник по products.supplier): новый товар в журнале поставщика (supplier_changes)
--     или связь supplier_links создана одновременно с товаром (перенос с Webasyst создаёт связи позже — в момент переноса);
--   остальное — перенос с Webasyst (id и даты товаров — со старого сайта).
UPDATE products p
  JOIN (SELECT entity_id, MIN(created_at) t FROM admin_log WHERE action = 'product_create' AND entity = 'product' AND entity_id IS NOT NULL GROUP BY entity_id) a ON a.entity_id = p.id
  SET p.source = 'manual'
  WHERE @add = 1 AND p.source IS NULL AND ABS(TIMESTAMPDIFF(SECOND, p.created_at, a.t)) <= 3600;

SET @s = IF(@add AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_seen') > 0,
    'UPDATE products p JOIN (SELECT DISTINCT product_id FROM import_seen WHERE created = 1) s ON s.product_id = p.id SET p.source = ''import'' WHERE p.source IS NULL',
    'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @sup = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('supplier_links', 'supplier_changes')) = 2;
SET @s = IF(@add AND @sup,
    'UPDATE products p SET p.source = ''webasyst'' WHERE p.source IS NULL
       AND NOT EXISTS (SELECT 1 FROM supplier_changes c WHERE c.product_id = p.id AND c.kind = ''new'')
       AND NOT EXISTS (SELECT 1 FROM supplier_links l WHERE l.product_id = p.id AND ABS(TIMESTAMPDIFF(SECOND, l.created_at, p.created_at)) <= 60)',
    IF(@add, 'UPDATE products SET source = ''webasyst'' WHERE source IS NULL', 'DO 0'));
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Когда перенесены товары со старого сайта: изменения до этого момента у перенесённых товаров — «на старом сайте».
-- Пишет bin/import-webasyst.php (шаг products); для уже перенесённой базы — время первой записи журнала админки
-- (первый вход в новую админку — после переноса; правки на старом сайте — раньше)
INSERT IGNORE INTO settings (name, value)
  SELECT 'webasyst.products_at', DATE_FORMAT(MIN(created_at), '%Y-%m-%d %H:%i:%s') FROM admin_log WHERE @add = 1 HAVING MIN(created_at) IS NOT NULL;

-- Сортировка списка «Дата загрузки»: ORDER BY created_at DESC, id DESC (id — в конце вторичного индекса InnoDB)
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'created') = 0,
    'ALTER TABLE `products` ADD KEY `created` (`created_at`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- История товара: WHERE entity = 'product' AND entity_id = ? ORDER BY created_at DESC, id DESC
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'admin_log' AND INDEX_NAME = 'entity') = 0,
    'ALTER TABLE `admin_log` ADD KEY `entity` (`entity`, `entity_id`, `created_at`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
