<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;

/**
 * Строит catalog_index (категория → активные товары с учётом подкатегорий и динамических
 * категорий) и category_facets (значения фильтров по категориям).
 *
 * Полная перестройка (~100 тыс. товаров) — 4–5 с: bin/reindex.php
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
 *
 * Витрина не видит перестройку на середине. Полная перестройка и перестройка категории — одна транзакция
 * (atomic, READ COMMITTED): строки не стираются заранее, а сверяются — INSERT … ON DUPLICATE KEY UPDATE текущих
 * и DELETE выбывших; неизменные строки не переписываются (4,4 с против 7,5 с у прежних TRUNCATE + INSERT).
 * Витрина (обычные SELECT) до COMMIT читает прежний полный индекс, после — сразу новый (MVCC).
 * Теневые таблицы + RENAME TABLE отвергнуты: не быстрее (7,8 с), нужны права CREATE/DROP, а RENAME ждёт конца
 * всех запросов к таблице и на это время задерживает новые запросы витрины.
 *
 * Правки не теряются — блокировка записи индекса (locked: GET_LOCK на соединении MySQL; порядок взятия
 * «файловая блокировка → блокировка индекса → строки InnoDB»). Её держат перестройка (всю транзакцию), точечное
 * обновление (чтение состояния + запись) и импорт (всю транзакцию пачки). Сохранение товара во время
 * bin/reindex.php пишет товар сразу, а индекс — после COMMIT перестройки, считая разницу уже от нового индекса.
 * Чужие незавершённые записи в индекс (транзакция, отпустившая блокировку до COMMIT) перестройка пережидает
 * блокирующим чтением строк индекса в начале своей транзакции (barrier). Пересчёт фильтров категории целиком
 * снимает подписи товаров, чья переиндексация ещё впереди (dropStaleSigs), — иначе их учли бы дважды.
 */
final class CatalogIndexer
{
    /** Больше стольких товаров за раз — затронутые категории перестраиваются целиком (прежний путь) */
    public const INCREMENTAL_MAX = 2000;
    /** Сколько точечная запись внутри чужой транзакции ждёт блокировку индекса (меньше innodb_lock_wait_timeout = 50 с) */
    private const TX_WAIT = 20.0;

    private static ?bool $sigTable = null;

    /** Блокировка перестройки уже взята этим процессом (повторный вход — без ожидания) */
    private static bool $locked = false;
    /** Сколько секунд последний exclusive() ждал чужую перестройку (для вывода bin/reindex.php) */
    public static float $waited = 0.0;

    /** Глубина входа в locked(): блокировка записи индекса уже у этого соединения */
    private static int $held = 0;
    private static ?string $mutex = null;
    /** Можно ли перестройке READ COMMITTED (null — ещё не проверяли) */
    private static ?bool $rc = null;
    /** Идёт своя транзакция atomic() в READ COMMITTED */
    private static bool $rcTx = false;

    /** Колонки строки индекса, которые перестройка сверяет с товаром (ключ — category_id, product_id) */
    private const UPSERT = ' ON DUPLICATE KEY UPDATE in_stock = VALUES(in_stock), created_at = VALUES(created_at),
        price = VALUES(price), sort = VALUES(sort), name = VALUES(name)';

