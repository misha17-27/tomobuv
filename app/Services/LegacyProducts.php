<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Services\Import\Importer;
use App\Services\Suppliers\HitObuvi;
use App\Services\Suppliers\HitObuviMap;
use App\Services\Suppliers\HitObuviSync;
use App\Services\Suppliers\JongGolf;
use App\Services\Suppliers\Supplier;
use App\Services\Suppliers\Tomm;
use App\Services\Suppliers\TommSync;

/**
 * Старые адреса товаров, удалённых при пересборке каталога (bin/catalog-reset.php, docs/INSTALL.md → «Пересборка каталога»):
 * таблица legacy_products (database/migrations/legacy-urls.sql), адрес /product/{url}/ (и /ua/…) → 301.
 *   capture($ids) — до удаления: строка на товар (адрес, категория и цепочка её предков, бренд, артикул, ряд, ящик, цена,
 *                   forsage-код, ключи сопоставления) и адреса из redirects, которые вели на него (alias = 1);
 *   resolve($url) — ProductController, товара с таким адресом нет: новая карточка той же модели (new_product_id, товар на
 *                   сайте — status 1), иначе категория (видимая; иначе ближайший видимый предок — по дереву сейчас, а если
 *                   категории уже нет — по цепочке на момент удаления), иначе главная; null — адреса нет в карте (404);
 *   match()       — сопоставление с загруженными товарами (bin/cron.php после загрузчиков, конец прохода «Хит обуви»,
 *                   bin/legacy-products.php --match). Правила по очереди, только однозначные (ключ есть ровно у одного
 *                   товара сайта; несколько старых адресов на одну новую карточку — можно: дубли старого каталога):
 *                   forsage — forsage-код старого товара = supplier_links.ext_id «Хит обуви» (как R2 загрузчика);
 *                   name    — то же название и ряд (R3);
 *                   article — бренд + тип + «хвост» названия (артикул и цвет; кириллица = латиница, без «-._/») + ряд (R4);
 *                   code    — бренд + код модели (артикул или первое слово «хвоста» с цифрой) + ряд — без типа и цвета;
 *                   own     — код модели Tom.m / Jong•Golf (TommSync::ourCode: «T-10862-D» = «T10862D») + ряд среди товаров
 *                             этих брендов — старые дубли бренда под другим брендом («Дутики Ok Shoes T10862D»).
 *                   Новая карточка удалена — строка снова ждёт сопоставления.
 */
final class LegacyProducts
{
    public const VIA = [
        'forsage' => 'по коду Forsage (связь «Хит обуви»)', 'name' => 'по названию и ряду',
        'article' => 'по бренду, типу, артикулу и ряду', 'code' => 'по бренду, коду модели и ряду',
        'own' => 'по коду модели Tom.m / Jong•Golf и ряду',
    ];
    private const CHUNK = 1000;
    private const COLS = ['url', 'old_id', 'alias', 'name', 'sku', 'size', 'box_qty', 'price', 'status', 'category_id', 'cat_path', 'brand_id', 'brand_name',
        'supplier', 'supplier_code', 'forsage_code', 'name_key', 'art_key', 'code_key', 'own_key', 'created_at'];

    private static bool $schemaOk = false;
    private static ?array $parents = null;

    // ================================================================== схема

