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
 * Общая логика раздела админки «Каталог»: перестройка категорий в индексе каталога,
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
    // Товары после изменения — CatalogIndexer::snapshot() до и CatalogIndexer::products() после (точечно, как импорт).

    /**
     * Пересчитать фильтры всех категорий (после смены «в фильтре» у характеристики): как в rebuildAll — и скрытых
     * (открываются по прямому адресу); подписи товаров — заново, они считаются по фильтруемым характеристикам.
     */
    public static function rebuildAllFacets(): void
    {
        $run = static function (): void {
            foreach (App::db()->col('SELECT id FROM categories') as $cid) CatalogIndexer::rebuildFacets((int) $cid);
            CatalogIndexer::rebuildSignatures();
        };
        if (!CatalogIndexer::exclusive($run, 120)) $run();
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
        // и скрытые (status=0) — как в CatalogIndexer::rebuildAll: в меню их нет, но по прямому адресу они открываются
        foreach (array_keys($todo) as $id) CatalogIndexer::rebuildCategory($byId[$id], $all);
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
     * 301 со старого адреса на новый (смена адреса товара, категории, бренда, страницы, статьи). from_url хранится
     * раскодированным (как Request::path), to_url — как ссылка: символы вне RFC 3986 (кириллица, пробел) — в %XX,
     * уже закодированное («%26», «+» в адресе бренда) — как есть. Без цепочек и петель:
     *  - редирект с нового адреса удаляется — там теперь страница (переименование обратно B → A не даёт петли A ↔ B);
     *  - всё, что вело на старый адрес («X → старый» в любом написании: раскодированном, %XX, без «/» в конце), сразу
     *    ведёт на новый: A → B, потом B → C даёт A → C и B → C.
     * Префикс /ua/ не хранится: ErrorController ищет редирект по пути без него, Response::send добавляет /ua к Location
     * на украинской версии (/ua/старый → /ua/новый). $oldTargets — ещё написания старого адреса в to_url.
     */
    public static function addRedirect(string $from, string $to, array $oldTargets = []): void
    {
        self::addRedirects([[$from, $to, $oldTargets]]);
    }

    /**
     * То же пачкой — [[from, to, oldTargets], …] (импорт прайсов меняет адреса многих товаров): несколько запросов на пачку,
     * а не на каждый адрес. Пары одной пачки независимы: новый адрес каждой свободен (не адрес другого товара и не старый
     * адрес другой пары — так проверяет импорт), поэтому результат тот же, что у addRedirect по очереди.
     */
    public static function addRedirects(array $pairs): void
    {
        $retarget = [];                                  // написание старого адреса в to_url → новый to_url
        $drop = [];                                      // новые адреса: редирект с них удаляется
        $rows = [];                                      // from_url → to_url
        foreach ($pairs as $p) {
            [$from, $to] = [(string) $p[0], self::linkUrl((string) $p[1])];
            $toPath = rawurldecode($to);
            if ($from === '' || $from === $toPath) continue;
            $drop[$toPath] = 1;
            foreach (array_merge([$from, self::linkUrl($from)], (array) ($p[2] ?? [])) as $u) {
                if ($u === '') continue;
                $retarget[$u] = $to;
                if ($u !== '/' && str_ends_with($u, '/')) $retarget[rtrim($u, '/')] = $to;
            }
            $rows[$from] = $to;
        }
        if (!$rows) return;
        $db = App::db();
        foreach (array_chunk(array_keys($drop), 500) as $part) {
            [$ph, $vals] = $db->in($part);
            $db->query("DELETE FROM redirects WHERE from_url IN ($ph)", $vals);
        }
        foreach (array_chunk($retarget, 300, true) as $part) {
            $case = '';
            $params = [];
            foreach ($part as $old => $new) { $case .= ' WHEN ? THEN ?'; $params[] = $old; $params[] = $new; }
            [$ph, $vals] = $db->in(array_map('strval', array_keys($part)));
            $db->query("UPDATE redirects SET to_url = CASE to_url$case ELSE to_url END WHERE to_url IN ($ph)", array_merge($params, $vals));
        }
        foreach (array_chunk($rows, 300, true) as $part) {
            $params = [];
            foreach ($part as $from => $to) array_push($params, $from, $to, 301);
            $db->query('INSERT INTO redirects (from_url, to_url, code) VALUES ' . implode(',', array_fill(0, count($part), '(?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE to_url = VALUES(to_url), code = VALUES(code)', $params);
        }
    }

    /** Адрес как ссылка для to_url: символы вне RFC 3986 (кириллица, пробел) — в %XX, уже закодированное («%26», «+») — как есть */
    private static function linkUrl(string $u): string
    {
        return preg_replace_callback("#[^A-Za-z0-9\\-._~!$&'()*+,;=:@/%]#u", static fn($m) => rawurlencode($m[0]), $u) ?? $u;
    }

    /**
     * Удалённая страница: редиректы на неё вели бы на 404 — убираем их. Адрес — в любом написании to_url, как у
     * addRedirect: раскодированный, %XX и без «/» в конце (редирект, добавленный вручную на «/o-kompanii»), а также
     * с /ua, ?параметрами или #якорем («/ua/o-kompanii/», «/o-kompanii/?utm=1» — раздел «Редиректы» такие допускает).
     * Возвращает число удалённых редиректов.
     */
    public static function dropRedirectsTo(array $targets): int
    {
        $all = [];
        foreach ($targets as $u) {
            $u = (string) $u;
            if ($u === '') continue;
            foreach ([$u, self::linkUrl($u)] as $v) {
                $all[$v] = 1;
                if ($v !== '/' && str_ends_with($v, '/')) $all[rtrim($v, '/')] = 1;
            }
        }
        if (!$all) return 0;
        $db = App::db();
        $n = 0;
        foreach (array_chunk(array_map('strval', array_keys($all)), 500) as $part) {
            [$ph, $vals] = $db->in($part);
            $n += $db->query("DELETE FROM redirects WHERE to_url IN ($ph)", $vals)->rowCount();
        }
        // /ua, ?… и #… в to_url — редкость: такие строки сверяются в PHP без них (без учёта регистра, как сравнивает база)
        $low = [];
        foreach (array_keys($all) as $v) $low[mb_strtolower((string) $v)] = true;
        $ids = [];
        foreach ($db->query('SELECT id, to_url FROM redirects WHERE to_url LIKE ? OR to_url LIKE ? OR to_url LIKE ?', ['/ua/%', '%?%', '%#%'])->fetchAll() as $r) {
            $u = (string) preg_replace('/[?#].*$/s', '', (string) $r['to_url']);
            if (preg_match('#^/ua(?=/|$)#i', $u)) $u = substr($u, 3) ?: '/';
            if (isset($low[mb_strtolower($u)])) $ids[] = (int) $r['id'];
        }
        foreach (array_chunk($ids, 500) as $part) {
            [$ph, $vals] = $db->in($part);
            $n += $db->query("DELETE FROM redirects WHERE id IN ($ph)", $vals)->rowCount();
        }
        return $n;
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

    /**
     * Удалить товары полностью (строки во всех таблицах + файлы фото). Возвращает количество.
     * Индекс — точечно, как после сохранения: снимок (фильтры, бренды) до удаления, CatalogIndexer::products после.
     * Строки catalog_index удалённых товаров убирает он же — по ним видно, в каких категориях уменьшить фильтры и счётчики.
     */
    public static function deleteProducts(array $ids): int
    {
        $ids = self::ids($ids);
        if (!$ids) return 0;
        $db = App::db();
        $snap = CatalogIndexer::snapshot($ids);
        $urls = [];
        foreach (array_chunk($ids, 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            foreach ($db->col("SELECT url FROM products WHERE id IN ($ph)", $vals) as $u) $urls[] = '/product/' . $u . '/';
            $db->transaction(static function ($db) use ($ph, $vals) {
                foreach (['product_texts', 'product_images', 'product_features', 'category_products', 'cart_items', 'product_set_items'] as $t) {
                    $db->query("DELETE FROM `$t` WHERE product_id IN ($ph)", $vals);
                }
                $db->query("DELETE FROM product_related WHERE product_id IN ($ph) OR related_product_id IN ($ph)", array_merge($vals, $vals));
                $db->query("DELETE FROM products WHERE id IN ($ph)", $vals);
            });
        }
        CatalogIndexer::products($ids, $snap);
        foreach ($ids as $id) self::deleteProductFiles($id);
        self::dropRedirectsTo($urls);
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
        return self::storeProductImage($pid, $chk['tmp'], $chk['ext']);
    }

    /**
     * Фото товара из медиатеки: файл public/uploads/… ($ref — путь «2026/09/a.jpg» или ссылка «/uploads/…»)
     * копируется в фото товара так же, как обычная загрузка (сам файл медиатеки не меняется и не удаляется).
     */
    public static function addProductImageFromMedia(int $pid, string $ref): array|string
    {
        $rel = Media::clean($ref);
        $src = $rel !== null ? Media::path($rel) : null;
        if ($src === null) return 'файл не найден в медиатеке';
        if ((int) @filesize($src) > self::MAX_UPLOAD) return 'файл больше 15 МБ';
        $info = @getimagesize($src);
        if (!$info || !isset(self::IMAGE_TYPES[$info['mime'] ?? ''])) return 'это не картинка jpg, png, webp или gif';
        if ($info[0] < 10 || $info[1] < 10 || $info[0] * $info[1] > 60_000_000) return 'недопустимый размер картинки';
        return self::storeProductImage($pid, $src, self::IMAGE_TYPES[$info['mime']]);
    }

    /** Общая часть загрузки: строка product_images + оригинал (до 1200 px) по схеме Webasyst; миниатюры создаст ImageController */
    private static function storeProductImage(int $pid, string $src, string $ext): array|string
    {
        @ini_set('memory_limit', '512M');
        $db = App::db();
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), -1) + 1 FROM product_images WHERE product_id = ?', [$pid]);
        $iid = $db->insert('product_images', ['product_id' => $pid, 'sort' => $sort, 'ext' => $ext, 'width' => 0, 'height' => 0,
            'filename' => '', 'created_at' => date('Y-m-d H:i:s')]);
        $dst = Image::originalPath($pid, $iid, $ext);
        if (!Image::resize($src, $dst, '1200', 90) || !($info = @getimagesize($dst))) {
            @unlink($dst);
            $db->delete('product_images', 'id = ?', [$iid]);
            return 'не удалось обработать картинку';
        }
        $db->update('product_images', ['width' => (int) $info[0], 'height' => (int) $info[1]], 'id = ?', [$iid]);
        self::protectOriginals();
        if (!$db->value('SELECT image_id FROM products WHERE id = ?', [$pid])) self::syncMainImage($pid);
        return ['id' => $iid, 'ext' => $ext, 'width' => (int) $info[0], 'height' => (int) $info[1], 'sort' => $sort];
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
        return SeoVars::product($p, $cat);          // те же переменные, что у витрины и SEO-обзора
    }

    /** Категория для SEO-шаблонов товара — как на витрине: основная (даже скрытая), иначе первая в дереве */
    public static function productSeoCategory(?int $categoryId): ?array
    {
        $db = App::db();
        $cols = 'id, parent_id, depth, name, seo_name, name_uk, seo_name_uk, product_count';
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
     * Что покажет сайт без своего title/description (шаблон, отрывок текста, название) — как витрина и SEO-обзор
     * (SeoAudit::auto), глазами версии $lang: для 'uk' — строка с *_uk и украинские шаблоны. $type: page | brand | blog | category | product.
     */
    public static function seoAuto(string $type, array $row, string $lang = 'ru'): array
    {
        return SeoAudit::inLang($lang, static fn() => SeoAudit::auto($type, $lang === 'uk' ? Lang::localize($row) : $row));
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
    // Один вид во всех семи редакторах (товар, категория, бренд, характеристика, страница, статья, баннер):
    // над формой — переключатель «RU | UA» со счётчиком переведённых полей (партиал admin/partials/lang-bar),
    // поля языка — .l-ru / .l-uk (видны по data-lang области), поля UA отмечены data-uk (по ним считается «заполнено N из M»),
    // HTML — textarea[data-editor] (редактор content.js: визуальный режим и HTML-код, «Медиатека»). Стили — admin.css.

    /** Подписи и пояснения SEO-полей — одинаковые во всех редакторах (ключ — роль поля; у страницы и бренда Title — колонка title) */
    public const SEO_FIELDS = [
        'seo_name'         => ['SEO-название', 'Подставляется в SEO-шаблоны вместо названия.'],
        'meta_title'       => ['Title', 'Заголовок в выдаче поиска и на вкладке браузера.'],
        'meta_description' => ['Description', 'Текст под заголовком в выдаче поиска.'],
        'meta_keywords'    => ['Keywords', 'Ключевые слова через запятую (Google их не учитывает).'],
        'h1'               => ['Заголовок H1', 'Главный заголовок на самой странице.'],
    ];

    /** Пояснение над SEO-полями — одно и то же во всех редакторах */
    public const SEO_INTRO = 'Заполняйте, только если нужно своё значение. Пустое поле — на сайте будет значение по умолчанию (показано серым в поле).';

    /** Метка языка у подписи поля: RU — серая, UA — жёлтая */
    public static function langTag(string $lang): string
    {
        return $lang === 'uk'
            ? '<i class="lp" title="Украинская версия сайта (/ua/…)">UA</i>'
            : '<i class="lp ru" title="Русская версия сайта">RU</i>';
    }

    /**
     * SEO-поле в двух языках с общей подписью и пояснением (SEO_FIELDS).
     * $role — ключ SEO_FIELDS, $name — колонка (title у страницы и бренда вместо meta_title); $o — как у i18nField.
     */
    public static function seoField(string $role, string $name, array $row, array $o = []): string
    {
        [$label, $hint] = self::SEO_FIELDS[$role];
        $area = $role === 'meta_description' || $role === 'meta_keywords';
        return self::i18nField($area ? 'area' : 'text', $name, $label, $row,
            $o + ['hint' => $hint, 'max' => $area ? 5000 : 500, 'rows' => $role === 'meta_keywords' ? 2 : 3]);
    }

    /**
     * Текстовое поле в двух языках: $name (русский) и {$name}_uk (украинский) — видно одно, по переключателю RU | UA.
     * $kind: text | area | html (HTML-редактор content.js).
     * $o: max, rows, required, placeholder, hint (пояснение, HTML), tpl (RU: что будет при пустом поле — шаблон),
     *     empty (RU: текст «Пусто — …» без шаблона), tpl_uk (UA: что будет при пустом поле), error (HTML), id, attrs (доп. атрибуты RU-поля)
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
            $req = !$uk && !empty($o['required']) ? ' required' : '';
            $max = isset($o['max']) ? ' maxlength="' . (int) $o['max'] . '"' : '';
            $attrs = $uk ? ' data-uk' : (string) ($o['attrs'] ?? '');
            // подсказка: пояснение + что будет на сайте, если поле пустое
            if ($uk) {
                $empty = isset($o['tpl_uk']) && $o['tpl_uk'] !== ''
                    ? 'Пусто — на украинской версии будет: <i>' . e(str_limit((string) $o['tpl_uk'], 220)) . '</i>'
                    : 'Пусто — на украинской версии показывается русский текст.';
            } else {
                $empty = !empty($o['tpl']) ? 'Пусто — по шаблону: <i>' . e((string) $o['tpl']) . '</i>' : e((string) ($o['empty'] ?? ''));
            }
            $hint = trim((string) ($o['hint'] ?? '') . ' ' . $empty);
            $hint = $hint !== '' ? '<small class="hint">' . $hint . '</small>' : '';
            $err = $uk ? '' : (string) ($o['error'] ?? '');
            // «*» (обязательное) — только у русского поля: украинское можно не заполнять
            $lbl = '<label class="lbl" for="' . e($id) . '">' . e($uk ? rtrim($label, ' *') : $label) . ' ' . self::langTag($lang) . '</label>';
            if ($kind === 'html') {
                $copy = $uk ? '<button type="button" class="btn btn-sm" data-copy-from="' . e($name) . '" data-copy-to="' . e($field) . '">Скопировать русский текст</button>' : '';
                $h .= '<div class="fld l-' . $lang . '"><div class="lbl-row">' . $lbl . $copy . '</div>'
                    . self::editorBox($field, $value, $id, (int) ($o['rows'] ?? 12), $ph, $uk) . $hint . HtmlSanitizer::hint() . $err . '</div>';
                continue;
            }
            // однострочное поле с переводами строк в значении (импорт со старого сайта: списки в H1) — textarea:
            // <input> молча склеил бы строки при сохранении
            $area = $kind === 'area' || str_contains($value, "\n");
            $input = $area
                ? '<textarea id="' . e($id) . '" name="' . e($field) . '" rows="' . (int) ($o['rows'] ?? 3) . '" class="plain" placeholder="' . e($ph) . '"' . $req . $max . $attrs . '>' . "\n" . e($value) . '</textarea>'
                : '<input type="text" id="' . e($id) . '" name="' . e($field) . '" value="' . e($value) . '" placeholder="' . e($ph) . '"' . $req . $max . $attrs . '>';
            $h .= '<div class="fld l-' . $lang . '">' . $lbl . $input . $hint . $err . '</div>';
        }
        return $h;
    }

    /**
     * HTML-поле: textarea[data-editor] — панель, визуальный режим и HTML-код строит редактор content.js
     * (тот же, что у страниц и статей). data-media-base — откуда брать картинки /wa-data/… в визуальном режиме (разработка).
     */
    public static function editorBox(string $name, string $value, string $id, int $rows = 12, string $placeholder = '', bool $uk = false): string
    {
        $remote = (string) App::config('images.remote_base', '');
        // перевод строки сразу после <textarea> браузер отбрасывает — так сохранится перевод строки в начале самого текста
        return '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . $rows . '" data-editor' . ($uk ? ' data-uk' : '')
            . ($remote !== '' ? ' data-media-base="' . e($remote) . '"' : '')
            . ' placeholder="' . e(str_limit($placeholder, 200)) . '">' . "\n" . e($value) . '</textarea>';
    }

    /**
     * Строка из POST: пусто → null, обрезка по длине. Переводы строк — LF: браузер отправляет textarea с CRLF,
     * а тексты в базе (перенос, переводы UA) — с LF; без этого «сохранить без правок» меняло бы каждый перевод строки.
     */
    public static function postStr(string $key, int $max = 500): ?string
    {
        $v = trim(mb_substr(str_replace("\r\n", "\n", (string) (is_scalar($_POST[$key] ?? null) ? $_POST[$key] : '')), 0, $max));
        return $v === '' ? null : $v;
    }

    /** HTML из POST (описания): пусто → null; переводы строк — LF (как PagesController::html) */
    public static function postHtml(string $key, int $max = 1000000): ?string
    {
        $v = is_scalar($_POST[$key] ?? null) ? trim(str_replace("\r\n", "\n", (string) $_POST[$key])) : '';
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /**
     * Поля без правок — прежние байты из базы. postStr/postHtml/PagesController::html() переводят CRLF в LF и обрезают края,
     * а у текстов из Webasyst бывают CRLF и перевод строки в конце (H1 категорий) — «сохранить без правок» меняло их.
     * Сравнение — после той же нормализации; текст, который очистил HtmlSanitizer (менеджер), отличается и сохраняется новым.
     */
    public static function keepUnchanged(array $new, array $old): array
    {
        $norm = static fn(string $s): string => trim(str_replace("\r\n", "\n", $s));
        foreach ($new as $k => $v) {
            if (is_string($v) && isset($old[$k]) && is_string($old[$k]) && $v !== $old[$k] && $norm($v) === $norm($old[$k])) $new[$k] = $old[$k];
        }
        return $new;
    }

    /**
     * HTML-поле формы админки ($html — из postHtml/PagesController::html, ещё не очищенное; $old — значение в базе).
     * Без правок (совпадает с базой с точностью до keepUnchanged: CRLF/LF, пробелы по краям) — прежние байты из базы
     * без очистки: менеджер правит на странице только title, а <script> виджета карты, записанный администратором
     * (или перенесённый с Webasyst), остаётся. Неочищенный HTML в базу пишет только администратор, поэтому это безопасно.
     * Изменённое поле — HtmlSanitizer::staff(): у менеджера очищается целиком, в том числе «почти такой же» HTML с добавкой.
     * Пустое поле ($html null) при « » в базе (описания брендов из Webasyst) — тоже «без правок»: остаётся « ».
     */
    public static function staffHtml(?string $html, mixed $old): ?string
    {
        if (is_string($old) && $old !== '' && self::keepUnchanged(['v' => $html ?? ''], ['v' => $old])['v'] === $old) return $old;
        return HtmlSanitizer::staff($html);
    }

    public static function storeName(): string
    {
        return (string) Settings::get('store_name', 'Tomobuv');
    }
}
