<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Бережный HTTP-клиент официального сайта бренда Jong•Golf (www.jonggolf.com; фото — public.jonggolf.com) — общий SiteClient:
 * только GET, один поток, пауза ≥ 1,5 с общая для обоих хостов и для админки, cron и CLI (метка storage/import/jonggolf/last-request),
 * User-Agent TomobuvLoader/1.0, robots.txt каждого хоста на сутки, лимит запросов на запуск; 429/5xx → 'retry', 403, Cloudflare
 * и блок-страница фильтра → 'blocked'. К API бренда (/api/json/ — JongGolfApi) этот клиент не обращается.
 */
final class JongGolfClient extends SiteClient
{
    public const BASE = 'https://www.jonggolf.com';
    public const HOSTS = ['public.jonggolf.com'];
    protected const SUPPLIER = JongGolf::class;
    protected const ERROR = SupplierError::class;

    /** Адрес API бренда — не для этого клиента (ключ, белый список IP — JongGolfApi) */
    public static function url(string $u): ?string
    {
        $url = parent::url($u);
        return $url !== null && str_contains((string) parse_url($url, PHP_URL_PATH), '/api/') ? null : $url;
    }
}
