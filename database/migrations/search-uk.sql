-- Поиск на /ua/: украинские названия товаров (products.name_uk, i18n.sql) — в полнотекстовом индексе ft_search вместе с name и sku
-- (Listing::search ищет по обоим языкам на обеих витринах). Идемпотентно: индекс уже с name_uk — ничего не делается.
SET @db = DATABASE();
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'ft_search' AND COLUMN_NAME = 'name_uk') > 0,
    'DO 0',
    IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'ft_search') > 0,
        'ALTER TABLE `products` DROP INDEX `ft_search`, ADD FULLTEXT KEY `ft_search` (`name`, `name_uk`, `sku`)',
        'ALTER TABLE `products` ADD FULLTEXT KEY `ft_search` (`name`, `name_uk`, `sku`)'));
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
