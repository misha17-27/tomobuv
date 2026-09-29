-- =============================================================================
-- Автозагрузка поставщиков по API (раздел админки «Поставщики», первый — Jong•Golf).
--   supplier_links        — товар сайта ↔ товар поставщика (Jong•Golf: код цвета «A20696-3» → товар; один товар
--                           поставщика = несколько товаров сайта, по одному на цвет). Надёжнее старого ключа «по названию»:
--                           переименование товара в админке не создаёт дубль.
--   supplier_category_map — таблица соответствия «сезон | категория | пол | размерный ряд» → категория сайта
--                           (как table/category_table_jonggolf.xls старого загрузчика).
--   supplier_runs, supplier_run_log, supplier_run_seen — запуски, их отчёты (как log/*.html) и встреченные товары.
-- Идемпотентно (можно выполнять повторно), MySQL 5.7+ / MariaDB 10.3+. Выполняется и автоматически:
-- App\Services\Suppliers\JongGolf::ensureSchema().
-- =============================================================================

CREATE TABLE IF NOT EXISTS `supplier_links` (
  `supplier`   VARCHAR(32)  NOT NULL,
  `code`       VARCHAR(100) NOT NULL,                 -- код у поставщика (латиница, верхний регистр)
  `product_id` INT UNSIGNED NOT NULL,
  `ext_id`     VARCHAR(64)  NULL,                     -- id товара у поставщика (Jong•Golf: id_product, общий для цветов)
  `hidden`     TINYINT(1)   NOT NULL DEFAULT 0,       -- 1 — скрыт загрузчиком (нет у поставщика / цвет закончился), а не вручную
  `created_at` DATETIME     NOT NULL,
  `updated_at` DATETIME     NULL,
  PRIMARY KEY (`supplier`, `code`),
  KEY `product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_category_map` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier`    VARCHAR(32)  NOT NULL,
  `season`      VARCHAR(100) NOT NULL DEFAULT '',
  `category`    VARCHAR(100) NOT NULL DEFAULT '',
  `gender`      VARCHAR(100) NOT NULL DEFAULT '',
  `size`        VARCHAR(32)  NOT NULL DEFAULT '',     -- размерный ряд «19-26»; пусто — любой ряд
  `target`      VARCHAR(500) NOT NULL DEFAULT '',     -- путь категории из файла «ДЕТСКАЯ ОБУВЬ>Кеды>12-26»
  `category_id` INT UNSIGNED NULL,                    -- категория сайта; NULL — не найдена, нужно выбрать
  `hash`        CHAR(32)     NOT NULL,                -- md5 ключа без регистра и лишних пробелов
  `sort`        INT          NOT NULL DEFAULT 0,      -- порядок строк файла
  `updated_at`  DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mapkey` (`supplier`, `hash`),
  KEY `target` (`supplier`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_runs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier`    VARCHAR(32)  NOT NULL,
  `kind`        VARCHAR(8)   NOT NULL,                -- run — запись | dry — пробный прогон | test — проверка связи
  `src`         VARCHAR(8)   NOT NULL DEFAULT 'api',  -- api | file (сохранённый ответ поставщика)
  `origin`      VARCHAR(8)   NOT NULL DEFAULT 'admin',-- cron | admin | cli
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'running', -- running | done | error | stopped
  `user_id`     INT UNSIGNED NULL,
  `stats`       MEDIUMTEXT   NULL,
  `state`       MEDIUMTEXT   NULL,
  `error`       VARCHAR(1000) NULL,
  `started_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NULL,
  `finished_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `supplier` (`supplier`, `kind`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_run_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`     INT UNSIGNED NOT NULL,
  `level`      VARCHAR(8)   NOT NULL,                 -- info | add | update | same | hide | skip | warn | error
  `product_id` INT UNSIGNED NULL,
  `message`    VARCHAR(1000) NOT NULL,
  `data`       TEXT         NULL,                     -- подробности для таблицы пробного прогона (JSON)
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `run` (`run_id`, `level`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_run_seen` (
  `run_id`     INT UNSIGNED NOT NULL,
  `code`       VARCHAR(100) NOT NULL,
  `product_id` INT UNSIGNED NULL,
  PRIMARY KEY (`run_id`, `code`),
  KEY `product` (`run_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Первое заполнение связей из перенесённых товаров (bin/import-webasyst.php: supplier = 'jonggolf',
-- supplier_code = id_jonggolf). Название старого загрузчика — «{категория} {бренд} {код цвета}»: код — последнее слово.
-- Только пока связей Jong•Golf нет; после повторного переноса их пересобирает JongGolf::relink() (с приведением
-- кириллических С/А/В… к латинице).
INSERT IGNORE INTO `supplier_links` (`supplier`, `code`, `product_id`, `ext_id`, `hidden`, `created_at`)
SELECT 'jonggolf', UPPER(SUBSTRING_INDEX(TRIM(p.`name`), ' ', -1)), p.`id`, p.`supplier_code`, IF(p.`status` = 0, 1, 0), NOW()
FROM `products` p
WHERE p.`supplier` = 'jonggolf' AND NOT EXISTS (SELECT 1 FROM `supplier_links` l WHERE l.`supplier` = 'jonggolf')
ORDER BY p.`id`;
