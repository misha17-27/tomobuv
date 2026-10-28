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

-- ------------------------------------------------ общий каркас поставщиков (Хит обуви и следующие)
-- Колонки — через проверку information_schema (в MySQL нет ADD COLUMN IF NOT EXISTS), как admin-import.sql.
-- supplier_links.missed  — подряд полных проходов, в которых товара не было у поставщика (скрытие после N — сбой одной
--                          страницы не скрывает товары); seen_at — когда товар последний раз был у поставщика.
-- supplier_category_map  — kind: '' — сочетание Jong•Golf (сезон|категория|пол|ряд); 'cat' — категория поставщика
--                          (ext_id — её id у поставщика); 'type' — тип из названия («шлепки») в разделе season;
--                          mode: '' — категория сайта, 'skip' — не загружать, 'fallback' — тип только для категорий
--                          без своей категории сайта; by_size — раскладывать по размерным рядам (12-26, 26-32 …).
SET @db = DATABASE();

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'supplier_links' AND COLUMN_NAME = 'missed') = 0, 'ALTER TABLE `supplier_links` ADD COLUMN `missed` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `hidden`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'supplier_links' AND COLUMN_NAME = 'seen_at') = 0, 'ALTER TABLE `supplier_links` ADD COLUMN `seen_at` DATETIME NULL AFTER `missed`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'supplier_category_map' AND COLUMN_NAME = 'kind') = 0, 'ALTER TABLE `supplier_category_map` ADD COLUMN `kind` VARCHAR(8) NOT NULL DEFAULT '''' AFTER `supplier`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'supplier_category_map' AND COLUMN_NAME = 'ext_id') = 0, 'ALTER TABLE `supplier_category_map` ADD COLUMN `ext_id` VARCHAR(64) NULL AFTER `kind`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'supplier_category_map' AND COLUMN_NAME = 'by_size') = 0, 'ALTER TABLE `supplier_category_map` ADD COLUMN `by_size` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category_id`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'supplier_category_map' AND COLUMN_NAME = 'note') = 0, 'ALTER TABLE `supplier_category_map` ADD COLUMN `note` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `by_size`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'supplier_category_map' AND COLUMN_NAME = 'mode') = 0, 'ALTER TABLE `supplier_category_map` ADD COLUMN `mode` VARCHAR(8) NOT NULL DEFAULT '''' AFTER `by_size`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ------------------------------------------------ «Обзор» поставщика: журнал изменений и история цен
-- supplier_links.cost — цена поставщика за пару в последнем проходе (закупочная, без наценки): «было → стало» у поставщика.
-- supplier_changes   — что загрузчик реально записал (пробные прогоны не пишут): kind new — добавлен, price — цена
--                      поставщика или наша цена изменилась (old/new_cost — у поставщика за пару, old/new_price — на сайте),
--                      hidden — скрыт загрузчиком (пропал у поставщика / цвет закончился), back — снова есть у поставщика (или снова в наличии),
--                      out — нет в наличии у поставщика (на сайте «нет в наличии»), edit — прочие изменения записи («было → стало» в note).
--                      Хранится «{код}.history_days» дней (по умолчанию 365) — Supplier::cleanup.
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'supplier_links' AND COLUMN_NAME = 'cost') = 0, 'ALTER TABLE `supplier_links` ADD COLUMN `cost` DECIMAL(12,2) NULL AFTER `seen_at`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS `supplier_changes` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `supplier`   VARCHAR(32)   NOT NULL,
  `run_id`     INT UNSIGNED  NULL,
  `product_id` INT UNSIGNED  NOT NULL,
  `code`       VARCHAR(100)  NOT NULL DEFAULT '',     -- товар у поставщика (supplier_links.code)
  `kind`       VARCHAR(8)    NOT NULL,                -- new | price | hidden | out | back | edit
  `old_cost`   DECIMAL(12,2) NULL,                    -- цена поставщика за пару: было / стало
  `new_cost`   DECIMAL(12,2) NULL,
  `old_price`  DECIMAL(12,2) NULL,                    -- цена на сайте за пару (с наценкой): было / стало
  `new_price`  DECIMAL(12,2) NULL,
  `note`       VARCHAR(255)  NOT NULL DEFAULT '',
  `created_at` DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sup_kind` (`supplier`, `kind`, `created_at`),
  KEY `product` (`product_id`, `created_at`),
  KEY `run` (`run_id`, `kind`)
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

-- Товары поставщиков, которые владелец удалил на сайте (AdminCatalog::deleteProducts; связь без товара — Supplier::cleanup):
-- загрузчик по этому коду новый товар больше не создаёт. Список и «Вернуть» — вкладка «Обзор» поставщика.
CREATE TABLE IF NOT EXISTS `supplier_excluded` (
  `supplier`   VARCHAR(32)  NOT NULL,
  `code`       VARCHAR(100) NOT NULL,                 -- код связи (supplier_links.code)
  `name`       VARCHAR(255) NULL,                     -- название удалённого товара (для списка)
  `ext_id`     VARCHAR(64)  NULL,                     -- код модели у поставщика (supplier_links.ext_id: Tom.m переиспользует id)
  `product_id` INT UNSIGNED NULL,                     -- удалённый товар сайта
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`supplier`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- «Хит обуви»: замена надписи hit-obuvi.com.ua на фото — один раз снова включить (23.10.2026). Форма настроек, открытая до
-- появления галочки, сохраняла её выключенной; дальше это не повторяется (watermark_form). Только при первом выполнении: метка.
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('hitobuvi.watermark_fix1', '1');
SET @wm_fix = ROW_COUNT();
UPDATE `settings` SET `value` = '1' WHERE `name` = 'hitobuvi.watermark' AND @wm_fix = 1;

ALTER TABLE `supplier_excluded` ADD COLUMN IF NOT EXISTS `ext_id` VARCHAR(64) NULL AFTER `name`;
-- размерный ряд удалённого товара: «Хит обуви» выкладывает ту же модель заново под новым номером — не добавлять её по названию и ряду
ALTER TABLE `supplier_excluded` ADD COLUMN IF NOT EXISTS `size` VARCHAR(64) NULL AFTER `name`;
-- бренд удалённого товара: товары, удалённые вместе с брендом, «Бренды → Удалённые → Вернуть с товарами» возвращает к загрузке
ALTER TABLE `supplier_excluded` ADD COLUMN IF NOT EXISTS `brand_id` INT UNSIGNED NULL AFTER `product_id`;
ALTER TABLE `supplier_excluded` ADD INDEX IF NOT EXISTS `brand` (`brand_id`);

-- Бренды, которые владелец удалил на сайте (28.10.2026): загрузчики новые товары этих брендов не создают (DeletedBrands;
-- bkey — HitObuviSync::bkey названия). Список и «Вернуть» — «Бренды → Удалённые».
CREATE TABLE IF NOT EXISTS `brand_excluded` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bkey`       VARCHAR(190) NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `brand_id`   INT UNSIGNED NULL,                     -- удалённый бренд сайта (supplier_excluded.brand_id — его товары)
  `products`   INT UNSIGNED NOT NULL DEFAULT 0,       -- товаров удалено вместе с брендом
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bkey` (`bkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Один раз: товары, удалённые владельцем до supplier_excluded (23.10.2026), — по журналу изменений поставщиков (supplier_changes:
-- код каждого добавленного / взятого под загрузчик товара). Товара больше нет и по этому коду нет живой связи — «удалён вручную»
-- (пересборка каталога удаляет и журнал своих товаров — их здесь нет). Метка — чтобы «Вернуть» в «Обзоре» не отменялось деплоем.
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('suppliers.excluded_backfill1', '1');
SET @ex_fix = ROW_COUNT();
INSERT IGNORE INTO `supplier_excluded` (`supplier`, `code`, `name`, `ext_id`, `product_id`, `created_at`)
SELECT c.`supplier`, c.`code`, NULL, NULL, MAX(c.`product_id`), MAX(c.`created_at`)
FROM `supplier_changes` c LEFT JOIN `products` p ON p.`id` = c.`product_id`
WHERE @ex_fix = 1 AND p.`id` IS NULL AND c.`code` <> ''
  AND NOT EXISTS (SELECT 1 FROM `supplier_links` l JOIN `products` p2 ON p2.`id` = l.`product_id` WHERE l.`supplier` = c.`supplier` AND l.`code` = c.`code`)
GROUP BY c.`supplier`, c.`code`;

-- «Хит обуви»: порог id новых товаров — с января 2025 (решение владельца 24.10.2026; был 1 550 000 ≈ 09.10.2025). Один раз (метка):
-- изменённый потом в настройках порог деплой не трогает. Массовая загрузка (bin/load-hitobuvi.sh) по новому порогу пройдёт сама.
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('hitobuvi.min_id_2025', '1');
SET @mid_fix = ROW_COUNT();
UPDATE `settings` SET `value` = '1442000' WHERE `name` = 'hitobuvi.min_id' AND `value` = '1550000' AND @mid_fix = 1;
