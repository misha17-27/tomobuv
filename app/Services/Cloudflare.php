<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Settings;

/**
 * Готовые страницы витрины на краю сети Cloudflare (решение владельца 25.10.2026; «Настройки → Cloudflare»): ответ, который
 * PageCache хранит у себя (одинаковый для всех посетителей), получает Cache-Control с s-maxage и метку Cache-Tag: html —
 * Cloudflare (правило кэширования: «Eligible for cache», Edge TTL — по заголовку) отдаёт его сам, без запроса к хостингу
 * (~0,05 с вместо ~0,3 с). Остальное (корзина, кабинет, заказ, админка, JSON для скриптов, кроме «Показать ещё») —
 * private, no-store.
 * После Cache::flush (изменения в админке, конец запуска загрузчика, деплой) страницы с меткой html удаляются из Cloudflare:
 * purge по метке через API (токен только с правом Zone → Cache Purge), не чаще раза в минуту. Отметка «нужна очистка» —
 * файл; очистку делает конец запроса (после ответа) — того, где был сброс, или любого следующего запроса к сайту, когда
 * минута прошла (public/index.php → due/arm); CLI (cron, загрузчики) минуту ждёт; ошибка API — повтор там же и в cron.
 * Очистка идёт и при выключенном хранении (если токен задан): снятые с хранения страницы тоже уходят из Cloudflare.
 * Фото (миниатюры) не трогаются никогда: их делает сервер, очистка всего кэша Cloudflare заставила бы делать их заново.
 * Локальная копия (env = dev) Cloudflare сама не чистит — база с токеном могла приехать с хостинга.
 * Настройки: cloudflare.edge (1 — включено), cloudflare.edge_ttl (минут), cloudflare.zone_id, cloudflare.token.
 */
final class Cloudflare
{
    /** Метка страниц витрины в Cloudflare (Cache-Tag) */
    public const TAG = 'html';
    /** Очистка — не чаще (секунд) */
    private const PURGE_GAP = 60;

    private static bool $armed = false;

    /**
     * Время хранения страниц в Cloudflare, секунд; 0 — выключено. $allowDb = false (страница из PageCache) — только из
     * уже закэшированных настроек: готовая страница отдаётся без подключения к базе (нет настроек в кэше — 0, как раньше).
     */
    public static function edgeTtl(bool $allowDb = true): int
    {
        try {
            $all = Cache::get('settings');                                  // тот же ключ, что у Settings::all()
            if (!is_array($all)) {
                if (!$allowDb) return 0;
                $all = Settings::all();
            }
            if ((string) ($all['cloudflare.edge'] ?? '0') !== '1') return 0;
            return max(1, min(60, (int) (($all['cloudflare.edge_ttl'] ?? '') ?: 5))) * 60;
        } catch (\Throwable) {
            return 0;                                                       // база недоступна — без хранения в Cloudflare
        }
    }

    /**
     * Кэш сайта сброшен (Cache::flush): отметить, что страницы в Cloudflare нужно удалить; удаление — в конце запроса
     * (после отправки ответа). Без обращения к базе: flush вызывается и при недоступной базе.
     */
    public static function flushed(): void
    {
        @file_put_contents(self::pendingFile(), (string) time());
        self::arm();
    }

    /** Есть отложенная очистка и прошла минута с прошлой попытки (public/index.php: тогда очистку делает этот запрос) — без базы */
    public static function due(): bool
    {
        return is_file(self::pendingFile()) && time() - (int) @filemtime(self::statusFile()) >= self::PURGE_GAP;
    }

    /** Очистка в конце этого запроса (после ответа посетителю: сессия записана, соединение закрыто) */
    public static function arm(): void
    {
        if (self::$armed) return;
        self::$armed = true;
        register_shutdown_function(static function (): void {
            if (PHP_SAPI !== 'cli') {
                if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();   // следующая страница админа не ждёт сессию
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            }
            @ignore_user_abort(true);
            try { self::processPending(PHP_SAPI === 'cli'); } catch (\Throwable $e) { \App\Core\Log::error('Cloudflare purge: ' . $e->getMessage()); }
        });
    }

