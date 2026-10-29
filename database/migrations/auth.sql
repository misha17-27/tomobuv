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

-- «Запомнить меня» при входе в админку (29.10.2026): вход без пароля на этом устройстве Auth::REMEMBER_DAYS дней (Auth::remember,
-- Auth::fromRemember). Cookie tremember = selector:validator; в базе — sha256 валидатора, при каждом входе по токену валидатор новый
-- (prev_hash действует ещё 2 мин — вкладки, открытые одновременно). pwf — отпечаток пароля: пароль сменили — токен не действует.
CREATE TABLE IF NOT EXISTS auth_tokens (
  selector    CHAR(24)     NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  hash        CHAR(64)     NOT NULL,
  prev_hash   CHAR(64)     NULL,
  rotated_at  DATETIME     NULL,
  pwf         CHAR(32)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  sid         VARCHAR(128) NULL,                     -- сессия последнего входа по токену (вкладки, открытые одновременно)
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  PRIMARY KEY (selector),
  KEY customer (customer_id),
  KEY expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
