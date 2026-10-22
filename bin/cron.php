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

// Автозагрузка поставщиков («Каталог → Поставщики»: Jong•Golf, Хит обуви, Tom.m — App\Services\Suppliers\Suppliers): у каждого — своё
// расписание (окно часов и период), прерванный запуск продолжается; блокировка не даёт идти двум запускам одного поставщика.
// Выключенный — только уборка старых отчётов. Поставщики идут по очереди: долгий проход одного не мешает расписанию другого
// больше чем на час (cron следующего часа продолжит).
foreach (\App\Services\Suppliers\Suppliers::all() as $code => $cls) {
    try {
        $cls::cron(static function (string $m) use ($code): void { echo $code . ': ' . $m . "\n"; });
    } catch (\Throwable $e) {
        \App\Core\Log::error('cron ' . $code . ': ' . $e->getMessage());
        echo $code . ': ошибка — ' . $e->getMessage() . "\n";
    }
}

// Фото «Хит обуви», загруженные до замены водяного знака: надпись поставщика → «tomobuv.com.ua» под новым адресом фото
// (HitObuviWatermark::backlog — порциями не дольше 10 мин; новые фото загрузчик обрабатывает сам; всё сделано — сразу выход)
try {
    \App\Services\Suppliers\HitObuviWatermark::backlog(static function (string $m): void { echo $m . "\n"; }, 600.0);
} catch (\Throwable $e) {
    \App\Core\Log::error('cron watermark: ' . $e->getMessage());
    echo 'водяной знак: ошибка — ' . $e->getMessage() . "\n";
}

// Старые адреса товаров, удалённых при пересборке каталога (bin/catalog-reset.php), → новые карточки тех же моделей, которые
// только что загрузили поставщики (LegacyProducts::match: только однозначные; товары сайта не менялись — сразу выход)
try {
    $m = \App\Services\LegacyProducts::match();
    if ($m['matched']) echo 'старые адреса: сопоставлено ' . $m['matched'] . ', без новой карточки ' . $m['left'] . "\n";
} catch (\Throwable $e) {
    \App\Core\Log::error('cron legacy: ' . $e->getMessage());
    echo 'старые адреса: ошибка — ' . $e->getMessage() . "\n";
}
