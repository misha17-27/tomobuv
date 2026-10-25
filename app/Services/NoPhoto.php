<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Core\Settings;

/**
 * Товары без фото на сайте не показываются (решение владельца 25.10.2026). Фото у товара есть, если есть файл (оригинал или
 * готовая миниатюра) главного фото или любого другого его фото — тогда другое становится главным. Нет ни одного (не перенесены
 * со старого сайта, фото бренда закрыто robots.txt) — товар скрывается: status = 0, nophoto_hidden = 1, nophoto_at — когда.
 * Фото появилось (загрузчик добавил, сотрудник загрузил) — товар снова на сайте. Скрытый так товар, который показали вручную,
 * при следующей проверке скрывается опять, пока у него нет фото; скрытые вручную без этой отметки не трогаются.
 * bin/cron.php — sync() раз в час; история товара — «Скрыт: нет фото» / «Показан: фото появилось».
 *
 * Удалить скрытые без фото — purge(), кнопка «Удалить их» в «Каталог → Товары» (решение владельца 25.10.2026; удаление
 * необратимо, поэтому только кнопкой, не по расписанию). Удаляется не больше показанного в подтверждении: purgeable() считает
 * на момент at (время базы), кнопка передаёт at и число; условия — от at, поэтому набор к нажатию может только уменьшиться
 * (стал больше — «обновите страницу»): скрытые проверкой не меньше PURGE_AFTER до at (видны в «Скрытые: нет фото»; сама кнопка
 * ничего не скрывает), созданные больше суток до at, без фото в очереди импорта; перед удалением каждой порции — ещё раз: нет
 * ни одного файла фото (под guard(): загрузка фото в админке ждёт, AdminCatalog::storeProductImage).
 * Старые адреса — в карту LegacyProducts (301 на ту же модель, когда она появится с фото, иначе на категорию); в «удалённые
 * вручную» поставщика (supplier_excluded) не заносятся — модель с фото загрузчик добавит снова. Связь, которую шаг загрузчика
 * успел записать к уже удалённому товару, Supplier::cleanup тоже не заносит в supplier_excluded: товар есть в карте старых адресов.
 */
final class NoPhoto
{
    /** Скрыть за раз больше этой доли товаров на сайте (и больше MASS_MIN) — только с $force: скорее пропала папка фото, чем сами фото */
    private const MASS_SHARE = 0.5;
    private const MASS_MIN = 200;
    /** purge: товаров за одно нажатие — не больше CatalogIndexer::INCREMENTAL_MAX (иначе полная перестройка затронутого) */
    public const PURGE_MAX = 2000;
    /** purge: скрыт проверкой не меньше этого назад (минут) — виден в списке «Скрытые: нет фото» до удаления */
    public const PURGE_AFTER = 60;
    /** Порция purge: проверка фото и удаление — подряд, под guard() */
    private const PURGE_CHUNK = 200;
    /** Условия purge (и purgeable) от момента ? (дважды) — алиас p */
    private const PURGE_WHERE = 'p.nophoto_hidden = 1 AND p.status = 0 AND p.nophoto_at < DATE_SUB(?, INTERVAL ' . self::PURGE_AFTER . ' MINUTE)'
        . ' AND p.created_at < DATE_SUB(?, INTERVAL 1 DAY)'
        . ' AND NOT EXISTS (SELECT 1 FROM import_images ii WHERE ii.product_id = p.id AND ii.status = 0)';

    /**
     * $force — скрыть, сколько бы ни было (кнопка в списке товаров), $by — кто нажал (в историю товара; иначе «по расписанию»).
     * Идёт другая проверка (cron и кнопка вместе) — ничего не делает, busy = true.
     * @return array{hidden: int, shown: int, fixed: int, held: int, busy: bool} held — сколько не скрыто из-за MASS_SHARE
     */
    public static function sync(bool $force = false, ?int $by = null): array
    {
        $out = ['hidden' => 0, 'shown' => 0, 'fixed' => 0, 'held' => 0, 'busy' => true];
        self::locked(static function () use (&$out, $force, $by): void { $out = self::syncNow($force, $by); });
        return $out;
    }

