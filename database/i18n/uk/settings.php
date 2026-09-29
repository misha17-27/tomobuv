<?php
/**
 * Украинские значения настроек: ключ «<имя настройки>.uk» → перевод.
 * Settings::get('x') в версии /ua/ берёт 'x.uk', если он задан.
 * Накатывается: php bin/i18n-seed-uk.php settings [--force]
 * Шаблоны title/description — стандарт SEO (App\Services\SeoFix::TEMPLATES / SERVICE): --force не возвращает шаблоны Webasyst.
 */
return [
    // ---------------------------------------------------------------- общие
    'site_title.uk' => 'Оптовий інтернет-магазин дитячого взуття Том Взуття в Одесі',
    'address.uk'    => 'Україна, Одеська область, Одеса, Промринок 7 км',
    'work_hours.uk' => 'Пн, Вт, Ср, Чт, Сб, Нд · 06:00—18:00',

    // ---------------------------------------------------------------- главная
    'seo.home_page_meta_title.uk'       => 'Оптовий інтернет-магазин дитячого взуття Том Взуття в Одесі',
    'seo.home_page_meta_description.uk' => 'Дитяче взуття оптом від виробника в інтернет-магазині Том Взуття: ✅кросівки, ✅туфлі, ✅черевики,✅ літнє та ✅зимове дитяче взуття оптом в Україні',
    'seo.home_page_meta_keywords.uk'    => 'дитяче взуття оптом, дитяче взуття оптом 7 км, дитяче взуття оптом україна, дитяче взуття оптом від виробника, взуття дитяче оптом',

    // ---------------------------------------------------------------- категории
    'seo.category_meta_title.uk'       => '{$category.full_name} оптом[[ — купити в Одесі]][[ | {$store_info.name}]]',
    'seo.category_h1.uk'               => '{$category.seo_name} оптом купити в Україні',
    'seo.category_meta_description.uk' => '{$category.full_name} оптом — купити ящиками в Одесі на 7 км або з доставкою по Україні.[[ У каталозі {$category.product_count|plural:модель,моделі,моделей}.]][[ Інтернет-магазин {$store_info.name}.]]',
    'seo.category_meta_keywords.uk'    => 'Взуття {$category.seo_name}, купити оптом {$category.seo_name}, взуття {$category.seo_name} оптом від виробника, {$category.seo_name} Україна, інтернет-магазин дитячого взуття',

    // ---------------------------------------------------------------- пагинация категорий
    'seo.category_pagination_meta_title.uk'       => '{$category.name} оптом Одеса — сторінка {$page_number} | інтернет-магазин {$store_info.name}',
    'seo.category_pagination_h1.uk'               => '{$category.seo_name} оптом Одеса | сторінка {$page_number}',
    'seo.category_pagination_meta_description.uk' => 'Купити {$category.seo_name} оптом Одеса | сторінка {$page_number}',

    // ---------------------------------------------------------------- товары
    'seo.product_meta_title.uk'       => '{$product.name} оптом[[ — купити в Одесі]][[ | {$store_info.name}]]',
    'seo.product_h1.uk'               => '{$product.seo_name} оптом в Україні',
    'seo.product_meta_description.uk' => '{$product.name} оптом[[, {$product.format_price} за пару]][[, у ящику {$product.box_qty|plural:пара,пари,пар}]] — купити в Одесі на 7 км або з доставкою по Україні.[[ Розміри {$product.sizes}.]][[ Інтернет-магазин {$store_info.name}.]]',
    'seo.product_meta_keywords.uk'    => 'Купити {$product.name} оптом, {$product.name} для дівчаток, {$product.name} для хлопчиків, {$category.name}, дитяче взуття {$product.name}, {$product.name} в Україні',
    'seo.product_page_meta_title.uk'   => '{$page.name} — {$product.name} | інтернет-магазин {$store_info.name}',
    'seo.product_review_meta_title.uk' => 'Відгуки про {$product.name} | інтернет-магазин {$store_info.name}',

    // ---------------------------------------------------------------- бренды
    'seo.brand_meta_title.uk'          => '{$brand.name} — взуття оптом[[, купити в Одесі]][[ | {$store_info.name}]]',
    'seo.brand_h1.uk'                  => 'Дитяче взуття {$brand.name}',
    'seo.brand_meta_description.uk'    => 'Взуття {$brand.name} оптом[[: {$brand.product_count|plural:модель,моделі,моделей} у каталозі]]. Купити ящиками в Одесі на 7 км або з доставкою по Україні в інтернет-магазині {$store_info.name}.',
    'seo.brand_meta_keywords.uk'       => 'дитяче взуття {$brand.name}, взуття оптом {$brand.name}, дитяче взуття оптом {$brand.name} від виробника, {$brand.name}, інтернет-магазин дитячого взуття',
    'seo.brand_category_meta_title.uk' => '{$category.name} {$brand.name} купити в інтернет-магазині {$store_info.name}',

    // ---------------------------------------------------------------- страницы и теги
    'seo.page_meta_title.uk' => '{$page.name} | інтернет-магазин {$store_info.name}',
    'seo.page_meta_description.uk' => '{$page.name} — оптовий інтернет-магазин взуття {$store_info.name}: опт ящиками в Одесі на 7 км і доставка по Україні.',

    // ---------------------------------------------------------------- служебные страницы (/reviews/, /sitemap/, /blog/)
    'seo.reviews_meta_title.uk'       => 'Відгуки про магазин {$store_info.name} — взуття оптом в Одесі',
    'seo.reviews_meta_description.uk' => 'Відгуки покупців про магазин {$store_info.name}: взуття, замовлення та доставка по Україні. Опт ящиками в Одесі на 7 км — читайте та залишайте свій відгук.',
    'seo.sitemap_meta_title.uk'       => 'Карта сайту: усі розділи каталогу взуття оптом | {$store_info.name}',
    'seo.sitemap_meta_description.uk' => 'Усі розділи оптового інтернет-магазину взуття {$store_info.name}: дитяче, жіноче, чоловіче та підліткове взуття, бренди, доставка й оплата.',
    'seo.tag_meta_title.uk'  => '{$tag.name} | інтернет-магазин {$store_info.name}',
];
