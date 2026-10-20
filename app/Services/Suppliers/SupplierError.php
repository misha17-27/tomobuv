<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Ошибка обмена с сайтом поставщика (SiteClient: «Хит обуви», Tom.m …). $kind: retry — 429/5xx или нет ответа (пауза
 * с удвоением, после 3 подряд — остановка); blocked — 403, проверка браузера или блок-страница фильтра (сразу остановка,
 * нужна проверка человеком); robots — путь запрещён robots.txt; limit — лимит запросов на запуск; net — нет curl;
 * foreign — адрес не на сайте поставщика; markup — наценку сняли во время запуска (запись остановлена);
 * layout — страница не похожа на ожидаемую (сменился шаблон сайта).
 */
class SupplierError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $kind = '', public readonly int $http = 0)
    {
        parent::__construct($message);
    }
}
