<?php
/**
 * Перенос данных со старого сайта (Webasyst / Shop-Script) в новую базу.
 *
 *   1) Загрузите дамп старой базы в отдельную БД (например, tomobuv_old) и укажите её
 *      в config.php → webasyst_db.
 *   2) php bin/install.php
 *   3) php bin/import-webasyst.php            — полный перенос (новые таблицы очищаются!)
 *      php bin/import-webasyst.php orders     — только отдельный шаг (см. список $steps внизу)
 *
 * Сохраняются: id товаров/категорий/фото/клиентов/заказов, адреса страниц (url), мета-теги,
 * SEO-шаблоны, характеристики, бренды, статьи, страницы, заказы с историей, отзывы о магазине.
 * Пароли клиентов переносятся как есть (md5 Webasyst) и перехешируются при первом входе.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\DB;
use App\Services\CatalogIndexer;

ini_set('memory_limit', '1024M');
if (function_exists('set_time_limit')) set_time_limit(0);

$new = App::db();
$oc = App::config('webasyst_db');
if (!$oc) exit("В config.php не задан webasyst_db\n");
$old = new DB($oc + ['charset' => 'utf8mb4']);

$t0 = microtime(true);
function say(string $s): void { global $t0; printf("[%6.1fs] %s\n", microtime(true) - $t0, $s); }
function nz($v) { return ($v === null || $v === '') ? null : $v; }
function dt($v): ?string { return ($v && $v !== '0000-00-00 00:00:00') ? (string) $v : null; }

/** Постраничное чтение большой таблицы по первичному ключу (не держит всё в памяти) */
function chunks(DB $db, string $sql, string $pk, int $size = 5000): Generator
{
    $last = -1;
    while (true) {
        $rows = $db->all(str_replace('{AFTER}', "$pk > $last", $sql) . " ORDER BY $pk LIMIT $size");
        if (!$rows) break;
        yield $rows;
        $last = (int) end($rows)[substr($pk, strrpos($pk, '.') !== false ? strrpos($pk, '.') + 1 : 0)];
        if (count($rows) < $size) break;
    }
}

// ============================================================================ шаги
$steps = [];

$steps['settings'] = function () use ($old, $new) {
    // Без очистки таблицы: переносимые ключи перезаписываются, остальное остаётся — значения по умолчанию
    // из миграций (notify_email, social_*, seo.*.uk …) и то, что настроено в новой админке (почта, WhatsApp,
    // IP админки), не теряются при первом переносе и при повторном перед переключением домена.
    $set = static fn($k, $v) => $new->upsert('settings', ['name' => $k, 'value' => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v], ['value']);
    $shop = $old->pairs("SELECT name, value FROM wa_app_settings WHERE app_id = 'shop'");
    $set('store_name', $shop['name'] ?? 'Tomobuv');
    $set('store_phone', $shop['phone'] ?? '+(380) 932753070');
    $set('store_email', $shop['email'] ?? 'tomobuv@gmail.com');
    $set('site_title', 'Оптовый интернет-магазин детской обуви Том Обувь в Одессе');
    $set('phones', ['+38 (093) 275-3070', '+38 (050) 761-6901']);
    $set('work_hours', 'Пн, Вт, Ср, Чт, Сб, Вс · 06:00—18:00');
    $set('address', 'Украина, Одесская область, Одесса, Промрынок 7 км');
    $set('since_year', '2011');
    $set('free_shipping_boxes', '20');
    $set('order_format', $shop['order_format'] ?? '#100{$order.id}');
    $set('products_per_page', '24');

    // SEO-шаблоны плагина shop.seo (витрина по умолчанию, group_id = 0)
    foreach ($old->all('SELECT name, value FROM shop_seo_storefront_settings WHERE group_id = 0') as $r) {
        $set('seo.' . $r['name'], $r['value']);
    }
    foreach ($old->all("SELECT name, value FROM wa_app_settings WHERE app_id = 'shop.seo'") as $r) {
        $set('seo.plugin.' . $r['name'], $r['value']);
    }
    // Описание главной — точно как сейчас на сайте (берётся из настроек витрины Webasyst)
    $set('seo.home_page_meta_description', 'Детская обувь оптом от производителя в интернет магазине Том Обувь: ✅кроссовки, ✅туфли, ✅ботинки,✅ летняя и ✅зимняя детская обувь оптом в Украине');

    // Валюты (курс к гривне)
    $cur = [];
    foreach ($old->all('SELECT code, rate FROM shop_currency ORDER BY sort') as $r) $cur[$r['code']] = (float) $r['rate'];
    $set('currencies', $cur);

    // Доставка и оплата (плагины Webasyst → справочники)
    $ship = []; $pay = [];
    foreach ($old->all("SELECT id, type, plugin, name, description, status, sort FROM shop_plugin WHERE type IN ('shipping','payment') ORDER BY sort") as $r) {
        $item = ['id' => (int) $r['id'], 'code' => 'p' . $r['id'], 'name' => trim($r['name']), 'description' => trim((string) $r['description']), 'status' => (int) $r['status']];
        if ($r['type'] === 'shipping') $ship[] = $item; else $pay[] = $item;
    }
    $set('shipping_methods', $ship);
    $set('payment_methods', $pay);
    say('Настройки: ' . count($ship) . ' способов доставки, ' . count($pay) . ' оплаты');
};