    /**
     * Полная перестройка индекса всех категорий — одной транзакцией (см. atomic): витрина до конца видит прежний индекс.
     * Идёт другая перестройка (bin/reindex.php, кнопка в «Состоянии системы», импорт) — ждёт её до $wait секунд;
     * не дождалась — false, ничего не сделано.
     */
    public static function rebuildAll(?callable $log = null, float $wait = 600.0): bool
    {
        return self::exclusive(static function () use ($log): void {
            self::atomic(static function () use ($log): void {
                $db = App::db();
                self::barrier();
                $cats = $db->all('SELECT id, parent_id, lft, rgt, type, conditions, include_sub, status FROM categories ORDER BY lft');
                // строки удалённых категорий (прежний TRUNCATE убирал их вместе со всеми)
                if ($cats) {
                    [$ph, $vals] = $db->in(array_map(static fn($c) => (int) $c['id'], $cats));
                    $db->query("DELETE FROM catalog_index WHERE category_id NOT IN ($ph)", $vals);
                    $db->query("DELETE FROM category_facets WHERE category_id NOT IN ($ph)", $vals);
                } else {
                    $db->query('DELETE FROM catalog_index');
                    $db->query('DELETE FROM category_facets');
                }
                foreach ($cats as $c) {
                    self::fillCategory($c, $cats);                             // и скрытые (status=0): они открываются по прямому адресу
                    if ($log) $log('категория ' . $c['id']);
                }
                self::fillSignatures(null);                                  // после фильтров: подпись = то, что в них учтено
                self::updateCounters();
            });
            Cache::flush();
        }, $wait);
    }

    /**
     * $fn одной транзакцией под блокировкой записи индекса (locked). READ COMMITTED: INSERT … SELECT читает товары
     * без блокировок строк (при REPEATABLE READ сохранение товара и оформление заказа ждали бы конца перестройки).
     * Внутри уже открытой транзакции (импорт) — в ней же. Взаимная блокировка или ожидание блокировки строк
     * (1213, 1205) — транзакцию откатил сервер, повтор целиком; блокировка индекса на паузе отпускается, чтобы
     * точечная запись, которую ждали, закончилась.
     */
    private static function atomic(callable $fn, int $tries = 3): void
    {
        $db = App::db();
        if ($db->pdo()->inTransaction()) {
            self::locked($fn);
            return;
        }
        for ($i = 1; ; $i++) {
            try {
                self::locked(static function () use ($db, $fn): void {
                    self::$rcTx = self::readCommitted();
                    try {
                        $db->transaction(static function () use ($fn): void { $fn(); });
                    } finally {
                        self::$rcTx = false;
                    }
                });
                return;
            } catch (\PDOException $e) {
                if ($i >= $tries || !self::lockError($e)) throw $e;
                \App\Core\Log::info('Индекс каталога: повтор транзакции после ' . (self::code($e) === 1213 ? 'взаимной блокировки' : 'ожидания блокировки') . ' (попытка ' . ($i + 1) . ')');
                usleep(200000 * $i);
            }
        }
    }

    /**
     * Выполнить $fn под блокировкой записи индекса: GET_LOCK на соединении MySQL (имя — с базой: сервер на хостинге
     * общий). Повторный вход — без ожидания. Занята дольше $wait секунд: $force — выполнить и без неё (с записью
     * в журнал; ночная перестройка выровняет), иначе false и $fn не выполнялась. GET_LOCK недоступен — без блокировки.
     * Порядок: сначала эта блокировка, потом транзакция — внутри транзакции её ждать нельзя (держали бы строки,
     * нужные перестройке).
     */
    public static function locked(callable $fn, float $wait = 120.0, bool $force = true): bool
    {
        if (self::$held > 0) {
            self::$held++;
            try { $fn(); } finally { self::$held--; }
            return true;
        }
        $db = App::db();
        try {
            $got = $db->value('SELECT GET_LOCK(?, ?)', [self::mutexName(), max(0, (int) ceil($wait))]);
        } catch (\PDOException) {
            $got = null;
        }
        if ($got !== null && (int) $got !== 1) {
            if (!$force) return false;
            \App\Core\Log::info('Индекс каталога: блокировка записи занята дольше ' . $wait . ' с — запись без неё');
        }
        self::$held++;
        try {
            $fn();
        } finally {
            self::$held--;
            if ((int) $got === 1) {
                try { $db->value('SELECT RELEASE_LOCK(?)', [self::mutexName()]); } catch (\PDOException) {}
            }
        }
        return true;
    }

    private static function mutexName(): string
    {
        return self::$mutex ??= 'tomobuv_ci_' . substr(md5((string) App::db()->value('SELECT DATABASE()')), 0, 16);
    }

