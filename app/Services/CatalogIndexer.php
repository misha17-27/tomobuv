<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;

/**
 * Строит catalog_index (категория → активные товары с учётом подкатегорий и динамических
 * категорий) и category_facets (значения фильтров по категориям).
 *
 * Полная перестройка (~100 тыс. товаров) — 7–10 с: bin/reindex.php
 * Точечная — после изменения товаров (1–10 товаров — десятки мс, 1000 — доли секунды):
 *   $s = CatalogIndexer::snapshot($ids);   // ДО изменения: характеристики и бренд товаров
 *   …изменения товаров…
 *   CatalogIndexer::products($ids, $s);
 * Строки catalog_index товаров удаляются и вставляются заново по всем категориям, куда товары попадают
 * (своя категория, предки с include_sub, динамические — условие проверяется только для этих товаров),
 * category_facets меняются на разницу «было − стало», счётчики — только у затронутых категорий и брендов.
 *
 * Что учтено в category_facets для товара, помнит catalog_index_sig (md5 его пар «характеристика:значение»
 * на момент индексации, database/migrations/catalog-index-sig.sql). Снимок (или, без снимка, текущие
 * характеристики) сверяется с этой подписью; не совпало — фильтры категорий товара пересчитываются
 * целиком (медленнее, но точно). Поэтому без снимка точечный путь тоже точный и быстрый, если
 * характеристики товара не менялись (цена, наличие, категория, статус).
 *
 * Перестройки целых категорий (rebuildAll, много товаров сразу, «Завершение» импорта) идут по одной:
 * файловая блокировка storage/cache/reindex.lock (CatalogIndexer::exclusive).
 */
final class CatalogIndexer
{
    /** Больше стольких товаров за раз — затронутые категории перестраиваются целиком (прежний путь) */
    public const INCREMENTAL_MAX = 2000;

    private static ?bool $sigTable = null;

    /** Блокировка перестройки уже взята этим процессом (повторный вход — без ожидания) */
    private static bool $locked = false;
    /** Сколько секунд последний exclusive() ждал чужую перестройку (для вывода bin/reindex.php) */
    public static float $waited = 0.0;

    /**
     * Полная перестройка индекса всех категорий. Идёт другая перестройка (bin/reindex.php, кнопка в «Состоянии системы»,
     * импорт) — ждёт её до $wait секунд; не дождалась — false, ничего не сделано.
     */
    public static function rebuildAll(?callable $log = null, float $wait = 600.0): bool
    {
        return self::exclusive(static function () use ($log): void {
            $db = App::db();
            $cats = $db->all('SELECT id, parent_id, lft, rgt, type, conditions, include_sub, status FROM categories ORDER BY lft');
            $db->query('TRUNCATE catalog_index');
            $db->query('TRUNCATE category_facets');
            foreach ($cats as $c) {
                self::retry(static fn() => self::rebuildCategory($c, $cats));   // и скрытые (status=0): они открываются по прямому адресу
                if ($log) $log('категория ' . $c['id']);
            }
            self::retry(static fn() => self::rebuildSignatures());              // после фильтров: подпись = то, что в них учтено
            self::retry(static fn() => self::updateCounters());
            Cache::flush();
        }, $wait);
    }

    /**
     * Повтор шага перестройки, если InnoDB выбрал его жертвой взаимной блокировки (1213) или не дождался блокировки
     * строк (1205): так бывает, когда в это же время импорт или админка пишут те же товары. Шаги повторяемы
     * (удалить и вставить заново), а оборванная на середине полная перестройка оставила бы каталог неполным.
     * В открытой транзакции не повторяем — её откатил сервер целиком, решает вызывающий.
     */
    private static function retry(callable $fn, int $tries = 5): void
    {
        for ($i = 1; ; $i++) {
            try {
                $fn();
                return;
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if ($i >= $tries || ($code !== 1213 && $code !== 1205) || App::db()->pdo()->inTransaction()) throw $e;
                \App\Core\Log::info('Индекс каталога: повтор после ' . ($code === 1213 ? 'взаимной блокировки' : 'ожидания блокировки') . ' (попытка ' . ($i + 1) . ')');
                usleep(100000 * $i);
            }
        }
    }

