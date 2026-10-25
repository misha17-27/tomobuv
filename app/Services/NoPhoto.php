<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Core\Settings;

/**
 * Товары без фото на сайте не показываются (решение владельца 25.10.2026): у товара на сайте нет главного фото (image_id пуст
 * или файла оригинала нет — не перенесён со старого сайта, фото бренда закрыто robots.txt) — сначала ищется другое его фото
 * с файлом (оно становится главным), иначе товар скрывается (status = 0, nophoto_hidden = 1). Фото появилось (загрузчик
 * добавил, владелец загрузил) — товар показывается снова. Скрытый так товар, который владелец показал вручную, при следующей
 * проверке скрывается опять, пока у него нет фото; скрытые владельцем без этой отметки не трогаются.
 * bin/cron.php — раз в час; история товара — «Скрыт: нет фото» / «Показан: фото появилось».
 */
final class NoPhoto
{
    /** Скрыть за раз больше этой доли товаров на сайте (и больше MASS_MIN) — только с $force: скорее пропала папка фото, чем сами фото */
    private const MASS_SHARE = 0.5;
    private const MASS_MIN = 200;

    /**
     * $force — скрыть, сколько бы ни было (кнопка в списке товаров), $by — кто нажал (в историю товара; иначе «по расписанию»).
     * @return array{hidden: int, shown: int, fixed: int, held: int} held — сколько не скрыто из-за MASS_SHARE
     */
    public static function sync(bool $force = false, ?int $by = null): array
    {
        $db = App::db();
        $out = ['hidden' => 0, 'shown' => 0, 'fixed' => 0, 'held' => 0];
        $hide = []; $show = []; $noMain = []; $visible = 0;
        foreach ($db->all('SELECT id, image_id, image_ext, status, nophoto_hidden FROM products WHERE status = 1 OR nophoto_hidden = 1') as $r) {
            if ((int) $r['status'] === 1) $visible++;
            if ($r['image_id'] && self::has((int) $r['id'], (int) $r['image_id'], (string) $r['image_ext'])) {
                if ((int) $r['nophoto_hidden'] === 1) $show[(int) $r['id']] = (int) $r['status'];
            } elseif ((int) $r['status'] === 1) {
                $noMain[] = (int) $r['id'];
            }
        }
        // главного фото нет, но есть другое с файлом — оно становится главным (товар не скрывается)
        foreach (array_chunk($noMain, 1000) as $part) {
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
            }
            foreach ($part as $pid) if (!isset($found[$pid])) $hide[] = $pid;
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
            $db->query("UPDATE products SET status = 0, nophoto_hidden = 1, updated_at = ? WHERE id IN ($ph) AND status = 1", array_merge([$now], $v));
            foreach ($part as $pid) $log[] = [$pid, 'product_nophoto', ['op' => 'hide', 'by' => $by, 'ch' => [['status', ProductHistory::show('status', 1), ProductHistory::show('status', 0)]]]];
        }
        $out['hidden'] = count($hide);
        foreach (array_chunk(array_keys($show), 1000) as $part) {
            [$ph, $v] = $db->in($part);
            $db->query("UPDATE products SET status = 1, nophoto_hidden = 0, updated_at = ? WHERE id IN ($ph)", array_merge([$now], $v));
            foreach ($part as $pid) if ($show[$pid] === 0) $log[] = [$pid, 'product_nophoto', ['op' => 'show', 'ch' => [['status', ProductHistory::show('status', 0), ProductHistory::show('status', 1)]]]];
        }
        $out['shown'] = count(array_filter($show, static fn($st) => $st === 0));
        CatalogIndexer::products($changed, $snap);
        ProductHistory::log($log, $now);
        Cache::flush();
        return $out;
    }

    /**
     * Фото есть на сайте: оригинал на диске или хоть одна готовая миниатюра (Image::url показывает её и без оригинала —
     * так бывает у перенесённых со старого сайта)
     */
    private static function has(int $pid, int $iid, string $ext): bool
    {
        $ext = $ext ?: 'jpg';
        if (is_file(Image::originalPath($pid, $iid, $ext))) return true;
        return (bool) glob(dirname(Image::thumbPath($pid, $iid, (string) $iid, '400', $ext)) . '/*.' . $ext, GLOB_NOSORT);
    }
}
