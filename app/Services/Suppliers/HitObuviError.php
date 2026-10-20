<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Ошибка обмена с сайтом «Хит обуви» (виды — SupplierError: retry, blocked, robots, limit, net, foreign, markup).
 * Отдельный класс — чтобы в журнале было видно, какой поставщик; обрабатывается как любая SupplierError.
 */
final class HitObuviError extends SupplierError
{
}
