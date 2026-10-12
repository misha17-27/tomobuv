<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Ошибка обмена с сайтом «Хит обуви». $kind: retry — 429/5xx или нет ответа (пауза с удвоением, после 3 подряд —
 * остановка); blocked — 403 или проверка Cloudflare (сразу остановка, нужна проверка человеком); robots — путь запрещён
 * robots.txt; limit — лимит запросов на запуск; net — нет curl; foreign — адрес не на сайте поставщика; markup — наценку
 * сняли во время запуска (запись остановлена).
 */
final class HitObuviError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $kind = '', public readonly int $http = 0)
    {
        parent::__construct($message);
    }
}
