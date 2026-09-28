<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Core\Lang;
use App\Core\Settings;
use App\Core\Str;

/**
 * Общая логика раздела админки «Каталог»: быстрое точечное обновление индекса каталога,
 * дерево категорий (nested set), фото товаров, значения характеристик, бренды.
 */
final class AdminCatalog
{
    public const MAX_UPLOAD = 15 * 1024 * 1024;
    public const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    public const BADGES = ['' => 'Нет', 'new' => 'Новинка', 'bestseller' => 'Хит продаж', 'lowprice' => 'Низкая цена'];
    /** Сортировка товаров в категории по умолчанию (формат Webasyst) */
    public const CATEGORY_SORTS = [
        ''                     => 'Как задано вручную',
        'create_datetime DESC' => 'Сначала новые',
        'edit_datetime DESC'   => 'Недавно изменённые',
        'price ASC'            => 'Сначала дешёвые',
        'price DESC'           => 'Сначала дорогие',
        'name ASC'             => 'По названию',
        'total_sales DESC'     => 'Популярные',
    ];

    // ======================================================================= индекс каталога

    /** Товары со значениями фильтруемых характеристик: [pid => [[fid, vid], …]] */
    private static function filterPairs(array $ids): array
    {
        $db = App::db();
        $fids = $db->col('SELECT id FROM features WHERE is_filter = 1');
        if (!$ids || !$fids) return [];
        [$ph, $vals] = $db->in($ids);
        [$fph, $fvals] = $db->in($fids);
        $out = [];
        foreach ($db->all("SELECT product_id, feature_id, value_id FROM product_features WHERE product_id IN ($ph) AND feature_id IN ($fph)", array_merge($vals, $fvals)) as $r) {
            $out[(int) $r['product_id']][] = [(int) $r['feature_id'], (int) $r['value_id']];
        }
        return $out;
    }

    /**
     * Снимок состояния товаров ДО изменения (нужен для точного пересчёта фильтров категорий).
     * Использование: $s = AdminCatalog::snapshot($ids); …изменения…; AdminCatalog::reindex($ids, $s);
     */
    public static function snapshot(array $ids): array
    {
        $ids = self::ids($ids);
        if (!$ids || count($ids) > 500) return ['ids' => $ids, 'rows' => [], 'pairs' => [], 'brands' => [], 'big' => count($ids) > 500];
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        return [
            'ids'    => $ids,
            'rows'   => array_map(static fn($r) => [(int) $r['category_id'], (int) $r['product_id']],
                $db->all("SELECT category_id, product_id FROM catalog_index WHERE product_id IN ($ph)", $vals)),
            'pairs'  => self::filterPairs($ids),
            'brands' => array_map('intval', $db->col("SELECT DISTINCT brand_id FROM products WHERE id IN ($ph) AND brand_id IS NOT NULL", $vals)),
            'big'    => false,
        ];
    }

