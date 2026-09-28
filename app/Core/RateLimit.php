<?php
declare(strict_types=1);

namespace App\Core;

/** Ограничение частоты действий (вход, формы, отзывы) — защита от перебора и спама. */
final class RateLimit
{
    /** true — действие разрешено; false — лимит исчерпан */
    public static function hit(string $key, int $max, int $windowSec): bool
    {
        $db = App::db();
        $k = substr($key, 0, 100);
        $now = time();
        $row = $db->row('SELECT hits, reset_at FROM rate_limits WHERE k = ?', [$k]);
        if (!$row || (int) $row['reset_at'] < $now) {
            $db->upsert('rate_limits', ['k' => $k, 'hits' => 1, 'reset_at' => $now + $windowSec], ['hits', 'reset_at']);
            return true;
        }
        if ((int) $row['hits'] >= $max) return false;
        $db->query('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', [$k]);
        return true;
    }

    /** Исчерпан ли лимит — без увеличения счётчика (чтобы считать только неудачные попытки входа) */
    public static function exceeded(string $key, int $max): bool
    {
        $r = App::db()->row('SELECT hits, reset_at FROM rate_limits WHERE k = ?', [substr($key, 0, 100)]);
        return $r !== null && (int) $r['reset_at'] >= time() && (int) $r['hits'] >= $max;
    }
}