    /** Таблица карты (миграция legacy-urls.sql выполняется сама, если таблицы нет) */
    public static function ensureSchema(): void
    {
        if (self::$schemaOk) return;
        $db = App::db();
        if (!(int) $db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legacy_products'")) {
            $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) @file_get_contents(ROOT . '/database/migrations/legacy-urls.sql'));
            foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
                if (trim($stmt) !== '') $db->pdo()->exec($stmt);
            }
        }
        self::$schemaOk = true;
    }

    // ================================================================== ключи сопоставления

    /** Размерный ряд для сравнения: «36 – 41» → «36-41» */
    public static function sz(string $s): string
    {
        return (string) preg_replace(['/\s+/u', '/[‐‑‒–—−]/u'], ['', '-'], trim($s));
    }

    /** Артикул — размерный ряд («26-31» у товаров Forsage и «Хит обуви»), а не код модели */
    private static function isRow(string $s): bool
    {
        return (bool) preg_match('/^\d{2}(?:[.,]5)?-\d{2}(?:[.,]5)?$/', self::sz($s));
    }

    /**
     * Название по бренду: [тип, «хвост»]. Тип — первое слово («Сапоги из пены» — три) по словарю типов «Хит обуви»
     * (RU/UA: «Кросівки» = «Кроссовки»), иначе само слово; бренд снимается, если «хвост» начинается с него
     * (по ключу bkey: «TOM.M» = «Tom.m», «Ok Shoes» = «OK-SHOES»).
     */
    public static function split(string $name, string $brandKey): array
    {
        $w = preg_split('/\s+/u', trim($name)) ?: [];
        if (!$w || $w[0] === '') return ['', ''];
        $tn = count($w) >= 3 && HitObuviMap::typeOf(implode(' ', array_slice($w, 0, 3))) === 'сапоги из пены' ? 3 : 1;
        $type = HitObuviMap::typeOf($name) ?? Importer::nk(implode(' ', array_slice($w, 0, $tn)));
        $rest = array_slice($w, $tn);
        if ($brandKey !== '') {
            for ($k = min(5, count($rest) - 1); $k >= 1; $k--) {
                if (HitObuviSync::bkey(implode(' ', array_slice($rest, 0, $k))) === $brandKey) { $rest = array_slice($rest, $k); break; }
            }
        }
        return [$type, implode(' ', $rest)];
    }

    /**
     * Ключи товара (старой строки и загруженного — одинаково): [name_key, art_key, code_key] — md5 или null.
     * Ряд — products.size, пустой — артикул, если он ряд. Бренд «Не указано» и пустой — только ключ названия.
     */
    public static function keys(string $name, string $sku, string $size, string $brand): array
    {
        $name = Supplier::name($name);
        $sku = trim($sku);
        $sz = self::sz($size !== '' ? $size : (self::isRow($sku) ? $sku : ''));
        $nk = Importer::nk($name);
        $nameKey = $nk !== '' ? md5('n|' . $nk . '|' . $sz) : null;
        $bk = HitObuviSync::bkey($brand);
        if ($bk === '' || $bk === HitObuviSync::bkey('Не указано')) return [$nameKey, null, null];
        [$type, $tail] = self::split($name, $bk);
        $art = preg_match('/\d/', $tail) ? HitObuviSync::akey($tail) : '';
        $artKey = $art !== '' && $type !== '' ? md5('a|' . $bk . '|' . $type . '|' . $art . '|' . $sz) : null;
        $code = self::modelCode($sku, $tail);
        $codeKey = $code !== '' ? md5('c|' . $bk . '|' . $code . '|' . $sz) : null;
        return [$nameKey, $artKey, $codeKey];
    }

    /** Код модели: артикул (не ряд, с цифрой), иначе первое слово «хвоста» с цифрой; меньше 3 знаков — нет кода */
    public static function modelCode(string $sku, string $tail): string
    {
        $c = '';
        if ($sku !== '' && !self::isRow($sku) && preg_match('/\d/', $sku)) $c = $sku;
        elseif (preg_match('/(?:^|\s)\(?([^\s()]*\d[^\s()]*)/u', $tail, $m)) $c = $m[1];
        $c = HitObuviSync::akey(JongGolf::code($c));
        return strlen($c) >= 3 ? $c : '';
    }

    /** Ключ кода модели Tom.m / Jong•Golf: TommSync::ourCode (артикул, иначе после «TOM.M», иначе последнее слово с цифрой) + ряд */
    public static function ownKey(string $name, string $sku, string $size): ?string
    {
        $sku = trim($sku);
        $c = TommSync::ourCode(['name' => Supplier::name($name), 'sku' => $sku]);
        if (strlen($c) < 5 || !preg_match('/\d{3}/', $c)) return null;
        return md5('o|' . $c . '|' . self::sz($size !== '' ? $size : (self::isRow($sku) ? $sku : '')));
    }

    /** Бренды Tom.m и Jong•Golf на сайте: [id …] (их модели берут и свои загрузчики, и «Хит обуви» — SupplierSync::ownModels) */
    public static function ownBrandIds(): array
    {
        return array_keys(self::ownBrands());
    }

    /** Бренды Tom.m и Jong•Golf на сайте (для правила own) */
    private static function ownBrands(): array
    {
        $ids = [];
        try { $ids = array_merge(Tomm::brandIds(), JongGolf::ownBrandIds()); } catch (\Throwable) {}
        foreach (App::db()->all('SELECT id, name FROM brands') as $b) {
            if (in_array(HitObuviSync::bkey((string) $b['name']), ['TOMM', 'TOMMM', 'JONGGOLF', 'JG'], true)) $ids[] = (int) $b['id'];
        }
        return array_fill_keys(array_map('intval', $ids), 1);
    }

    // ================================================================== карта (до удаления)

    /** Родители всех категорий (и скрытых): [id => parent_id] */
    private static function parents(): array
    {
        return self::$parents ??= array_map('intval', Cache::remember('legacy.cat_parents', 86400,
            static fn(): array => App::db()->pairs('SELECT id, parent_id FROM categories')));
    }

    /** Цепочка категорий от корня: «12/45/78» */
    private static function path(?int $cid): string
    {
        $parents = self::parents();
        $out = [];
        for ($id = (int) $cid, $g = 0; $id > 0 && isset($parents[$id]) && $g < 20; $id = $parents[$id], $g++) array_unshift($out, $id);
        return implode('/', $out);
    }

    /**
     * Записать в карту товары $ids (до их удаления). Повторно — обновляет строку того же адреса (сопоставление сохраняется,
     * если это тот же товар). Адреса из redirects, которые вели на эти товары, — строки alias (если адрес не занят).
     * @return array{rows: int, aliases: int}
     */
    public static function capture(array $ids, ?callable $say = null): array
    {
        self::ensureSchema();
        $db = App::db();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $brands = $db->pairs('SELECT id, name FROM brands');
        $visible = $db->pairs('SELECT id, status FROM categories');
        $now = date('Y-m-d H:i:s');
        $rows = 0; $aliases = 0; $done = 0;
        foreach (array_chunk($ids, self::CHUNK) as $part) {
            [$ph, $v] = $db->in($part);
            $list = $db->all("SELECT id, url, name, sku, size, box_qty, price, status, category_id, brand_id, supplier, supplier_code FROM products WHERE id IN ($ph)", $v);
            // без основной категории — первая из category_products (видимая — первой)
            $extra = [];
            $noCat = array_map(static fn($p) => (int) $p['id'], array_filter($list, static fn($p) => !(int) $p['category_id']));
            if ($noCat) {
                [$nph, $nv] = $db->in($noCat);
                foreach ($db->all("SELECT product_id, category_id FROM category_products WHERE product_id IN ($nph) ORDER BY product_id, category_id", $nv) as $r) {
                    $pid = (int) $r['product_id']; $cid = (int) $r['category_id'];
                    if (!isset($extra[$pid]) || (!(int) ($visible[$extra[$pid]] ?? 0) && (int) ($visible[$cid] ?? 0))) $extra[$pid] = $cid;
                }
            }
            $vals = []; $byUrl = [];
            foreach ($list as $p) {
                $row = self::row($p, $extra[(int) $p['id']] ?? null, (string) ($brands[(int) $p['brand_id']] ?? ''), $now);
                $byUrl['/product/' . $p['url'] . '/'] = $row;
                foreach (self::COLS as $c) $vals[] = $row[$c];
            }
            if ($list) {
                $upd = 'new_product_id = IF(old_id = VALUES(old_id), new_product_id, NULL), matched_by = IF(old_id = VALUES(old_id), matched_by, NULL), '
                    . 'matched_at = IF(old_id = VALUES(old_id), matched_at, NULL), '
                    . implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", array_diff(self::COLS, ['url', 'created_at'])));
                $db->query('INSERT INTO legacy_products (`' . implode('`,`', self::COLS) . '`) VALUES '
                    . implode(',', array_fill(0, count($list), '(' . implode(',', array_fill(0, count(self::COLS), '?')) . ')'))
                    . ' ON DUPLICATE KEY UPDATE ' . $upd, $vals);
                $rows += count($list);
            }
            $aliases += self::aliases($byUrl);
            $done += count($part);
            if ($say && ($done % 20000 < self::CHUNK || $done === count($ids))) $say('Карта старых адресов: ' . $done . ' из ' . count($ids));
        }
        return ['rows' => $rows, 'aliases' => $aliases];
    }

    /** Строка карты по товару */
    private static function row(array $p, ?int $extraCat, string $brand, string $now): array
    {
        $cid = (int) $p['category_id'] ?: (int) $extraCat;
        [$nk, $ak, $ck] = self::keys((string) $p['name'], (string) $p['sku'], (string) $p['size'], $brand);
        $supplier = $p['supplier'] !== null && $p['supplier'] !== '' ? (string) $p['supplier'] : null;
        $code = $p['supplier_code'] !== null && $p['supplier_code'] !== '' ? mb_substr((string) $p['supplier_code'], 0, 100) : null;
        return [
            'url' => (string) $p['url'], 'old_id' => (int) $p['id'], 'alias' => 0, 'name' => mb_substr((string) $p['name'], 0, 255),
            'sku' => mb_substr((string) $p['sku'], 0, 255), 'size' => mb_substr((string) $p['size'], 0, 64), 'box_qty' => max(1, (int) $p['box_qty']),
            'price' => (float) $p['price'], 'status' => (int) $p['status'], 'category_id' => $cid ?: null, 'cat_path' => mb_substr(self::path($cid ?: null), 0, 255),
            'brand_id' => $p['brand_id'] !== null ? (int) $p['brand_id'] : null, 'brand_name' => mb_substr($brand, 0, 255),
            'supplier' => $supplier, 'supplier_code' => $code, 'forsage_code' => $supplier === 'forsage' ? $code : null,
            'name_key' => $nk, 'art_key' => $ak, 'code_key' => $ck, 'own_key' => self::ownKey((string) $p['name'], (string) $p['sku'], (string) $p['size']),
            'created_at' => $now,
        ];
    }

    /**
     * Редиректы /product/{старый}/ → удалённый товар (смена адреса в админке, импорт с колонкой «Адрес»): старый адрес — та же
     * строка карты под своим url (alias). AdminCatalog::dropRedirectsTo потом удалит сами редиректы. @return int добавлено
     */
    private static function aliases(array $byUrl): int
    {
        if (!$byUrl) return 0;
        $db = App::db();
        $targets = [];
        foreach (array_keys($byUrl) as $u) { $targets[$u] = $u; $targets[rtrim($u, '/')] = $u; }
        [$ph, $v] = $db->in(array_map('strval', array_keys($targets)));
        $add = [];
        foreach ($db->all("SELECT from_url, to_url FROM redirects WHERE to_url IN ($ph)", $v) as $r) {
            if (!preg_match('~^/product/([^/?#]+)/?$~', (string) $r['from_url'], $m) || !isset($targets[(string) $r['to_url']])) continue;
            $row = $byUrl[$targets[(string) $r['to_url']]];
            $row['url'] = mb_substr($m[1], 0, 255);
            $row['alias'] = 1;
            $add[$row['url']] = $row;
        }
        if (!$add) return 0;
        [$aph, $av] = $db->in(array_map('strval', array_keys($add)));
        foreach ($db->col("SELECT url FROM products WHERE url IN ($aph)", $av) as $busy) unset($add[$busy]);   // адрес снова у товара
        $n = 0;
        foreach ($add as $row) $n += $db->query('INSERT IGNORE INTO legacy_products (`' . implode('`,`', self::COLS) . '`) VALUES ('
            . implode(',', array_fill(0, count(self::COLS), '?')) . ')', array_map(static fn($c) => $row[$c], self::COLS))->rowCount();
        return $n;
    }

    /** Строки карты, адрес которых снова у товара сайта (не нужны: ProductController найдёт товар). @return int удалено */
    public static function dropBusy(): int
    {
        self::ensureSchema();
        return App::db()->query('DELETE l FROM legacy_products l JOIN products p ON p.url = l.url')->rowCount();
    }

    // ================================================================== 301

    /**
     * Куда вести старый адрес товара: '/product/{новый}/', '/category/{…}/' или '/'; null — адреса нет в карте.
     * Путь — без /ua: Response::send добавит префикс к Location сам (/ua/product/старый/ → /ua/category/…/).
     * $count — считать переход (hits; CLI-проверка --resolve не считает).
     */
    public static function resolve(string $url, bool $count = true): ?string
    {
        if ($url === '' || strlen($url) > 255) return null;
        $db = App::db();
        try {
            $r = $db->row('SELECT id, category_id, cat_path, new_product_id FROM legacy_products WHERE url = ? LIMIT 1', [$url]);
        } catch (\PDOException) {
            return null;                                                     // таблицы ещё нет (до bin/install.php)
        }
        if (!$r) return null;
        $to = null;
        if ($r['new_product_id']) {
            $u = $db->value('SELECT url FROM products WHERE id = ? AND status = 1', [(int) $r['new_product_id']]);
            if ($u !== null && $u !== '') $to = '/product/' . $u . '/';
        }
        $to ??= self::categoryTarget($r['category_id'] !== null ? (int) $r['category_id'] : null, (string) $r['cat_path']);
        if ($count) {
            try {
                $db->query('UPDATE legacy_products SET hits = hits + 1, last_hit_at = NOW() WHERE id = ?', [(int) $r['id']]);
            } catch (\PDOException) {
                // счётчик не важнее ответа
            }
        }
        return $to;
    }

    /** Видимая категория товара, иначе ближайший видимый предок (по дереву сейчас, затем по цепочке на момент удаления), иначе '/' */
    public static function categoryTarget(?int $cid, string $path): string
    {
        $visible = Catalog::categories();
        $parents = self::parents();
        for ($id = (int) $cid, $g = 0; $id > 0 && isset($parents[$id]) && $g < 20; $id = $parents[$id], $g++) {
            if (isset($visible[$id])) return Catalog::categoryUrl($visible[$id]);
        }
        foreach (array_reverse(array_filter(array_map('intval', explode('/', $path)))) as $id) {
            if (isset($visible[$id])) return Catalog::categoryUrl($visible[$id]);
        }
        return '/';
    }

    /** Вид цели без запроса к странице: product | category (своя) | parent (предок) | home */
    private static function targetKind(?int $cid, string $path, array $visible, array $parents): string
    {
        if ($cid && isset($visible[$cid])) return 'category';
        for ($id = (int) $cid, $g = 0; $id > 0 && isset($parents[$id]) && $g < 20; $id = $parents[$id], $g++) if (isset($visible[$id])) return 'parent';
        foreach (array_filter(array_map('intval', explode('/', $path))) as $id) if (isset($visible[$id])) return 'parent';
        return 'home';
    }

    // ================================================================== сопоставление

    /**
     * Сопоставить строки карты без новой карточки с товарами сайта. $dry — только посчитать; $force — даже если товары сайта
     * не менялись с прошлого раза (по умолчанию тогда выход сразу: cron раз в час не пересчитывает ключи 50 тыс. товаров зря).
     * Параллельно не идёт (GET_LOCK). @return array [table, busy, skipped, lost, left, matched, by => [правило => n], ambiguous => [правило => n], ms]
     */
    public static function match(?callable $say = null, bool $dry = false, bool $force = false): array
    {
        $t = microtime(true);
        $st = ['table' => true, 'busy' => false, 'skipped' => false, 'lost' => 0, 'left' => 0, 'matched' => 0, 'by' => [], 'ambiguous' => [], 'ms' => 0];
        $db = App::db();
        try {
            $left = (int) $db->value('SELECT COUNT(*) FROM legacy_products WHERE new_product_id IS NULL');
        } catch (\PDOException) {
            $st['table'] = false;
            return $st;
        }
        $lock = 'tomobuv_legacy_' . substr(md5((string) $db->value('SELECT DATABASE()')), 0, 16);
        if ((int) $db->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) { $st['busy'] = true; return $st; }
        try {
            // новая карточка удалена (вручную, загрузчиком) — строка снова ждёт сопоставления
            if (!$dry) {
                $st['lost'] = $db->query('UPDATE legacy_products l LEFT JOIN products p ON p.id = l.new_product_id
                    SET l.new_product_id = NULL, l.matched_by = NULL, l.matched_at = NULL WHERE l.new_product_id IS NOT NULL AND p.id IS NULL')->rowCount();
                $left += $st['lost'];
            }
            $st['left'] = $left;
            if (!$left) return $st;
            $sigRow = $db->row('SELECT COUNT(*) n, COALESCE(MAX(id), 0) m FROM products');
            $sig = $sigRow['n'] . ':' . $sigRow['m'] . ':' . $left;
            if (!$force && !$dry && Cache::get('legacy.match.sig') === $sig) { $st['skipped'] = true; return $st; }

            $taken = [];                                                        // [id строки карты => id товара] (для $dry)
            // forsage: код старого товара Forsage = ext_id связи «Хит обуви» (тот же код в адресе у поставщика)
            $by = [];
            foreach ($db->query("SELECT l.id lid, s.product_id pid FROM supplier_links s
                    JOIN legacy_products l ON l.forsage_code = s.ext_id AND l.new_product_id IS NULL
                    JOIN products p ON p.id = s.product_id
                    LEFT JOIN legacy_products lo ON lo.old_id = p.id
                    WHERE s.supplier = ? AND s.ext_id IS NOT NULL AND s.ext_id <> '' AND lo.id IS NULL", [HitObuvi::CODE]) as $r) {
                $by[(int) $r['lid']][(int) $r['pid']] = 1;
            }
            $pick = [];
            foreach ($by as $lid => $pids) {
                if (count($pids) === 1) $pick[$lid] = (int) array_key_first($pids);
                else $st['ambiguous']['forsage'] = ($st['ambiguous']['forsage'] ?? 0) + 1;
            }
            self::assign($pick, 'forsage', $dry, $taken, $st);
            if ($say) $say('по коду Forsage: ' . ($st['by']['forsage'] ?? 0));

            // ключи товаров сайта: [правило => [ключ => id товара | 0 — неоднозначно]]; товары, которые сами в карте (карта записана
            // заранее, до удаления), — не кандидаты: иначе старый адрес «нашёл» бы свою же удаляемую карточку
            $brands = $db->pairs('SELECT id, name FROM brands');
            $own = self::ownBrands();
            $cand = ['name' => [], 'article' => [], 'code' => [], 'own' => []];
            foreach ($db->query('SELECT p.id, p.name, p.sku, p.size, p.brand_id FROM products p LEFT JOIN legacy_products lo ON lo.old_id = p.id WHERE lo.id IS NULL') as $p) {
                $k = self::keys((string) $p['name'], (string) $p['sku'], (string) $p['size'], (string) ($brands[(int) $p['brand_id']] ?? ''));
                $k[3] = isset($own[(int) $p['brand_id']]) ? self::ownKey((string) $p['name'], (string) $p['sku'], (string) $p['size']) : null;
                foreach (['name' => $k[0], 'article' => $k[1], 'code' => $k[2], 'own' => $k[3]] as $rule => $key) {
                    if ($key === null) continue;
                    $cand[$rule][$key] = isset($cand[$rule][$key]) && $cand[$rule][$key] !== (int) $p['id'] ? 0 : (int) $p['id'];
                }
            }
            $col = ['name' => 'name_key', 'article' => 'art_key', 'code' => 'code_key', 'own' => 'own_key'];
            foreach ($cand as $rule => $map) {
                $pick = [];
                $amb = array_keys(array_filter($map, static fn($v) => $v === 0));
                $ok = array_filter($map, static fn($v) => $v > 0);
                foreach (array_chunk(array_keys($ok), self::CHUNK) as $part) {
                    [$ph, $v] = $db->in(array_map('strval', $part));
                    foreach ($db->all("SELECT id, `{$col[$rule]}` k FROM legacy_products WHERE new_product_id IS NULL AND `{$col[$rule]}` IN ($ph)", $v) as $r) {
                        if (!isset($taken[(int) $r['id']])) $pick[(int) $r['id']] = $ok[$r['k']];
                    }
                }
                foreach (array_chunk($amb, self::CHUNK) as $part) {
                    [$ph, $v] = $db->in(array_map('strval', $part));
                    $n = (int) $db->value("SELECT COUNT(*) FROM legacy_products WHERE new_product_id IS NULL AND `{$col[$rule]}` IN ($ph)", $v);
                    if ($n) $st['ambiguous'][$rule] = ($st['ambiguous'][$rule] ?? 0) + $n;
                }
                self::assign($pick, $rule, $dry, $taken, $st);
                if ($say) $say(self::VIA[$rule] . ': ' . ($st['by'][$rule] ?? 0));
            }
            $st['left'] = $left - $st['matched'];
            if (!$dry) Cache::set('legacy.match.sig', $sigRow['n'] . ':' . $sigRow['m'] . ':' . $st['left'], 7 * 86400);
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
            $st['ms'] = (int) round((microtime(true) - $t) * 1000);
        }
        return $st;
    }

    /** Записать найденные пары [id строки карты => id товара] правила $rule (только строки ещё без карточки) */
    private static function assign(array $pick, string $rule, bool $dry, array &$taken, array &$st): void
    {
        foreach ($pick as $lid => $pid) if (isset($taken[$lid])) unset($pick[$lid]);
        if (!$pick) return;
        $n = 0;
        if ($dry) {
            $n = count($pick);
        } else {
            $db = App::db();
            foreach (array_chunk($pick, 500, true) as $part) {
                $case = ''; $params = [];
                foreach ($part as $lid => $pid) { $case .= ' WHEN ? THEN ?'; $params[] = $lid; $params[] = $pid; }
                [$ph, $v] = $db->in(array_keys($part));
                $n += $db->query("UPDATE legacy_products SET new_product_id = CASE id$case END, matched_by = ?, matched_at = NOW()
                    WHERE new_product_id IS NULL AND id IN ($ph)", array_merge($params, [$rule], $v))->rowCount();
            }
        }
        foreach ($pick as $lid => $pid) $taken[$lid] = $pid;
        $st['by'][$rule] = ($st['by'][$rule] ?? 0) + $n;
        $st['matched'] += $n;
    }

    // ================================================================== сводка

    /**
     * Сводка карты: строк (из них alias), сопоставлено по правилам, куда ведут сейчас (product — новая карточка на сайте,
     * category — своя категория, parent — видимый предок, home — главная), переходов (hits).
     */
    public static function stats(): array
    {
        self::ensureSchema();
        $db = App::db();
        $out = ['rows' => 0, 'aliases' => 0, 'matched' => [], 'targets' => ['product' => 0, 'category' => 0, 'parent' => 0, 'home' => 0],
            'hidden_new' => 0, 'hits' => 0, 'hit_rows' => 0, 'busy' => 0, 'by_status' => []];
        $r = $db->row('SELECT COUNT(*) n, COALESCE(SUM(alias), 0) a, COALESCE(SUM(hits), 0) h, COALESCE(SUM(hits > 0), 0) hr FROM legacy_products');
        $out['rows'] = (int) $r['n']; $out['aliases'] = (int) $r['a']; $out['hits'] = (int) $r['h']; $out['hit_rows'] = (int) $r['hr'];
        foreach ($db->all('SELECT matched_by, COUNT(*) n FROM legacy_products WHERE new_product_id IS NOT NULL GROUP BY matched_by') as $m) $out['matched'][(string) $m['matched_by']] = (int) $m['n'];
        $out['by_status'] = array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM legacy_products GROUP BY status'));
        $out['busy'] = (int) $db->value('SELECT COUNT(*) FROM legacy_products l JOIN products p ON p.url = l.url');
        // новая карточка на сайте — product; есть, но скрыта или удалена — по категории
        $rows = $db->all('SELECT l.category_id, l.cat_path, (p.id IS NOT NULL AND p.status = 1) live, (l.new_product_id IS NOT NULL) has_new, COUNT(*) n
            FROM legacy_products l LEFT JOIN products p ON p.id = l.new_product_id GROUP BY l.category_id, l.cat_path, live, has_new');
        $visible = Catalog::categories();
        $parents = self::parents();
        foreach ($rows as $g) {
            if ((int) $g['live']) { $out['targets']['product'] += (int) $g['n']; continue; }
            if ((int) $g['has_new']) $out['hidden_new'] += (int) $g['n'];
            $out['targets'][self::targetKind($g['category_id'] !== null ? (int) $g['category_id'] : null, (string) $g['cat_path'], $visible, $parents)] += (int) $g['n'];
        }
        return $out;
    }

    /** Цели по категориям для предпросмотра (без записи в карту): [вид => число], $rows — [[category_id, cat_path, n], …] */
    public static function previewTargets(array $rows): array
    {
        $visible = Catalog::categories();
        $parents = self::parents();
        $out = ['category' => 0, 'parent' => 0, 'home' => 0];
        foreach ($rows as $r) $out[self::targetKind($r[0], $r[1], $visible, $parents)] += $r[2];
        return $out;
    }

    /** Цепочка категорий для предпросмотра (bin/catalog-reset.php --dry-run) */
    public static function catPath(?int $cid): string
    {
        return self::path($cid);
    }
}
