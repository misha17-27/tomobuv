<?php
/**
 * Пересборка каталога: удалить все товары, кроме Tom.m и Jong•Golf (каталог заново собирают загрузчики «Хит обуви»,
 * Tom.m и Jong•Golf), старые адреса удалённых — 301 (App\Services\LegacyProducts). Порядок — docs/INSTALL.md →
 * «Пересборка каталога»; что остаётся и что удаляется — App\Services\CatalogReset.
 *
 *   php bin/catalog-reset.php [--dry-run] [--samples=20]   — ничего не меняет: состав «оставить / удалить» с разбивкой,
 *                                                            примеры, строки по таблицам, карта старых адресов, файлы фото
 *   php bin/catalog-reset.php --confirm=<N> [--batch=2000] [--pause=0.2]
 *                                                          — выполнить: N — «Удаляется товаров» из --dry-run (защита от
 *                                                            запуска «не глядя»); после обрыва — тот же N или новый остаток
 *   php bin/catalog-reset.php --purge-files [--dry-run]    — потом, после проверки нового каталога: удалить папки фото
 *                                                            удалённых товаров (public/wa-data; сначала — копия папки)
 *
 * Перед --confirm — свежая копия базы (mysqldump, docs/INSTALL.md) и остановленные запуски загрузчиков.
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
// ширина колонки в символах, а не байтах (кириллица)
$pad = static fn(string $s, int $w, bool $left = false): string => ($left ? str_repeat(' ', max(0, $w - mb_strlen($s))) : '')
    . (mb_strlen($s) > $w ? mb_substr($s, 0, $w) : $s) . ($left ? '' : str_repeat(' ', max(0, $w - mb_strlen($s))));
@ini_set('memory_limit', '1024M');

try {
    if (isset($opts['purge-files'])) {
        $dry = isset($opts['dry-run']);
        $r = CatalogReset::purgeFiles($dry, $say);
        $say(($dry ? 'Будет удалено' : 'Удалено') . ' папок фото: ' . $num($r['dirs']) . ', файлов: ' . $num($r['files']) . ', ' . round($r['bytes'] / 1048576, 1) . ' МБ');
        exit(0);
    }

    if (isset($opts['confirm'])) {
        if (!preg_match('/^\d+$/', (string) $opts['confirm'])) { $say('ОШИБКА: --confirm=<число удаляемых из --dry-run>'); exit(1); }
        $r = CatalogReset::run((int) $opts['confirm'], $say, (int) ($opts['batch'] ?? 2000), (float) ($opts['pause'] ?? 0.2));
        $s = $r['state'];
        $say('Готово за ' . $s['seconds'] . ' с: удалено товаров ' . $num($r['deleted']) . ' (всего по запускам ' . $num($s['deleted']) . ' из ' . $num($s['planned']) . ')');
        $say('Строк по таблицам: ' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys($s['rows']), $s['rows'])));
        $say('Карта старых адресов: ' . $num($r['captured']) . ' (+' . $r['aliases'] . ' из редиректов), удалено редиректов на товары ' . $r['redirects']
            . ', промокодов изменено ' . $r['coupons'] . ', строк карты с занятым адресом убрано ' . $r['busy_dropped']);
        $m = $r['match'];
        $say('Сопоставление старых адресов с карточками сайта: ' . ($m['busy'] ? 'идёт в другом процессе' : 'новых ' . $num($m['matched'])
            . ($m['by'] ? ' (' . implode(', ', array_map(static fn($k, $v) => (LegacyProducts::VIA[$k] ?? $k) . ' ' . $v, array_keys($m['by']), $m['by'])) . ')' : '')
            . ', без новой карточки ' . $num($m['left'])));
        $say('Дальше: загрузка «Хит обуви» (php bin/supplier-hitobuvi.php --load, локально — D:\\tomobuv.com.ua\\load-hitobuvi.bat), '
            . 'Tom.m и Jong•Golf — «Запустить сейчас»; сопоставление старых адресов — само (cron), сводка — php bin/legacy-products.php --stats');
        exit(0);
    }

    // ------------------------------------------------------------------ пробный режим (по умолчанию)
    $t = microtime(true);
    $p = CatalogReset::plan(max(5, (int) ($opts['samples'] ?? 20)));
    $line = static fn(string $s = '') => print($s . PHP_EOL);
    $line('ПЕРЕСБОРКА КАТАЛОГА — пробный режим (ничего не меняется)');
    $line(str_repeat('=', 100));
    $line('Товаров в каталоге: ' . $num($p['total']) . '  |  остаётся: ' . $num($p['keep']) . '  |  УДАЛЯЕТСЯ ТОВАРОВ: ' . $num($p['delete']));
    $line();
    $line('Бренды групп (все написания, по ключу bkey; + настройки загрузчиков):');
    foreach (CatalogReset::GROUPS as $g => $title) {
        $line('  ' . $title . ': ' . ($p['brands'][$g] ? implode(', ', array_map(static fn($id, $n) => $n . ' (id ' . $id . ')', array_keys($p['brands'][$g]), $p['brands'][$g])) : '—'));
    }
    $line('  «Не указано»: id ' . (implode(', ', $p['brands']['none']) ?: '—'));
    $line();
    $line('ОСТАЁТСЯ (товар может иметь несколько признаков):');
    foreach (CatalogReset::GROUPS as $g => $title) {
        $x = $p['groups'][$g] ?? ['total' => 0];
        $line('  ' . $title . ': ' . $num($x['total']) . ' (на сайте ' . $num($x['active'] ?? 0) . ')');
        foreach (CatalogReset::WHY as $w => $label) if (!empty($x['why'][$w])) $line('    ' . $pad($w, 9) . ' ' . $pad($num($x['why'][$w]), 6, true) . '  ' . $label);
        $line('    по бренду: ' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys($x['brand'] ?? []), $x['brand'] ?? [])));
        $line('    по поставщику: ' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys($x['supplier'] ?? []), $x['supplier'] ?? [])));
    }
    $fmt = static fn(array $r): string => sprintf('%8d  ', $r['id']) . $pad((string) $r['name'], 52) . ' ' . $pad((string) $r['sku'], 9) . ' '
        . $pad((string) ($r['supplier'] ?? '') ?: '—', 9) . ' ' . $pad((string) ($r['brand'] ?? '(нет)'), 11) . ' ' . $r['why'];
    $line();
    $line('Примеры оставляемых (' . count($p['samples_keep']) . '):   id  название / артикул / поставщик / бренд / признаки');
    foreach ($p['samples_keep'] as $r) $line($fmt($r));
    $line();
    $line('ГРАНИЧНЫЕ УДАЛЯЕМЫЕ: ' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys($p['boundary']['counts']), $p['boundary']['counts'])));
    $line('  brand_similar — бренд похож (TOM, JG, GOLF…), bare — без бренда и без кода бренда, manual_other — без поставщика, другой бренд,');
    $line('  same_code — код модели как у оставляемого Tom.m/Jong•Golf, jg_like_code — код вида Jong•Golf у другого бренда');
    foreach ($p['boundary']['rows'] as $r) $line($fmt($r));
    $line();
    $line('СТРОКИ ПО ТАБЛИЦАМ (у удаляемых товаров; «без товара» — висят уже сейчас, хвосты тоже уберутся):');
    $line('  ' . $pad('таблица', 18) . ' ' . $pad('действие', 8) . ' ' . $pad('когда', 7) . ' ' . $pad('всего', 9, true) . ' ' . $pad('удаляемых', 9, true) . ' ' . $pad('без товара', 10, true));
    $acts = ['delete' => 'удалить', 'null' => 'NULL', 'keep' => 'оставить', 'rebuild' => 'пересчёт'];
    $whenL = ['batch' => 'пачка', 'tail' => 'после', 'rebuild' => 'в конце', '' => '—'];
    $sumDel = 0;
    foreach ($p['tables'] as $tb => $x) {
        $note = CatalogReset::LABELS[$tb] ?? '';
        if ($tb === 'admin_log') $note .= ' — не удаляются (действия сотрудников); машинных записей истории: ' . $x['quiet'];
        if ($tb === 'order_items') $note .= ' — снимок названия и цены остаётся';
        if ($tb === 'category_facets') $note = 'фильтры категорий — пересчитает rebuildAll';
        $line('  ' . $pad($tb, 18) . ' ' . $pad($acts[$x['action']] ?? $x['action'], 8) . ' ' . $pad($whenL[$x['where']] ?? '', 7) . ' ' . $pad($num($x['rows']), 9, true)
            . ' ' . $pad($num($x['deleted']), 9, true) . ' ' . $pad($num($x['orphans']), 10, true) . '  ' . $note);
        if (in_array($x['action'], ['delete'], true)) $sumDel += $x['deleted'] + ($x['where'] === 'tail' ? $x['orphans'] : 0);
    }
    $line('  Итого строк к удалению (с хвостами): ' . $num($sumDel));
    $e = $p['extra'];
    $line('  Прочее: редиректов на удаляемые товары ' . $e['redirects_to'] . ' (из них /product/… → в карту адресов ' . $e['redirects_alias'] . '), промокодов с удаляемыми товарами ' . $e['coupons']
        . ', баннеров со ссылкой на товар ' . $e['banners'] . ', ручных подборок ' . $e['sets_manual'] . ' (новинки/промо — по правилу), страниц/статей/категорий со ссылкой на товар ' . $e['content']);
    $line('  Избранное, сравнение, «Вы смотрели» — в cookie браузера (в базе нет): удалённые id просто не вернутся карточками.');
    $line();
    $l = $p['legacy'];
    $line('КАРТА СТАРЫХ АДРЕСОВ (legacy_products): строк ' . $num($l['rows']) . ' — /product/{url}/ и /ua/product/{url}/ → 301');
    $line('  с forsage-кодом (сопоставление с «Хит обуви» по коду) ' . $num($l['forsage']) . ', без основной категории ' . $num($l['no_category'])
        . ', в скрытой категории ' . $num($l['hidden_cats']));
    $line('  куда ведут сейчас (новых карточек ещё нет): своя видимая категория ' . $num($l['targets']['category']) . ', видимый предок ' . $num($l['targets']['parent'])
        . ', главная ' . $num($l['targets']['home']) . '; разных адресов-целей ' . $l['targets_distinct']);
    foreach ($l['top'] as $to => $n) $line('    ' . str_pad($num($n), 7, ' ', STR_PAD_LEFT) . '  ' . $to);
    $line('  уже в карте: ' . ($l['in_map'] === null ? 'таблицы нет (создаст запуск)' : $num($l['in_map']) . ' (ключи: название ' . $num($l['with_keys']['name'])
        . ', бренд+тип+артикул ' . $num($l['with_keys']['article']) . ', бренд+код ' . $num($l['with_keys']['code']) . ', код Tom.m/Jong•Golf ' . $num($l['with_keys']['own']) . ')'));
    if ($l['matched']) $line('  уже сопоставлено с оставляемыми карточками: ' . implode(', ', array_map(static fn($k, $v) => (LegacyProducts::VIA[$k] ?? $k) . ' ' . $num($v), array_keys($l['matched']), $l['matched']))
        . ' (дубли Tom.m под другим брендом → карточка Tom.m); остальное — после загрузки (cron, конец прохода «Хит обуви»)');
    $line();
    $f = $p['files'];
    $line('ФАЙЛЫ ФОТО (' . $f['root'] . '): папок товаров ' . $num($f['dirs']) . ', у удаляемых ' . $num($f['deleted_dirs'])
        . ' — при --confirm не трогаются, потом: --purge-files (файлы и объём — --purge-files --dry-run)');
    $em = $p['empty'];
    $line('ОПУСТЕЕТ до загрузки: категорий с товарами сейчас ' . $em['categories_now'] . ', останутся с товарами ' . $em['categories_left'] . ', станут пустыми ' . $em['categories_empty']
        . '; брендов на сайте сейчас ' . $em['brands_now'] . ', останется ' . $em['brands_left'] . ' (страница бренда без товаров — 404)');
    $line();
    $st = CatalogReset::state();
    if ($st && empty($st['finished_at'])) $line('ВНИМАНИЕ: прерванный запуск от ' . $st['started_at'] . ' (удалено ' . $num($st['deleted']) . ' из ' . $num($st['planned']) . ') — повтор с --confirm=' . $st['planned'] . ' доделает.');
    $line(sprintf('Проверка: %.1f с. Выполнить: php bin/catalog-reset.php --confirm=%d  (сначала — копия базы, см. docs/INSTALL.md «Пересборка каталога»)', microtime(true) - $t, $p['delete']));
} catch (\Throwable $e) {
    $say('ОШИБКА: ' . $e->getMessage());
    exit(1);
}
