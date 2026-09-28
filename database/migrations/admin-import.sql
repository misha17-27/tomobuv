-- =============================================================================
-- Раздел админки «Импорт / экспорт»: дополнительные поля заданий и служебные таблицы.
-- Идемпотентно (можно выполнять повторно). Совместимо с MySQL 5.7+ и MariaDB 10.3+:
-- колонки и индексы добавляются через проверку information_schema
-- (в MySQL нет ADD COLUMN IF NOT EXISTS).
-- Выполняется и автоматически: App\Services\Import\Importer::ensureSchema().
-- =============================================================================

SET @db = DATABASE();

-- ------------------------------------------------ import_jobs: новые колонки
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'name') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `name` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `file`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'mapping') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `mapping` MEDIUMTEXT NULL AFTER `options`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'columns') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `columns` MEDIUMTEXT NULL AFTER `mapping`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'state') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `state` MEDIUMTEXT NULL AFTER `columns`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'last_n') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `last_n` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `processed`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'unchanged') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `unchanged` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `updated`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'error_count') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `error_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `skipped`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'user_id') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `user_id` INT UNSIGNED NULL AFTER `profile_id`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'started_at') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `started_at` DATETIME NULL AFTER `created_at`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'updated_at') = 0, 'ALTER TABLE `import_jobs` ADD COLUMN `updated_at` DATETIME NULL AFTER `started_at`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ------------------------------------------------ import_profiles: дата изменения
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_profiles' AND COLUMN_NAME = 'updated_at') = 0, 'ALTER TABLE `import_profiles` ADD COLUMN `updated_at` DATETIME NULL AFTER `created_at`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ------------------------------------------------ products: индексы для ключей поиска «название» и «код поставщика»
-- прежняя версия добавляла `name` (name(100)); если есть `name_sort` (admin-catalog.sql) — он лишний
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'name') > 0
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND INDEX_NAME = 'name_sort') > 0,
    'ALTER TABLE `products` DROP KEY `name`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'name' AND SEQ_IN_INDEX = 1 AND INDEX_TYPE = 'BTREE') = 0,
    'ALTER TABLE `products` ADD KEY `name` (`name`(100))', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- поиск по коду поставщика без указания поставщика (индекс `supplier` начинается с supplier)
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'supplier_code' AND SEQ_IN_INDEX = 1) = 0,
    'ALTER TABLE `products` ADD KEY `supplier_code` (`supplier_code`(32))', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ------------------------------------------------ товары, встреченные в файле (для «скрыть отсутствующие» и переиндексации)
CREATE TABLE IF NOT EXISTS `import_seen` (
  `job_id`     INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `created`    TINYINT(1) NOT NULL DEFAULT 0,   -- создан этим заданием
  `changed`    TINYINT(1) NOT NULL DEFAULT 0,   -- изменён (нужна переиндексация)
  PRIMARY KEY (`job_id`, `product_id`),
  KEY `changed` (`job_id`, `changed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------ очередь загрузки фото по ссылкам
CREATE TABLE IF NOT EXISTS `import_images` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`     INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `n`          INT UNSIGNED NOT NULL DEFAULT 0,    -- строка файла
  `url`        VARCHAR(1000) NOT NULL,
  `sort`       INT NOT NULL DEFAULT 0,
  `status`     TINYINT NOT NULL DEFAULT 0,         -- 0 ждёт, 1 загружено, 2 ошибка, 3 пропущено
  PRIMARY KEY (`id`),
  KEY `queue` (`job_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------ ошибки и предупреждения по строкам
CREATE TABLE IF NOT EXISTS `import_errors` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`  INT UNSIGNED NOT NULL,
  `n`       INT UNSIGNED NOT NULL DEFAULT 0,       -- строка файла (0 — задание целиком)
  `level`   VARCHAR(8) NOT NULL DEFAULT 'error',   -- error | warn | skip
  `message` VARCHAR(500) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `job` (`job_id`, `level`, `n`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
