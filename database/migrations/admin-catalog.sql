-- =============================================================================
-- Раздел админки «Каталог»: товары, категории, бренды, характеристики.
-- Идемпотентно (можно выполнять повторно). MySQL 5.7+ / MariaDB 10.3+:
-- индексы добавляются через проверку information_schema.
-- Новых таблиц и колонок нет: украинские поля (*_uk) — в database/migrations/i18n.sql,
-- фото пишутся в product_images, картинки категорий/брендов — в public/uploads/{categories,brands}/.
-- =============================================================================

SET @db = DATABASE();

-- Список товаров /admin/products/?sort=updated — «Недавно изменённые» без сортировки 100 тыс. строк
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'updated') = 0,
    'ALTER TABLE `products` ADD KEY `updated` (`updated_at`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Список товаров ?sort=price / price_desc
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'price') = 0,
    'ALTER TABLE `products` ADD KEY `price` (`price`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Фильтр «Нет в наличии» (таких товаров единицы — без индекса пришлось бы читать все 100 тыс.)
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'in_stock') = 0,
    'ALTER TABLE `products` ADD KEY `in_stock` (`in_stock`, `status`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Фильтр «Со скидкой»: compare_price > 0 AND compare_price > price
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'compare_price') = 0,
    'ALTER TABLE `products` ADD KEY `compare_price` (`compare_price`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Значения характеристики «как на сайте» (ORDER BY sort, id) — у «Даты съёмки» 190 тыс. значений
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'feature_values' AND INDEX_NAME = 'fsort') = 0,
    'ALTER TABLE `feature_values` ADD KEY `fsort` (`feature_id`, `sort`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Список товаров ?sort=name / name_desc (в том числе внутри категории на 57 тыс. товаров): префиксный индекс name(100)
-- для ORDER BY не годится — без полного индекса была сортировка всей таблицы (~300 мс → ~50 мс)
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'name_sort') = 0,
    'ALTER TABLE `products` ADD KEY `name_sort` (`name`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Значения характеристики «по алфавиту» и автокомплит value LIKE 'x%' ORDER BY value: уникальный ключ fv — по префиксу value(191),
-- для сортировки не годится (190 тыс. значений «Даты съёмки», дальние страницы 0,6 с → 0,15 с)
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'feature_values' AND INDEX_NAME = 'fvalue') = 0,
    'ALTER TABLE `feature_values` ADD KEY `fvalue` (`feature_id`, `value`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
