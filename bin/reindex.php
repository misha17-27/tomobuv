<?php
/** Полная перестройка индекса каталога и фильтров: php bin/reindex.php (≈5–10 сек на 100 тыс. товаров) */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$t = microtime(true);
App\Services\CatalogIndexer::rebuildAll();
printf("Индекс перестроен за %.1f с\n", microtime(true) - $t);
