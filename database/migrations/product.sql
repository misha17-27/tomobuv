-- Страница товара (/product/{url}/, /product/{url}/reviews/). Идемпотентно: можно запускать повторно.
-- Новых таблиц и колонок раздел не требует: product_reviews — в schema.sql
-- (колонки ответа магазина response/response_at — в admin-sales.sql, витрина их показывает).

-- Украинская версия (/ua/): SEO-шаблоны товара для товаров без своих meta_*_uk и H1.
-- Settings::get('seo.product_h1') на /ua/ берёт вариант '.uk'. Заполняется только отсутствующее —
-- правки, сделанные в админке, не затираются.
INSERT IGNORE INTO settings (name, value) VALUES
 ('seo.product_h1.uk', '{$product.seo_name} оптом в Україні'),
 ('seo.product_meta_title.uk', 'Купити взуття {$product.name} оптом в Україні від інтернет-магазину {$store_info.name}'),
 ('seo.product_meta_description.uk', 'Інтернет-магазин {$store_info.name} пропонує дитяче взуття оптом в Україні, модель взуття {$product.name} з розмірним рядом {$category.seo_name}. Ціна на дитяче взуття {$product.format_price} за пару. Безкоштовна доставка по Україні при замовленні від 12 ящиків. Телефонуйте {$store_info.phone} цілодобово.'),
 ('seo.product_meta_keywords.uk', 'Купити {$product.name} оптом, {$product.name} для дівчаток, {$product.name} для хлопчиків, {$category.name}, дитяче взуття {$product.name}, {$product.name} в Україні'),
 ('seo.product_review_meta_title.uk', 'Відгуки про {$product.name} | інтернет-магазин {$store_info.name}');
