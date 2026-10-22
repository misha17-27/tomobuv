-- Журнал уведомлений (WhatsApp) — для экрана «Настройки → WhatsApp»
CREATE TABLE IF NOT EXISTS notify_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel    VARCHAR(16) NOT NULL,
  target     VARCHAR(64) NOT NULL,
  ok         TINYINT(1) NOT NULL DEFAULT 0,
  error      VARCHAR(500) NULL,
  ref_type   VARCHAR(16) NULL,
  ref_id     INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY channel (channel, created_at),
  KEY ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Получатель новых заказов по умолчанию (решение владельца 22.10.2026): только если номер ещё не задан — правки в
-- «Настройки → WhatsApp» не перезаписываются (INSERT IGNORE: строка уже есть — ничего не делается)
INSERT IGNORE INTO settings (name, value) VALUES ('whatsapp.to', '+380507616901');

-- Кнопки «Отправить заказ в WhatsApp / Viber» после оформления (Orders::messenger; «Настройки → Магазин»): номер по умолчанию
INSERT IGNORE INTO settings (name, value) VALUES ('order_messenger', '+38 (050) 761-6901');
