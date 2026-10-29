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
try {
    $db->query('DELETE FROM auth_tokens WHERE expires_at < NOW()');          // «Запомнить меня»: срок вышел
} catch (\PDOException) {
}
// импорт: старые задания и их файлы, разобранные строки, брошенные загрузки (import/tmp); задания в работе не трогаются
\App\Services\Import\Importer::cleanup();
$prev = (int) @file_get_contents(STORAGE . '/cron-last') ?: time() - 3600;
if ($db->value("SELECT 1 FROM blog_posts WHERE status = 'published' AND published_at > FROM_UNIXTIME(?) AND published_at <= NOW() LIMIT 1", [$prev])) {
    Cache::flush();   // запланированная статья стала опубликованной — обновить /blog/ и главную
}
@file_put_contents(STORAGE . '/cron-last', (string) time());   // для «Состояния системы» в админке
echo "cron: кэш-файлов удалено $removed, старых корзин $carts\n";

// Cloudflare: отложенная очистка страниц после сброса кэша (не удалась — повтор; до загрузчиков: они идут до часа)
try {
    \App\Services\Cloudflare::processPending(true);
} catch (\Throwable $e) {
    \App\Core\Log::error('cron cloudflare: ' . $e->getMessage());
}

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

// Оригиналы фото — в плоскую раскладку (1 файл на фото вместо 3 записей на товар; лимит файлов хостинга): OriginalsFlatten,
// не дольше 10 мин за час; всё перенесено — сразу выход
try {
    \App\Services\OriginalsFlatten::run(600.0, static function (string $m): void { echo $m . "\n"; });
} catch (\Throwable $e) {
    \App\Core\Log::error('cron originals: ' . $e->getMessage());
    echo 'оригиналы фото: ошибка — ' . $e->getMessage() . "\n";
}

// Файлы и папки аккаунта (inodes) по папкам — раз в 3 ч, для «Состояния системы» (InodeReport, до 2 мин)
try {
    $inodes = \App\Services\InodeReport::last();
    if (!$inodes || (int) $inodes['at'] < time() - 3 * 3600 + 300) {
        $r = \App\Services\InodeReport::run(120.0);
        echo 'файлов и папок на аккаунте: ' . $r['total'] . ($r['complete'] ? '' : '+') . ' (' . $r['sec'] . ' с)' . "\n";
    }
} catch (\Throwable $e) {
    \App\Core\Log::error('cron inodes: ' . $e->getMessage());
}

// Миниатюры фото на диске (до 25.10.2026 сохранялись — ~7 файлов и папок на товар; хостинг ограничивает число файлов): у фото
// с оригиналом — удалить (ThumbCleanup, не дольше 5 мин за час; всё пройдено — сразу выход), теперь их делает сервер и кэширует Cloudflare
try {
    \App\Services\ThumbCleanup::run(300.0, static function (string $m): void { echo $m . "\n"; });
} catch (\Throwable $e) {
    \App\Core\Log::error('cron thumbs: ' . $e->getMessage());
    echo 'миниатюры: ошибка — ' . $e->getMessage() . "\n";
}

// Товар без фото на сайте не показывается (NoPhoto, решение владельца 25.10.2026): нет главного фото (или его файла) — другое фото
// товара становится главным, иначе товар скрыт с отметкой «нет фото»; фото появилось — снова на сайте. Скрыть больше половины
// товаров сразу — только кнопкой в «Каталог → Товары» (скорее пропала папка фото)
try {
    $np = \App\Services\NoPhoto::sync();
    if ($np['hidden'] || $np['shown'] || $np['fixed'] || $np['held']) {
        echo 'без фото: скрыто ' . $np['hidden'] . ', снова на сайте ' . $np['shown'] . ', главным стало другое фото ' . $np['fixed']
            . ($np['held'] ? ', не скрыто (больше половины товаров) ' . $np['held'] : '') . "\n";
    }
} catch (\Throwable $e) {
    \App\Core\Log::error('cron nophoto: ' . $e->getMessage());
    echo 'без фото: ошибка — ' . $e->getMessage() . "\n";
}

// SEO (SeoFix, «SEO → Исправить автоматически»): стандартные шаблоны («только опт по всей Украине»), title/description вне нормы,
// одинаковые title у новых товаров — раз в сутки после ночных загрузок (с 7:00) и сразу, когда в коде новые стандартные шаблоны
// (SeoFix::VERSION). Запуск с изменениями — пакет в журнале автоисправления (откат там же, откаченное cron не повторяет);
// без изменений — ничего не пишется. Выключить — там же («Ежедневно автоматически», настройка seo.autofix_cron).
try {
    $seoDay = STORAGE . '/seo-autofix-day';
    $seoNew = (int) \App\Core\Settings::get('seo.standard_version', '0') < \App\Services\SeoFix::VERSION;
    if (\App\Core\Settings::get('seo.autofix_cron', '1') === '1' && \App\Services\SeoFix::hasTables()
        && ($seoNew || ((int) date('G') >= 7 && @file_get_contents($seoDay) !== date('Y-m-d')))) {
        @ini_set('memory_limit', '1024M');
        $r = \App\Services\SeoFix::run(true, ['source' => 'cron', 'note' => $seoNew ? 'новые стандартные шаблоны (версия ' . \App\Services\SeoFix::VERSION . ')' : 'ежедневно']);
        @file_put_contents($seoDay, date('Y-m-d'));
        if ($seoNew) \App\Core\Settings::set('seo.standard_version', (string) \App\Services\SeoFix::VERSION);   // и без изменений (свои шаблоны)
        echo 'SEO: ' . ($r['changes'] ? 'исправлено ' . $r['changes'] . ' (пакет №' . $r['batch'] . ')' : 'всё в норме') . "\n";
    }
} catch (\Throwable $e) {
    \App\Core\Log::error('cron seo: ' . $e->getMessage());
    echo 'SEO: ошибка — ' . $e->getMessage() . "\n";
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