    /**
     * Выполнить $fn под файловой блокировкой перестройки индекса (storage/cache/reindex.lock): две перестройки
     * целых категорий одновременно не идут — одна стёрла бы то, что успела записать другая, а одинаковые строки
     * фильтров и подписей мешали бы друг другу. Занято — ждём до $wait секунд (0 — не ждать); не дождались — false,
     * $fn не выполнялась. Внутри уже взятой блокировки (rebuildAll из другого участка под ней) — сразу.
     */
    public static function exclusive(callable $fn, float $wait = 600.0): bool
    {
        if (self::$locked) { $fn(); return true; }
        @mkdir(STORAGE . '/cache', 0775, true);
        $h = @fopen(STORAGE . '/cache/reindex.lock', 'c');
        if (!$h) { $fn(); return true; }                 // файл не открыть (права на storage) — как раньше, без блокировки
        $start = microtime(true);
        $until = $start + max(0.0, $wait);
        while (!flock($h, LOCK_EX | LOCK_NB, $busy)) {
            if (!$busy) break;                              // ФС без flock (бывает на NFS) — без блокировки, как при ошибке fopen
            if (microtime(true) >= $until) { fclose($h); return false; }
            usleep(200000);
        }
        self::$waited = microtime(true) - $start;
        self::$locked = true;
        try {
            $fn();
        } finally {
            self::$locked = false;
            flock($h, LOCK_UN);
            fclose($h);
        }
        return true;
    }