    /**
     * Точечное обновление catalog_index / category_facets / счётчиков после изменения товаров.
     * Логика принадлежности товара категории — как в CatalogIndexer::rebuildCategory, но без
     * перестройки целых категорий (CatalogIndexer::products на категории в 57 тыс. товаров — ~3 с,
     * здесь — десятки мс). Больше 500 товаров — полная перестройка CatalogIndexer::rebuildAll().
     */
    public static function reindex(array $ids, ?array $before = null): void
    {
        $ids = self::ids($ids);
        if (!$ids) return;
        if (count($ids) > 500 || ($before['big'] ?? false)) {
            CatalogIndexer::rebuildAll();
            return;
        }
        if ($before === null) {                       // без снимка точно посчитать фильтры нельзя
            CatalogIndexer::products($ids);
            return;
        }
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        $cats = $db->all('SELECT id, parent_id, lft, rgt, type, conditions, include_sub, status FROM categories');
        $prods = $db->keyed("SELECT id, in_stock, created_at, price, LEFT(name, 64) AS name, brand_id FROM products WHERE id IN ($ph) AND status = 1", $vals);
        $links = [];
        if ($prods) {
            [$aph, $avals] = $db->in(array_keys($prods));
            foreach ($db->all("SELECT category_id, product_id, sort FROM category_products WHERE product_id IN ($aph)", $avals) as $l) {
                $links[(int) $l['product_id']][] = [(int) $l['category_id'], (int) $l['sort']];
            }
        }
        $byId = array_column($cats, null, 'id');
        $new = [];                                    // "cid:pid" => строка индекса
        foreach ($cats as $c) {
            if (!(int) $c['status']) continue;
            $cid = (int) $c['id'];
            if ((int) $c['type'] === 1) {             // динамическая: условие считает БД
                if (!$prods) continue;
                [$where, $params] = CatalogIndexer::conditionSql((string) $c['conditions']);
                if ($where === '') continue;
                [$aph, $avals] = $db->in(array_keys($prods));
                foreach ($db->col("SELECT p.id FROM products p WHERE p.id IN ($aph) AND p.status = 1 AND $where", array_merge($avals, $params)) as $pid) {
                    $new[$cid . ':' . $pid] = [$cid, (int) $pid, 0];
                }
                continue;
            }
            foreach ($prods as $pid => $p) {
                $min = null;
                foreach ($links[$pid] ?? [] as [$lc, $ls]) {
                    $x = $byId[$lc] ?? null;
                    if (!$x) continue;
                    $inside = $lc === $cid || ((int) $c['include_sub'] && (int) $x['lft'] > (int) $c['lft'] && (int) $x['rgt'] < (int) $c['rgt']);
                    if ($inside) $min = $min === null ? $ls : min($min, $ls);
                }
                if ($min !== null) $new[$cid . ':' . $pid] = [$cid, (int) $pid, $min];
            }
        }

        $db->transaction(static function ($db) use ($ids, $ph, $vals, $new, $prods, $before) {
            $db->query("DELETE FROM catalog_index WHERE product_id IN ($ph)", $vals);
            $rows = [];
            foreach ($new as [$cid, $pid, $sort]) {
                $p = $prods[$pid];
                $rows[] = ['category_id' => $cid, 'product_id' => $pid, 'in_stock' => (int) $p['in_stock'], 'created_at' => $p['created_at'],
                    'price' => $p['price'], 'sort' => $sort, 'name' => (string) $p['name']];
            }
            $db->insertMany('catalog_index', $rows, true);

            // фильтры: разница «было − стало» по тройкам (категория, характеристика, значение)
            $after = self::filterPairs($ids);
            $delta = [];
            foreach ($before['rows'] as [$cid, $pid]) {
                foreach ($before['pairs'][$pid] ?? [] as [$f, $v]) $delta["$cid:$f:$v"] = ($delta["$cid:$f:$v"] ?? 0) - 1;
            }
            foreach ($new as [$cid, $pid]) {
                foreach ($after[$pid] ?? [] as [$f, $v]) $delta["$cid:$f:$v"] = ($delta["$cid:$f:$v"] ?? 0) + 1;
            }
            $plus = []; $minus = [];
            foreach ($delta as $k => $d) {
                if ($d === 0) continue;
                [$c, $f, $v] = array_map('intval', explode(':', $k));
                if ($d > 0) $plus[] = ['category_id' => $c, 'feature_id' => $f, 'value_id' => $v, 'cnt' => $d];
                else $minus[-$d][] = [$c, $f, $v];
            }
            foreach (array_chunk($plus, 300) as $part) {
                $sql = 'INSERT INTO category_facets (category_id, feature_id, value_id, cnt) VALUES '
                    . implode(',', array_fill(0, count($part), '(?,?,?,?)')) . ' ON DUPLICATE KEY UPDATE cnt = cnt + VALUES(cnt)';
                $params = [];
                foreach ($part as $r) array_push($params, $r['category_id'], $r['feature_id'], $r['value_id'], $r['cnt']);
                $db->query($sql, $params);
            }
            foreach ($minus as $d => $triples) {
                foreach (array_chunk($triples, 200) as $part) {
                    $w = implode(' OR ', array_fill(0, count($part), '(category_id = ? AND feature_id = ? AND value_id = ?)'));
                    $db->query("UPDATE category_facets SET cnt = IF(cnt > ?, cnt - ?, 0) WHERE $w", array_merge([$d, $d], array_merge(...$part)));
                }
            }
            $affected = array_values(array_unique(array_merge(array_column($before['rows'], 0), array_column($new, 0))));
            if ($affected) {
                [$cph, $cvals] = $db->in($affected);
                $db->query("DELETE FROM category_facets WHERE cnt = 0 AND category_id IN ($cph)", $cvals);
                $db->query("UPDATE categories c SET c.product_count = (SELECT COUNT(*) FROM catalog_index ci WHERE ci.category_id = c.id) WHERE c.id IN ($cph)", $cvals);
            }
            $brands = array_values(array_unique(array_filter(array_merge($before['brands'],
                array_map(static fn($p) => (int) $p['brand_id'], array_values($prods)),
                array_map('intval', $db->col("SELECT DISTINCT brand_id FROM products WHERE id IN ($ph) AND brand_id IS NOT NULL", $vals))))));
            if ($brands) {
                [$bph, $bvals] = $db->in($brands);
                $db->query("UPDATE brands b SET b.product_count = (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.status = 1) WHERE b.id IN ($bph)", $bvals);
            }
        });
        Cache::flush();
    }

    /** Пересчитать фильтры всех категорий (после смены «в фильтре» у характеристики или слияния значений) */
    public static function rebuildAllFacets(): void
    {
        foreach (App::db()->col('SELECT id FROM categories WHERE status = 1') as $cid) CatalogIndexer::rebuildFacets((int) $cid);
        Cache::flush();
    }

    /**
     * Перестроить категории (после изменения категории). $ancestors = true — и всех предков:
     * нужно, только когда товары переходят между ветками (смена родителя, удаление с переносом).
     * Индекс родителя зависит лишь от привязок товаров к потомкам, а не от их типа/статуса/условия,
     * поэтому при правке настроек категории достаточно перестроить её одну (для «Детской обуви» — 2 с против 0,1 с).
     */
    public static function reindexCategories(array $ids, bool $ancestors = true): void
    {
        $db = App::db();
        $all = $db->all('SELECT id, parent_id, lft, rgt, type, conditions, include_sub, status FROM categories');
        $byId = array_column($all, null, 'id');
        $todo = [];
        foreach (self::ids($ids) as $id) {
            $x = $byId[$id] ?? null;
            if (!$x) continue;
            $todo[$id] = 1;
            if (!$ancestors) continue;
            foreach ($all as $a) {
                if ((int) $a['lft'] < (int) $x['lft'] && (int) $a['rgt'] > (int) $x['rgt']) $todo[(int) $a['id']] = 1;
            }
        }
        foreach (array_keys($todo) as $id) {
            if ((int) $byId[$id]['status']) {
                CatalogIndexer::rebuildCategory($byId[$id], $all);
            } else {                                   // скрытая категория — убираем из индекса
                $db->query('DELETE FROM catalog_index WHERE category_id = ?', [$id]);
                $db->query('DELETE FROM category_facets WHERE category_id = ?', [$id]);
            }
        }
        if ($todo) {                                   // счётчики только затронутых категорий
            [$ph, $vals] = $db->in(array_keys($todo));
            $db->query("UPDATE categories c SET c.product_count = (SELECT COUNT(*) FROM catalog_index ci WHERE ci.category_id = c.id) WHERE c.id IN ($ph)", $vals);
        }
        Cache::flush();
    }

