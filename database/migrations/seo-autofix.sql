-- =============================================================================
-- Автоисправление SEO title/description (App\Services\SeoFix, bin/seo-autofix.php, админка «SEO → Исправить автоматически»).
-- Журнал: пакет (одно применение) и строки «объект, поле, язык, было, стало» — для отката пакета.
-- Идемпотентно (можно выполнять повторно). Совместимо с MySQL 5.7+ и MariaDB 10.3+:
-- таблицы — CREATE TABLE IF NOT EXISTS, новые колонки и индексы — через проверку information_schema
-- (в MySQL нет ADD COLUMN IF NOT EXISTS).
-- =============================================================================

SET @db = DATABASE();

CREATE TABLE IF NOT EXISTS `seo_fix_batches` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at`     DATETIME NOT NULL,
  `user_id`        INT UNSIGNED NULL,                        -- кто применил из админки (customers.id), NULL — командная строка
  `source`         VARCHAR(16) NOT NULL DEFAULT 'cli',       -- cli | admin | import | i18n
  `note`           VARCHAR(255) NOT NULL DEFAULT '',
  `parts`          VARCHAR(100) NOT NULL DEFAULT '',         -- settings,categories,brands,pages,blog,products
  `changes`        INT UNSIGNED NOT NULL DEFAULT 0,          -- строк в seo_fix_log
  `summary`        MEDIUMTEXT NULL,                          -- JSON: итоги по группам (в норме до/после, правила)
  `reverted_at`    DATETIME NULL,
  `reverted_by`    INT UNSIGNED NULL,
  `revert_summary` TEXT NULL,                                -- JSON: возвращено, пропущено (значение меняли после пакета)
  PRIMARY KEY (`id`),
  KEY `created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seo_fix_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id`   INT UNSIGNED NOT NULL,
  `entity`     VARCHAR(16) NOT NULL,                         -- product | category | brand | page | blog | setting
  `entity_id`  INT UNSIGNED NOT NULL DEFAULT 0,              -- у настроек 0
  `field`      VARCHAR(100) NOT NULL,                        -- колонка (meta_title_uk) или имя настройки (seo.product_meta_title.uk)
  `lang`       CHAR(2) NOT NULL DEFAULT 'ru',
  `rule`       VARCHAR(16) NOT NULL DEFAULT '',              -- clear | keywords | fix | uk_tpl | uk_fill | template | text
  `old_value`  MEDIUMTEXT NULL,                              -- было (NULL — пусто / настройки не было)
  `new_value`  MEDIUMTEXT NULL,                              -- стало (NULL — очищено, работает шаблон)
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `batch` (`batch_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- поиск по объекту («что автоисправление делало с товаром N»)
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'seo_fix_log' AND INDEX_NAME = 'entity') = 0,
    'ALTER TABLE `seo_fix_log` ADD KEY `entity` (`entity`, `entity_id`)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- версия правил в пакете (App\Services\SeoFix::VERSION)
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'seo_fix_batches' AND COLUMN_NAME = 'version') = 0,
    'ALTER TABLE `seo_fix_batches` ADD COLUMN `version` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `source`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