    /** Перестроить одну категорию */
    public static function rebuildCategory(array $c, ?array $all = null): void
    {
        $db = App::db();
        $id = (int) $c['id'];
        $db->query('DELETE FROM catalog_index WHERE category_id = ?', [$id]);
        $db->query('DELETE FROM category_facets WHERE category_id = ?', [$id]);

        if ((int) $c['type'] === 1) {
            [$where, $params] = self::conditionSql((string) $c['conditions']);
            if ($where === '') return;
            $db->query("INSERT IGNORE INTO catalog_index (category_id, product_id, in_stock, created_at, price, sort, name)
                SELECT ?, p.id, p.in_stock, p.created_at, p.price, 0, LEFT(p.name, 64) FROM products p
                WHERE p.status = 1 AND $where", array_merge([$id], $params));
        } else {
            $ids = [$id];
            if ((int) $c['include_sub']) {
                $all ??= $db->all('SELECT id, lft, rgt, status FROM categories');
                foreach ($all as $x) {
                    if ((int) $x['lft'] > (int) $c['lft'] && (int) $x['rgt'] < (int) $c['rgt']) $ids[] = (int) $x['id'];
                }
            }
            [$ph, $vals] = $db->in($ids);
            $db->query("INSERT IGNORE INTO catalog_index (category_id, product_id, in_stock, created_at, price, sort, name)
                SELECT ?, p.id, p.in_stock, p.created_at, p.price, MIN(cp.sort), LEFT(p.name, 64)
                FROM category_products cp JOIN products p ON p.id = cp.product_id AND p.status = 1
                WHERE cp.category_id IN ($ph) GROUP BY p.id", array_merge([$id], $vals));
        }
        self::rebuildFacets($id);
    }

    /**
     * Значения фильтров категории с количеством товаров. ON DUPLICATE KEY — если ту же категорию одновременно
     * перестраивает другой процесс (сохранение категории в админке во время bin/reindex.php), вставка не падает
     * на повторе ключа: остаётся счёт того, кто посчитал последним.
     */
    public static function rebuildFacets(int $categoryId): void
    {
        $db = App::db();
        $fids = self::filterIds();
        $db->query('DELETE FROM category_facets WHERE category_id = ?', [$categoryId]);
        if (!$fids) return;
        [$ph, $vals] = $db->in($fids);
        $db->query("INSERT INTO category_facets (category_id, feature_id, value_id, cnt)
            SELECT ci.category_id, pf.feature_id, pf.value_id, COUNT(*) FROM catalog_index ci
            JOIN product_features pf ON pf.product_id = ci.product_id AND pf.feature_id IN ($ph)
            WHERE ci.category_id = ? GROUP BY pf.feature_id, pf.value_id
            ON DUPLICATE KEY UPDATE cnt = VALUES(cnt)", array_merge($vals, [$categoryId]));
    }

    /**
     * Снимок товаров ДО изменения: значения фильтруемых характеристик и бренды — ['ids', 'pairs', 'brands', 'big'].
     * Новый товар — в ids с пустыми pairs/brands (до записи у него не было ни фильтров, ни бренда).
     */
    public static function snapshot(array $productIds): array
    {
        $ids = self::ids($productIds);
        if (!$ids || count($ids) > self::INCREMENTAL_MAX) return ['ids' => $ids, 'pairs' => [], 'brands' => [], 'big' => count($ids) > self::INCREMENTAL_MAX];
        [$ph, $vals] = App::db()->in($ids);
        return [
            'ids'    => $ids,
            'pairs'  => self::pairs($ids, self::filterIds()),
            'brands' => array_map('intval', App::db()->col("SELECT DISTINCT brand_id FROM products WHERE id IN ($ph) AND brand_id IS NOT NULL", $vals)),
            'big'    => false,
        ];
    }

    /**
     * Точечное обновление после изменения (создания, удаления) товаров. $before — CatalogIndexer::snapshot()
     * до изменения; $flush = false — не сбрасывать кэш (импорт сбросит один раз в конце).
     * Работает и внутри открытой транзакции (импорт пишет пачку и индекс одной транзакцией).
     */
    public static function products(array $productIds, ?array $before = null, bool $flush = true): void
    {
        $ids = self::ids($productIds);
        if (!$ids) return;
        if (count($ids) > self::INCREMENTAL_MAX) {
            self::rebuildAffected($ids);
        } elseif (App::db()->pdo()->inTransaction()) {
            self::productsNow($ids, $before);                           // транзакцию импорта при сбое откатит и повторит шаг
        } else {
            // сервер выбрал запись жертвой взаимной блокировки (идёт bin/reindex.php) — считаем заново и повторяем
            self::retry(static fn() => self::productsNow($ids, $before));
        }
        if ($flush) Cache::flush();
    }

    /** Точечное обновление (см. products): чтение текущего состояния и запись одной транзакцией */
    private static function productsNow(array $ids, ?array $before): void
    {
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        $fids = self::filterIds();
        $cats = $db->all('SELECT id, lft, rgt, type, conditions, include_sub FROM categories');

        // 1. было: категории, где товары сейчас в индексе (их фильтры уже учтены в category_facets)
        $old = [];
        foreach ($db->all("SELECT category_id, product_id FROM catalog_index WHERE product_id IN ($ph)", $vals) as $r) {
            $old[(int) $r['product_id']][] = (int) $r['category_id'];
        }

        // 2. стало: строки индекса по той же логике, что rebuildCategory, но только для этих товаров
        $prods = [];
        foreach ($db->all("SELECT id, status, in_stock, created_at, price, LEFT(name, 64) AS name FROM products WHERE id IN ($ph)", $vals) as $p) {
            $prods[(int) $p['id']] = $p;
        }
        $active = array_keys(array_filter($prods, static fn($p) => (int) $p['status'] === 1));
        $new = self::rowsFor($active, $cats);
        $newPairs = self::pairs($ids, $fids);

        // 3. что было учтено в фильтрах: снимок (или текущие значения), если совпал с подписью индексации
        $sigs = self::signatures($ids);
        $inSnap = $before !== null ? array_flip(array_map('intval', (array) ($before['ids'] ?? []))) : [];
        $oldPairs = []; $recount = [];
        foreach ($old as $pid => $cids) {
            $cand = isset($inSnap[$pid]) ? (array) ($before['pairs'][$pid] ?? []) : ($newPairs[$pid] ?? []);
            $ok = $sigs === null ? isset($inSnap[$pid]) : (isset($sigs[$pid]) && hash_equals($sigs[$pid], self::sig($cand)));
            if ($ok) $oldPairs[$pid] = $cand;
            else foreach ($cids as $cid) $recount[$cid] = 1;     // неизвестно, что учтено, — фильтры категории целиком
        }

        $write = static function () use ($db, $ph, $vals, $ids, $prods, $new, $old, $oldPairs, $newPairs, $recount, $sigs, $before, $inSnap): void {
            $db->query("DELETE FROM catalog_index WHERE product_id IN ($ph)", $vals);
            $rows = [];
            foreach ($new as [$cid, $pid, $sort]) {
                $p = $prods[$pid];
                $rows[] = ['category_id' => $cid, 'product_id' => $pid, 'in_stock' => (int) $p['in_stock'], 'created_at' => $p['created_at'],
                    'price' => $p['price'], 'sort' => $sort, 'name' => (string) $p['name']];
            }
            $db->insertMany('catalog_index', $rows, true, 500);

            // фильтры: разница «было − стало» по тройкам (категория, характеристика, значение)
            $delta = [];
            foreach ($old as $pid => $cids) {
                if (!isset($oldPairs[$pid])) continue;
                foreach ($cids as $cid) {
                    if (isset($recount[$cid])) continue;
                    foreach ($oldPairs[$pid] as [$f, $v]) { $k = $cid . ':' . $f . ':' . $v; $delta[$k] = ($delta[$k] ?? 0) - 1; }
                }
            }
            foreach ($new as [$cid, $pid]) {
                if (isset($recount[$cid])) continue;
                foreach ($newPairs[$pid] ?? [] as [$f, $v]) { $k = $cid . ':' . $f . ':' . $v; $delta[$k] = ($delta[$k] ?? 0) + 1; }
            }
            self::applyFacetDelta($delta);
            foreach (array_keys($recount) as $cid) self::rebuildFacets((int) $cid);

            // подпись: что теперь учтено в фильтрах
            if ($sigs !== null) {
                $sp = []; $sv = [];
                foreach (array_keys($prods) as $pid) { $sp[] = '(?, UNHEX(?))'; array_push($sv, $pid, self::sig($newPairs[$pid] ?? [])); }
                if ($sp) $db->query('INSERT INTO catalog_index_sig (product_id, sig) VALUES ' . implode(',', $sp) . ' ON DUPLICATE KEY UPDATE sig = VALUES(sig)', $sv);
                $gone = array_values(array_diff($ids, array_keys($prods)));
                if ($gone) { [$gph, $gv] = $db->in($gone); $db->query("DELETE FROM catalog_index_sig WHERE product_id IN ($gph)", $gv); }
            }

            // счётчики: категории, где товары были или стали; бренды — прежние (из снимка) и текущие
            $cids = [];
            foreach ($old as $list) foreach ($list as $cid) $cids[$cid] = 1;
            foreach ($new as [$cid]) $cids[$cid] = 1;
            self::countCategories(array_keys($cids));
            if ($before !== null && !array_diff($ids, array_keys($inSnap))) {
                $brands = array_merge(array_map('intval', (array) ($before['brands'] ?? [])),
                    array_map('intval', $db->col("SELECT DISTINCT brand_id FROM products WHERE id IN ($ph) AND brand_id IS NOT NULL", $vals)));
                self::countBrands(array_values(array_unique($brands)));
            } else {
                self::countBrands(null);                             // прежний бренд неизвестен — все (≈40 мс)
            }
        };
        $db->pdo()->inTransaction() ? $write() : $db->transaction($write);
    }

    public static function updateCounters(): void
    {
        $db = App::db();
        $db->query('UPDATE categories c LEFT JOIN (SELECT category_id, COUNT(*) n FROM catalog_index GROUP BY category_id) x
            ON x.category_id = c.id SET c.product_count = COALESCE(x.n, 0)');
        self::countBrands(null);
    }

    /** Подписи всех товаров (или перечисленных) по текущим характеристикам — после перестройки их категорий */
    public static function rebuildSignatures(?array $productIds = null): void
    {
        if (!self::hasSigTable()) return;
        $db = App::db();
        $fids = self::filterIds();
        [$fph, $fvals] = $db->in($fids ?: [0]);
        $db->query('SET SESSION group_concat_max_len = 1048576');
        $sql = "INSERT INTO catalog_index_sig (product_id, sig)
            SELECT p.id, UNHEX(MD5(COALESCE(GROUP_CONCAT(pf.feature_id, ':', pf.value_id ORDER BY pf.feature_id, pf.value_id SEPARATOR ','), '')))
            FROM products p LEFT JOIN product_features pf ON pf.product_id = p.id AND pf.feature_id IN ($fph)";
        $dup = ' ON DUPLICATE KEY UPDATE sig = VALUES(sig)';     // одновременная перестройка — без ошибки повтора ключа
        if ($productIds === null) {
            $db->query('TRUNCATE catalog_index_sig');
            $db->query($sql . ' GROUP BY p.id' . $dup, $fvals);
            return;
        }
        foreach (array_chunk(self::ids($productIds), 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            $db->query("DELETE FROM catalog_index_sig WHERE product_id IN ($ph)", $vals);
            $db->query($sql . " WHERE p.id IN ($ph) GROUP BY p.id" . $dup, array_merge($fvals, $vals));
        }
    }

    /**
     * Условия динамических категорий (формат Webasyst):
     *   compare_price>0 · price<500 · brand.value_id=40 · rating>=4 · create_datetime>=2024-01-01
     * Несколько условий через «&».
     */
    public static function conditionSql(string $cond): array
    {
        $where = []; $params = [];
        foreach (array_filter(array_map('trim', explode('&', $cond))) as $part) {
            if (!preg_match('/^([a-z_0-9.]+)\s*(>=|<=|!=|=|>|<)\s*(.+)$/i', $part, $m)) continue;
            [, $field, $op, $val] = $m;
            $val = trim($val);
            if ($field === 'compare_price' || $field === 'price' || $field === 'rating') {
                $where[] = "p.$field $op ?"; $params[] = (float) $val;
            } elseif ($field === 'create_datetime') {
                $where[] = "p.created_at $op ?"; $params[] = $val;
            } elseif (preg_match('/^([a-z_0-9]+)\.value_id$/i', $field, $fm)) {
                $ids = array_map('intval', explode(',', $val));
                if ($fm[1] === 'brand') {
                    [$ph, $v] = App::db()->in($ids);
                    $where[] = "p.brand_id IN ($ph)"; $params = array_merge($params, $v);
                } else {
                    $f = App::db()->value('SELECT id FROM features WHERE code = ?', [$fm[1]]);
                    if (!$f) continue;
                    [$ph, $v] = App::db()->in($ids);
                    $where[] = "EXISTS (SELECT 1 FROM product_features pf WHERE pf.product_id = p.id AND pf.feature_id = ? AND pf.value_id IN ($ph))";
                    $params = array_merge($params, [(int) $f], $v);
                }
            }
        }
        return [implode(' AND ', $where), $params];
    }

    // ============================================================ служебное

    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    }

    private static function filterIds(): array
    {
        return array_map('intval', App::db()->col('SELECT id FROM features WHERE is_filter = 1'));
    }

    /** Значения фильтруемых характеристик: [pid => [[fid, vid], …]] */
    private static function pairs(array $ids, array $fids): array
    {
        if (!$ids || !$fids) return [];
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        [$fph, $fvals] = $db->in($fids);
        $out = [];
        foreach ($db->all("SELECT product_id, feature_id, value_id FROM product_features WHERE product_id IN ($ph) AND feature_id IN ($fph)", array_merge($vals, $fvals)) as $r) {
            $out[(int) $r['product_id']][] = [(int) $r['feature_id'], (int) $r['value_id']];
        }
        return $out;
    }

    /** md5 пар «fid:vid» по возрастанию — так же, как GROUP_CONCAT в rebuildSignatures */
    private static function sig(array $pairs): string
    {
        usort($pairs, static fn($a, $b) => ((int) $a[0] <=> (int) $b[0]) ?: ((int) $a[1] <=> (int) $b[1]));
        return md5(implode(',', array_map(static fn($p) => (int) $p[0] . ':' . (int) $p[1], $pairs)));
    }

    /** Подписи товаров [pid => md5]; null — таблицы нет (миграция не применена) */
    private static function signatures(array $ids): ?array
    {
        if (!self::hasSigTable()) return null;
        [$ph, $vals] = App::db()->in($ids);
        $out = [];
        foreach (App::db()->pairs("SELECT product_id, LOWER(HEX(sig)) FROM catalog_index_sig WHERE product_id IN ($ph)", $vals) as $pid => $s) $out[(int) $pid] = (string) $s;
        return $out;
    }

    private static function hasSigTable(): bool
    {
        if (self::$sigTable === null) {
            try {
                App::db()->query('SELECT 1 FROM catalog_index_sig LIMIT 1');
                self::$sigTable = true;
            } catch (\PDOException) {
                self::$sigTable = false;
            }
        }
        return self::$sigTable;
    }

    /**
     * Строки индекса для активных товаров: ["cid:pid" => [cid, pid, sort]].
     * Обычная категория: товары своей категории и (include_sub) всех вложенных, sort — минимальный;
     * динамическая: условие категории, sort = 0. Скрытые категории — тоже (открываются по прямому адресу).
     */
    private static function rowsFor(array $active, array $cats): array
    {
        $new = [];
        if (!$active) return $new;
        $db = App::db();
        $into = [];                                   // категория товара → обычные категории, в индекс которых он попадает
        foreach ($cats as $d) {
            $list = (int) $d['type'] === 0 ? [(int) $d['id']] : [];
            foreach ($cats as $a) {
                if ((int) $a['type'] === 0 && (int) $a['include_sub'] && (int) $a['lft'] < (int) $d['lft'] && (int) $a['rgt'] > (int) $d['rgt']) $list[] = (int) $a['id'];
            }
            $into[(int) $d['id']] = $list;
        }
        [$ph, $vals] = $db->in($active);
        foreach ($db->all("SELECT category_id, product_id, sort FROM category_products WHERE product_id IN ($ph)", $vals) as $l) {
            $pid = (int) $l['product_id'];
            $sort = (int) $l['sort'];
            foreach ($into[(int) $l['category_id']] ?? [] as $cid) {
                $k = $cid . ':' . $pid;
                if (!isset($new[$k]) || $sort < $new[$k][2]) $new[$k] = [$cid, $pid, $sort];
            }
        }
        foreach ($cats as $c) {                       // динамические: условие проверяется только для этих товаров
            if ((int) $c['type'] !== 1) continue;
            [$where, $params] = self::conditionSql((string) $c['conditions']);
            if ($where === '') continue;
            $cid = (int) $c['id'];
            foreach ($db->col("SELECT p.id FROM products p WHERE p.id IN ($ph) AND p.status = 1 AND $where", array_merge($vals, $params)) as $pid) {
                $new[$cid . ':' . $pid] = [$cid, (int) $pid, 0];
            }
        }
        return $new;
    }

    /** Применить разницу к category_facets: [ "cid:fid:vid" => ±n ] */
    private static function applyFacetDelta(array $delta): void
    {
        $db = App::db();
        $plus = []; $minus = []; $cats = [];
        foreach ($delta as $k => $d) {
            if ($d === 0) continue;
            [$c, $f, $v] = array_map('intval', explode(':', (string) $k));
            if ($d > 0) $plus[] = [$c, $f, $v, $d];
            else { $minus[-$d][] = [$c, $f, $v]; $cats[$c] = 1; }
        }
        foreach (array_chunk($plus, 300) as $part) {
            $db->query('INSERT INTO category_facets (category_id, feature_id, value_id, cnt) VALUES ' . implode(',', array_fill(0, count($part), '(?,?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE cnt = cnt + VALUES(cnt)', array_merge(...$part));
        }
        foreach ($minus as $d => $triples) {
            foreach (array_chunk($triples, 200) as $part) {
                $w = implode(' OR ', array_fill(0, count($part), '(category_id = ? AND feature_id = ? AND value_id = ?)'));
                $db->query("UPDATE category_facets SET cnt = IF(cnt > ?, cnt - ?, 0) WHERE $w", array_merge([$d, $d], array_merge(...$part)));
            }
        }
        if ($cats) {
            [$ph, $vals] = $db->in(array_keys($cats));
            $db->query("DELETE FROM category_facets WHERE cnt = 0 AND category_id IN ($ph)", $vals);
        }
    }

    /*
     * Счётчики считаются обычным SELECT, а пишутся UPDATE … CASE только там, где число изменилось:
     * UPDATE с подзапросом внутри транзакции импорта блокировал бы все строки products / catalog_index
     * этих брендов и категорий до её конца (и шёл в 3–4 раза дольше).
     */

    /** categories.product_count перечисленных категорий */
    private static function countCategories(array $cids): void
    {
        $cids = self::ids($cids);
        if (!$cids) return;
        $db = App::db();
        [$ph, $vals] = $db->in($cids);
        $n = $db->pairs("SELECT category_id, COUNT(*) FROM catalog_index WHERE category_id IN ($ph) GROUP BY category_id", $vals);
        self::setCounts('categories', $db->pairs("SELECT id, product_count FROM categories WHERE id IN ($ph)", $vals), $n);
    }

    /** brands.product_count: перечисленные бренды или все (null) */
    private static function countBrands(?array $brandIds): void
    {
        $db = App::db();
        if ($brandIds === null) {
            $n = $db->pairs('SELECT brand_id, COUNT(*) FROM products WHERE status = 1 AND brand_id IS NOT NULL GROUP BY brand_id');
            self::setCounts('brands', $db->pairs('SELECT id, product_count FROM brands'), $n);
            return;
        }
        $brandIds = self::ids($brandIds);
        if (!$brandIds) return;
        [$ph, $vals] = $db->in($brandIds);
        $n = $db->pairs("SELECT brand_id, COUNT(*) FROM products WHERE brand_id IN ($ph) AND status = 1 GROUP BY brand_id", $vals);
        self::setCounts('brands', $db->pairs("SELECT id, product_count FROM brands WHERE id IN ($ph)", $vals), $n);
    }

    /** $table — categories | brands (из кода); $cur — [id => текущее значение], $n — [id => нужное] (нет — 0) */
    private static function setCounts(string $table, array $cur, array $n): void
    {
        $set = [];
        foreach ($cur as $id => $c) {
            $want = (int) ($n[$id] ?? 0);
            if ((int) $c !== $want) $set[(int) $id] = $want;
        }
        $db = App::db();
        foreach (array_chunk($set, 500, true) as $part) {
            $sql = 'UPDATE `' . $table . '` SET product_count = CASE id';
            $params = [];
            foreach ($part as $id => $v) { $sql .= ' WHEN ? THEN ?'; array_push($params, $id, $v); }
            [$ph, $vals] = $db->in(array_keys($part));
            $db->query($sql . " ELSE product_count END WHERE id IN ($ph)", array_merge($params, $vals));
        }
    }

    /**
     * Прежний путь для большого числа товаров: перестроить целиком категории, где товары есть или были
     * (прямые, их предки, где товары уже в индексе, и все динамические). Под блокировкой перестройки;
     * внутри открытой транзакции — без неё: транзакция держит таблицы индекса, а TRUNCATE в rebuildAll
     * ждал бы её конца — оба ждали бы друг друга. Не дождались блокировки — перестраиваем всё равно.
     */
    private static function rebuildAffected(array $ids): void
    {
        $run = static fn() => self::rebuildAffectedNow($ids);
        if (App::db()->pdo()->inTransaction() || !self::exclusive($run, 120)) $run();
    }

    private static function rebuildAffectedNow(array $ids): void
    {
        $db = App::db();
        $all = $db->all('SELECT id, parent_id, lft, rgt, type, conditions, include_sub, status FROM categories');
        $byId = array_column($all, null, 'id');
        $direct = []; $affected = [];
        foreach (array_chunk($ids, 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            foreach ($db->col("SELECT DISTINCT category_id FROM category_products WHERE product_id IN ($ph)", $vals) as $c) $direct[(int) $c] = 1;
            foreach ($db->col("SELECT DISTINCT category_id FROM catalog_index WHERE product_id IN ($ph)", $vals) as $c) $affected[(int) $c] = 1;
        }
        foreach (array_keys($direct) as $cid) {
            $affected[$cid] = 1;
            $x = $byId[$cid] ?? null;
            if (!$x) continue;
            foreach ($all as $a) {
                if ((int) $a['lft'] < (int) $x['lft'] && (int) $a['rgt'] > (int) $x['rgt']) $affected[(int) $a['id']] = 1;
            }
        }
        foreach ($all as $a) if ((int) $a['type'] === 1) $affected[(int) $a['id']] = 1;
        foreach (array_keys($affected) as $cid) {
            if (isset($byId[$cid])) self::retry(static fn() => self::rebuildCategory($byId[$cid], $all));
        }
        self::retry(static fn() => self::rebuildSignatures($ids));
        self::retry(static fn() => self::updateCounters());
    }
}
