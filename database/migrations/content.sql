-- =============================================================================
-- Раздел «Контент и SEO-служебные»: инфо-страницы, блог, sitemap, robots.
-- Идемпотентно: можно выполнять повторно, уже изменённые в админке значения не затираются.
-- Новых таблиц и колонок не требуется.
-- =============================================================================

SET NAMES utf8mb4;

-- 1) Мета-теги дублей /pages/* (приложение «Сайт» старого сайта, site_page_params).
--    bin/import-webasyst.php переносит только title, description/keywords терял.
UPDATE pages SET meta_keywords = 'оптовый интернет магазин обуви украина, украина детская обувь оптом, оптовая продажа детской обуви в украине, интернет магазины обуви в украине недорого'
 WHERE url = 'pages/o-kompanii/' AND (meta_keywords IS NULL OR meta_keywords = '');
UPDATE pages SET meta_description = 'TomObuv.com.ua – это ведущий оптовый интернет-магазин детской обуви в Украине. Лучшие бренды. Доставка по Украине.Широкий  ассортимент детской обуви оптом.Самые выгодные оптовые цены в Украине'
 WHERE url = 'pages/o-kompanii/' AND (meta_description IS NULL OR meta_description = '');

UPDATE pages SET meta_keywords = 'детская обувь мелкий опт, детская обувь оптом с документами, магазин детской обуви опт, детская обувь доставка'
 WHERE url = 'pages/dostavka-i-oplata/' AND (meta_keywords IS NULL OR meta_keywords = '');
UPDATE pages SET meta_description = 'Доставка и оплата покупки в оптовом интернет-магазине детской обуви Том Обувь по всей Украине'
 WHERE url = 'pages/dostavka-i-oplata/' AND (meta_description IS NULL OR meta_description = '');

UPDATE pages SET meta_keywords = 'купить обувь мелким оптом, обувь мелким оптом украина'
 WHERE url = 'pages/usloviya-sotrudnichestva/' AND (meta_keywords IS NULL OR meta_keywords = '');
UPDATE pages SET meta_description = 'Условия сотрудничества при покупке качественной детской обуви в интернет-магазине Том обувь. У нас можно купить обувь мелким оптом высококачественную и недорогую с быстрой доставкой.'
 WHERE url = 'pages/usloviya-sotrudnichestva/' AND (meta_description IS NULL OR meta_description = '');

UPDATE pages SET meta_keywords = '7 км одесса детская обувь, интернет магазин обуви одесса 7 км, обувь оптом одесса 7 километр,обувь оптом Одесса 7 километр'
 WHERE url = 'pages/kontakty/' AND (meta_keywords IS NULL OR meta_keywords = '');
UPDATE pages SET meta_description = 'Нам доверяют и с нами работают 60 крупнейших поставщиков и производителей детской обуви в Украине! Интернет магазин обуви, который находиться в Одессе 7 км предлагает самые трендовые бренды во всей Украине. Обувь оптом одесса 7 километр ждет вас.'
 WHERE url = 'pages/kontakty/' AND (meta_description IS NULL OR meta_description = '');

UPDATE pages SET meta_keywords = 'Статьи, правила выбора детской обуви, советы выбор детской обуви, детская обувь оптом, детская обувь оптом от производителя'
 WHERE url = 'pages/stati/' AND (meta_keywords IS NULL OR meta_keywords = '');
UPDATE pages SET meta_description = 'Правильный выбор детской обуви оптом от производителей советы и рекомендации при покупке обуви в Одессе'
 WHERE url = 'pages/stati/' AND (meta_description IS NULL OR meta_description = '');

-- 2) 301 со старых адресов статей: страница «Статьи» ссылается на /pages/<статья> (на старом сайте — 404),
--    статьи давно переехали в блог. Архивы и рубрики блога Webasyst → /blog/.
INSERT IGNORE INTO redirects (from_url, to_url, code) VALUES
 ('/pages/kak-vyibrat-detskuyu-obuv-dlya-pokupki/', '/blog/kak-podobrat-obuv-rebenku/', 301),
 ('/pages/neprostoy-vybor-obuvi-dlya-shkolnika/', '/blog/neprostoy-vybor-obuvi-dlya-shkolnika/', 301),
 ('/pages/ortopedicheskaya-obuv-dlya-detey-sovetyi-molodyim-roditelyam/', '/blog/ortopedicheskaya-obuv-dlya-detey-sovety-molodym-roditelyam/', 301),
 ('/pages/ortopedicheskaya-obuv-dlya-detey-sovety-molodym-roditelyam/', '/blog/ortopedicheskaya-obuv-dlya-detey-sovety-molodym-roditelyam/', 301),
 ('/pages/skazki-o-proizvoditeljah-detskoj-obuvi/', '/blog/skazki-o-proizvoditeljah-detskoj-obuvi/', 301),
 ('/pages/detskaja-obuv-o-chem-nado-znat/', '/blog/detskaja-obuv-o-chem-nado-znat/', 301),
 ('/pages/kak-podobrat-obuv-rebenku/', '/blog/kak-podobrat-obuv-rebenku/', 301),
 ('/pages/detskaja-ortopedicheskaja-obuv/', '/blog/detskaja-ortopedicheskaja-obuv/', 301),
 ('/pages/detskaja-obuv-pinetki/', '/blog/detskaja-obuv-pinetki/', 301),
 ('/pages/detskaja-obuv-hranenie-i-uhod/', '/blog/detskaja-obuv-hranenie-i-uhod/', 301),
 ('/blog/category/article/', '/blog/', 301),
 ('/blog/category/news/', '/blog/', 301),
 ('/blog/2017/07/', '/blog/', 301),
 ('/blog/2017/08/', '/blog/', 301);

-- Ссылки в текстах статей блога на адреса ещё более старого сайта (/catalog/…, /filter/4) — на старом сайте 404;
-- ведём в подходящие детские категории (в тексте статьи ссылка сразу заменяется конечным адресом).
INSERT IGNORE INTO redirects (from_url, to_url, code) VALUES
 ('/catalog/zimnyaya-obuv/', '/category/zimnyaya-obuv-malchikam-i-devochkam/', 301),
 ('/catalog/vesna-osen_/', '/category/obuv-detskaya-vesna-osen-optom/', 301),
 ('/catalog/krossovki/', '/category/optom-krossovki-detyam/', 301),
 ('/catalog/letnyaya-obuv/', '/category/detskaya-letnyaya-obuv-opt/', 301),
 ('/catalog/tufli/', '/category/tufli-na-malchika-i-devochku-optom/', 301),
 ('/catalog/pinetki/', '/category/obuv-dlya-malyshej-pinetki-optom/', 301),
 ('/filter/4/', '/category/obuv-dlya-malyshej-pinetki-optom/', 301);

-- 3) Украинская версия (/ua/): шаблон title инфо-страниц и названия страниц в меню.
--    Заполняется только пустое — переводы, сделанные в админке, не затираются.
INSERT IGNORE INTO settings (name, value) VALUES
 ('seo.page_meta_title.uk', '{$page.name} | інтернет-магазин {$store_info.name}');

UPDATE pages SET name_uk = 'Про компанію' WHERE url IN ('o-kompanii/', 'pages/o-kompanii/') AND (name_uk IS NULL OR name_uk = '');
UPDATE pages SET name_uk = 'Доставка та оплата' WHERE url IN ('dostavka-i-oplata/', 'pages/dostavka-i-oplata/') AND (name_uk IS NULL OR name_uk = '');
UPDATE pages SET name_uk = 'Умови співпраці' WHERE url IN ('usloviya-sotrudnichestva/', 'pages/usloviya-sotrudnichestva/') AND (name_uk IS NULL OR name_uk = '');
UPDATE pages SET name_uk = 'Контакти' WHERE url IN ('kontakty/', 'pages/kontakty/') AND (name_uk IS NULL OR name_uk = '');
UPDATE pages SET name_uk = 'Статті' WHERE url IN ('stati/', 'pages/stati/') AND (name_uk IS NULL OR name_uk = '');
