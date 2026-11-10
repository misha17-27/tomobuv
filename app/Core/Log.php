<?php
declare(strict_types=1);

namespace App\Core;

final class Log
{
    public static function write(string $channel, string $msg): void
    {
        @mkdir(STORAGE . '/logs', 0775, true);
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . ' | ' . ($_SERVER['REQUEST_URI'] ?? 'cli') . PHP_EOL;
        @file_put_contents(STORAGE . '/logs/' . $channel . '-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $msg): void { self::write('error', $msg); }
    public static function info(string $msg): void { self::write('app', $msg); }

    /**
     * Журнал ошибок PHP (storage/logs/php-error.log, его задаёт bootstrap) сам не ограничен: на PHP 8.5 предупреждения об
     * устаревших функциях писались на каждый запрос — к 10.11.2026 файл вырос до 717 МБ. Больше $max байт — последние $keep байт
     * (с начала строки) переходят в php-error.old.log (прежний заменяется), сам журнал обнуляется. bin/cron.php — раз в час.
     * PHP дописывает журнал в режиме добавления — после обнуления запись идёт с начала файла; строки, записанные между чтением
     * хвоста и обнулением, теряются (доли секунды). @return int размер журнала до обнуления, 0 — не понадобилось или не удалось
     */
    public static function rotatePhpErrors(int $max = 20 << 20, int $keep = 2 << 20): int
    {
        $f = STORAGE . '/logs/php-error.log';
        clearstatcache(true, $f);
        if ((int) @filesize($f) <= $max) return 0;
        $h = @fopen($f, 'r+');
        if (!$h) return 0;
        try {
            if (!flock($h, LOCK_EX | LOCK_NB)) return 0;                     // обрезает другой процесс
            $size = (int) (fstat($h)['size'] ?? 0);
            if ($size <= $max) return 0;
            fseek($h, max(0, $size - $keep));
            $tail = (string) stream_get_contents($h, $keep);
            $nl = strpos($tail, "\n");
            if ($nl !== false) $tail = substr($tail, $nl + 1);              // первая строка обрезана посередине
            $note = '[' . date('Y-m-d H:i:s') . '] журнал ошибок PHP был ' . round($size / 1048576) . ' МБ — здесь последние строки' . PHP_EOL;
            if (@file_put_contents(dirname($f) . '/php-error.old.log', $note . $tail, LOCK_EX) === false) return 0;
            if (!ftruncate($h, 0)) return 0;
            return $size;
        } finally {
            fclose($h);                                                      // и снимает блокировку
        }
    }
}
