<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Реестр поставщиков с автозагрузкой: код → класс адаптера (наследник Supplier). Новый поставщик — класс-адаптер,
 * строка здесь, маршруты админки и CLI (docs/ARCHITECTURE.md, «Автозагрузка поставщиков»). bin/cron.php обходит всех.
 */
final class Suppliers
{
    /** @var array<string, class-string<Supplier>> */
    public const LIST = [
        JongGolf::CODE => JongGolf::class,
        HitObuvi::CODE => HitObuvi::class,
    ];

    /** Кратко для списка в админке */
    public const ABOUT = [
        'jonggolf' => 'Товары по API поставщика (очередь выгрузки с подтверждением) — замена wa_loader_jonggolf.php старого сайта.',
        'hitobuvi' => 'Товары со страниц сайта hit-obuvi.com.ua (списки категорий; фото — только для новых), бережно: пауза между запросами, robots.txt.',
    ];

    /** @return array<string, class-string<Supplier>> */
    public static function all(): array
    {
        return self::LIST;
    }

    /** @return class-string<Supplier>|null */
    public static function get(string $code): ?string
    {
        return self::LIST[$code] ?? null;
    }
}
