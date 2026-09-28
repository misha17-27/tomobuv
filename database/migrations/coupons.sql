-- =============================================================================
-- Админка, раздел «Промокоды»: промокоды и история их применения в заказах.
-- Идемпотентно: можно выполнять повторно (MySQL 5.7+ / MariaDB 10.3+).
--   mysql … tomobuv < database/migrations/coupons.sql
-- Перенос промокодов со старого сайта: php bin/import-webasyst.php coupons
-- =============================================================================

CREATE TABLE IF NOT EXISTS `coupons` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`               VARCHAR(32) NOT NULL,                  -- всегда ЗАГЛАВНЫМИ: A-Z, 0-9, «-», «_»
  `type`               VARCHAR(8) NOT NULL DEFAULT 'percent', -- percent | fixed (грн)
  `value`              DECIMAL(12,2) NOT NULL DEFAULT 0,      -- % или грн
  `min_sum`            DECIMAL(12,2) NOT NULL DEFAULT 0,      -- заказ от, грн (0 — без условия)
  `min_boxes`          INT UNSIGNED NOT NULL DEFAULT 0,       -- заказ от N ящиков (0 — без условия)
  `max_discount`       DECIMAL(12,2) NOT NULL DEFAULT 0,      -- потолок скидки для процента, грн (0 — без потолка)
  `starts_at`          DATETIME NULL,                         -- действует с (NULL — сразу)
  `expires_at`         DATETIME NULL,                         -- действует по (NULL — бессрочно)
  `usage_limit`        INT UNSIGNED NOT NULL DEFAULT 0,       -- всего применений (0 — без ограничения)
  `used`               INT UNSIGNED NOT NULL DEFAULT 0,       -- счётчик применений
  `per_customer_limit` INT UNSIGNED NOT NULL DEFAULT 0,       -- применений на одного клиента/телефон (0 — без ограничения)
  `category_ids`       TEXT NULL,                             -- JSON [id, …]: только товары этих категорий (с подкатегориями)
  `brand_ids`          TEXT NULL,                             -- JSON [id, …]: только товары этих брендов
  `product_ids`        TEXT NULL,                             -- JSON [id, …]: эти товары — всегда
  `status`             TINYINT(1) NOT NULL DEFAULT 1,         -- 1 включён, 0 выключен
  `comment`            VARCHAR(500) NULL,                     -- заметка для сотрудников
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `status_expires` (`status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Применения: один промокод на заказ (UNIQUE order_id — повторный вызов Coupons::apply() не удвоит счётчик).
-- code — копия кода на момент применения: история не теряется, даже если промокод удалят (coupon_id станет 0).
CREATE TABLE IF NOT EXISTS `coupon_usages` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id`   INT UNSIGNED NOT NULL,
  `code`        VARCHAR(32) NOT NULL DEFAULT '',
  `order_id`    INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NULL,
  `phone`       VARCHAR(32) NULL,
  `discount`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order` (`order_id`),
  KEY `coupon` (`coupon_id`, `created_at`),
  KEY `coupon_customer` (`coupon_id`, `customer_id`),
  KEY `coupon_phone` (`coupon_id`, `phone`),
  KEY `created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
