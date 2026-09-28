-- Вход по логину (сотрудники Webasyst входили по логину: admin, Arzu, …) и все e-mail клиента
ALTER TABLE customers ADD COLUMN IF NOT EXISTS login VARCHAR(64) NULL AFTER phone;
ALTER TABLE customers ADD INDEX IF NOT EXISTS login (login);

-- Дополнительные e-mail клиента (в Webasyst у контакта могло быть несколько адресов; вход — по любому)
CREATE TABLE IF NOT EXISTS customer_emails (
  customer_id INT UNSIGNED NOT NULL,
  email       VARCHAR(190) NOT NULL,
  PRIMARY KEY (customer_id, email),
  KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
