-- =============================================================================
-- Карта старых адресов товаров, удалённых при пересборке каталога (bin/catalog-reset.php, docs/INSTALL.md →
-- «Пересборка каталога»): адрес удалённой карточки → 301 на новую карточку той же модели (new_product_id, если её
-- загрузил поставщик — App\Services\LegacyProducts::match), иначе на категорию товара (видимую или ближайшего
-- видимого родителя по cat_path), иначе на главную. Адрес — products.url (/product/{url}/ и /ua/product/{url}/).
--   forsage_code — products.supplier_code товара Forsage (у «Хит обуви» тот же код — supplier_links.ext_id);
--   name_key / art_key / code_key / own_key — md5 ключей сопоставления (название+ряд; бренд+тип+артикул+ряд; бренд+код+ряд;
--   код модели Tom.m / Jong•Golf+ряд — дубли бренда под другим брендом, «Ok Shoes T10862D» = «TOM.M T-10862-D»),
--   считает LegacyProducts::keys() / ownKey() — одинаково для старой строки и для загруженного товара;
--   alias = 1 — адрес из таблицы redirects, который вёл на удалённый товар (тот же товар, другой старый адрес).
-- Идемпотентно (можно выполнять повторно), MySQL 5.7+ / MariaDB 10.3+: колонки и индексы — через information_schema.
-- Выполняется и автоматически: App\Services\LegacyProducts::ensureSchema().
-- =============================================================================

CREATE TABLE IF NOT EXISTS `legacy_products` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `url`            VARCHAR(255) NOT NULL,
  `old_id`         INT UNSIGNED NOT NULL,
  `alias`          TINYINT(1) NOT NULL DEFAULT 0,
  `name`           VARCHAR(255) NOT NULL DEFAULT '',
  `sku`            VARCHAR(255) NOT NULL DEFAULT '',
  `size`           VARCHAR(64) NOT NULL DEFAULT '',
  `box_qty`        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `price`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`         TINYINT(1) NOT NULL DEFAULT 1,
  `category_id`    INT UNSIGNED NULL,
  `cat_path`       VARCHAR(255) NOT NULL DEFAULT '',
  `brand_id`       INT UNSIGNED NULL,
  `brand_name`     VARCHAR(255) NOT NULL DEFAULT '',
  `supplier`       VARCHAR(64) NULL,
  `supplier_code`  VARCHAR(100) NULL,
  `forsage_code`   VARCHAR(100) NULL,
  `name_key`       CHAR(32) NULL,
  `art_key`        CHAR(32) NULL,
  `code_key`       CHAR(32) NULL,
  `own_key`        CHAR(32) NULL,
  `new_product_id` INT UNSIGNED NULL,
  `matched_by`     VARCHAR(16) NULL,
  `matched_at`     DATETIME NULL,
  `hits`           INT UNSIGNED NOT NULL DEFAULT 0,
  `last_hit_at`    DATETIME NULL,
  `created_at`     DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`),
  KEY `old_id` (`old_id`),
  KEY `new_product` (`new_product_id`),
  KEY `forsage` (`forsage_code`),
  KEY `name_key` (`name_key`),
  KEY `art_key` (`art_key`),
  KEY `code_key` (`code_key`),
  KEY `own_key` (`own_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Колонки и индексы, которых могло не быть в ранней копии таблицы (повторный запуск ничего не меняет)
SET @db = DATABASE();

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'legacy_products' AND COLUMN_NAME = 'code_key') = 0,
    'ALTER TABLE `legacy_products` ADD COLUMN `code_key` CHAR(32) NULL AFTER `art_key`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'legacy_products' AND COLUMN_NAME = 'last_hit_at') = 0,
    'ALTER TABLE `legacy_products` ADD COLUMN `last_hit_at` DATETIME NULL AFTER `hits`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'legacy_products' AND COLUMN_NAME = 'own_key') = 0,
    'ALTER TABLE `legacy_products` ADD COLUMN `own_key` CHAR(32) NULL AFTER `code_key`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'legacy_products' AND INDEX_NAME = 'code_key') = 0,
    'ALTER TABLE `legacy_products` ADD INDEX `code_key` (`code_key`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'legacy_products' AND INDEX_NAME = 'own_key') = 0,
    'ALTER TABLE `legacy_products` ADD INDEX `own_key` (`own_key`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
