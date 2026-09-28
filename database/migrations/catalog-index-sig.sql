-- =============================================================================
-- Точечная переиндексация каталога (App\Services\CatalogIndexer::products).
-- catalog_index_sig — подпись того, что учтено в category_facets для каждого товара:
-- md5 его пар «характеристика:значение» (только фильтруемые характеристики) на момент индексации.
-- По ней индексатор проверяет, какие значения вычесть из фильтров категорий, не пересчитывая
-- категории целиком. Заполняется CatalogIndexer::rebuildAll() (php bin/reindex.php).
-- Идемпотентно (можно выполнять повторно). MySQL 5.7+ / MariaDB 10.3+.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `catalog_index_sig` (
  `product_id` INT UNSIGNED NOT NULL,
  `sig`        BINARY(16) NOT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