    // ======================================================================= товары

    public static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    }

    /** Уникальный адрес товара: slug, при занятости — slug-2, slug-3… */
    public static function uniqueProductUrl(string $url, int $exceptId = 0): string
    {
        $db = App::db();
        $base = $url;
        for ($i = 2; $i < 1000; $i++) {
            if (!$db->value('SELECT id FROM products WHERE url = ? AND id <> ?', [$url, $exceptId])) return $url;
            $url = mb_substr($base, 0, 180) . '-' . $i;
        }
        return $base . '-' . Str::random(3);
    }

    /** Очистка адреса, введённого вручную: латиница/кириллица, цифры, «-», «_», «.» */
    public static function cleanUrl(string $url): string
    {
        $url = mb_strtolower(trim($url));
        $url = preg_replace('/\s+/u', '-', $url);
        $url = preg_replace('/[^\p{L}\p{N}\-_.]+/u', '', (string) $url);
        return trim(mb_substr((string) $url, 0, 190), '-.');
    }

    // ======================================================================= редиректы (смена адреса, удаление)

    /**
     * 301 со старого адреса на новый. from_url хранится раскодированным (как Request::path), to_url — как ссылка.
     * Цепочки «X → старый адрес» сразу переводятся на новый (без двойного редиректа), обратный редирект «новый → …»
     * удаляется (иначе петля). $oldTargets — в каком виде старый адрес мог быть записан в to_url (по умолчанию $from).
     */
    public static function addRedirect(string $from, string $to, array $oldTargets = []): void
    {
        $toPath = rawurldecode($to);
        if ($from === '' || $from === $toPath) return;
        $db = App::db();
        $db->query('DELETE FROM redirects WHERE from_url = ?', [$toPath]);
        $old = array_values(array_unique(array_merge([$from], $oldTargets)));
        [$ph, $vals] = $db->in($old);
        $db->query("UPDATE redirects SET to_url = ? WHERE to_url IN ($ph)", array_merge([$to], $vals));
        $db->upsert('redirects', ['from_url' => $from, 'to_url' => $to, 'code' => 301], ['to_url', 'code']);
    }

    /** Удалённая страница: редиректы на неё вели бы на 404 — убираем их */
    public static function dropRedirectsTo(array $targets): void
    {
        $targets = array_values(array_unique(array_filter($targets, 'strlen')));
        if (!$targets) return;
        $db = App::db();
        foreach (array_chunk($targets, 500) as $part) {
            [$ph, $vals] = $db->in($part);
            $db->query("DELETE FROM redirects WHERE to_url IN ($ph)", $vals);
        }
    }

    /**
     * Куда вернуться после действия: адрес админки этого же сайта из Referer (путь + query), иначе $fallback.
     * Защита от открытого редиректа: чужой хост в Referer игнорируется.
     */
    public static function backUrl(string $fallback): string
    {
        $p = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if (!is_array($p) || !isset($p['path']) || !str_starts_with($p['path'], '/admin/')) return $fallback;
        if (isset($p['host'])) {
            $host = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            if (strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) return $fallback;
        }
        return $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /** Удалить товары полностью (строки во всех таблицах + файлы фото). Возвращает количество. */
    public static function deleteProducts(array $ids): int
    {
        $ids = self::ids($ids);
        if (!$ids) return 0;
        $db = App::db();
        $big = count($ids) > 500;
        $cats = []; $brands = []; $urls = [];
        foreach (array_chunk($ids, 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            if (!$big) {
                $cats = array_merge($cats, $db->col("SELECT DISTINCT category_id FROM catalog_index WHERE product_id IN ($ph)", $vals));
                $brands = array_merge($brands, $db->col("SELECT DISTINCT brand_id FROM products WHERE id IN ($ph) AND brand_id IS NOT NULL", $vals));
            }
            foreach ($db->col("SELECT url FROM products WHERE id IN ($ph)", $vals) as $u) $urls[] = '/product/' . $u . '/';
            $db->transaction(static function ($db) use ($ph, $vals) {
                foreach (['product_texts', 'product_images', 'product_features', 'category_products', 'catalog_index', 'cart_items', 'product_set_items'] as $t) {
                    $db->query("DELETE FROM `$t` WHERE product_id IN ($ph)", $vals);
                }
                $db->query("DELETE FROM product_related WHERE product_id IN ($ph) OR related_product_id IN ($ph)", array_merge($vals, $vals));
                $db->query("DELETE FROM products WHERE id IN ($ph)", $vals);
            });
        }
        foreach ($ids as $id) self::deleteProductFiles($id);
        self::dropRedirectsTo($urls);
        if ($big) {
            CatalogIndexer::rebuildAll();
        } else {
            $cats = array_values(array_unique(array_map('intval', $cats)));
            foreach ($cats as $cid) CatalogIndexer::rebuildFacets($cid);
            if ($cats) {
                [$cph, $cvals] = $db->in($cats);
                $db->query("UPDATE categories c SET c.product_count = (SELECT COUNT(*) FROM catalog_index ci WHERE ci.category_id = c.id) WHERE c.id IN ($cph)", $cvals);
            }
            if ($brands) {
                [$bph, $bvals] = $db->in(array_values(array_unique(array_map('intval', $brands))));
                $db->query("UPDATE brands b SET b.product_count = (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.status = 1) WHERE b.id IN ($bph)", $bvals);
            }
            Cache::flush();
        }
        return count($ids);
    }

    // ======================================================================= фото товаров

    private static function waData(): string
    {
        return PUBLIC_DIR . '/' . App::config('images.wa_data', 'wa-data');
    }

    /**
     * Ссылка на миниатюру для админки. Если оригинал лежит локально (новые загрузки) — локальный адрес
     * (миниатюру создаст ImageController), иначе — Image::url (в dev — с живого сайта).
     */
    public static function thumb(int $pid, ?int $iid, ?string $ext, string $size = '96x96'): string
    {
        if (!$iid) return '';
        $ext = $ext ?: 'jpg';
        if (is_file(Image::originalPath($pid, $iid, $ext))) {
            return '/' . App::config('images.wa_data', 'wa-data') . '/public/shop/products/' . Image::productDir($pid)
                . '/images/' . $iid . '/' . $iid . '.' . $size . '.' . $ext;
        }
        return Image::url($pid, $iid, $ext, $size);
    }

    /**
     * Проверка загруженного файла: только jpg/png/webp/gif до 15 МБ, реальная картинка (getimagesize).
     * Возвращает ['tmp' => путь, 'ext' => расширение] или строку с ошибкой.
     */
    public static function checkUpload(array $f): array|string
    {
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'файл больше, чем разрешает сервер (' . ini_get('upload_max_filesize') . ')';
        if ($err !== UPLOAD_ERR_OK) return 'файл не загружен (код ' . $err . ')';
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) return 'файл не загружен';
        if ((int) $f['size'] > self::MAX_UPLOAD) return 'файл больше 15 МБ';
        $info = @getimagesize($tmp);
        if (!$info || !isset(self::IMAGE_TYPES[$info['mime'] ?? ''])) return 'это не картинка jpg, png, webp или gif';
        if ($info[0] < 10 || $info[1] < 10 || $info[0] * $info[1] > 60_000_000) return 'недопустимый размер картинки';
        return ['tmp' => $tmp, 'ext' => self::IMAGE_TYPES[$info['mime']], 'w' => (int) $info[0], 'h' => (int) $info[1]];
    }

    /** Сохранить фото товара: пересохранение через GD (до 1200 px) в путь оригинала Webasyst, запись product_images */
    public static function addProductImage(int $pid, array $file): array|string
    {
        $chk = self::checkUpload($file);
        if (is_string($chk)) return $chk;
        @ini_set('memory_limit', '512M');
        $db = App::db();
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), -1) + 1 FROM product_images WHERE product_id = ?', [$pid]);
        $iid = $db->insert('product_images', ['product_id' => $pid, 'sort' => $sort, 'ext' => $chk['ext'], 'width' => 0, 'height' => 0,
            'filename' => '', 'created_at' => date('Y-m-d H:i:s')]);
        $dst = Image::originalPath($pid, $iid, $chk['ext']);
        if (!Image::resize($chk['tmp'], $dst, '1200', 90) || !($info = @getimagesize($dst))) {
            @unlink($dst);
            $db->delete('product_images', 'id = ?', [$iid]);
            return 'не удалось обработать картинку';
        }
        $db->update('product_images', ['width' => (int) $info[0], 'height' => (int) $info[1]], 'id = ?', [$iid]);
        self::protectOriginals();
        if (!$db->value('SELECT image_id FROM products WHERE id = ?', [$pid])) self::syncMainImage($pid);
        return ['id' => $iid, 'ext' => $chk['ext'], 'width' => (int) $info[0], 'height' => (int) $info[1], 'sort' => $sort];
    }

    /** Оригиналы (wa-data/protected) не отдаются напрямую — как в Webasyst; наружу только миниатюры */
    private static function protectOriginals(): void
    {
        $f = self::waData() . '/protected/.htaccess';
        if (is_file($f)) return;
        @mkdir(dirname($f), 0775, true);
        @file_put_contents($f, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }

    /** Главное фото = первое по порядку */
    public static function syncMainImage(int $pid): void
    {
        $db = App::db();
        $img = $db->row('SELECT id, ext FROM product_images WHERE product_id = ? ORDER BY sort, id LIMIT 1', [$pid]);
        $db->update('products', ['image_id' => $img ? (int) $img['id'] : null, 'image_ext' => $img['ext'] ?? null], 'id = ?', [$pid]);
    }

    /** Удалить файлы одного фото: оригинал + папка миниатюр */
    public static function deleteImageFiles(int $pid, int $iid, string $ext): void
    {
        @unlink(Image::originalPath($pid, $iid, $ext));
        self::rmTree(self::waData() . '/public/shop/products/' . Image::productDir($pid) . '/images/' . $iid);
    }

    /** Удалить все файлы товара (оригиналы и миниатюры) */
    public static function deleteProductFiles(int $pid): void
    {
        if ($pid <= 0) return;
        foreach (['protected', 'public'] as $zone) {
            $dir = self::waData() . '/' . $zone . '/shop/products/' . Image::productDir($pid);
            if (!is_dir($dir)) continue;
            self::rmTree($dir);
            @rmdir(dirname($dir));                     // опустевшие папки {id%100}/{id/100%100} (rmdir удаляет только пустые)
            @rmdir(dirname($dir, 2));
        }
    }

    /** Рекурсивное удаление папки (только внутри public/) */
    private static function rmTree(string $dir): void
    {
        $real = realpath($dir);
        $root = realpath(PUBLIC_DIR);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_dir($real)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($real);
    }

    /** Загрузка картинки категории/бренда в public/uploads/{dir}/ (пересохранение через GD). Путь для БД или ошибка. */
    public static function saveUpload(array $file, string $dir, string $prefix, string $size = '800'): string|array
    {
        $chk = self::checkUpload($file);
        if (is_string($chk)) return ['error' => $chk];
        @ini_set('memory_limit', '512M');
        $dir = preg_replace('/[^a-z0-9_-]/', '', $dir);
        $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($prefix)) . '-' . Str::random(4) . '.' . $chk['ext'];
        $dst = PUBLIC_DIR . '/uploads/' . $dir . '/' . $name;
        if (!Image::resize($chk['tmp'], $dst, $size, 90)) return ['error' => 'не удалось обработать картинку'];
        return '/uploads/' . $dir . '/' . $name;
    }

    /**
     * Удалить прежнюю картинку категории/бренда — только файл, загруженный этой формой (uploads/categories|brands),
     * и только если он больше нигде не используется (его могли выбрать из медиатеки для другой страницы).
     * Вызывать ПОСЛЕ записи новой картинки в базу.
     */
    public static function deleteUpload(?string $path): void
    {
        $path = (string) $path;
        if (!preg_match('#^/uploads/(categories|brands)/[a-z0-9_.-]+$#i', $path)) return;
        if (Media::usage($path, 1)) return;
        @unlink(PUBLIC_DIR . $path);
        Media::forget();
    }

    /**
     * Картинка из поля с путём (его заполняет пикер медиатеки): существующий файл public/uploads/… → '/uploads/…'.
     * Прежнее значение ($current, например /wa-data/… со старого сайта) принимается как есть.
     * Возвращает путь, null (поле очищено) или false (файла нет / недопустимый путь).
     */
    public static function pickedImage(string $input, ?string $current): string|null|false
    {
        $input = trim($input);
        if ($input === '') return null;
        if ($current !== null && $current !== '' && ($input === $current || media($current) === $input)) return $current;
        $rel = Media::clean($input);
        if ($rel === null || Media::path($rel) === null) return false;
        return Media::url($rel);
    }

    /**
     * Сводка по характеристикам для списка: значений, без перевода UA, товаров. Считается по 800 тыс. строк (~0,3 с),
     * поэтому лежит отдельным файлом storage/cache/admin-feature-stats.php на 10 минут — Cache::flush() после каждого
     * сохранения товара не заставляет пересчитывать. Сброс — AdminCatalog::forgetFeatureStats() (после правки значений).
     */
    public static function featureStats(): array
    {
        $file = STORAGE . '/cache/admin-feature-stats.php';
        $data = is_file($file) ? @include $file : null;
        if (is_array($data) && ($data['t'] ?? 0) > time() - 600) return $data;
        $db = App::db();
        $values = $noUk = $products = [];
        foreach ($db->all("SELECT feature_id, COUNT(*) n, SUM(value_uk IS NULL OR value_uk = '') nu FROM feature_values GROUP BY feature_id") as $r) {
            $values[(int) $r['feature_id']] = (int) $r['n'];
            $noUk[(int) $r['feature_id']] = (int) $r['nu'];
        }
        // у характеристик с одним значением на товар строк = товаров; у «нескольких значений» считаем товары отдельно
        $products = array_map('intval', $db->pairs('SELECT feature_id, COUNT(*) FROM product_features GROUP BY feature_id'));
        $multi = array_map('intval', $db->col('SELECT id FROM features WHERE multiple = 1'));
        if ($multi) {
            [$ph, $vals] = $db->in($multi);
            foreach ($db->pairs("SELECT feature_id, COUNT(DISTINCT product_id) FROM product_features WHERE feature_id IN ($ph) GROUP BY feature_id", $vals) as $fid => $n) {
                $products[(int) $fid] = (int) $n;
            }
        }
        $data = ['t' => time(), 'values' => $values, 'noUk' => $noUk, 'products' => $products];
        @mkdir(dirname($file), 0775, true);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, '<?php return ' . var_export($data, true) . ';', LOCK_EX) !== false) {
            @rename($tmp, $file);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
        }
        return $data;
    }

    public static function forgetFeatureStats(): void
    {
        @unlink(STORAGE . '/cache/admin-feature-stats.php');
    }

    // ======================================================================= характеристики и бренды

    /** Найти или создать значение характеристики; для «Бренда» заодно создаётся строка в brands */
    public static function valueId(int $featureId, string $value): int
    {
        $value = mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, 255);
        if ($value === '') return 0;
        $db = App::db();
        $id = (int) $db->value('SELECT id FROM feature_values WHERE feature_id = ? AND value = ?', [$featureId, $value]);
        if (!$id) {
            $sort = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM feature_values WHERE feature_id = ?', [$featureId]);
            $db->insert('feature_values', ['feature_id' => $featureId, 'value' => $value, 'sort' => $sort], true);
            $id = (int) $db->value('SELECT id FROM feature_values WHERE feature_id = ? AND value = ?', [$featureId, $value]);
        }
        if ($id && $featureId === self::brandFeatureId()) self::ensureBrand($id, $value);
        return $id;
    }

    public static function featureId(string $code): int
    {
        static $map = null;
        $map ??= App::db()->pairs('SELECT code, id FROM features');
        return (int) ($map[$code] ?? 0);
    }

    public static function brandFeatureId(): int
    {
        return self::featureId('brand');
    }

    /** Строка brands для значения характеристики «Бренд» (id совпадают, как в Webasyst) */
    public static function ensureBrand(int $id, string $name): void
    {
        $db = App::db();
        if ($db->value('SELECT id FROM brands WHERE id = ?', [$id])) return;
        $db->insert('brands', ['id' => $id, 'name' => $name, 'url' => self::uniqueBrandUrl($name, $id), 'hidden' => 0, 'sort' => 0], true);
    }

    /** Адрес бренда как в Webasyst — имя бренда; при совпадении добавляется id */
    public static function uniqueBrandUrl(string $url, int $exceptId): string
    {
        $url = trim(preg_replace('/[\/\\\\?#%]+/u', ' ', $url));
        $url = trim((string) preg_replace('/\s+/u', ' ', $url)) ?: 'brand-' . $exceptId;
        $url = mb_substr($url, 0, 190);
        if (App::db()->value('SELECT id FROM brands WHERE url = ? AND id <> ?', [$url, $exceptId])) $url .= '-' . $exceptId;
        return $url;
    }

    // ======================================================================= категории

    /** Все категории в порядке дерева: [id => row] */
    public static function categories(): array
    {
        return App::db()->keyed('SELECT id, parent_id, lft, rgt, depth, sort, name, name_uk, url, type, conditions, include_sub, status, product_count, image
            FROM categories ORDER BY lft, sort, id');
    }

    /** Полные пути категорий [id => 'ЖЕНСКАЯ ОБУВЬ › Кроссовки'] — у многих подкатегорий одинаковые имена («32-38», «Зимняя обувь») */
    public static function paths(array $cats): array
    {
        $out = [];
        foreach ($cats as $id => $c) {                  // $cats в порядке дерева: родитель всегда раньше потомков
            $pid = (int) $c['parent_id'];
            $out[(int) $id] = ($pid && isset($out[$pid]) ? $out[$pid] . ' › ' : '') . $c['name'];
        }
        return $out;
    }

    /** id категории и всех её потомков */
    public static function subtreeIds(int $id, array $cats): array
    {
        $c = $cats[$id] ?? null;
        if (!$c) return [];
        $out = [$id];
        foreach ($cats as $x) {
            if ((int) $x['lft'] > (int) $c['lft'] && (int) $x['rgt'] < (int) $c['rgt']) $out[] = (int) $x['id'];
        }
        return $out;
    }

    /** Количество прямых привязок товаров к категориям (все статусы) — кэш на 10 минут */
    public static function directCounts(): array
    {
        return Cache::remember('admin.category_direct_counts', 600, static fn() =>
            array_map('intval', App::db()->pairs('SELECT category_id, COUNT(*) FROM category_products GROUP BY category_id')));
    }

    /**
     * Пересчёт nested set (lft/rgt/depth) и full_url по parent_id + sort.
     * Категории с несуществующим родителем становятся корневыми; циклы разрываются.
     */
    public static function rebuildTree(): void
    {
        $db = App::db();
        $rows = $db->keyed('SELECT id, parent_id, sort, lft, rgt, depth, url, full_url FROM categories ORDER BY sort, id');
        $children = [];
        foreach ($rows as $id => $r) {
            $pid = (int) $r['parent_id'];
            if ($pid && !isset($rows[$pid])) $pid = 0;
            $children[$pid][] = (int) $id;
        }
        $n = 0; $seen = []; $new = [];
        $walk = static function (int $parent, int $depth, string $path) use (&$walk, &$n, &$seen, &$new, $children, $rows) {
            foreach ($children[$parent] ?? [] as $id) {
                if (isset($seen[$id])) continue;
                $seen[$id] = 1;
                $lft = ++$n;
                $full = ($path !== '' ? $path . '/' : '') . $rows[$id]['url'];
                $walk($id, $depth + 1, $full);
                $new[$id] = ['parent_id' => $parent, 'lft' => $lft, 'rgt' => ++$n, 'depth' => $depth, 'full_url' => $full];
            }
        };
        $walk(0, 0, '');
        foreach ($rows as $id => $r) {                // оторванные циклы — в корень
            if (!isset($seen[$id])) {
                $lft = ++$n;
                $new[$id] = ['parent_id' => 0, 'lft' => $lft, 'rgt' => ++$n, 'depth' => 0, 'full_url' => $r['url']];
            }
        }
        $changed = [];
        foreach ($new as $id => $v) {
            $r = $rows[$id];
            if ((int) $r['parent_id'] !== $v['parent_id'] || (int) $r['lft'] !== $v['lft'] || (int) $r['rgt'] !== $v['rgt']
                || (int) $r['depth'] !== $v['depth'] || (string) $r['full_url'] !== $v['full_url']) $changed[$id] = $v;
        }
        if (!$changed) return;
        foreach (array_chunk($changed, 200, true) as $part) {
            $sql = 'UPDATE categories SET ';
            $params = [];
            $sets = [];
            foreach (['parent_id', 'lft', 'rgt', 'depth', 'full_url'] as $col) {
                $case = "`$col` = CASE id";
                foreach ($part as $id => $v) { $case .= ' WHEN ? THEN ?'; array_push($params, $id, $v[$col]); }
                $sets[] = $case . ' END';
            }
            [$ph, $vals] = $db->in(array_keys($part));
            $db->query($sql . implode(', ', $sets) . " WHERE id IN ($ph)", array_merge($params, $vals));
        }
    }

    /** Перенумеровать sort у детей родителя (10, 20, 30…) в заданном порядке id */
    public static function renumber(array $orderedIds): void
    {
        if (!$orderedIds) return;
        $db = App::db();
        $params = []; $case = 'CASE id';
        foreach (array_values($orderedIds) as $i => $id) { $case .= ' WHEN ? THEN ?'; array_push($params, (int) $id, ($i + 1) * 10); }
        [$ph, $vals] = $db->in(array_map('intval', $orderedIds));
        $db->query("UPDATE categories SET sort = $case END WHERE id IN ($ph)", array_merge($params, $vals));
    }

    // ======================================================================= SEO

    /** Переменные SEO-шаблонов товара — как Front\ProductController::seoVars */
    public static function productSeoVars(array $p, ?array $cat): array
    {
        $name = (string) ($p['name'] ?? '');
        return [
            'product'  => ['name' => $name, 'seo_name' => trim((string) ($p['seo_name'] ?? '')) ?: $name, 'sku' => (string) ($p['sku'] ?? ''),
                'price' => (string) round((float) ($p['price'] ?? 0)), 'format_price' => price_format((float) ($p['price'] ?? 0))],
            'category' => $cat ? ['name' => (string) $cat['name'], 'seo_name' => trim((string) ($cat['seo_name'] ?? '')) ?: (string) $cat['name']] : ['name' => '', 'seo_name' => ''],
        ];
    }

    /** Категория для SEO-шаблонов товара — как на витрине: основная (даже скрытая), иначе первая в дереве */
    public static function productSeoCategory(?int $categoryId): ?array
    {
        $db = App::db();
        $cols = 'id, name, seo_name, name_uk, seo_name_uk';
        $c = $categoryId ? $db->row("SELECT $cols FROM categories WHERE id = ?", [$categoryId]) : null;
        return $c ?? $db->row("SELECT $cols FROM categories WHERE status = 1 ORDER BY lft, sort, id LIMIT 1");
    }

    /**
     * Что покажет украинская версия, если украинское поле пустое: DB подставляет x_uk только когда он заполнен,
     * поэтому при пустом x_uk остаётся своё русское значение, а если и оно пустое — украинский шаблон.
     * $own — строка с русскими полями, $tplUk — результат шаблонов UA (seoTemplates(..., 'uk')).
     */
    public static function ukFallback(array $own, array $tplUk): array
    {
        $out = [];
        foreach ($tplUk as $k => $v) $out[$k] = trim((string) ($own[$k] ?? '')) ?: (string) $v;
        return $out;
    }

    /**
     * Результат SEO-шаблонов (то, что будет на сайте при пустых полях).
     * $lang = 'uk' — шаблоны украинской версии (настройки seo.….uk, если заданы).
     */
    public static function seoTemplates(string $type, array $vars, string $lang = 'ru'): array
    {
        $prev = Lang::current();
        if ($lang !== $prev) Lang::set($lang);
        $out = [];
        try {
            foreach (['meta_title', 'meta_description', 'meta_keywords', 'h1'] as $f) {
                $key = $f === 'h1' ? "seo.{$type}_h1" : "seo.{$type}_{$f}";
                $out[$f] = \App\Core\Seo::pick('', $key, $vars);
            }
        } finally {
            if ($lang !== $prev) Lang::set($prev);
        }
        return $out;
    }

    /**
     * Параметры превью Google украинской версии для партиала admin/partials/serp (ручной режим):
     * своё — поля *_uk, «по шаблону» — то, что покажет /ua/ при пустом поле ($fallback из ukFallback).
     */
    public static function serpUk(string $path, array $row, array $fallback, string $titleField = 'meta_title'): array
    {
        return ['path' => $path,
            'title' => (string) ($row[$titleField . '_uk'] ?? ''), 'titleAuto' => (string) ($fallback[$titleField] ?? ''),
            'desc' => (string) ($row['meta_description_uk'] ?? ''), 'descAuto' => (string) ($fallback['meta_description'] ?? ''),
            'fields' => ['title' => $titleField . '_uk', 'desc' => 'meta_description_uk']];
    }

    /** Строка с подставленными украинскими полями (x_uk вместо x, если заполнено) — для шаблонов SEO UA */
    public static function ukRow(?array $row): ?array
    {
        return $row === null ? null : Lang::localize($row);
    }

    // ======================================================================= формы: поля RU | UA, редактор

    /** Переключатель языка текстовых полей (.subtabs). Все переключатели страницы синхронны (catalog.js). */
    public static function langTabs(): string
    {
        return '<nav class="subtabs ac-langtabs" aria-label="Язык текстов">'
            . '<a href="#" data-lang="ru" class="on" aria-pressed="true" title="Русская версия сайта">RU</a>'
            . '<a href="#" data-lang="uk" aria-pressed="false" title="Украинская версия сайта (/ua/…)">UA</a></nav>';
    }

    /**
     * Текстовое поле в двух языках: $name (русский) и {$name}_uk (украинский) — видно одно, по переключателю RU | UA.
     * $kind: text | area | html (textarea с панелью оформления и предпросмотром).
     * $o: max, rows, required, placeholder, tpl (результат SEO-шаблона RU), tpl_uk, hint, error (HTML), id, attrs (доп. атрибуты RU-поля)
     */
    public static function i18nField(string $kind, string $name, string $label, array $row, array $o = []): string
    {
        $h = '';
        foreach (['ru' => $name, 'uk' => $name . '_uk'] as $lang => $field) {
            $uk = $lang === 'uk';
            $value = (string) ($row[$field] ?? '');
            $id = ($o['id'] ?? 'f-' . preg_replace('/[^a-z0-9_]/', '', $name)) . ($uk ? '-uk' : '');
            $ph = $uk ? (string) ($o['tpl_uk'] ?? '') : (string) ($o['tpl'] ?? $o['placeholder'] ?? '');
            if ($uk && $ph === '') $ph = str_limit((string) ($row[$name] ?? ''), 180);
            $tag = '<b class="ac-ltag' . ($uk ? ' uk' : '') . '">' . ($uk ? 'UA' : 'RU') . '</b>';
            $req = !$uk && !empty($o['required']) ? ' required' : '';
            $max = isset($o['max']) ? ' maxlength="' . (int) $o['max'] . '"' : '';
            $attrs = !$uk ? (string) ($o['attrs'] ?? '') : '';
            $hint = '';
            if ($uk) {
                $hint = isset($o['tpl_uk']) && $o['tpl_uk'] !== ''
                    ? '<small class="hint">Пусто — на украинской версии будет: <i>' . e(str_limit((string) $o['tpl_uk'], 220)) . '</i></small>'
                    : '<small class="hint">Пусто — на украинской версии показывается русский текст.</small>';
            } elseif (!empty($o['tpl'])) {
                $hint = '<small class="hint">Если пусто — по шаблону: <i>' . e($o['tpl']) . '</i></small>';
            } elseif (!empty($o['hint'])) {
                $hint = '<small class="hint">' . $o['hint'] . '</small>';
            }
            $wrapAttrs = ' data-l="' . $lang . '"' . ($uk ? ' hidden' : '');
            if ($kind === 'html') {
                $h .= '<div class="fld ac-i18n"' . $wrapAttrs . '><label for="' . $id . '"><span>' . e($label) . ' ' . $tag . '</span></label>'
                    . self::editorBox($field, $value, $id, (int) ($o['rows'] ?? 12), $ph) . $hint . ($uk ? '' : (string) ($o['error'] ?? '')) . '</div>';
                continue;
            }
            $input = $kind === 'area'
                ? '<textarea id="' . $id . '" name="' . e($field) . '" rows="' . (int) ($o['rows'] ?? 3) . '" class="plain" placeholder="' . e($ph) . '"' . $req . $max . $attrs . '>' . e($value) . '</textarea>'
                : '<input type="text" id="' . $id . '" name="' . e($field) . '" value="' . e($value) . '" placeholder="' . e($ph) . '"' . $req . $max . $attrs . '>';
            $h .= '<div class="fld ac-i18n"' . $wrapAttrs . '><label for="' . $id . '"><span>' . e($label) . ' ' . $tag . '</span></label>'
                . $input . $hint . ($uk ? '' : (string) ($o['error'] ?? '')) . '</div>';
        }
        return $h;
    }

    /** Textarea для HTML с простой панелью (жирный, список, ссылка…) и предпросмотром */
    public static function editorBox(string $name, string $value, string $id, int $rows = 12, string $placeholder = ''): string
    {
        $remote = (string) App::config('images.remote_base', '');
        return '<div class="ac-editor" data-remote="' . e($remote) . '">'
            . '<div class="ac-etools" role="toolbar" aria-label="Оформление текста">'
            . '<button type="button" class="btn btn-sm" data-cmd="b" title="Жирный" aria-label="Жирный"><b>Ж</b></button>'
            . '<button type="button" class="btn btn-sm" data-cmd="i" title="Курсив" aria-label="Курсив"><i>К</i></button>'
            . '<button type="button" class="btn btn-sm" data-cmd="h3" title="Подзаголовок" aria-label="Подзаголовок">H3</button>'
            . '<button type="button" class="btn btn-sm" data-cmd="p" title="Абзац" aria-label="Абзац">¶</button>'
            . '<button type="button" class="btn btn-sm" data-cmd="ul" title="Маркированный список">• Список</button>'
            . '<button type="button" class="btn btn-sm" data-cmd="ol" title="Нумерованный список">1. Список</button>'
            . '<button type="button" class="btn btn-sm" data-cmd="a" title="Ссылка">Ссылка</button>'
            . '<span class="sp"></span><button type="button" class="btn btn-sm" data-cmd="preview" aria-pressed="false">Предпросмотр</button></div>'
            . '<textarea class="code" id="' . e($id) . '" name="' . e($name) . '" rows="' . $rows . '" placeholder="' . e(str_limit($placeholder, 200)) . '">' . e($value) . '</textarea>'
            . '<iframe class="ac-preview" hidden sandbox="" title="Предпросмотр"></iframe></div>';
    }

    /** Строка из POST: пусто → null, обрезка по длине */
    public static function postStr(string $key, int $max = 500): ?string
    {
        $v = trim(mb_substr((string) (is_scalar($_POST[$key] ?? null) ? $_POST[$key] : ''), 0, $max));
        return $v === '' ? null : $v;
    }

    /** HTML из POST (описания): пусто → null */
    public static function postHtml(string $key, int $max = 1000000): ?string
    {
        $v = is_scalar($_POST[$key] ?? null) ? trim((string) $_POST[$key]) : '';
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    public static function storeName(): string
    {
        return (string) Settings::get('store_name', 'Tomobuv');
    }
}
