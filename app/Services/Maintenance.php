<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;

/**
 * Технические работы: сайт закрыт для посетителей (решение владельца 27.10.2026 — пока идёт массовая загрузка товаров).
 * Отметка — файл storage/maintenance (его читает public/index.php до подключения к базе): посетители и поисковики получают
 * 503 с Retry-After (страницы не выкидываются из поиска, если закрыто не дольше нескольких дней) и текст владельца; админка,
 * cron и загрузчики работают как обычно. Включивший сотрудник получает cookie MAINT_COOKIE — видит сайт как обычно (ответы
 * ему — private, no-store: Cloudflare их не хранит). Файл со словом «auto» ставит bin/deploy.sh (пустая база) — его
 * снимает deploy; включённый здесь (JSON) deploy не трогает.
 * «Состояние системы» → «Технические работы» (только администратор); плашка во всей админке, пока включено.
 */
final class Maintenance
{
    public const COOKIE = 'tomobuv_preview';
    public const TITLE = 'Сайт обновляется';
    public const TEXT = "Мы обновляем каталог — скоро всё заработает. Оптовые заказы принимаем по телефону.\nСайт оновлюється — незабаром усе запрацює. Замовлення приймаємо за телефоном.";

    private static function file(): string
    {
        return STORAGE . '/maintenance';
    }

    /** null — сайт открыт; иначе ['mode' => manual | auto, 'title', 'text', 'token', 'at', 'by'] */
    public static function state(): ?array
    {
        if (!is_file(self::file())) return null;
        $raw = trim((string) @file_get_contents(self::file()));
        $j = json_decode($raw, true);
        if (!is_array($j)) return ['mode' => 'auto', 'title' => self::TITLE, 'text' => self::TEXT, 'token' => '', 'at' => (int) @filemtime(self::file()), 'by' => null];
        return ['mode' => 'manual', 'title' => (string) ($j['title'] ?? self::TITLE), 'text' => (string) ($j['text'] ?? self::TEXT),
            'token' => (string) ($j['token'] ?? ''), 'at' => (int) ($j['at'] ?? 0), 'by' => isset($j['by']) ? (int) $j['by'] : null];
    }

    /** Закрыть сайт; @return string токен для cookie предпросмотра включившего */
    public static function enable(string $title, string $text, ?int $by): string
    {
        $token = bin2hex(random_bytes(16));
        $data = ['title' => $title !== '' ? $title : self::TITLE, 'text' => $text !== '' ? $text : self::TEXT, 'token' => $token, 'at' => time(), 'by' => $by];
        @mkdir(dirname(self::file()), 0775, true);
        file_put_contents(self::file(), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        Cache::flush();                                                     // страницы в Cloudflare — тоже: посетители сразу видят заглушку
        return $token;
    }

    /** Открыть сайт */
    public static function disable(): void
    {
        @unlink(self::file());
        Cache::flush();
    }
}
