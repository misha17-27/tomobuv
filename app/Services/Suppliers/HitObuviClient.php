<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Бережный HTTP-клиент hit-obuvi.com.ua — общий SiteClient (только GET, один поток, пауза ≥ 1,5 с общая для админки,
 * cron и CLI — метка storage/import/hitobuvi/last-request, User-Agent TomobuvLoader/1.0, robots.txt на сутки, лимит
 * запросов на запуск; 429/5xx → 'retry', 403 и Cloudflare → 'blocked'; режим без сети — папка сохранённых ответов).
 */
final class HitObuviClient extends SiteClient
{
    public const BASE = 'https://hit-obuvi.com.ua';
    protected const SUPPLIER = HitObuvi::class;
    protected const ERROR = HitObuviError::class;
}
