-- =============================================================================
-- Tomobuv — схема базы данных (MySQL 5.7+ / MariaDB 10.3+, InnoDB, utf8mb4)
--
-- Принципы производительности (каталог 100 000+ товаров на обычном хостинге):
--  * «узкие» таблицы для списков (products без длинных текстов, тексты — в product_texts);
--  * catalog_index — денормализованный индекс «категория → товары» с учётом подкатегорий
--    и динамических категорий: страница категории = один проход по покрывающему индексу;
--  * product_features с индексом (feature_id, value_id, product_id) для фильтров;
--  * category_facets — заранее посчитанные значения фильтров по категориям;
--  * id товаров, категорий, заказов и клиентов сохранены из Webasyst.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------- настройки
CREATE TABLE IF NOT EXISTS `settings` (
  `name`  VARCHAR(100) NOT NULL,
  `value` MEDIUMTEXT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- категории
CREATE TABLE IF NOT EXISTS `categories` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`        INT UNSIGNED NOT NULL DEFAULT 0,
  `lft`              INT UNSIGNED NOT NULL DEFAULT 0,
  `rgt`              INT UNSIGNED NOT NULL DEFAULT 0,
  `depth`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `sort`             INT NOT NULL DEFAULT 0,
  `name`             VARCHAR(255) NOT NULL,
  `url`              VARCHAR(255) NOT NULL,                -- /category/{url}/
  `full_url`         VARCHAR(512) NOT NULL DEFAULT '',
  `type`             TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 обычная, 1 динамическая (по условию)
  `conditions`       VARCHAR(1000) NULL,                   -- для type=1: compare_price>0 | brand.value_id=40
  `include_sub`      TINYINT(1) NOT NULL DEFAULT 1,        -- показывать товары подкатегорий
  `status`           TINYINT(1) NOT NULL DEFAULT 1,
  `sort_products`    VARCHAR(32) NULL,                     -- сортировка по умолчанию
  `filter`           VARCHAR(255) NULL,                    -- набор фильтров: 'price,2,5,8' (price + id характеристик)
  `enable_sorting`   TINYINT(1) NOT NULL DEFAULT 1,
  `seo_name`         VARCHAR(500) NULL,                    -- {$category.seo_name} в SEO-шаблонах
  `h1`               VARCHAR(500) NULL,
  `meta_title`       VARCHAR(500) NULL,
  `meta_keywords`    TEXT NULL,
  `meta_description` TEXT NULL,
  `description`      MEDIUMTEXT NULL,                      -- текст над списком
  `seo_description`  MEDIUMTEXT NULL,                      -- SEO-текст под списком
  `image`            VARCHAR(255) NULL,                    -- путь к картинке категории (плитка на главной)
  `banner`           VARCHAR(255) NULL,
  `product_count`    INT UNSIGNED NOT NULL DEFAULT 0,      -- кэш: активных товаров с учётом подкатегорий
  `id_1c`            VARCHAR(36) NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`),
  KEY `tree` (`parent_id`, `sort`),
  KEY `ns` (`lft`, `rgt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- бренды
CREATE TABLE IF NOT EXISTS `brands` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,  -- = id значения характеристики «Бренд» в Webasyst
  `name`             VARCHAR(255) NOT NULL,
  `url`              VARCHAR(255) NOT NULL,                 -- /brand/{url}/ (в Webasyst — имя бренда)
  `title`            VARCHAR(500) NULL,
  `h1`               VARCHAR(500) NULL,
  `meta_keywords`    TEXT NULL,
  `meta_description` TEXT NULL,
  `summary`          VARCHAR(500) NULL,
  `description`      MEDIUMTEXT NULL,
  `seo_description`  MEDIUMTEXT NULL,
  `image`            VARCHAR(255) NULL,
  `hidden`           TINYINT(1) NOT NULL DEFAULT 0,
  `sort`             INT NOT NULL DEFAULT 0,
  `product_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- товары
CREATE TABLE IF NOT EXISTS `products` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `url`              VARCHAR(255) NOT NULL,                 -- /product/{url}/
  `name`             VARCHAR(255) NOT NULL,
  `sku`              VARCHAR(255) NOT NULL DEFAULT '',      -- артикул
  `sku_id`           INT UNSIGNED NULL,                     -- id SKU Webasyst (для старых заказов)
  `category_id`      INT UNSIGNED NULL,                     -- основная категория (хлебные крошки, SEO)
  `brand_id`         INT UNSIGNED NULL,
  `price`            DECIMAL(12,2) NOT NULL DEFAULT 0,      -- цена за пару, грн
  `compare_price`    DECIMAL(12,2) NOT NULL DEFAULT 0,      -- старая цена за пару
  `purchase_price`   DECIMAL(12,2) NOT NULL DEFAULT 0,
  `box_qty`          SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- пар в ящике (кратность заказа)
  `min_qty`          SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- минимальный заказ, пар
  `size`             VARCHAR(64) NOT NULL DEFAULT '',       -- размерный ряд «32-37» (копия характеристики для списков)
  `stock`            INT NULL,                              -- NULL = не ограничено
  `in_stock`         TINYINT(1) NOT NULL DEFAULT 1,
  `status`           TINYINT(1) NOT NULL DEFAULT 1,         -- 1 опубликован, 0 скрыт
  `badge`            VARCHAR(64) NULL,                      -- new | bestseller | lowprice | произвольный текст
  `image_id`         INT UNSIGNED NULL,                     -- главное фото
  `image_ext`        VARCHAR(8) NULL,
  `rating`           DECIMAL(3,2) NOT NULL DEFAULT 0,
  `rating_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `sales`            INT UNSIGNED NOT NULL DEFAULT 0,
  `seo_name`         VARCHAR(500) NULL,
  `h1`               VARCHAR(500) NULL,
  `meta_title`       VARCHAR(500) NULL,
  `meta_keywords`    TEXT NULL,
  `meta_description` TEXT NULL,
  `supplier_code`    VARCHAR(100) NULL,                     -- код поставщика для импорта (id_forsage, id_jonggolf…)
  `supplier`         VARCHAR(64) NULL,
  `id_1c`            VARCHAR(36) NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`),
  KEY `status_created` (`status`, `created_at`),
  KEY `promo` (`status`, `compare_price`),
  KEY `brand` (`brand_id`, `status`, `created_at`),
  KEY `category` (`category_id`),
  KEY `supplier` (`supplier`, `supplier_code`),
  KEY `sku` (`sku`(32)),
  FULLTEXT KEY `ft_search` (`name`, `sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_texts` (
  `product_id`  INT UNSIGNED NOT NULL,
  `summary`     TEXT NULL,
  `description` MEDIUMTEXT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_images` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,  -- id сохранён из Webasyst (путь к файлу строится по нему)
  `product_id`  INT UNSIGNED NOT NULL,
  `sort`        INT NOT NULL DEFAULT 0,
  `ext`         VARCHAR(8) NOT NULL DEFAULT 'jpg',
  `width`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `height`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `filename`    VARCHAR(255) NOT NULL DEFAULT '',       -- SEO-имя файла (если было)
  `description` VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product` (`product_id`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- товар ↔ категории (прямые связи, как в админке)
CREATE TABLE IF NOT EXISTS `category_products` (
  `category_id` INT UNSIGNED NOT NULL,
  `product_id`  INT UNSIGNED NOT NULL,
  `sort`        INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`category_id`, `product_id`),
  KEY `product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Денормализованный индекс для витрины: все активные товары категории с учётом
-- подкатегорий (include_sub) и динамических категорий. Перестраивается CatalogIndexer.
CREATE TABLE IF NOT EXISTS `catalog_index` (
  `category_id` INT UNSIGNED NOT NULL,
  `product_id`  INT UNSIGNED NOT NULL,
  `in_stock`    TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL,
  `price`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `sort`        INT NOT NULL DEFAULT 0,
  `name`        VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`category_id`, `product_id`),
  KEY `by_new`   (`category_id`, `in_stock`, `created_at`, `product_id`),
  KEY `by_price` (`category_id`, `price`, `product_id`),
  KEY `by_name`  (`category_id`, `name`, `product_id`),
  KEY `by_sort`  (`category_id`, `sort`, `product_id`),
  KEY `product`  (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- характеристики
CREATE TABLE IF NOT EXISTS `features` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(64) NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `type`       VARCHAR(32) NOT NULL DEFAULT 'varchar',  -- varchar | color | double
  `multiple`   TINYINT(1) NOT NULL DEFAULT 0,
  `status`     VARCHAR(10) NOT NULL DEFAULT 'public',   -- public | hidden | private
  `is_filter`  TINYINT(1) NOT NULL DEFAULT 0,           -- показывать в фильтре каталога
  `sort`       INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feature_values` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `feature_id` INT UNSIGNED NOT NULL,
  `value`      VARCHAR(255) NOT NULL,
  `code`       INT UNSIGNED NULL,                        -- цвет RGB для type=color
  `sort`       INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fv` (`feature_id`, `value`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_features` (
  `product_id` INT UNSIGNED NOT NULL,
  `feature_id` INT UNSIGNED NOT NULL,
  `value_id`   INT UNSIGNED NOT NULL,
  PRIMARY KEY (`product_id`, `feature_id`, `value_id`),
  KEY `filter` (`feature_id`, `value_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- заранее посчитанные значения фильтров для каждой категории (перестраивается индексатором)
CREATE TABLE IF NOT EXISTS `category_facets` (
  `category_id` INT UNSIGNED NOT NULL,
  `feature_id`  INT UNSIGNED NOT NULL,
  `value_id`    INT UNSIGNED NOT NULL,
  `cnt`         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`category_id`, `feature_id`, `value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_related` (
  `product_id`         INT UNSIGNED NOT NULL,
  `related_product_id` INT UNSIGNED NOT NULL,
  `type`               VARCHAR(16) NOT NULL DEFAULT 'cross_selling',
  PRIMARY KEY (`product_id`, `type`, `related_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- наборы товаров (блоки на главной: новинки, промо и т.п.)
CREATE TABLE IF NOT EXISTS `product_sets` (
  `id`    VARCHAR(64) NOT NULL,
  `name`  VARCHAR(255) NOT NULL,
  `type`  TINYINT NOT NULL DEFAULT 0, -- 0 ручной список, 1 динамический по правилу
  `rule`  VARCHAR(32) NULL,          -- для type=1: 'create_datetime DESC' | 'compare_price DESC' | …
  `limit` INT NOT NULL DEFAULT 24,
  `sort`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_set_items` (
  `set_id`     VARCHAR(64) NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `sort`       INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`set_id`, `product_id`),
  KEY `set_sort` (`set_id`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_reviews` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`  INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NULL,
  `name`        VARCHAR(100) NOT NULL,
  `email`       VARCHAR(190) NULL,
  `title`       VARCHAR(190) NULL,
  `text`        TEXT NOT NULL,
  `rate`        TINYINT UNSIGNED NULL,
  `status`      VARCHAR(12) NOT NULL DEFAULT 'moderation', -- approved | moderation | deleted
  `ip`          VARCHAR(45) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product` (`product_id`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- отзывы о магазине
CREATE TABLE IF NOT EXISTS `store_reviews` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(190) NOT NULL,
  `email`       VARCHAR(190) NULL,
  `text`        TEXT NOT NULL,
  `rating`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `response`    TEXT NULL,
  `status`      TINYINT(1) NOT NULL DEFAULT 0,  -- 1 опубликован
  `ip`          VARCHAR(45) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- контент
-- Информационные страницы. url — полный путь без ведущего слэша: 'o-kompanii/' или 'pages/o-kompanii/'.
CREATE TABLE IF NOT EXISTS `pages` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`        INT UNSIGNED NULL,
  `url`              VARCHAR(255) NOT NULL,
  `name`             VARCHAR(255) NOT NULL,
  `title`            VARCHAR(500) NULL,        -- meta title (пусто = шаблон)
  `h1`               VARCHAR(500) NULL,
  `meta_keywords`    TEXT NULL,
  `meta_description` TEXT NULL,
  `content`          MEDIUMTEXT NULL,
  `status`           TINYINT(1) NOT NULL DEFAULT 1,
  `in_menu`          TINYINT(1) NOT NULL DEFAULT 1,
  `sort`             INT NOT NULL DEFAULT 0,
  `canonical`        VARCHAR(255) NULL,        -- для дублей /pages/… → основная страница
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `url`              VARCHAR(255) NOT NULL,    -- /blog/{url}/
  `title`            VARCHAR(255) NOT NULL,
  `text_before_cut`  MEDIUMTEXT NULL,
  `text`             MEDIUMTEXT NULL,
  `meta_title`       VARCHAR(500) NULL,
  `meta_keywords`    TEXT NULL,
  `meta_description` TEXT NULL,
  `image`            VARCHAR(255) NULL,
  `status`           VARCHAR(12) NOT NULL DEFAULT 'published',
  `published_at`     DATETIME NOT NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`),
  KEY `feed` (`status`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `banners` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `place`     VARCHAR(32) NOT NULL DEFAULT 'home_slider',  -- home_slider | home_side | home_wide
  `title`     VARCHAR(255) NULL,
  `text`      VARCHAR(500) NULL,
  `button`    VARCHAR(64) NULL,
  `link`      VARCHAR(500) NULL,
  `image`     VARCHAR(255) NULL,
  `status`    TINYINT(1) NOT NULL DEFAULT 1,
  `sort`      INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `place` (`place`, `status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redirects` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_url` VARCHAR(500) NOT NULL,      -- путь с ведущим слэшем, без домена
  `to_url`   VARCHAR(500) NOT NULL,
  `code`     SMALLINT UNSIGNED NOT NULL DEFAULT 301,
  `hits`     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `from_url` (`from_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- клиенты и заказы
CREATE TABLE IF NOT EXISTS `customers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,   -- = wa_contact.id
  `name`          VARCHAR(190) NOT NULL DEFAULT '',
  `firstname`     VARCHAR(100) NOT NULL DEFAULT '',
  `lastname`      VARCHAR(100) NOT NULL DEFAULT '',
  `company`       VARCHAR(190) NOT NULL DEFAULT '',
  `email`         VARCHAR(190) NULL,
  `phone`         VARCHAR(32) NULL,
  `city`          VARCHAR(190) NULL,
  `password`      VARCHAR(255) NULL,                      -- password_hash(); legacy:md5 после переноса
  `role`          VARCHAR(16) NOT NULL DEFAULT 'customer', -- customer | manager | admin
  `status`        TINYINT(1) NOT NULL DEFAULT 1,
  `orders_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `total_spent`   DECIMAL(14,2) NOT NULL DEFAULT 0,
  `note`          TEXT NULL,
  `reset_token`   VARCHAR(64) NULL,
  `reset_expires` DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `phone` (`phone`),
  KEY `role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,   -- номер заказа = #100{id}
  `customer_id`      INT UNSIGNED NULL,
  `status`           VARCHAR(16) NOT NULL DEFAULT 'new',     -- new | processing | paid | shipped | completed | refunded | deleted
  `total`            DECIMAL(14,2) NOT NULL DEFAULT 0,
  `subtotal`         DECIMAL(14,2) NOT NULL DEFAULT 0,
  `shipping_cost`    DECIMAL(14,2) NOT NULL DEFAULT 0,
  `discount`         DECIMAL(14,2) NOT NULL DEFAULT 0,
  `currency`         CHAR(3) NOT NULL DEFAULT 'UAH',
  `boxes`            INT UNSIGNED NOT NULL DEFAULT 0,
  `pairs`            INT UNSIGNED NOT NULL DEFAULT 0,
  `name`             VARCHAR(190) NOT NULL DEFAULT '',
  `phone`            VARCHAR(32) NOT NULL DEFAULT '',
  `email`            VARCHAR(190) NULL,
  `shipping_method`  VARCHAR(64) NULL,
  `shipping_name`    VARCHAR(190) NULL,
  `city`             VARCHAR(190) NULL,
  `region`           VARCHAR(190) NULL,
  `address`          VARCHAR(500) NULL,       -- отделение / адрес
  `payment_method`   VARCHAR(64) NULL,
  `payment_name`     VARCHAR(190) NULL,
  `comment`          TEXT NULL,
  `manager_comment`  TEXT NULL,
  `source`           VARCHAR(32) NOT NULL DEFAULT 'site',   -- site | quickorder | callback | admin | webasyst
  `ip`               VARCHAR(45) NULL,
  `params`           TEXT NULL,               -- JSON: прочие параметры (UTM, старые поля Webasyst)
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `customer` (`customer_id`, `created_at`),
  KEY `status` (`status`, `created_at`),
  KEY `created` (`created_at`),
  KEY `phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NULL,
  `name`       VARCHAR(255) NOT NULL,
  `sku`        VARCHAR(255) NOT NULL DEFAULT '',
  `price`      DECIMAL(12,2) NOT NULL DEFAULT 0,   -- за пару
  `quantity`   INT UNSIGNED NOT NULL DEFAULT 0,     -- пар
  `box_qty`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `order` (`order_id`),
  KEY `product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `status_from` VARCHAR(16) NULL,
  `status_to`   VARCHAR(16) NULL,
  `text`        TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- серверная корзина (ключ — cookie cart_token): переживает смену устройства после входа
CREATE TABLE IF NOT EXISTS `cart_items` (
  `token`      CHAR(32) NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `boxes`      INT UNSIGNED NOT NULL DEFAULT 1,
  `customer_id` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token`, `product_id`),
  KEY `customer` (`customer_id`),
  KEY `updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- заявки: обратный звонок, «купить в 1 клик», подписка
CREATE TABLE IF NOT EXISTS `requests` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`       VARCHAR(16) NOT NULL,          -- callback | quickorder | subscribe | contact
  `name`       VARCHAR(190) NULL,
  `phone`      VARCHAR(32) NULL,
  `email`      VARCHAR(190) NULL,
  `text`       TEXT NULL,
  `product_id` INT UNSIGNED NULL,
  `status`     VARCHAR(12) NOT NULL DEFAULT 'new',
  `ip`         VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `type` (`type`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ограничение частоты (вход, формы) без Redis
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `k`          VARCHAR(100) NOT NULL,
  `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
  `reset_at`   INT UNSIGNED NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- импорт от поставщиков
CREATE TABLE IF NOT EXISTS `import_profiles` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(190) NOT NULL,
  `format`     VARCHAR(16) NOT NULL DEFAULT 'csv',   -- csv | xlsx | xml | yml
  `mapping`    TEXT NULL,                            -- JSON: колонка файла → поле товара
  `options`    TEXT NULL,                            -- JSON: разделитель, ключ сопоставления, категория по умолчанию…
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_jobs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_id`  INT UNSIGNED NULL,
  `file`        VARCHAR(255) NOT NULL,
  `format`      VARCHAR(16) NOT NULL,
  `status`      VARCHAR(16) NOT NULL DEFAULT 'new',  -- new | parsing | running | done | error
  `total`       INT UNSIGNED NOT NULL DEFAULT 0,
  `processed`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created`     INT UNSIGNED NOT NULL DEFAULT 0,
  `updated`     INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped`     INT UNSIGNED NOT NULL DEFAULT 0,
  `errors`      MEDIUMTEXT NULL,
  `options`     TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_rows` (
  `job_id` INT UNSIGNED NOT NULL,
  `n`      INT UNSIGNED NOT NULL,
  `data`   MEDIUMTEXT NOT NULL,       -- JSON строки файла
  PRIMARY KEY (`job_id`, `n`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- журнал действий в админке
CREATE TABLE IF NOT EXISTS `admin_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NULL,
  `action`     VARCHAR(64) NOT NULL,
  `entity`     VARCHAR(32) NULL,
  `entity_id`  INT UNSIGNED NULL,
  `details`    TEXT NULL,
  `ip`         VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
