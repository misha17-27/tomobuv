-- =============================================================================
-- Раздел «Корзина и оформление заказа». Идемпотентно — можно выполнять повторно
-- (MySQL 5.7+ / MariaDB 10.3+):  mysql … tomobuv < database/migrations/checkout.sql
-- =============================================================================

-- Порядок позиций в корзине = порядок добавления (updated_at меняется при смене количества).
-- Микросекунды — чтобы различать товары, добавленные в одну секунду.
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `cart_items` ADD COLUMN `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER `customer_id`',
  'ALTER TABLE `cart_items` MODIFY `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND COLUMN_NAME = 'created_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Поиск клиента по телефону при оформлении без входа (customers.phone уже с индексом `phone`),
-- заказы клиента для предзаполнения формы — индекс orders.customer уже есть в schema.sql.

-- Старый адрес оформления Webasyst (плагин «Корзина + заказ в 1 шаг») → новая страница корзины
INSERT IGNORE INTO `redirects` (`from_url`, `to_url`, `code`) VALUES ('/checkoutone/', '/cart/', 301);
INSERT IGNORE INTO `redirects` (`from_url`, `to_url`, `code`) VALUES ('/checkout/success/', '/', 302);
-- Шаги пошагового оформления Webasyst (на живом сайте отдают 200 «Оформление заказа / Ошибка!») → страница оформления
INSERT IGNORE INTO `redirects` (`from_url`, `to_url`, `code`) VALUES
  ('/checkout/contactinfo/', '/cart/', 301), ('/checkout/shipping/', '/cart/', 301), ('/checkout/payment/', '/cart/', 301),
  ('/checkout/confirmation/', '/cart/', 301), ('/checkout/error/', '/cart/', 301);
