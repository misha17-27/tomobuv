<?php
/**
 * Автозагрузка Jong•Golf из командной строки (замена wa_loader_jonggolf.php старого сайта).
 *
 *   php bin/supplier-jonggolf.php                 — по расписанию, как из cron: продолжить прерванный запуск или начать новый,
 *                                                   если автозагрузка включена, час в окне и прошёл период (bin/cron.php делает то же)
 *   php bin/supplier-jonggolf.php --now [--limit=N]   — боевой запуск сейчас, вне расписания (автозагрузка должна быть включена)
 *   php bin/supplier-jonggolf.php --test          — проверить подключение: только export_product_setting (у поставщика ничего не меняется)
 *   php bin/supplier-jonggolf.php --dry-run [--source=api|last|<файл.json>] [--dict=<product_setting.json>] [--limit=N]
 *                                                 — пробный прогон: в каталог не пишет, поставщику не подтверждает;
 *                                                   api — первая страница текущей очереди (без clean_export), last — страницы
 *                                                   последнего запуска, файл — сохранённый ответ export_product
 *   php bin/supplier-jonggolf.php --write --source=<файл.json> [--dict=…] [--limit=N]
 *                                                 — запись по сохранённому ответу поставщика (проверка записи; поставщику ничего не уходит)
 *   php bin/supplier-jonggolf.php --import-map=<category_table_jonggolf.xls|xlsx|csv>  — таблица соответствия категорий
 *   php bin/supplier-jonggolf.php --import-cfg=<wa_loader_jonggolf.cfg.php>            — настройки старого загрузчика (файл
 *                                                   только читается, не выполняется; логин и пароль старой админки не переносятся)
 *   php bin/supplier-jonggolf.php --relink        — пересобрать связи «код цвета → товар» (после bin/import-webasyst.php — сам)
 *   php bin/supplier-jonggolf.php --report=<ID>   — отчёт запуска
 *
 * cron (если нужен отдельно от bin/cron.php — например, чаще раза в час, как старый загрузчик):
 *   0,10,20,30,40,50 * * * * /usr/bin/php /home/USER/tomobuv/bin/supplier-jonggolf.php >> /home/USER/tomobuv/storage/logs/jonggolf-cron.log 2>&1
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

JongGolf::ensureSchema();

try {
    if (isset($opts['test'])) {
        $r = JongGolf::test(0, 'cli');
        $say($r['ok'] ? $r['message'] : 'Нет связи: ' . $r['error']);
        exit($r['ok'] ? 0 : 1);
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
        [$set, $skipped] = JongGolf::importOldConfig((string) file_get_contents($f));
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

    $files = [];
    $src = (string) ($opts['source'] ?? 'api');
    if ($src === 'last') {
        foreach (App::db()->col("SELECT id FROM supplier_runs WHERE supplier = ? AND src = 'api' AND kind IN ('run', 'dry') ORDER BY id DESC", [JongGolf::CODE]) as $rid) {
            $files = glob(JongGolf::dir((int) $rid) . '/page-*.json') ?: [];
            natsort($files);
            if ($files) { $say('Страницы запуска №' . $rid . ': ' . count($files)); break; }
        }
        if (!$files) $fail('нет сохранённых страниц прошлых запусков');
        $src = 'file';
    } elseif ($src !== 'api') {
        if (!is_file($src)) $fail('файл не найден: ' . $src);
        $files = [$src];
        $src = 'file';
    }
    $common = ['src' => $src, 'files' => array_values($files), 'dict_file' => $opts['dict'] ?? null, 'limit' => $limit, 'origin' => 'cli'];

    if (isset($opts['dry-run'])) {
        $id = JongGolf::start('dry', $common);
    } elseif (isset($opts['write'])) {
        if ($src !== 'file') $fail('--write — только с --source=<файл.json> (запись по сохранённому ответу; боевой запуск по API — --now)');
        $id = JongGolf::start('run', $common);
    } elseif (isset($opts['now'])) {
        if ($src !== 'api') $fail('--now — боевой запуск по API; для файла — --write');
        $id = JongGolf::start('run', ['src' => 'api', 'limit' => $limit, 'origin' => 'cli']);
    } else {
        JongGolf::cron($say);
        exit(0);
    }
    $say('Запуск №' . $id);
    $p = JongGolf::drive($id, $say);
    $run = JongGolf::run($id);
    if ($run && $run['status'] === 'error') $fail((string) $run['error']);
    $say('Отчёт: /admin/suppliers/jonggolf/runs/' . $id . '/  или  php bin/supplier-jonggolf.php --report=' . $id);
} catch (\Throwable $e) {
    $fail($e->getMessage());
}