$steps['categories'] = function () use ($old, $new) {
    $new->query('TRUNCATE categories');
    $seo = [];
    foreach ($old->all('SELECT category_id, name, value FROM shop_seo_category_settings WHERE group_storefront_id = 0') as $r) {
        $seo[(int) $r['category_id']][$r['name']] = $r['value'];
    }
    $params = [];
    foreach ($old->all('SELECT category_id, name, value FROM shop_category_params') as $r) $params[(int) $r['category_id']][$r['name']] = $r['value'];
    $img = $old->pairs("SELECT category_id, CONCAT('/wa-data/public/shop/wmimageincatPlugin/categories/', category_id, '/image_', id, '.', ext)
        FROM shop_wmimageincat_images WHERE type_image = 'image' ORDER BY id");
    $rows = [];
    foreach ($old->all('SELECT * FROM shop_category ORDER BY left_key') as $c) {
        $id = (int) $c['id'];
        $rows[] = [
            'id' => $id, 'parent_id' => (int) $c['parent_id'], 'lft' => (int) $c['left_key'], 'rgt' => (int) $c['right_key'],
            'depth' => (int) $c['depth'], 'sort' => (int) $c['left_key'], 'name' => trim((string) $c['name']),
            'url' => (string) $c['url'], 'full_url' => (string) $c['full_url'], 'type' => (int) $c['type'],
            'conditions' => nz($c['conditions']), 'include_sub' => (int) $c['include_sub_categories'], 'status' => (int) $c['status'],
            'sort_products' => nz($c['sort_products']), 'filter' => nz($c['filter']),
            'enable_sorting' => (int) ($params[$id]['enable_sorting'] ?? 1),
            'seo_name' => nz($seo[$id]['seo_name'] ?? null), 'h1' => nz($seo[$id]['h1'] ?? null),
            'meta_title' => nz($c['meta_title']), 'meta_keywords' => nz($c['meta_keywords']), 'meta_description' => nz($c['meta_description']),
            'description' => nz($c['description']), 'seo_description' => nz($c['seo_description']),
            'image' => $img[$id] ?? null, 'id_1c' => nz($c['id_1c']),
            'created_at' => dt($c['create_datetime']) ?? date('Y-m-d H:i:s'), 'updated_at' => dt($c['edit_datetime']),
        ];
    }
    $new->insertMany('categories', $rows);
    say('Категории: ' . count($rows));
};

$steps['features'] = function () use ($old, $new) {
    $new->query('TRUNCATE features');
    $new->query('TRUNCATE feature_values');
    $skip = ['weight', 'gtin'];
    $n = 0;
    foreach ($old->all('SELECT * FROM shop_feature ORDER BY id') as $f) {
        if (in_array($f['code'], $skip, true)) continue;
        $type = str_starts_with((string) $f['type'], 'color') ? 'color' : (str_starts_with((string) $f['type'], 'double') ? 'double' : 'varchar');
        $new->insert('features', [
            'id' => (int) $f['id'], 'code' => $f['code'], 'name' => $f['name'], 'type' => $type,
            'multiple' => (int) $f['multiple'], 'status' => $f['status'],
            'is_filter' => ((int) $f['selectable'] && $f['status'] === 'public') ? 1 : 0, 'sort' => (int) $f['id'],
        ]);
        $n++;
    }
    // Значения: varchar — id сохраняются (id брендов = id значений «Бренд»), color — со сдвигом, чтобы не пересекались
    $cnt = 0;
    foreach (chunks($old, 'SELECT id, feature_id, sort, value FROM shop_feature_values_varchar WHERE {AFTER}', 'id', 20000) as $rows) {
        $new->insertMany('feature_values', array_map(static fn($r) => ['id' => (int) $r['id'], 'feature_id' => (int) $r['feature_id'],
            'value' => mb_substr(trim((string) $r['value']), 0, 255), 'code' => null, 'sort' => (int) $r['sort']], $rows), true, 2000);
        $cnt += count($rows);
    }
    $rows = $old->all('SELECT id, feature_id, sort, code, value FROM shop_feature_values_color');
    $new->insertMany('feature_values', array_map(static fn($r) => ['id' => 5000000 + (int) $r['id'], 'feature_id' => (int) $r['feature_id'],
        'value' => trim((string) $r['value']), 'code' => $r['code'] !== null ? (int) $r['code'] : null, 'sort' => (int) $r['sort']], $rows), true);
    say("Характеристики: $n, значений: " . ($cnt + count($rows)));
};

$steps['brands'] = function () use ($old, $new) {
    $new->query('TRUNCATE brands');
    $bf = (int) ($old->value("SELECT value FROM wa_app_settings WHERE app_id = 'shop.productbrands' AND name = 'feature_id'") ?: 5);
    $extra = $old->keyed('SELECT * FROM shop_productbrands');
    $rows = []; $urls = [];
    foreach ($old->all('SELECT id, value, sort, brand_description FROM shop_feature_values_varchar WHERE feature_id = ? ORDER BY value', [$bf]) as $v) {
        $id = (int) $v['id'];
        $x = $extra[$id] ?? [];
        $url = trim((string) ($x['url'] ?? '')) ?: trim((string) $v['value']);
        $key = mb_strtolower($url);
        if (isset($urls[$key])) $url .= '-' . $id;       // дубли имён (редко) — уникальный адрес
        $urls[mb_strtolower($url)] = 1;
        $rows[] = [
            'id' => $id, 'name' => trim((string) $v['value']), 'url' => $url,
            'title' => nz($x['title'] ?? null), 'h1' => nz($x['h1'] ?? null),
            'meta_keywords' => nz($x['meta_keywords'] ?? null), 'meta_description' => nz($x['meta_description'] ?? null),
            'summary' => nz($x['summary'] ?? null),
            'description' => nz($x['description'] ?? null) ?? nz($v['brand_description']),
            'seo_description' => nz($x['seo_description'] ?? null),
            'image' => !empty($x['image']) ? '/wa-data/public/shop/brands/' . $id . '/' . $id . $x['image'] : null,
            'hidden' => (int) ($x['hidden'] ?? 0), 'sort' => (int) $v['sort'],
        ];
    }
    $new->insertMany('brands', $rows);
    say('Бренды: ' . count($rows));
};

$steps['products'] = function () use ($old, $new) {
    foreach (['products', 'product_texts', 'product_images', 'category_products', 'product_features', 'product_related', 'product_sets', 'product_set_items'] as $t) $new->query("TRUNCATE $t");
    $sizeF = (int) $old->value("SELECT id FROM shop_feature WHERE code = 'size'");
    $brandF = (int) $old->value("SELECT id FROM shop_feature WHERE code = 'brand'");
    $pairsF = (int) $old->value("SELECT id FROM shop_feature WHERE code = 'kol_vo_par'");
    $colorFeatures = array_map('intval', $old->col("SELECT id FROM shop_feature WHERE type LIKE 'color%'"));
    $keptFeatures = array_flip(array_map('intval', $new->col('SELECT id FROM features')));
    $seo = [];
    foreach ($old->all('SELECT product_id, name, value FROM shop_seo_product_settings WHERE group_storefront_id = 0') as $r) $seo[(int) $r['product_id']][$r['name']] = $r['value'];

    $total = 0;
    foreach (chunks($old, 'SELECT * FROM shop_product WHERE {AFTER}', 'id', 3000) as $rows) {
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        [$ph, $vals] = $old->in($ids);
        $skus = $old->keyed("SELECT id, product_id, sku, price, compare_price, purchase_price, count, available, status FROM shop_product_skus WHERE product_id IN ($ph) ORDER BY sort", $vals);
        $skuByProduct = [];
        foreach ($skus as $s) $skuByProduct[(int) $s['product_id']][] = $s;
        $feat = $old->all("SELECT pf.product_id, pf.feature_id, pf.feature_value_id, v.value FROM shop_product_features pf
            LEFT JOIN shop_feature_values_varchar v ON v.id = pf.feature_value_id AND v.feature_id = pf.feature_id
            WHERE pf.product_id IN ($ph)", $vals);
        $fv = []; $pfRows = [];
        foreach ($feat as $f) {
            $pid = (int) $f['product_id']; $fid = (int) $f['feature_id'];
            if ($fid === $sizeF || $fid === $pairsF) $fv[$pid][$fid] = (string) $f['value'];
            if ($fid === $brandF) $fv[$pid][$fid] = (int) $f['feature_value_id'];
            if (!isset($keptFeatures[$fid])) continue;
            $vid = (int) $f['feature_value_id'] + (in_array($fid, $colorFeatures, true) ? 5000000 : 0);
            $pfRows[$pid . ':' . $fid . ':' . $vid] = ['product_id' => $pid, 'feature_id' => $fid, 'value_id' => $vid];
        }
        $prod = []; $texts = [];
        foreach ($rows as $p) {
            $id = (int) $p['id'];
            $main = null;
            foreach ($skuByProduct[$id] ?? [] as $s) if ((int) $s['id'] === (int) $p['sku_id']) $main = $s;
            $main ??= ($skuByProduct[$id][0] ?? null);
            $box = (int) $p['wholesale_multiplicity'] ?: (int) ($fv[$id][$pairsF] ?? 0) ?: 1;
            $stock = ($main && $main['count'] !== null) ? (int) $main['count'] : null;
            $inStock = ($main === null || ((int) $main['available'] === 1 && ($stock === null || $stock > 0))) ? 1 : 0;
            $supplier = null; $scode = null;
            if ((int) $p['id_forsage'] > 0) { $supplier = 'forsage'; $scode = (string) $p['id_forsage']; }
            elseif ((int) $p['id_jonggolf'] > 0) { $supplier = 'jonggolf'; $scode = (string) $p['id_jonggolf']; }
            $prod[] = [
                'id' => $id, 'url' => (string) ($p['url'] ?: 'product-' . $id), 'name' => trim((string) $p['name']),
                'sku' => (string) ($main['sku'] ?? ''), 'sku_id' => $main ? (int) $main['id'] : null,
                'category_id' => $p['category_id'] ? (int) $p['category_id'] : null,
                'brand_id' => isset($fv[$id][$brandF]) ? (int) $fv[$id][$brandF] : null,
                'price' => (float) $p['price'], 'compare_price' => (float) $p['compare_price'],
                'purchase_price' => (float) ($main['purchase_price'] ?? 0),
                'box_qty' => max(1, $box), 'min_qty' => max(1, (int) $p['wholesale_min_product_count'] ?: $box),
                'size' => mb_substr((string) ($fv[$id][$sizeF] ?? ''), 0, 64),
                'stock' => $stock, 'in_stock' => $inStock, 'status' => (int) $p['status'] === 1 ? 1 : 0,
                'badge' => nz($p['badge']), 'image_id' => $p['image_id'] ? (int) $p['image_id'] : null, 'image_ext' => nz($p['ext']),
                'rating' => (float) $p['rating'], 'rating_count' => (int) $p['rating_count'], 'sales' => (int) round((float) $p['total_sales']),
                'seo_name' => nz($seo[$id]['seo_name'] ?? null), 'h1' => nz($seo[$id]['h1'] ?? null),
                'meta_title' => nz($p['meta_title']), 'meta_keywords' => nz($p['meta_keywords']), 'meta_description' => nz($p['meta_description']),
                'supplier' => $supplier, 'supplier_code' => $scode, 'id_1c' => nz($p['id_1c']),
                'created_at' => dt($p['create_datetime']) ?? '2011-01-01 00:00:00', 'updated_at' => dt($p['edit_datetime']),
            ];
            if (trim((string) $p['summary']) !== '' || trim((string) $p['description']) !== '') {
                $texts[] = ['product_id' => $id, 'summary' => nz($p['summary']), 'description' => nz($p['description'])];
            }
        }
        // Дубли url (в Webasyst url не уникален) — второму товару добавляем id
        $urls = array_column($prod, 'url');
        $exists = [];
        if ($urls) {
            [$uph, $uvals] = $new->in($urls);
            $exists = array_flip($new->col("SELECT url FROM products WHERE url IN ($uph)", $uvals));
        }
        $seen = [];
        foreach ($prod as &$pr) {
            if (isset($exists[$pr['url']]) || isset($seen[$pr['url']])) $pr['url'] .= '-' . $pr['id'];
            $seen[$pr['url']] = 1;
        }
        unset($pr);
        $new->insertMany('products', $prod, false, 500);
        $new->insertMany('product_texts', $texts, false, 200);
        $new->insertMany('product_features', array_values($pfRows), true, 3000);
        $new->insertMany('category_products', array_map(static fn($r) => ['category_id' => (int) $r['category_id'], 'product_id' => (int) $r['product_id'], 'sort' => (int) $r['sort']],
            $old->all("SELECT category_id, product_id, sort FROM shop_category_products WHERE product_id IN ($ph)", $vals)), true, 3000);
        $new->insertMany('product_images', array_map(static fn($r) => [
            'id' => (int) $r['id'], 'product_id' => (int) $r['product_id'], 'sort' => (int) $r['sort'], 'ext' => $r['ext'] ?: 'jpg',
            'width' => (int) $r['width'], 'height' => (int) $r['height'], 'filename' => (string) $r['filename'],
            'description' => nz($r['description']), 'created_at' => dt($r['upload_datetime']) ?? date('Y-m-d H:i:s'),
        ], $old->all("SELECT * FROM shop_product_images WHERE product_id IN ($ph)", $vals)), true, 2000);
        $total += count($rows);
        say("Товары: $total");
    }
    $rel = $old->all('SELECT product_id, related_product_id, type FROM shop_product_related');
    $new->insertMany('product_related', array_map(static fn($r) => ['product_id' => (int) $r['product_id'], 'related_product_id' => (int) $r['related_product_id'], 'type' => $r['type']], $rel), true);
    foreach ($old->all('SELECT * FROM shop_set ORDER BY sort') as $s) {
        $rule = $s['rule'] ?: ((int) $s['type'] === 1 ? 'create_datetime DESC' : null);
        $new->insert('product_sets', ['id' => $s['id'], 'name' => $s['name'], 'type' => (int) $s['type'], 'rule' => $rule, 'limit' => max(1, (int) $s['count']), 'sort' => (int) $s['sort']]);
    }
    $new->insertMany('product_set_items', array_map(static fn($r) => ['set_id' => $r['set_id'], 'product_id' => (int) $r['product_id'], 'sort' => (int) $r['sort']],
        $old->all('SELECT set_id, product_id, sort FROM shop_set_products')), true);
    say('Связанные товары: ' . count($rel));
};

$steps['content'] = function () use ($old, $new) {
    $new->query('TRUNCATE pages');
    $new->query('TRUNCATE blog_posts');
    $n = 0;
    // meta keywords/description страниц — в *_page_params (name = keywords | description)
    $meta = static function (string $table) use ($old): array {
        $r = [];
        foreach ($old->all("SELECT page_id, name, value FROM $table WHERE name IN ('keywords', 'description')") as $p) $r[(int) $p['page_id']][$p['name']] = $p['value'];
        return $r;
    };
    $shopMeta = $meta('shop_page_params');
    $siteMeta = $meta('site_page_params');
    // Страницы магазина: /o-kompanii/ …
    foreach ($old->all("SELECT * FROM shop_page WHERE status = 1 AND (domain = 'tomobuv.com.ua' OR domain IS NULL) ORDER BY sort, id") as $p) {
        $m = $shopMeta[(int) $p['id']] ?? [];
        $new->insert('pages', ['url' => ltrim((string) $p['full_url'], '/'), 'name' => $p['name'], 'title' => nz($p['title']),
            'meta_keywords' => nz($m['keywords'] ?? null), 'meta_description' => nz($m['description'] ?? null),
            'content' => $p['content'], 'status' => 1, 'in_menu' => 1, 'sort' => (int) $p['sort'], 'updated_at' => dt($p['update_datetime'])], true);
        $n++;
    }
    // Страницы приложения «Сайт»: /pages/o-kompanii/ (есть в sitemap старого сайта — сохраняем адреса)
    foreach ($old->all('SELECT * FROM site_page WHERE status = 1 ORDER BY sort, id') as $p) {
        $route = trim(str_replace('*', '', (string) $p['route']), '/');
        $url = ($route !== '' ? $route . '/' : '') . ltrim((string) $p['full_url'], '/');
        $m = $siteMeta[(int) $p['id']] ?? [];
        $new->insert('pages', ['url' => $url, 'name' => $p['name'], 'title' => nz(trim((string) $p['title'])),
            'meta_keywords' => nz($m['keywords'] ?? null), 'meta_description' => nz($m['description'] ?? null),
            'content' => $p['content'], 'status' => 1, 'in_menu' => 0, 'sort' => 100 + (int) $p['sort'], 'updated_at' => dt($p['update_datetime'])], true);
        $n++;
    }
    $b = 0;
    foreach ($old->all("SELECT * FROM blog_post WHERE status = 'published' ORDER BY datetime") as $p) {
        $new->insert('blog_posts', ['id' => (int) $p['id'], 'url' => $p['url'], 'title' => $p['title'], 'text_before_cut' => nz($p['text_before_cut']),
            'text' => $p['text'], 'meta_title' => nz($p['meta_title']), 'meta_keywords' => nz($p['meta_keywords']), 'meta_description' => nz($p['meta_description']),
            'status' => 'published', 'published_at' => dt($p['datetime']) ?? date('Y-m-d H:i:s'), 'updated_at' => dt($p['update_datetime'])], true);
        $b++;
    }
    // Отзывы о магазине: переносим только опубликованные (в старой базе много спама со статусом 0)
    $new->query('TRUNCATE store_reviews');
    $r = $old->all('SELECT * FROM shop_reviews WHERE status = 1');
    $new->insertMany('store_reviews', array_map(static fn($x) => ['id' => (int) $x['id'], 'name' => mb_substr((string) $x['name'], 0, 190), 'email' => nz($x['email']),
        'text' => $x['text'], 'rating' => (int) $x['rating'], 'response' => nz($x['response']), 'status' => 1, 'created_at' => dt($x['datetime']) ?? date('Y-m-d H:i:s')], $r));
    // Баннеры главной (картинки темы старого сайта) — редактируются в админке
    $new->query('TRUNCATE banners');
    $base = '/wa-data/public/shop/themes/balance/img/';
    $new->insertMany('banners', [
        ['place' => 'home_slider', 'title' => 'Детская обувь оптом от производителя', 'text' => 'Ящиками по 8 пар. Размерные ряды 12-26, 26-32, 32-38, 36-41.', 'button' => 'Смотреть', 'link' => '/category/dyetskaya-obuv/', 'image' => $base . 'slider/slide_1.jpg', 'status' => 1, 'sort' => 1],
        ['place' => 'home_slider', 'title' => 'Акции и скидки', 'text' => 'Товары по сниженным ценам — пока есть в наличии.', 'button' => 'Смотреть', 'link' => '/category/aktsiya/', 'image' => $base . 'slider/slide_2.jpg', 'status' => 1, 'sort' => 2],
        ['place' => 'home_slider', 'title' => 'Обувь украинских производителей', 'text' => 'Качественно и доступно.', 'button' => 'Смотреть', 'link' => '/category/ukrainskaya-obuv/', 'image' => $base . 'slider/slide_3.jpg', 'status' => 1, 'sort' => 3],
        ['place' => 'home_wide', 'title' => 'Украинская обувь', 'text' => null, 'button' => 'Перейти', 'link' => '/category/ukrainskaya-obuv/', 'image' => $base . 'banners/banner-2_1.jpg', 'status' => 1, 'sort' => 1],
        ['place' => 'home_wide', 'title' => 'Подростковая обувь', 'text' => null, 'button' => 'Перейти', 'link' => '/category/podrostkovaya-obuv-208/', 'image' => $base . 'banners/banner-2_2.jpg', 'status' => 1, 'sort' => 2],
    ]);
    say("Страницы: $n, статьи: $b, отзывы о магазине: " . count($r));
};

$steps['customers'] = function () use ($old, $new) {
    // Клиенты переносятся ОБНОВЛЕНИЕМ (без очистки таблицы): не теряются клиенты, зарегистрированные на новом сайте,
    // и пароли, уже перехешированные при входе. Вход — как на старом сайте: e-mail (любой из адресов), логин, телефон.
    $contacts = $old->keyed('SELECT * FROM wa_contact ORDER BY id');
    $emailsBy = [];                                   // contact_id => [email, …] (по sort) — корректные адреса
    $loginEmails = [];                                // все адреса как есть — для входа (бывают с кириллицей: «а.name@ukr.net»)
    foreach ($old->all('SELECT contact_id, email FROM wa_contact_emails ORDER BY contact_id, sort') as $r) {
        $e = mb_strtolower(trim((string) $r['email']));
        if ($e === '') continue;
        $loginEmails[(int) $r['contact_id']][] = mb_substr($e, 0, 190);
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) $emailsBy[(int) $r['contact_id']][] = $e;
    }
    // Владелец адреса (customers.email уникален): контакт с паролем (зарегистрированный) → сотрудник → последний вход → меньший id
    $owner = [];
    $rank = static function (array $c): array {
        return [(string) $c['password'] !== '' ? 1 : 0, (int) $c['is_user'], (string) ($c['last_datetime'] ?? ''), -(int) $c['id']];
    };
    foreach ($emailsBy as $cid => $list) {
        if (!isset($contacts[$cid])) continue;
        $e = $list[0];
        if (!isset($owner[$e]) || $rank($contacts[$cid]) > $rank($contacts[$owner[$e]])) $owner[$e] = $cid;
    }
    $primary = [];
    foreach ($owner as $e => $cid) $primary[$cid] = $e;
    $phones = []; $cities = [];
    foreach ($old->all("SELECT contact_id, field, value FROM wa_contact_data WHERE field IN ('phone','address:city') ORDER BY contact_id, sort") as $r) {
        if ($r['field'] === 'phone') $phones[(int) $r['contact_id']] ??= \App\Core\Str::phone((string) $r['value']) ?: substr(preg_replace('/\D/', '', (string) $r['value']), 0, 20);
        else $cities[(int) $r['contact_id']] ??= trim((string) $r['value']);
    }
    $cust = $old->keyed('SELECT contact_id, total_spent, number_of_orders FROM shop_customer');

    // освободить адреса у перенесённых записей, чтобы переназначение не упёрлось в уникальность
    $ids = array_keys($contacts);
    foreach (array_chunk($ids, 2000) as $part) {
        [$ph, $vals] = $new->in($part);
        $new->query("UPDATE customers SET email = NULL WHERE id IN ($ph)", $vals);
    }
    $taken = array_flip(array_map('strval', $new->col('SELECT email FROM customers WHERE email IS NOT NULL')));   // адреса клиентов нового сайта
    $staff = 0; $withPass = 0; $n = 0;
    foreach ($contacts as $id => $c) {
        $id = (int) $id;
        $email = $primary[$id] ?? null;
        if ($email !== null && isset($taken[$email])) $email = null;   // адрес уже занят клиентом, зарегистрированным на новом сайте
        $pass = (string) $c['password'] !== '' ? 'wa:' . $c['password'] : null;
        $row = [
            'id' => $id, 'name' => mb_substr(trim((string) $c['name']), 0, 190), 'firstname' => mb_substr((string) $c['firstname'], 0, 100),
            'lastname' => mb_substr((string) $c['lastname'], 0, 100), 'company' => mb_substr((string) $c['company'], 0, 190),
            'email' => $email, 'phone' => nz($phones[$id] ?? null), 'login' => nz(trim((string) $c['login'])), 'city' => nz($cities[$id] ?? null),
            'password' => $pass, 'role' => (int) $c['is_user'] === 1 ? 'admin' : 'customer', 'status' => 1,
            'orders_count' => (int) ($cust[$id]['number_of_orders'] ?? 0), 'total_spent' => (float) ($cust[$id]['total_spent'] ?? 0),
            'created_at' => dt($c['create_datetime']) ?? date('Y-m-d H:i:s'), 'last_login_at' => dt($c['last_datetime']),
        ];
        $cols = array_keys($row);
        $upd = [];
        foreach ($cols as $col) {
            if ($col === 'id') continue;
            // пароль, уже перехешированный на новом сайте (password_hash), и роль, изменённую в новой админке, не трогаем
            if ($col === 'password') { $upd[] = "`password` = IF(`password` LIKE '$%', `password`, VALUES(`password`))"; continue; }
            if ($col === 'role') { $upd[] = "`role` = IF(`role` = 'customer', VALUES(`role`), `role`)"; continue; }
            $upd[] = "`$col` = VALUES(`$col`)";
        }
        $new->query('INSERT INTO customers (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $upd), array_values($row));
        $n++;
        if ($row['role'] === 'admin') $staff++;
        if ($pass) $withPass++;
    }
    // все адреса каждого контакта — для входа по любому из них
    $new->query('DELETE FROM customer_emails');
    $emailRows = [];
    foreach ($loginEmails as $cid => $list) {
        if (!isset($contacts[$cid])) continue;
        foreach (array_unique($list) as $e) $emailRows[] = ['customer_id' => (int) $cid, 'email' => $e];
    }
    $new->insertMany('customer_emails', $emailRows, true, 1000);
    // Клиенты и сотрудники нового сайта (регистрация, оформление, bin/create-admin.php) — с id с запасом после старых:
    // старый сайт до переключения продолжает создавать контакты с id подряд, и повторный перенос (ON DUPLICATE KEY по id)
    // иначе перезаписал бы ими новые записи — вплоть до e-mail администратора при сохранённых пароле и роли.
    $next = (int) $old->value('SELECT MAX(id) FROM wa_contact') + 100000;
    $new->query("ALTER TABLE customers AUTO_INCREMENT = $next");
    say("Клиенты: $n (с паролем: $withPass, сотрудников: $staff, адресов для входа: " . count($emailRows) . ')');
};

$steps['orders'] = function () use ($old, $new) {
    foreach (['orders', 'order_items', 'order_log'] as $t) $new->query("TRUNCATE $t");
    $contacts = $new->keyed('SELECT id, name, phone, email FROM customers');
    $box = $new->pairs('SELECT id, box_qty FROM products');
    $total = 0;
    foreach (chunks($old, 'SELECT * FROM shop_order WHERE {AFTER}', 'id', 1000) as $rows) {
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        [$ph, $vals] = $old->in($ids);
        $params = [];
        foreach ($old->all("SELECT order_id, name, value FROM shop_order_params WHERE order_id IN ($ph)", $vals) as $p) $params[(int) $p['order_id']][$p['name']] = $p['value'];
        $items = $old->all("SELECT * FROM shop_order_items WHERE order_id IN ($ph) AND type = 'product'", $vals);
        $agg = [];
        $itemRows = [];
        foreach ($items as $it) {
            $bq = (int) ($box[(int) $it['product_id']] ?? 1) ?: 1;
            $q = (int) $it['quantity'];
            $agg[(int) $it['order_id']]['pairs'] = ($agg[(int) $it['order_id']]['pairs'] ?? 0) + $q;
            $agg[(int) $it['order_id']]['boxes'] = ($agg[(int) $it['order_id']]['boxes'] ?? 0) + (int) ceil($q / $bq);
            $agg[(int) $it['order_id']]['subtotal'] = ($agg[(int) $it['order_id']]['subtotal'] ?? 0) + (float) $it['price'] * $q;
            $itemRows[] = ['id' => (int) $it['id'], 'order_id' => (int) $it['order_id'], 'product_id' => (int) $it['product_id'] ?: null,
                'name' => mb_substr((string) $it['name'], 0, 255), 'sku' => (string) $it['sku_code'], 'price' => (float) $it['price'],
                'quantity' => $q, 'box_qty' => $bq];
        }
        $orderRows = [];
        foreach ($rows as $o) {
            $id = (int) $o['id']; $p = $params[$id] ?? []; $c = $contacts[(int) $o['contact_id']] ?? [];
            $addr = trim(implode(', ', array_filter([$p['shipping_address.otdelenie-pocht'] ?? '', $p['shipping_address.street'] ?? ''])));
            $keep = array_diff_key($p, array_flip(['auth_pin', 'auth_code', 'user_agent', 'shipping_address.lat', 'shipping_address.lng']));
            $orderRows[] = [
                'id' => $id, 'customer_id' => $o['contact_id'] ? (int) $o['contact_id'] : null, 'status' => (string) $o['state_id'],
                'total' => (float) $o['total'], 'subtotal' => (float) ($agg[$id]['subtotal'] ?? 0), 'shipping_cost' => (float) $o['shipping'],
                'discount' => (float) $o['discount'], 'currency' => (string) $o['currency'],
                'boxes' => (int) ($agg[$id]['boxes'] ?? 0), 'pairs' => (int) ($agg[$id]['pairs'] ?? 0),
                'name' => mb_substr((string) ($c['name'] ?? ''), 0, 190), 'phone' => (string) ($c['phone'] ?? ''), 'email' => $c['email'] ?? null,
                'shipping_method' => isset($p['shipping_id']) ? 'p' . $p['shipping_id'] : null, 'shipping_name' => nz($p['shipping_name'] ?? null),
                'city' => nz($p['shipping_address.city'] ?? null), 'region' => nz($p['shipping_address.region'] ?? null), 'address' => nz($addr),
                'payment_method' => isset($p['payment_id']) ? 'p' . $p['payment_id'] : null, 'payment_name' => nz($p['payment_name'] ?? null),
                'comment' => nz($o['comment']), 'source' => 'webasyst', 'ip' => nz($p['ip'] ?? null),
                'params' => json_encode($keep, JSON_UNESCAPED_UNICODE),
                'created_at' => dt($o['create_datetime']), 'updated_at' => dt($o['update_datetime']),
            ];
        }
        $new->insertMany('orders', $orderRows, false, 500);
        $new->insertMany('order_items', $itemRows, false, 1000);
        $new->insertMany('order_log', array_map(static fn($l) => ['order_id' => (int) $l['order_id'], 'user_id' => $l['contact_id'] ? (int) $l['contact_id'] : null,
            'status_from' => nz($l['before_state_id']), 'status_to' => nz($l['after_state_id']), 'text' => nz($l['text']), 'created_at' => dt($l['datetime'])],
            $old->all("SELECT * FROM shop_order_log WHERE order_id IN ($ph)", $vals)), false, 1000);
        $total += count($rows);
    }
    say("Заказы: $total");
};

// Промокоды (shop_coupon): code, тип '%' → percent, иначе fixed (грн), value, limit, used, expire_datetime, comment.
// Не очищает таблицу: коды, созданные в новой админке, сохраняются; совпадающий код обновляется.
// Применения — из shop_order_params.coupon_id (если такие заказы уже перенесены шагом orders).
$steps['coupons'] = function () use ($old, $new) {
    $n = 0;
    $map = [];
    foreach ($old->all('SELECT * FROM shop_coupon ORDER BY id') as $c) {
        $code = \App\Services\Coupons::normalize((string) $c['code']);
        if ($code === '') { say('  пропущен код «' . $c['code'] . '» — недопустимые символы'); continue; }
        // счётчик used при повторном переносе не уменьшается: применения на новом сайте (coupon_usages) не теряются
        $new->query('INSERT INTO coupons (code, type, value, usage_limit, used, expires_at, comment, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE type = VALUES(type), value = VALUES(value), usage_limit = VALUES(usage_limit),
                used = GREATEST(used, VALUES(used)), expires_at = VALUES(expires_at), comment = VALUES(comment)', [
            $code, $c['type'] === '%' ? 'percent' : 'fixed', round(max(0, (float) $c['value']), 2),
            max(0, (int) $c['limit']), max(0, (int) $c['used']), dt($c['expire_datetime']),
            nz(mb_substr(trim((string) $c['comment']), 0, 500)),
            dt($c['create_datetime']) ?? date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
        $map[(int) $c['id']] = $code;
        $n++;
    }
    $uses = 0;
    if ($map) {
        $ids = $new->pairs('SELECT code, id FROM coupons');
        $rows = [];
        foreach ($old->all("SELECT order_id, value FROM shop_order_params WHERE name = 'coupon_id' AND value <> '' AND value <> '0'") as $p) {
            $code = $map[(int) $p['value']] ?? null;
            if ($code === null || !isset($ids[$code])) continue;
            $rows[(int) $p['order_id']] = ['coupon_id' => (int) $ids[$code], 'code' => $code, 'order_id' => (int) $p['order_id']];
        }
        if ($rows) {
            [$ph, $vals] = $new->in(array_keys($rows));
            $orders = $new->keyed("SELECT id, customer_id, phone, discount, created_at FROM orders WHERE id IN ($ph)", $vals);
            $ins = [];
            foreach ($rows as $oid => $r) {
                $o = $orders[$oid] ?? null;
                if (!$o) continue;
                $ins[] = $r + ['customer_id' => $o['customer_id'] ? (int) $o['customer_id'] : null,
                    'phone' => \App\Services\Coupons::phoneKey((string) $o['phone']), 'discount' => (float) $o['discount'],
                    'created_at' => $o['created_at'] ?? date('Y-m-d H:i:s')];
            }
            $new->insertMany('coupon_usages', $ins, true);   // IGNORE: UNIQUE order_id — повторный запуск не дублирует
            $uses = count($ins);
        }
    }
    say("Промокоды: $n, применений в заказах: $uses");
};

$steps['index'] = function () {
    CatalogIndexer::rebuildAll();
    $db = App::db();
    say('Индекс каталога: ' . $db->value('SELECT COUNT(*) FROM catalog_index') . ' строк, фильтров: ' . $db->value('SELECT COUNT(*) FROM category_facets'));
};

// ============================================================================ запуск
$only = $argv[1] ?? null;
foreach ($steps as $name => $fn) {
    if ($only && $only !== $name) continue;
    say("== $name");
    $fn();
}
say('Готово.');
