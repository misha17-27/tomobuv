-- =============================================================================
-- Украинское название бренда: brands.name_uk (пусто — на /ua/ русское название).
-- Переводятся только служебные названия («Не указано» → «Не вказано»), торговые марки — нет
-- (database/i18n/uk/brands.php, php bin/i18n-seed-uk.php brands). Адрес бренда (brands.url) общий для RU и UA.
-- Идемпотентно, MySQL 5.7 / 8.0 и MariaDB 10.3+: колонка добавляется через проверку information_schema.
-- =============================================================================

SET @db = DATABASE();

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'brands' AND COLUMN_NAME = 'name_uk') = 0, 'ALTER TABLE `brands` ADD COLUMN `name_uk` VARCHAR(255) NULL AFTER `name`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
