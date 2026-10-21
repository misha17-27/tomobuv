<?php
/**
 * Карта старых адресов товаров, удалённых при пересборке каталога (App\Services\LegacyProducts, таблица legacy_products):
 * /product/{старый}/ и /ua/product/{старый}/ → 301 на новую карточку той же модели или на категорию.
 *
 *   php bin/legacy-products.php [--stats]          — сводка: строк, сопоставлено (по правилам), куда ведут сейчас, переходов
 *   php bin/legacy-products.php --match [--dry-run] [--force]
 *                                                  — сопоставить с загруженными товарами (forsage-код «Хит обуви», название
 *                                                    и ряд, бренд+тип+артикул+ряд, бренд+код+ряд, код Tom.m/Jong•Golf+ряд;
 *                                                    только однозначные); --dry-run — только посчитать; --force — даже если
 *                                                    товары сайта не менялись с прошлого раза (cron делает это сам)
 *   php bin/legacy-products.php --capture          — записать в карту товары, которые удалит bin/catalog-reset.php (товары
 *                                                    не меняются; --confirm сброса делает это сам, повтор безопасен)
 *   php bin/legacy-products.php --resolve=<url>    — куда ведёт старый адрес (url товара без /product/)
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Services\CatalogReset;
use App\Services\LegacyProducts;

if (PHP_SAPI !== 'cli') exit("Только из командной строки\n");
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? '1';
    else { fwrite(STDERR, "Непонятный параметр: $a\n"); exit(1); }
}
$say = static function (string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL; };
$num = static fn($n): string => number_format((int) $n, 0, ',', ' ');
@ini_set('memory_limit', '1024M');

try {
    LegacyProducts::ensureSchema();
    if (isset($opts['capture'])) {
        $t = microtime(true);
        $keep = CatalogReset::keep();
        $del = CatalogReset::deleteIds($keep);
        $r = LegacyProducts::capture($del, $say);
        $say(sprintf('Записано в карту: %s (товаров к удалению %s, остаётся %s), старых адресов из редиректов: %d — за %.1f с',
            $num($r['rows']), $num(count($del)), $num(count($keep)), $r['aliases'], microtime(true) - $t));
        exit(0);
    }
    if (isset($opts['resolve'])) {
        $u = trim((string) $opts['resolve'], '/');
        if (str_starts_with($u, 'product/')) $u = substr($u, 8);
        $to = LegacyProducts::resolve($u, false);
        $say($to === null ? 'Адреса нет в карте — обычный 404 (или товар есть на сайте)' : '/product/' . $u . '/ → 301 ' . $to . '  (на /ua/ — /ua' . $to . ')');
        exit(0);
    }
    if (isset($opts['match'])) {
        $dry = isset($opts['dry-run']);
        $r = LegacyProducts::match(static fn(string $m) => $say('  ' . $m), $dry, isset($opts['force']));
        if (!$r['table']) { $say('Таблицы legacy_products нет'); exit(1); }
        if ($r['busy']) { $say('Сопоставление уже идёт в другом процессе'); exit(0); }
        if ($r['skipped']) { $say('Товары сайта не менялись с прошлого сопоставления — пропуск (--force — всё равно)'); exit(0); }
        $say(($dry ? 'Будет сопоставлено: ' : 'Сопоставлено: ') . $num($r['matched']) . ' (' . (implode(', ', array_map(static fn($k, $v) => (LegacyProducts::VIA[$k] ?? $k) . ' ' . $v, array_keys($r['by']), $r['by'])) ?: '—') . ')'
            . '; неоднозначных ключей: ' . (implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys($r['ambiguous']), $r['ambiguous'])) ?: '0')
            . '; новая карточка удалена — снова в очереди: ' . $r['lost'] . '; без новой карточки: ' . $num($r['left']) . ' (' . $r['ms'] . ' мс)');
        exit(0);
    }
    $s = LegacyProducts::stats();
    $say('Карта старых адресов: строк ' . $num($s['rows']) . ' (из редиректов ' . $s['aliases'] . '), были скрыты на сайте ' . $num($s['by_status'][0] ?? 0));
    $say('Сопоставлено с новыми карточками: ' . $num(array_sum($s['matched'])) . ($s['matched'] ? ' — ' . implode(', ', array_map(static fn($k, $v) => (LegacyProducts::VIA[$k] ?? $k) . ' ' . $v, array_keys($s['matched']), $s['matched'])) : ''));
    $say('Куда ведут сейчас: новая карточка ' . $num($s['targets']['product']) . ', своя категория ' . $num($s['targets']['category']) . ', видимый предок ' . $num($s['targets']['parent'])
        . ', главная ' . $num($s['targets']['home']) . ($s['hidden_new'] ? ' (новая карточка скрыта — по категории: ' . $num($s['hidden_new']) . ')' : ''));
    $say('Адрес снова у товара сайта (301 не нужен, строки уберёт сброс): ' . $num($s['busy']) . '; переходов по старым адресам: ' . $num($s['hits']) . ' (адресов ' . $num($s['hit_rows']) . ')');
} catch (\Throwable $e) {
    $say('ОШИБКА: ' . $e->getMessage());
    exit(1);
}
