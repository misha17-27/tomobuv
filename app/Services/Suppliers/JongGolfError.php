<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/** Ошибка обмена с поставщиком; $kind = 'ip' — не в белом списке IP */
final class JongGolfError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $kind = '')
    {
        parent::__construct($message);
    }
}
