<?php
/** Сбросить кэш страниц и данных: php bin/cache-clear.php */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

App\Core\Cache::flush();
echo "Кэш сброшен (версия " . App\Core\Cache::version() . ")\n";
