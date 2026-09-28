<?php
/**
 * Полная перестройка индекса каталога и фильтров: php bin/reindex.php (≈8 с на 107 тыс. товаров).
 * Можно поставить в cron на ночь (docs/INSTALL.md): выравнивает счётчики фильтров, если индекс когда-нибудь разошёлся.
 * Идёт другая перестройка (кнопка в «Состоянии системы», «Завершение» импорта, второй запуск) — ждёт её до 10 минут.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$t = microtime(true);
if (!App\Services\CatalogIndexer::rebuildAll()) {
    fwrite(STDERR, "Индекс не перестроен: другая перестройка не закончилась за 10 минут\n");
    exit(1);
}
$waited = App\Services\CatalogIndexer::$waited;
printf("Индекс перестроен за %.1f с%s\n", microtime(true) - $t - $waited, $waited >= 1 ? sprintf(' (ещё %.0f с ждал другую перестройку)', $waited) : '');
