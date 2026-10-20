<?php
/**
 * Загрузка товаров Jong•Golf из командной строки. Источник — настройка «jonggolf.source»: сайт бренда www.jonggolf.com
 * (по умолчанию) или API бренда (замена wa_loader_jonggolf.php старого сайта); связи, категории и «Обзор» у них общие.
 *
 *   php bin/supplier-jonggolf.php                 — по расписанию, как из cron: продолжить прерванный запуск или начать новый,
 *                                                   если автозагрузка включена, час в окне и прошёл период (bin/cron.php делает то же)
 *   php bin/supplier-jonggolf.php --now [--limit=N] [--aids=B40678-3,…]
 *                                                 — боевой запуск сейчас, вне расписания, из источника в настройках: сайт бренда —
 *                                                   нужна наценка (автозагрузка может быть выключена), API — автозагрузка включена
 *   php bin/supplier-jonggolf.php --test          — проверка связи: сайт бренда — robots.txt и первая страница каталога;
 *                                                   API — только export_product_setting (у поставщика ничего не меняется)
 *   php bin/supplier-jonggolf.php --dry-run [--source=…] [--limit=N] [--aids=…] [--cache=<папка>] [--dict=<product_setting.json>]
 *                                                 — пробный прогон: в каталог не пишет, поставщику не подтверждает. --source:
 *                                                   web — сайт бренда (весь каталог в наличии — 1 запрос; --cache — сквозной кэш ответов:
 *                                                   есть — не качать снова), site-last — список последнего запуска с сайта, <папка> —
 *                                                   сохранённые страницы сайта (list-1.html прошлого запуска или кэш ответов SiteClient),
 *                                                   api — первая страница очереди API (без clean_export; только при источнике «API»), last — страницы последнего
 *                                                   запуска по API, <файл.json> — сохранённый ответ export_product. По умолчанию — источник настроек
 *   php bin/supplier-jonggolf.php --write --source=<папка>|site-last|<файл.json> [--aids=…] [--limit=N]
 *                                                 — запись по сохранённым данным (проверка записи на своих данных; сеть не используется,
 *                                                   поставщику ничего не уходит; сайт бренда — нужна наценка, ничего не скрывает)
 *   --mode=quick|full                             — для совместимости с другими загрузчиками: с сайта бренда каждый проход полный
 *   php bin/supplier-jonggolf.php --seed-map [--force]  — разделы сайта бренда из черновика database/suppliers/jonggolf.php
 *                                                   (--force — заменить правки владельца черновиком)
 *   php bin/supplier-jonggolf.php --import-map=<category_table_jonggolf.xls|xlsx|csv>  — таблица сочетаний (старый загрузчик)
 *   php bin/supplier-jonggolf.php --import-cfg=<wa_loader_jonggolf.cfg.php>            — настройки старого загрузчика (файл
 *                                                   только читается, не выполняется; логин и пароль старой админки не переносятся)
 *   php bin/supplier-jonggolf.php --relink        — пересобрать связи «код цвета → товар» (после bin/import-webasyst.php — сам)
 *   php bin/supplier-jonggolf.php --report=<ID>   — отчёт запуска
 *
 * cron — общий bin/cron.php (раз в час); отдельно (необязательно):
 *   40 * * * * /usr/bin/php /home/USER/tomobuv/bin/supplier-jonggolf.php >> /home/USER/tomobuv/storage/logs/jonggolf-cron.log 2>&1
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Services\Suppliers\JongGolf;
use App\Services\Suppliers\JongGolfMap;

if (PHP_SAPI !== 'cli') exit("Только из командной строки\n");

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? '1';
    else { fwrite(STDERR, "Непонятный параметр: $a\n"); exit(1); }
}
$say = static function (string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL; };
$fail = static function (string $msg) use ($say): never { $say('ОШИБКА: ' . $msg); exit(1); };
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;
$aids = isset($opts['aids']) ? array_values(array_filter(array_map('trim', explode(',', (string) $opts['aids'])))) : [];
$mode = (string) ($opts['mode'] ?? 'auto');
if (!in_array($mode, ['auto', 'quick', 'full'], true)) $fail('--mode=quick или --mode=full');

JongGolf::ensureSchema();

try {
    if (isset($opts['test'])) {
        $r = JongGolf::test(0, 'cli');
        $say($r['ok'] ? $r['message'] : 'Нет связи: ' . $r['error']);
        exit($r['ok'] ? 0 : 1);
    }
    if (isset($opts['seed-map'])) {
        $st = JongGolfMap::seedSections(isset($opts['force']));
        $say($st['added'] ? 'Разделов сайта бренда из черновика: ' . $st['added'] . ($st['unresolved'] ? '; категорий нет на сайте: ' . implode(', ', $st['unresolved']) : '')
            : 'Разделы сайта бренда уже заполнены — без --force не меняются');
        exit(0);
    }
    if (isset($opts['import-map'])) {
        $f = (string) $opts['import-map'];
        if (!is_file($f)) $fail('файл не найден: ' . $f);
        $st = JongGolfMap::import(JongGolfMap::readFile($f, basename($f)));
        $say(sprintf('Таблица соответствия загружена: строк %d, сочетаний %d (повторов %d, из них с другой категорией %d), без категории сайта %d',
            $st['rows'], $st['keys'], $st['duplicates'], count($st['conflicts']), $st['unresolved']));
        foreach ($st['conflicts'] as $c) $say('  повтор: ' . $c);
        foreach ($st['paths_missing'] as $p => $n) $say('  нет на сайте: ' . $p . ' (' . $n . ')');
        exit(0);
    }
    if (isset($opts['import-cfg'])) {
        $f = (string) $opts['import-cfg'];
        if (!is_file($f)) $fail('файл не найден: ' . $f);
        [$set, $skipped] = JongGolf::importOldConfig($f);
        $say('Перенесено настроек: ' . count($set) . ' (' . implode(', ', array_keys($set)) . ')');
        if ($skipped) $say('Не переносятся: ' . implode(', ', $skipped));
        $say('Автозагрузка остаётся ' . (JongGolf::on('enabled') ? 'ВКЛЮЧЁННОЙ' : 'выключенной') . ' — включите её в админке после отключения cron старого сайта.');
        exit(0);
    }
    if (isset($opts['relink'])) {
        $say('Связей «код цвета → товар»: ' . JongGolf::relink());
        exit(0);
    }
    if (isset($opts['report'])) {
        $run = JongGolf::run((int) $opts['report']) ?? $fail('запуск не найден');
        $say('Запуск №' . $run['id'] . ' (' . JongGolf::KINDS[$run['kind']] . ', ' . JongGolf::STATUSES[$run['status']] . ')');
        foreach (App::db()->query('SELECT level, message FROM supplier_run_log WHERE run_id = ? ORDER BY id', [(int) $run['id']]) as $l) echo str_pad($l['level'], 7) . ' ' . $l['message'] . PHP_EOL;
        exit(0);
    }
    if ($mode === 'quick') $say('С сайта бренда каждый проход полный (весь каталог — 1 запрос) — --mode=quick не нужен');

    // источник: сайт бренда (web | site-last | папка) или API (api | last | файл.json); по умолчанию — из настроек
    $source = (string) ($opts['source'] ?? (JongGolf::siteSource() ? 'web' : 'api'));
    $o = ['origin' => 'cli', 'limit' => $limit];
    if ($source === 'web') {
        $o['src'] = 'web';
        if (isset($opts['cache'])) $o['cache'] = (string) $opts['cache'];
        if ($aids) $o['aids'] = $aids;
    } elseif ($source === 'site-last' || is_dir($source)) {
        $dir = $source === 'site-last' ? JongGolf::lastSiteDir() : $source;
        if ($dir === null) $fail('нет сохранённого списка прошлых запусков с сайта бренда');
        $say('Сохранённые страницы сайта бренда: ' . $dir);
        $o += ['src' => 'pages', 'dir' => $dir];
        if ($aids) $o['aids'] = $aids;
    } elseif ($source === 'api') {
        $o['src'] = 'api';
    } elseif ($source === 'last') {
        $files = [];
        foreach (App::db()->col("SELECT id FROM supplier_runs WHERE supplier = ? AND src = 'api' AND kind IN ('run', 'dry') ORDER BY id DESC", [JongGolf::CODE]) as $rid) {
            $files = glob(JongGolf::dir((int) $rid) . '/page-*.json') ?: [];
            natsort($files);
            if ($files) { $say('Страницы запуска №' . $rid . ': ' . count($files)); break; }
        }
        if (!$files) $fail('нет сохранённых страниц прошлых запусков по API');
        $o += ['src' => 'file', 'files' => array_values($files)];
    } elseif (is_file($source)) {
        $o += ['src' => 'file', 'files' => [$source]];
    } else {
        $fail('--source: web, site-last, папка сохранённых страниц, api, last или файл .json (не найдено: ' . $source . ')');
    }
    if (($o['src'] ?? '') === 'file') $o['dict_file'] = $opts['dict'] ?? null;

    if (isset($opts['dry-run'])) {
        $id = JongGolf::start('dry', $o);
    } elseif (isset($opts['write'])) {
        if (!in_array($o['src'], ['pages', 'file'], true)) $fail('--write — только по сохранённым данным: --source=<папка>|site-last|<файл.json> (боевой запуск — --now)');
        $id = JongGolf::start('run', $o);
    } elseif (isset($opts['now'])) {
        if (!in_array($o['src'], ['web', 'api'], true)) $fail('--now — боевой запуск из источника настроек; для сохранённых данных — --write');
        if (($o['src'] === 'web') !== JongGolf::siteSource()) $fail('--now — только из источника в настройках (сейчас: ' . (JongGolf::siteSource() ? 'сайт бренда' : 'API') . ')');
        $id = JongGolf::start('run', $o);
    } else {
        JongGolf::cron($say);
        exit(0);
    }
    $say('Запуск №' . $id);
    JongGolf::drive($id, $say, 6 * 3600.0);
    $run = JongGolf::run($id);
    if ($run && $run['status'] === 'error') $fail((string) $run['error']);
    $say('Отчёт: /admin/suppliers/jonggolf/runs/' . $id . '/  или  php bin/supplier-jonggolf.php --report=' . $id);
} catch (\Throwable $e) {
    $fail($e->getMessage());
}
