-- Админка «Контент и настройки»: новые ключи настроек (идемпотентно — существующие значения не меняются).
-- Таблицы pages, blog_posts, banners, redirects, settings уже есть в schema.sql, колонки *_uk — в migrations/i18n.sql;
-- новых колонок этот раздел не требует.
--
-- Ключи, которые создаёт админка при сохранении (заранее не нужны):
--   <ключ>.uk                 — украинский вариант текста/шаблона: site_title.uk, address.uk, work_hours.uk, seo.*.uk, blog.*.uk
--                               (Settings::get на /ua/ берёт его сам; пусто — русский вариант);
--   blog.name, blog.meta_title, blog.meta_description, blog.meta_keywords — SEO страницы /blog/;
--   mail.admin_to             — дублирует notify_email (его читает Mailer::adminEmail(), правит и экран «Почта (SMTP)»).
-- Украинские названия способов доставки/оплаты — поля name_uk/description_uk внутри JSON shipping_methods/payment_methods
-- (их читает App\Services\Orders); отдельных ключей shipping_methods.uk/payment_methods.uk быть не должно.
SET NAMES utf8mb4;

-- Адрес для уведомлений о заказах и заявках (на старом сайте — tomobuv@gmail.com, уведомление «Заказ оформлен (Администратор)»)
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('notify_email', 'tomobuv@gmail.com');

-- Ссылки на соцсети для подвала (пусто — иконка не выводится)
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES
  ('social_telegram', ''),
  ('social_viber', ''),
  ('social_instagram', ''),
  ('social_facebook', '');

-- Шаблон description для информационных страниц (в Webasyst его не было — пусто)
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('seo.page_meta_description', '');

-- Флаги «использовать SEO-шаблоны» (витрина читает category, category_pagination, page; в Webasyst были включены)
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES
  ('seo.category_is_enabled', '1'),
  ('seo.category_pagination_is_enabled', '1'),
  ('seo.page_is_enabled', '1');

-- Копии списков способов для /ua/ (делала первая версия админки) подменяли весь список, и в заказы
-- попадали украинские названия — удаляем, если есть.
DELETE FROM `settings` WHERE `name` IN ('shipping_methods.uk', 'payment_methods.uk');
