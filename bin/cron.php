<?php
/**
 * Обслуживание — раз в час по cron хостинга:
 *   0 * * * * /usr/bin/php /home/USER/tomobuv/bin/cron.php >/dev/null 2>&1
 * Удаляет устаревший кэш, старые корзины и счётчики лимитов, старые файлы импорта и сессий.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Cache;

$db = App::db();
$removed = Cache::gc();
$carts = $db->query('DELETE FROM cart_items WHERE updated_at < DATE_SUB(NOW(), INTERVAL 60 DAY)')->rowCount();
$db->query('DELETE FROM rate_limits WHERE reset_at < ?', [time() - 3600]);
foreach (glob(STORAGE . '/sessions/sess_*') ?: [] as $f) if (filemtime($f) < time() - 86400 * 2) @unlink($f);
// импорт: старые задания и их файлы, разобранные строки, брошенные загрузки (import/tmp); задания в работе не трогаются
\App\Services\Import\Importer::cleanup();
$prev = (int) @file_get_contents(STORAGE . '/cron-last') ?: time() - 3600;
if ($db->value("SELECT 1 FROM blog_posts WHERE status = 'published' AND published_at > FROM_UNIXTIME(?) AND published_at <= NOW() LIMIT 1", [$prev])) {
    Cache::flush();   // запланированная статья стала опубликованной — обновить /blog/ и главную
}
@file_put_contents(STORAGE . '/cron-last', (string) time());   // для «Состояния системы» в админке
echo "cron: кэш-файлов удалено $removed, старых корзин $carts\n";

// Автозагрузка Jong•Golf («Каталог → Поставщики»): если включена — по расписанию владельца (окно часов и период),
// прерванный запуск продолжается; блокировка не даёт идти двум запускам сразу. Выключена — только уборка старых отчётов.
try {
    \App\Services\Suppliers\JongGolf::cron(static function (string $m): void { echo 'jonggolf: ' . $m . "\n"; });
} catch (\Throwable $e) {
    \App\Core\Log::error('cron jonggolf: ' . $e->getMessage());
    echo 'jonggolf: ошибка — ' . $e->getMessage() . "\n";
}
