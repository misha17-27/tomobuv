<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Бережный HTTP-клиент tomm.ua (официальный сайт бренда Tom.m) — общий SiteClient: только GET, один поток, пауза ≥ 1,5 с
 * общая для админки, cron и CLI (метка storage/import/tomm/last-request), User-Agent TomobuvLoader/1.0, robots.txt на сутки,
 * лимит запросов на запуск; 429/5xx → 'retry', 403, Cloudflare и блок-страница фильтра → 'blocked'.
 * Списки — с cookie products_per_page (по умолчанию 100: весь каталог — 17 страниц поиска); cookie входит в ключ
 * сохранённого ответа (как у кэша разбора).
 */
final class TommClient extends SiteClient
{
    public const BASE = 'https://tomm.ua';
    protected const SUPPLIER = Tomm::class;
    protected const ERROR = SupplierError::class;
}