    /**
     * Удалить товары, скрытые проверкой из-за отсутствия фото (условия — в описании класса) на момент $at, которых тогда было
     * $n (purgeable() — число в подтверждении); за раз не больше PURGE_MAX (left — сколько ещё не проверено). Сейчас подходящих
     * больше $n или $at некорректно — ничего не удаляется, stale. busy — идёт проверка или другое удаление.
     * @return array{deleted: int, ids: int[], left: int, busy: bool, stale: bool} ids — удалённые
     */
    public static function purge(string $at, int $n): array
    {
        $out = ['deleted' => 0, 'ids' => [], 'left' => 0, 'busy' => true, 'stale' => false];
        self::locked(static function () use (&$out, $at, $n): void {
            $db = App::db();
            $out['busy'] = false;
            if (!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at) || (string) $db->value('SELECT NOW()') < $at) { $out['stale'] = true; return; }
            $cand = array_map('intval', $db->col('SELECT p.id FROM products p WHERE ' . self::PURGE_WHERE . ' ORDER BY p.id', [$at, $at]));
            if (count($cand) > $n) { $out['stale'] = true; return; }
            $done = 0;
            foreach (array_chunk($cand, self::PURGE_CHUNK) as $part) {
                if (count($out['ids']) >= self::PURGE_MAX) break;
                $done += count($part);
                // порция: условия ещё раз и нет ни одного файла фото — и сразу удаление (загрузка фото в админке тем временем ждёт)
                self::guard(static function () use ($db, $part, $at, &$out): void {
                    [$ph, $v] = $db->in($part);
                    $ids = array_values(array_filter(array_map('intval', $db->col('SELECT p.id FROM products p WHERE p.id IN (' . $ph . ') AND ' . self::PURGE_WHERE,
                        array_merge($v, [$at, $at]))), static fn(int $pid): bool => !self::anyPhoto($pid)));   // фото появилось — покажет проверка
                    $ids = array_slice($ids, 0, self::PURGE_MAX - count($out['ids']));
                    if (!$ids) return;
                    LegacyProducts::capture($ids);                      // старые адреса → 301 (та же модель с фото или категория)
                    AdminCatalog::deleteProducts($ids, false, false);
                    $out['ids'] = array_merge($out['ids'], $ids);
                }, 60);
            }
            $out['deleted'] = count($out['ids']);
            if ($out['ids']) Cache::flush();                               // один раз за нажатие, а не на каждую порцию
            $out['left'] = count($cand) - $done;
        });
        return $out;
    }

    /**
     * Сколько удалит purge на момент at (время базы) — число и at для предупреждения и подтверждения в списке товаров (кнопка
     * передаёт их в purge); без проверки файлов — её делает purge. @return array{n: int, at: string}
     */
    public static function purgeable(): array
    {
        return Cache::remember('admin.nophoto.purgeable', 120, static function (): array {
            $db = App::db();
            $at = (string) $db->value('SELECT NOW()');
            return ['n' => (int) $db->value('SELECT COUNT(*) FROM products p WHERE ' . self::PURGE_WHERE, [$at, $at]), 'at' => $at];
        });
    }

    /**
     * $fn под блокировкой удаления товаров без фото (GET_LOCK, ждём до $wait с): purge удаляет порцию, AdminCatalog::storeProductImage
     * сохраняет фото — не одновременно. Не дождались — false; GET_LOCK недоступен — без блокировки.
     */
    public static function guard(callable $fn, int $wait = 30): bool
    {
        $db = App::db();
        $name = 'tomobuv_nophoto_del_' . substr(md5((string) $db->value('SELECT DATABASE()')), 0, 16);
        try {
            $got = $db->value('SELECT GET_LOCK(?, ?)', [$name, $wait]);
        } catch (\PDOException) {
            $got = null;
        }
        if ($got !== null && (int) $got !== 1) return false;
        try {
            $fn();
        } finally {
            if ((int) $got === 1) {
                try { $db->value('SELECT RELEASE_LOCK(?)', [$name]); } catch (\PDOException) {}
            }
        }
        return true;
    }

    /** Скрыто из-за отсутствия фото (всего, вместе с теми, что удалить пока нельзя) */
    public static function hiddenCount(): int
    {
        return (int) Cache::remember('admin.nophoto.count', 120, static fn() => (int) App::db()->value('SELECT COUNT(*) FROM products WHERE nophoto_hidden = 1 AND status = 0'));
    }

    /**
     * Фото есть на сайте: оригинал на диске или хоть одна готовая миниатюра (Image::url показывает её и без оригинала —
     * так бывает у перенесённых со старого сайта). Для выбора главного фото (AdminCatalog::syncMainImage) — то же правило.
     */
    public static function has(int $pid, int $iid, string $ext): bool
    {
        $ext = $ext ?: 'jpg';
        if (is_file(Image::originalPath($pid, $iid, $ext))) return true;
        return (bool) glob(dirname(Image::thumbPath($pid, $iid, (string) $iid, '400', $ext)) . '/*.' . $ext, GLOB_NOSORT);
    }

    private static function syncNow(bool $force, ?int $by): array
    {
        $db = App::db();
        $out = ['hidden' => 0, 'shown' => 0, 'fixed' => 0, 'held' => 0, 'busy' => false];
        $hide = []; $show = []; $noMain = []; $visible = 0;
        foreach ($db->all('SELECT id, image_id, image_ext, status, nophoto_hidden FROM products WHERE status = 1 OR nophoto_hidden = 1') as $r) {
            $pid = (int) $r['id'];
            if ((int) $r['status'] === 1) $visible++;
            if ($r['image_id'] && self::has($pid, (int) $r['image_id'], (string) $r['image_ext'])) {
                if ((int) $r['nophoto_hidden'] === 1) $show[$pid] = (int) $r['status'];
            } else {
                $noMain[$pid] = $r;                                      // на сайте или скрыт проверкой — ищем другое фото
            }
        }
        // главного фото нет, но есть другое с файлом — оно становится главным: на сайте — не скрывается, скрытый проверкой — показывается
        foreach (array_chunk(array_keys($noMain), 1000) as $part) {
            [$ph, $v] = $db->in($part);
            $found = [];
            foreach ($db->all("SELECT id, product_id, ext FROM product_images WHERE product_id IN ($ph) ORDER BY sort, id", $v) as $img) {
                $pid = (int) $img['product_id'];
                if (isset($found[$pid])) continue;
                if (self::has($pid, (int) $img['id'], (string) $img['ext'])) $found[$pid] = $img;
            }
            foreach ($found as $pid => $img) {
                $db->query('UPDATE products SET image_id = ?, image_ext = ? WHERE id = ?', [(int) $img['id'], (string) ($img['ext'] ?: 'jpg'), $pid]);
                $out['fixed']++;
                if ((int) $noMain[$pid]['nophoto_hidden'] === 1) $show[$pid] = (int) $noMain[$pid]['status'];
            }
            foreach ($part as $pid) if (!isset($found[$pid]) && (int) $noMain[$pid]['status'] === 1) $hide[] = $pid;
        }
        if (!$force && count($hide) > self::MASS_MIN && count($hide) > $visible * self::MASS_SHARE) {
            \App\Core\Log::error('NoPhoto: без фото ' . count($hide) . ' из ' . $visible . ' товаров на сайте — не скрываю (проверьте папку public/wa-data; '
                . 'если это правда — «Скрыть их» в «Каталог → Товары»)');
            $out['held'] = count($hide);
            $hide = [];
        }
        if ((int) Settings::get('nophoto.held', '0') !== $out['held']) Settings::set('nophoto.held', (string) $out['held']);   // предупреждение в списке товаров
        $changed = array_merge($hide, array_keys($show));
        if (!$changed) {
            if ($out['fixed']) Cache::flush();
            return $out;
        }
        $snap = CatalogIndexer::snapshot($changed);
        $now = date('Y-m-d H:i:s');
        $log = [];
        foreach (array_chunk($hide, 1000) as $part) {
            [$ph, $v] = $db->in($part);
            $db->query("UPDATE products SET status = 0, nophoto_hidden = 1, nophoto_at = ?, updated_at = ? WHERE id IN ($ph) AND status = 1", array_merge([$now, $now], $v));
            foreach ($part as $pid) $log[] = [$pid, 'product_nophoto', ['op' => 'hide', 'by' => $by, 'ch' => [['status', ProductHistory::show('status', 1), ProductHistory::show('status', 0)]]]];
        }
        $out['hidden'] = count($hide);
        foreach (array_chunk(array_keys($show), 1000) as $part) {
            [$ph, $v] = $db->in($part);
            $db->query("UPDATE products SET status = 1, nophoto_hidden = 0, nophoto_at = NULL, updated_at = ? WHERE id IN ($ph)", array_merge([$now], $v));
            foreach ($part as $pid) if ($show[$pid] === 0) $log[] = [$pid, 'product_nophoto', ['op' => 'show', 'ch' => [['status', ProductHistory::show('status', 0), ProductHistory::show('status', 1)]]]];
        }
        $out['shown'] = count(array_filter($show, static fn($st) => $st === 0));
        CatalogIndexer::products($changed, $snap, false);
        ProductHistory::log($log, $now);
        Cache::flush();
        return $out;
    }

    /** У товара есть хоть один файл фото (главного или любого другого) */
    private static function anyPhoto(int $pid): bool
    {
        $db = App::db();
        $p = $db->row('SELECT image_id, image_ext FROM products WHERE id = ?', [$pid]);
        if ($p && $p['image_id'] && self::has($pid, (int) $p['image_id'], (string) $p['image_ext'])) return true;
        foreach ($db->all('SELECT id, ext FROM product_images WHERE product_id = ?', [$pid]) as $img) {
            if (self::has($pid, (int) $img['id'], (string) $img['ext'])) return true;
        }
        return false;
    }

    /** Одна проверка за раз (cron и кнопка): GET_LOCK на соединении MySQL; занято — $fn не выполняется; GET_LOCK недоступен — без блокировки */
    private static function locked(callable $fn): void
    {
        $db = App::db();
        $name = 'tomobuv_nophoto_' . substr(md5((string) $db->value('SELECT DATABASE()')), 0, 16);
        try {
            $got = $db->value('SELECT GET_LOCK(?, 0)', [$name]);
        } catch (\PDOException) {
            $got = null;
        }
        if ($got !== null && (int) $got !== 1) return;
        try {
            $fn();
        } finally {
            if ((int) $got === 1) {
                try { $db->value('SELECT RELEASE_LOCK(?)', [$name]); } catch (\PDOException) {}
            }
        }
    }
}
