-- =============================================================================
-- Скорость списков админки (Товары). Идемпотентно (можно выполнять повторно).
-- Совместимо с MySQL 5.7+ и MariaDB 10.3+: индексы добавляются через проверку information_schema
-- (в MySQL нет ADD INDEX IF NOT EXISTS).
-- =============================================================================

SET @db = DATABASE();

-- ------------------------------------------------ products.image_id: фильтр «Без фото»
-- Без индекса «Без фото» — полный проход по 107 тыс. товаров: счётчик ~35 мс, а с сортировкой по названию или цене
-- MariaDB идёт по индексу сортировки и дочитывает все строки в поисках пяти подходящих (~130 мс). С индексом — доли мс.
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'image') = 0,
    'ALTER TABLE `products` ADD INDEX `image` (`image_id`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