    /** Следующая транзакция — READ COMMITTED, если позволяет binlog (STATEMENT запрещает INSERT … SELECT при нём, 1665) */
    private static function readCommitted(): bool
    {
        $db = App::db();
        if (self::$rc === null) {
            try {
                $r = $db->row('SELECT @@log_bin AS b, @@binlog_format AS f');
                self::$rc = !($r && (int) $r['b'] === 1 && strtoupper((string) $r['f']) === 'STATEMENT');
            } catch (\PDOException) {
                self::$rc = false;
            }
        }
        if (self::$rc) $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return self::$rc;
    }

    /**
     * Суффикс проверочного SELECT рядом с INSERT … SELECT. В своей транзакции READ COMMITTED оба читают свежие данные
     * без блокировок — ''. Иначе (REPEATABLE READ: транзакция импорта, точечная запись, binlog STATEMENT) INSERT … SELECT
     * читает последние данные с блокировкой, а обычный SELECT — старый снимок транзакции; чтобы они видели одно и то же,
     * SELECT тоже блокирующий.
     */
    private static function readLock(): string
    {
        return self::$rcTx ? '' : ' LOCK IN SHARE MODE';
    }

    /**
     * Дождаться чужих незавершённых записей в индекс, прежде чем читать товары: блокирующее чтение строк индекса
     * (всех или одной категории) ждёт COMMIT транзакций, которые их меняли, и держит строки до конца своей.
     * Иначе запрос перестройки, начатый до чужого COMMIT, записал бы поверх него прежнее состояние товара.
     * ~0,3 с на весь индекс (243 тыс. строк), ~0,05 с на «Женскую обувь».
     */
    private static function barrier(?int $categoryId = null): void
    {
        $db = App::db();
        if ($categoryId !== null) {
            $db->value('SELECT COUNT(*) FROM catalog_index WHERE category_id = ? FOR UPDATE', [$categoryId]);
            $db->value('SELECT COUNT(*) FROM category_facets WHERE category_id = ? FOR UPDATE', [$categoryId]);
            return;
        }
        $db->value('SELECT COUNT(*) FROM catalog_index FOR UPDATE');
        $db->value('SELECT COUNT(*) FROM category_facets FOR UPDATE');
        if (self::hasSigTable()) $db->value('SELECT COUNT(*) FROM catalog_index_sig FOR UPDATE');
    }

