-- =============================================================================
-- Импорт: товары, изменённые на сайте после выгрузки (колонка updated_at в файле экспорта).
-- import_seen.conflict = 1 — строка файла не записана: товар изменён на сайте позже, чем выгружен.
-- Идемпотентно (можно выполнять повторно). Совместимо с MySQL 5.7+ и MariaDB 10.3+:
-- колонка добавляется через проверку information_schema. Выполняется и автоматически:
-- App\Services\Import\Importer::ensureSchema(). import_seen создаётся в admin-import.sql (раньше по алфавиту).
-- =============================================================================

SET @db = DATABASE();

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_seen' AND COLUMN_NAME = 'conflict') = 0,
    'ALTER TABLE `import_seen` ADD COLUMN `conflict` TINYINT(1) NOT NULL DEFAULT 0 AFTER `changed`', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
