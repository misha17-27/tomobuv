<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Ошибка обмена с сайтом поставщика (SiteClient: «Хит обуви», Tom.m …). $kind: retry — 429/5xx или нет ответа (пауза
 * с удвоением, после 3 подряд — остановка); blocked — 403, проверка браузера или блок-страница фильтра (сразу остановка,
 * нужна проверка человеком); robots — путь запрещён robots.txt; limit — лимит запросов на запуск; net — нет curl;
 * foreign — адрес не на сайте поставщика; markup — наценку сняли во время запуска (запись остановлена);
 * layout — страница не похожа на ожидаемую (сменился шаблон сайта). $why у blocked: denied — просто ответ 403 (отказ в доступе
 * к этому адресу), challenge — проверка браузера, filter — блок-страница фильтра (WebSync::photos: 403 на одно фото — ошибка фото).
 */
class SupplierError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $kind = '', public readonly int $http = 0, public readonly string $why = '')
    {
        parent::__construct($message);
    }
}
