<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Image;

/**
 * Перенос оригиналов фото в плоскую раскладку (решение 28.10.2026: хостинг ограничивает число файлов и папок — 300 тыс.,
 * еженедельные бэкапы Namecheap — только до 200 тыс.): было wa-data/protected/shop/products/{xx}/{yy}/{id}/images/{фото}.{ext}
 * (3 записи на товар: папка товара, images, файл), стало wa-data/protected/shop/p/{id%100}/{id}-{фото}.{ext} (Image::flatPath —
 * 1 файл на фото). Новые фото пишутся сразу плоско (Image::originalPath), здесь — перенос старых и удаление опустевших папок.
 * Посетителям ничего не меняется: адреса миниатюр те же, оригиналы наружу не отдаются.
 * bin/cron.php — порциями по времени (место — storage/originals-flatten.json, пары {xx}/{yy} 0…9999); всё перенесено — сразу выход.
 */
final class OriginalsFlatten
{
    private const VERSION = 1;

    /** @return array{moved: int, dirs: int, done: bool} — за этот вызов */
    public static function run(float $maxSeconds, ?callable $say = null): array
    {
        $out = ['moved' => 0, 'dirs' => 0, 'done' => true];
        $base = PUBLIC_DIR . '/' . App::config('images.wa_data', 'wa-data') . '/protected/shop/products';
        $stFile = STORAGE . '/originals-flatten.json';
        $st = json_decode((string) @file_get_contents($stFile), true);
        if (!is_array($st) || (int) ($st['v'] ?? 0) !== self::VERSION) $st = ['v' => self::VERSION, 'pos' => 0, 'done' => false, 'moved' => 0];
        if (!empty($st['done']) || !is_dir($base)) return $out;
        $lock = @fopen(STORAGE . '/originals-flatten.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['moved' => 0, 'dirs' => 0, 'done' => false];
        $deadline = microtime(true) + $maxSeconds;
        $out['done'] = false;
        try {
            while ((int) $st['pos'] < 10000 && microtime(true) < $deadline) {
                $xx = sprintf('%02d', (int) $st['pos'] % 100);
                $yy = sprintf('%02d', intdiv((int) $st['pos'], 100));
                $dir = $base . '/' . $xx . '/' . $yy;
                if (is_dir($dir)) {
                    foreach (scandir($dir) ?: [] as $pid) {
                        if (!ctype_digit($pid)) continue;
                        $idir = $dir . '/' . $pid . '/images';
                        foreach (is_dir($idir) ? (scandir($idir) ?: []) : [] as $f) {
                            if (!preg_match('/^(\d+)\.(jpe?g|png|gif|webp)$/i', $f, $m)) continue;   // только оригиналы {фото}.{ext}
                            $dst = Image::flatPath((int) $pid, (int) $m[1], $m[2]);
                            @mkdir(dirname($dst), 0775, true);
                            if (is_file($dst)) { if (@unlink($idir . '/' . $f)) $out['moved']++; continue; }   // плоский уже есть — он главный
                            if (@rename($idir . '/' . $f, $dst)) $out['moved']++;
                        }
                        if (@rmdir($idir)) $out['dirs']++;
                        if (@rmdir($dir . '/' . $pid)) $out['dirs']++;
                    }
                    if (@rmdir($dir)) $out['dirs']++;
                }
                if ((int) $st['pos'] >= 9900 && is_dir(dirname($dir)) && @rmdir(dirname($dir))) $out['dirs']++;   // {xx} пуст, когда пройдены все его {yy}
                $st['pos'] = (int) $st['pos'] + 1;
                if ((int) $st['pos'] % 200 === 0) @file_put_contents($stFile, json_encode(['moved' => (int) $st['moved'] + $out['moved']] + $st), LOCK_EX);
            }
            if ((int) $st['pos'] >= 10000) { $st['done'] = true; $out['done'] = true; @rmdir($base); }
            $st['moved'] = (int) $st['moved'] + $out['moved'];
            @file_put_contents($stFile, json_encode($st), LOCK_EX);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($say && ($out['moved'] || $out['done'])) $say('Оригиналы фото в плоскую раскладку: перенесено ' . $out['moved'] . ', папок удалено ' . $out['dirs'] . ($out['done'] ? ' — всё перенесено' : ' (продолжение — следующий час)'));
        return $out;
    }
}
