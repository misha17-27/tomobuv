<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Image;

/**
 * Удаление миниатюр фото товаров с диска (решение 25.10.2026: хостинг ограничивает число файлов — 300 тыс.; на товар уходило
 * ~10 файлов и папок, из них ~7 — миниатюры). Миниатюры теперь делаются на лету и кэшируются Cloudflare (ImageController),
 * сохранённые раньше — лишние: папка wa-data/public/shop/products/{xx}/{yy}/{id}/images/{фото}/ удаляется, если у фото есть
 * оригинал (иначе это перенесённое со старого сайта без оригинала — миниатюры остаются, их показывает сайт). Опустевшие
 * папки товара и {xx}/{yy} — тоже.
 * bin/cron.php — порциями по времени (место — storage/thumb-cleanup.json); всё пройдено — сразу выход. При
 * images.thumbs_on_disk = true (миниатюры снова на диске) не работает.
 */
final class ThumbCleanup
{
    /** Новая версия — пройти заново (если изменится правило) */
    private const VERSION = 1;

    /** @return array{files: int, dirs: int, done: bool} — удалено за этот вызов */
    public static function run(float $maxSeconds, ?callable $say = null): array
    {
        $out = ['files' => 0, 'dirs' => 0, 'done' => true];
        if (App::config('images.thumbs_on_disk', false)) return $out;
        $base = PUBLIC_DIR . '/' . App::config('images.wa_data', 'wa-data') . '/public/shop/products';
        $stFile = STORAGE . '/thumb-cleanup.json';
        $st = json_decode((string) @file_get_contents($stFile), true);
        if (!is_array($st) || (int) ($st['v'] ?? 0) !== self::VERSION) $st = ['v' => self::VERSION, 'pos' => 0, 'done' => false, 'files' => 0, 'dirs' => 0];
        if (!empty($st['done']) || !is_dir($base)) return $out;
        $lock = @fopen(STORAGE . '/thumb-cleanup.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['files' => 0, 'dirs' => 0, 'done' => false];   // идёт другой вызов
        $deadline = microtime(true) + $maxSeconds;
        $out['done'] = false;
        try {
            // pos — номер пары {xx}/{yy} (0…9999: xx = id % 100, yy = id / 100 % 100), пройденные целиком
            while ((int) $st['pos'] < 10000) {
                if (microtime(true) > $deadline) break;
                $xx = sprintf('%02d', (int) $st['pos'] % 100);
                $yy = sprintf('%02d', intdiv((int) $st['pos'], 100));
                $dir = $base . '/' . $xx . '/' . $yy;
                if (is_dir($dir)) {
                    foreach (scandir($dir) ?: [] as $pid) {
                        if (!ctype_digit($pid) || !is_dir($dir . '/' . $pid . '/images')) continue;
                        foreach (scandir($dir . '/' . $pid . '/images') ?: [] as $iid) {
                            if (!ctype_digit($iid)) continue;
                            $idir = $dir . '/' . $pid . '/images/' . $iid;
                            if (!is_dir($idir)) continue;
                            $files = array_values(array_filter(scandir($idir) ?: [], static fn($f) => $f !== '.' && $f !== '..'));
                            // расширение оригинала — по миниатюре (1611693.400.jpg → jpg); оригинала нет — не трогать
                            $ext = null;
                            foreach ($files as $f) if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $f, $m)) { $ext = strtolower($m[1]); break; }
                            if ($ext === null || !self::hasOriginal((int) $pid, (int) $iid, $ext)) continue;
                            foreach ($files as $f) {
                                // только миниатюры: {имя}.{размер}.{расширение}
                                if (is_file($idir . '/' . $f) && preg_match('/^[A-Za-z0-9_\-]+\.(?:\d+x\d+|\d+|\d+x0|0x\d+)\.(?:jpe?g|png|gif|webp)$/i', $f) && @unlink($idir . '/' . $f)) $out['files']++;
                            }
                            if (@rmdir($idir)) $out['dirs']++;
                        }
                        if (@rmdir($dir . '/' . $pid . '/images')) $out['dirs']++;
                        if (@rmdir($dir . '/' . $pid)) $out['dirs']++;
                    }
                    if (@rmdir($dir)) $out['dirs']++;
                }
                if ((int) $st['pos'] >= 9900 && is_dir(dirname($dir)) && @rmdir(dirname($dir))) $out['dirs']++;   // {xx} пуст, когда пройдены все его {yy}
                $st['pos'] = (int) $st['pos'] + 1;
                if ((int) $st['pos'] % 200 === 0) self::save($stFile, $st, $out);
            }
            if ((int) $st['pos'] >= 10000) { $st['done'] = true; $out['done'] = true; }
            self::save($stFile, $st, $out);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($say && ($out['files'] || $out['done'])) $say('Миниатюры на диске: удалено файлов ' . $out['files'] . ', папок ' . $out['dirs'] . ($out['done'] ? ' — всё пройдено' : ' (продолжение — следующий час)'));
        return $out;
    }

    /** Ход: [пройдено пар {xx}/{yy} из 10 000, удалено файлов, папок, всё пройдено] */
    public static function status(): array
    {
        $st = json_decode((string) @file_get_contents(STORAGE . '/thumb-cleanup.json'), true);
        return is_array($st) ? ['pos' => (int) ($st['pos'] ?? 0), 'files' => (int) ($st['files'] ?? 0), 'dirs' => (int) ($st['dirs'] ?? 0), 'done' => !empty($st['done'])]
            : ['pos' => 0, 'files' => 0, 'dirs' => 0, 'done' => false];
    }

    private static function hasOriginal(int $pid, int $iid, string $ext): bool
    {
        foreach (array_unique([$ext, 'jpg', 'jpeg', 'png', 'webp', 'gif']) as $e) if (is_file(Image::originalPath($pid, $iid, $e))) return true;
        return false;
    }

    /** Состояние на диск (итоги — накопительно, со счётом этого вызова) */
    private static function save(string $file, array &$st, array &$out): void
    {
        $st['files'] = (int) ($st['files'] ?? 0) + $out['files'] - (int) ($st['_files'] ?? 0);
        $st['dirs'] = (int) ($st['dirs'] ?? 0) + $out['dirs'] - (int) ($st['_dirs'] ?? 0);
        $st['_files'] = $out['files'];
        $st['_dirs'] = $out['dirs'];
        $copy = $st;
        unset($copy['_files'], $copy['_dirs']);
        @file_put_contents($file, json_encode($copy), LOCK_EX);
    }
}