    private static function code(\PDOException $e): int
    {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    /** Взаимная блокировка (1213) или не дождались блокировки строк (1205) — шаг можно повторить */
    public static function lockError(\Throwable $e): bool
    {
        return $e instanceof \PDOException && in_array(self::code($e), [1213, 1205], true);
    }

    /**
     * Повтор точечной записи или пересчёта счётчиков, если InnoDB выбрал их жертвой взаимной блокировки (1213) или
     * не дождался блокировки строк (1205): так бывает, когда в это же время импорт или админка пишут те же строки.
     * Шаги повторяемы (считаются заново от текущего состояния). В открытой транзакции не повторяем — её откатил
     * сервер целиком, решает вызывающий. Транзакции перестройки повторяет atomic().
     */
    private static function retry(callable $fn, int $tries = 5): void
    {
        for ($i = 1; ; $i++) {
            try {
                $fn();
                return;
            } catch (\PDOException $e) {
                $code = self::code($e);
                if ($i >= $tries || !self::lockError($e) || App::db()->pdo()->inTransaction()) throw $e;
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

    /**
     * Перестроить одну категорию — одной транзакцией (витрина видит прежний список до COMMIT, потом новый):
     * «Завершение» импорта, AdminCatalog::reindexCategories после правки категории.
     */
    public static function rebuildCategory(array $c, ?array $all = null): void
    {
        self::atomic(static function () use ($c, $all): void {
            self::barrier((int) $c['id']);
            self::fillCategory($c, $all);
            self::dropStaleSigs((int) $c['id']);
        });
    }

    /**
     * Строки индекса и фильтры категории по текущим товарам (без своей транзакции). Строки не стираются заранее:
     * INSERT … ON DUPLICATE KEY UPDATE обновляет изменившиеся (неизменные не переписываются), DELETE убирает
     * выбывшие — результат тот же, что у «удалить всё и вставить», но короче и без пустого окна.
     */
    private static function fillCategory(array $c, ?array $all): void
    {
        $db = App::db();
        $id = (int) $c['id'];
        if ((int) $c['type'] === 1) {
            [$where, $params] = self::conditionSql((string) $c['conditions']);
            if ($where === '') {                                         // условия нет — категория пуста
                $db->query('DELETE FROM catalog_index WHERE category_id = ?', [$id]);
                $db->query('DELETE FROM category_facets WHERE category_id = ?', [$id]);
                return;
            }
            $db->query("INSERT INTO catalog_index (category_id, product_id, in_stock, created_at, price, sort, name)
                SELECT ?, p.id, p.in_stock, p.created_at, p.price, 0, LEFT(p.name, 64) FROM products p
                WHERE p.status = 1 AND $where" . self::UPSERT, array_merge([$id], $params));
            // выбывшие: товар скрыт, удалён или больше не подходит под условие
            $gone = $db->col("SELECT ci.product_id FROM catalog_index ci LEFT JOIN products p ON p.id = ci.product_id AND p.status = 1 AND $where
                WHERE ci.category_id = ? AND p.id IS NULL" . self::readLock(), array_merge($params, [$id]));
        } else {
            $ids = [$id];
            if ((int) $c['include_sub']) {
                $all ??= $db->all('SELECT id, lft, rgt, status FROM categories');
                foreach ($all as $x) {
                    if ((int) $x['lft'] > (int) $c['lft'] && (int) $x['rgt'] < (int) $c['rgt']) $ids[] = (int) $x['id'];
                }
            }
            [$ph, $vals] = $db->in($ids);
            $db->query("INSERT INTO catalog_index (category_id, product_id, in_stock, created_at, price, sort, name)
                SELECT ?, p.id, p.in_stock, p.created_at, p.price, MIN(cp.sort), LEFT(p.name, 64)
                FROM category_products cp JOIN products p ON p.id = cp.product_id AND p.status = 1
                WHERE cp.category_id IN ($ph) GROUP BY p.id" . self::UPSERT, array_merge([$id], $vals));
            // выбывшие: товар скрыт, удалён или отвязан от категории и её подкатегорий
            $gone = $db->col("SELECT ci.product_id FROM catalog_index ci
                LEFT JOIN category_products cp ON cp.product_id = ci.product_id AND cp.category_id IN ($ph)
                LEFT JOIN products p ON p.id = cp.product_id AND p.status = 1
                WHERE ci.category_id = ? GROUP BY ci.product_id HAVING COUNT(p.id) = 0" . self::readLock(), array_merge($vals, [$id]));
        }
        // отдельным SELECT, а не DELETE … JOIN: тот читал бы products с блокировкой строк (S) до конца транзакции —
        // сохранение товара ждало бы конца перестройки, а бывало и взаимной блокировкой
        foreach (array_chunk($gone, 1000) as $part) {
            [$gph, $gvals] = $db->in($part);
            $db->query("DELETE FROM catalog_index WHERE category_id = ? AND product_id IN ($gph)", array_merge([$id], $gvals));
        }
        self::fillFacets($id);
    }

    /**
     * После пересчёта фильтров категории целиком: товары категории, чья подпись не совпадает с текущими
     * характеристиками, теряют подпись. Это товары, характеристики которых уже записаны, а точечная переиндексация
     * ещё впереди (ждёт блокировку индекса): пересчёт уже учёл их новые значения, и products() по снимку вычел бы
     * прежние и прибавил новые второй раз. Без подписи products() пересчитает фильтры их категорий целиком — точно.
     * Обычно таких товаров нет — ничего не удаляется (~0,25 с на «Женскую обувь», 57 тыс. товаров).
     */
    private static function dropStaleSigs(int $categoryId): void
    {
        if (!self::hasSigTable()) return;
        $db = App::db();
        [$fph, $fvals] = $db->in(self::filterIds() ?: [0]);
        $db->query('SET SESSION group_concat_max_len = 1048576');
        $stale = $db->col("SELECT ci.product_id FROM catalog_index ci
            LEFT JOIN product_features pf ON pf.product_id = ci.product_id AND pf.feature_id IN ($fph)
            JOIN catalog_index_sig s ON s.product_id = ci.product_id
            WHERE ci.category_id = ? GROUP BY ci.product_id, s.sig
            HAVING s.sig <> UNHEX(MD5(COALESCE(GROUP_CONCAT(pf.feature_id, ':', pf.value_id ORDER BY pf.feature_id, pf.value_id SEPARATOR ','), '')))"
            . self::readLock(), array_merge($fvals, [$categoryId]));
        foreach (array_chunk($stale, 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            $db->query("DELETE FROM catalog_index_sig WHERE product_id IN ($ph)", $vals);
        }
    }

    /**
     * Значения фильтров категории с количеством товаров — одной транзакцией (без пустого окна между DELETE и INSERT).
     * ON DUPLICATE KEY — на случай записи без блокировки индекса (GET_LOCK недоступен): остаётся счёт посчитавшего последним.
     */
    public static function rebuildFacets(int $categoryId): void
    {
        self::atomic(static function () use ($categoryId): void {
            self::fillFacets($categoryId);
            self::dropStaleSigs($categoryId);
        });
    }

    private static function fillFacets(int $categoryId): void
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
     * Под блокировкой записи индекса: идёт перестройка — ждём её конца и считаем от нового индекса.
     * Работает и внутри открытой транзакции (импорт пишет пачку и индекс одной транзакцией; блокировку индекса
     * импорт берёт до транзакции — Importer). Внутри транзакции без неё: ждём до TX_WAIT секунд, не дождались —
     * исключение «ожидание блокировки» (1205): транзакцию откатит и повторит вызывающий (шаг импорта).
     */
    public static function products(array $productIds, ?array $before = null, bool $flush = true): void
    {
        $ids = self::ids($productIds);
        if (!$ids) return;
        if (count($ids) > self::INCREMENTAL_MAX) {
            self::rebuildAffected($ids);
        } elseif (App::db()->pdo()->inTransaction()) {
            if (!self::locked(static fn() => self::productsNow($ids, $before), self::TX_WAIT, false)) {
                $e = new \PDOException('Индекс каталога: блокировка записи занята дольше ' . self::TX_WAIT . ' с (идёт перестройка)');
                $e->errorInfo = ['HY000', 1205, $e->getMessage()];
                throw $e;
            }
        } else {
            // сервер выбрал запись жертвой взаимной блокировки — считаем заново и повторяем
            self::retry(static fn() => self::locked(static fn() => self::productsNow($ids, $before)));
        }
        if ($flush) Cache::flush();
    }

    /**
     * Точечное обновление (см. products): чтение текущего состояния и запись одной транзакцией. Внутри чужой
     * транзакции индекс и подписи читаются блокирующим чтением: снимок REPEATABLE READ мог быть взят до COMMIT
     * перестройки, а разница «было − стало» должна считаться от того, что сейчас в таблицах.
     */
    private static function productsNow(array $ids, ?array $before): void
    {
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        $fids = self::filterIds();
        $cats = $db->all('SELECT id, lft, rgt, type, conditions, include_sub FROM categories');
        $lock = $db->pdo()->inTransaction() ? ' LOCK IN SHARE MODE' : '';

        // 1. было: категории, где товары сейчас в индексе (их фильтры уже учтены в category_facets)
        $old = [];
        foreach ($db->all("SELECT category_id, product_id FROM catalog_index WHERE product_id IN ($ph)" . $lock, $vals) as $r) {
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
        $sigs = self::signatures($ids, $lock);
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
            foreach (array_keys($recount) as $cid) {
                self::fillFacets((int) $cid);
                self::dropStaleSigs((int) $cid);                       // чужие товары, ждущие своей переиндексации
            }

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

    /**
     * Подписи всех товаров (или перечисленных) по текущим характеристикам — после перестройки их категорий.
     * Одной транзакцией, без TRUNCATE (он бы закрыл транзакцию импорта и оставил таблицу пустой до вставки).
     */
    public static function rebuildSignatures(?array $productIds = null): void
    {
        if (!self::hasSigTable()) return;
        self::atomic(static fn() => self::fillSignatures($productIds));
    }

    /** Подписи (см. rebuildSignatures) без своей транзакции: изменившиеся — ON DUPLICATE KEY, удалённых товаров — DELETE */
    private static function fillSignatures(?array $productIds): void
    {
        if (!self::hasSigTable()) return;
        $db = App::db();
        $fids = self::filterIds();
        [$fph, $fvals] = $db->in($fids ?: [0]);
        $db->query('SET SESSION group_concat_max_len = 1048576');
        $sql = "INSERT INTO catalog_index_sig (product_id, sig)
            SELECT p.id, UNHEX(MD5(COALESCE(GROUP_CONCAT(pf.feature_id, ':', pf.value_id ORDER BY pf.feature_id, pf.value_id SEPARATOR ','), '')))
            FROM products p LEFT JOIN product_features pf ON pf.product_id = p.id AND pf.feature_id IN ($fph)";
        $dup = ' ON DUPLICATE KEY UPDATE sig = VALUES(sig)';
        $gone = 'DELETE s FROM catalog_index_sig s LEFT JOIN products p ON p.id = s.product_id WHERE p.id IS NULL';
        if ($productIds === null) {
            $db->query($sql . ' GROUP BY p.id' . $dup, $fvals);
            $db->query($gone);
            return;
        }
        foreach (array_chunk(self::ids($productIds), 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            $db->query($sql . " WHERE p.id IN ($ph) GROUP BY p.id" . $dup, array_merge($fvals, $vals));
            $db->query($gone . " AND s.product_id IN ($ph)", $vals);
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

    /** Есть ли динамическая категория с условием по полю $field (rating, price, brand.value_id…) — разбор как в conditionSql */
    public static function conditionUses(string $field): bool
    {
        $like = '%' . addcslashes($field, '%_\\') . '%';
        foreach (App::db()->col('SELECT conditions FROM categories WHERE type = 1 AND conditions LIKE ?', [$like]) as $cond) {
            foreach (explode('&', (string) $cond) as $part) {
                if (preg_match('/^([a-z_0-9.]+)\s*(>=|<=|!=|=|>|<)\s*(.+)$/i', trim($part), $m) && $m[1] === $field) return true;
            }
        }
        return false;
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

    /** Подписи товаров [pid => md5]; null — таблицы нет (миграция не применена). $lock — ' LOCK IN SHARE MODE' или '' */
    private static function signatures(array $ids, string $lock = ''): ?array
    {
        if (!self::hasSigTable()) return null;
        [$ph, $vals] = App::db()->in($ids);
        $out = [];
        foreach (App::db()->pairs("SELECT product_id, LOWER(HEX(sig)) FROM catalog_index_sig WHERE product_id IN ($ph)" . $lock, $vals) as $pid => $s) $out[(int) $pid] = (string) $s;
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
     * внутри открытой транзакции — без неё: транзакция держит строки индекса, а rebuildAll под файловой
     * блокировкой ждал бы их (barrier) — оба ждали бы друг друга. Не дождались блокировки — перестраиваем всё равно.
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
            if (isset($byId[$cid])) self::rebuildCategory($byId[$cid], $all);        // сама повторяет при взаимной блокировке
        }
        self::rebuildSignatures($ids);
        self::retry(static fn() => self::updateCounters());
    }
}
