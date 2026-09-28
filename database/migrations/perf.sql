-- =============================================================================
-- Скорость: индексы, найденные замерами (EXPLAIN + профиль) на базе 107 тыс. товаров.
-- Идемпотентно (можно выполнять повторно). MySQL 5.7+ / MariaDB 10.3+:
-- колонки и индексы добавляются через проверку information_schema.
-- =============================================================================

SET @db = DATABASE();

-- ------------------------------------------------ catalog_index: сортировки категорий без filesort
-- Порядок как на старом сайте: «по убыванию, при равных — по id по возрастанию»
-- (ci.in_stock DESC, ci.created_at DESC, ci.product_id ASC). Смешанные направления MySQL 5.7 / MariaDB < 10.8
-- по индексу не читают — сортировали все 57 тыс. строк «Женской обуви» (~40 мс на каждую страницу).
-- product_rid = 4294967295 − product_id: «product_rid DESC» = «product_id ASC», и весь ORDER BY идёт
-- в одну сторону — обратным проходом по индексу (0,3 мс на 1-й странице, ~3 мс на 300-й).
-- Колонка вычисляемая: CatalogIndexer и AdminCatalog её не заполняют, она считается сама.
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_index' AND COLUMN_NAME = 'product_rid') = 0,
    'ALTER TABLE `catalog_index` ADD COLUMN `product_rid` INT UNSIGNED AS (4294967295 - `product_id`) STORED', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- «Сначала новые» (сортировка по умолчанию): by_new (category_id, in_stock, created_at, product_id) → … product_rid
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_index' AND INDEX_NAME = 'by_new' AND COLUMN_NAME = 'product_id') > 0,
    'ALTER TABLE `catalog_index` DROP KEY `by_new`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_index' AND INDEX_NAME = 'by_new') = 0,
    'ALTER TABLE `catalog_index` ADD KEY `by_new` (`category_id`, `in_stock`, `created_at`, `product_rid`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- «Сначала старые»: ci.created_at ASC, ci.product_id ASC
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_index' AND INDEX_NAME = 'by_old') = 0,
    'ALTER TABLE `catalog_index` ADD KEY `by_old` (`category_id`, `created_at`, `product_id`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- «Сначала дорогие»: ci.price DESC, ci.product_rid DESC (= product_id ASC)
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_index' AND INDEX_NAME = 'by_price_rid') = 0,
    'ALTER TABLE `catalog_index` ADD KEY `by_price_rid` (`category_id`, `price`, `product_rid`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
