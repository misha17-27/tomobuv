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
}
