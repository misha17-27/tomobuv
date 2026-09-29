-- Страница товара (/product/{url}/, /product/{url}/reviews/). Идемпотентно: можно запускать повторно.
-- Новых таблиц и колонок раздел не требует: product_reviews — в schema.sql
-- (колонки ответа магазина response/response_at — в admin-sales.sql, витрина их показывает).

-- Украинская версия (/ua/): SEO-шаблоны товара для товаров без своих meta_*_uk и H1.
-- Settings::get('seo.product_h1') на /ua/ берёт вариант '.uk'. Заполняется только отсутствующее —
-- правки, сделанные в админке, не затираются. Title/description — стандарт SEO (App\Services\SeoFix::TEMPLATES).
INSERT IGNORE INTO settings (name, value) VALUES
 ('seo.product_h1.uk', '{$product.seo_name} оптом в Україні'),
 ('seo.product_meta_title.uk', '{$product.name} оптом[[ — купити в Одесі]][[ | {$store_info.name}]]'),
 ('seo.product_meta_description.uk', '{$product.name} оптом[[, {$product.format_price} за пару]][[, у ящику {$product.box_qty|plural:пара,пари,пар}]] — купити в Одесі на 7 км або з доставкою по Україні.[[ Розміри {$product.sizes}.]][[ Інтернет-магазин {$store_info.name}.]]'),
 ('seo.product_meta_keywords.uk', 'Купити {$product.name} оптом, {$product.name} для дівчаток, {$product.name} для хлопчиків, {$category.name}, дитяче взуття {$product.name}, {$product.name} в Україні'),
 ('seo.product_review_meta_title.uk', 'Відгуки про {$product.name} | інтернет-магазин {$store_info.name}');