    /**
     * Отложенная очистка: есть отметка — удалить страницы из Cloudflare (не чаще раза в минуту; $wait — дождаться минуты, CLI).
     * Настройки — прямо из базы: в долгом CLI-процессе Settings и версия кэша могут быть старыми. Отметка забирается
     * переименованием: сброс во время запроса к API создаёт новую — она дождётся следующего раза; ошибка — отметка возвращается.
     */
    public static function processPending(bool $wait = false): void
    {
        $f = self::pendingFile();
        if (!is_file($f)) return;
        if ((string) App::config('env', 'production') === 'dev') { @unlink($f); return; }   // локальная копия — не трогать Cloudflare сайта
        try {
            $cf = App::db()->pairs("SELECT name, value FROM settings WHERE name IN ('cloudflare.zone_id', 'cloudflare.token')");
        } catch (\Throwable) {
            return;                                                         // база недоступна — отметка остаётся, повторит cron
        }
        $zone = (string) ($cf['cloudflare.zone_id'] ?? '');
        $token = (string) ($cf['cloudflare.token'] ?? '');
        if (!self::validZone($zone) || $token === '') { @unlink($f); return; }   // нечем чистить (и в Cloudflare сайт ничего не хранит)
        $left = self::PURGE_GAP - (time() - (int) (self::status()['at'] ?? 0));
        if ($left > 0) {
            if (!$wait) return;                                             // недавно чистили — сделает следующий запрос (due) или cron
            sleep($left);
        }
        $claim = $f . '.' . getmypid();
        if (!@rename($f, $claim)) return;                                   // забрал другой процесс
        $r = self::purge($zone, $token);
        if (!$r['ok']) @touch($f);                                          // не удалось — снова в очередь
        @unlink($claim);
    }

    /** Удалить из Cloudflare страницы витрины (метка html). Без аргументов — зона и токен из настроек. @return array{ok: bool, error: string} */
    public static function purge(?string $zone = null, ?string $token = null): array
    {
        $zone ??= (string) Settings::get('cloudflare.zone_id', '');
        $token ??= (string) Settings::get('cloudflare.token', '');
        $out = ['ok' => false, 'error' => ''];
        if (!self::validZone($zone) || $token === '') {
            $out['error'] = 'не заданы Zone ID и API-токен';
        } elseif (!function_exists('curl_init')) {
            $out['error'] = 'на хостинге нет PHP curl';
        } else {
            $ch = curl_init('https://api.cloudflare.com/client/v4/zones/' . $zone . '/purge_cache');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['tags' => [self::TAG]]),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $j = is_string($body) ? json_decode($body, true) : null;
            if (is_array($j) && !empty($j['success'])) {
                $out['ok'] = true;
            } else {
                $msg = is_array($j) ? implode('; ', array_map(static fn($e) => (string) ($e['code'] ?? '') . ' ' . (string) ($e['message'] ?? ''), (array) ($j['errors'] ?? []))) : '';
                $out['error'] = trim($msg) !== '' ? trim($msg) : ($err !== '' ? $err : 'ответ ' . $code);
            }
        }
        @file_put_contents(self::statusFile(), json_encode(['at' => time(), 'ok' => $out['ok'], 'error' => $out['error']], JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (!$out['ok']) \App\Core\Log::error('Cloudflare purge: ' . $out['error']);
        return $out;
    }

    /** Последняя очистка: ['at' => время, 'ok' => bool, 'error' => текст] или [] */
    public static function status(): array
    {
        $j = json_decode((string) @file_get_contents(self::statusFile()), true);
        return is_array($j) ? $j : [];
    }

    /** Есть отложенная очистка */
    public static function pending(): bool
    {
        return is_file(self::pendingFile());
    }

    private static function validZone(string $zone): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $zone) === 1;
    }

    private static function pendingFile(): string
    {
        return STORAGE . '/cache/cf-purge-pending';
    }

    private static function statusFile(): string
    {
        return STORAGE . '/cloudflare-purge.json';
    }
}
