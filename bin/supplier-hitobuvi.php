<?php
/**
 * Загрузка товаров поставщика «Хит обуви» (hit-obuvi.com.ua) из командной строки.
 *
 *   php bin/supplier-hitobuvi.php                 — по расписанию, как из cron: продолжить прерванный запуск или начать новый,
 *                                                   если загрузка включена, задана наценка, час в окне и прошёл период (bin/cron.php делает то же)
 *   php bin/supplier-hitobuvi.php --now [--mode=quick|full] [--limit=N]
 *                                                 — боевой запуск сейчас, вне расписания (нужна наценка; автозагрузка может быть выключена)
 *   php bin/supplier-hitobuvi.php --load [--max-new=50000] [--max-requests=60000]
 *                                                 — первая (массовая) загрузка после пересборки каталога (D:\tomobuv.com.ua\load-hitobuvi.bat
 *                                                   запускает по кругу): продолжить прерванный боевой запуск (окно закрыли — любой
 *                                                   давности), иначе начать полный проход с лимитами только этого запуска
 *                                                   (0 — без ограничения; настройки не меняются); не дольше 6 ч за вызов.
 *                                                   Код выхода: 0 — есть что продолжать, 2 — всё загружено (полный проход без
 *                                                   отложенных по лимиту), 3 — запуск остановлен в админке, 1 — ошибка
 *   php bin/supplier-hitobuvi.php --test          — проверка связи: robots.txt и первая страница раздела (2 запроса)
 *   php bin/supplier-hitobuvi.php --dry-run [--source=web|last|<папка>] [--mode=quick|full] [--limit=N] [--ids=1,2] [--cache=<папка>]
 *                                                 — пробный прогон: в каталог не пишет; web — страницы с сайта поставщика (бережно:
 *                                                   пауза, robots.txt), last — страницы последнего запуска, <папка> — сохранённые
 *                                                   страницы (без сети); --cache — сквозной кэш ответов (повторно не скачивается)
 *   php bin/supplier-hitobuvi.php --write --source=<папка>|last [--ids=1,2] [--limit=N]
 *                                                 — запись по сохранённым страницам (проверка на своих данных; нужна наценка)
 *   php bin/supplier-hitobuvi.php --seed-map [--force]  — таблица категорий из черновика (database/suppliers/hitobuvi.php);
 *                                                   --force — заменить правки владельца черновиком
 *   php bin/supplier-hitobuvi.php --report=<ID>   — отчёт запуска
 *
 * cron — общий bin/cron.php (раз в час); отдельно:
 *   15 * * * * /usr/bin/php /home/USER/tomobuv/bin/supplier-hitobuvi.php >> /home/USER/tomobuv/storage/logs/hitobuvi-cron.log 2>&1
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Services\Suppliers\HitObuvi;
use App\Services\Suppliers\HitObuviMap;

if (PHP_SAPI !== 'cli') exit("Только из командной строки\n");

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? '1';
    else { fwrite(STDERR, "Непонятный параметр: $a\n"); exit(1); }
}
$say = static function (string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL; };
$fail = static function (string $msg) use ($say): never { $say('ОШИБКА: ' . $msg); exit(1); };
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;
$ids = isset($opts['ids']) ? array_values(array_filter(array_map('intval', explode(',', (string) $opts['ids'])))) : [];
$mode = (string) ($opts['mode'] ?? 'auto');
if (!in_array($mode, ['auto', 'quick', 'full'], true)) $fail('--mode=quick или --mode=full');

HitObuvi::ensureSchema();

try {
    if (isset($opts['test'])) {
        $r = HitObuvi::test(0, 'cli');
        $say($r['ok'] ? $r['message'] : 'Нет связи: ' . $r['error']);
        exit($r['ok'] ? 0 : 1);
    }
    if (isset($opts['seed-map'])) {
        $st = HitObuviMap::seed(isset($opts['force']));
        if (!$st['cats'] && !$st['types']) { $say('Таблица категорий уже заполнена — без --force не меняется'); exit(0); }
        $say('Таблица категорий: категорий поставщика ' . $st['cats'] . ', правил по типу ' . $st['types'] . ($st['unresolved'] ? '; нет на сайте: ' . implode(', ', $st['unresolved']) : ''));
        exit(0);
    }
    if (isset($opts['load'])) {
        // первая загрузка: прерванный боевой запуск — продолжить (его шаги двигает только этот процесс: блокировка запусков)
        $o = ['origin' => 'cli', 'src' => 'web', 'mode' => 'full',
            'max_new' => isset($opts['max-new']) ? max(0, (int) $opts['max-new']) : 50000,
            'max_requests' => isset($opts['max-requests']) ? max(100, (int) $opts['max-requests']) : 60000];
        $cur = App::db()->row("SELECT id FROM supplier_runs WHERE supplier = ? AND status = 'running' AND kind = 'run' AND src = 'web' ORDER BY id DESC LIMIT 1", [HitObuvi::CODE]);
        if ($cur) {
            $id = (int) $cur['id'];
            $say('Продолжаю запуск №' . $id);
            HitObuvi::log($id, 'info', 'Продолжение (bin/supplier-hitobuvi.php --load)');
            HitObuvi::flushLog();
        } else {
            $id = HitObuvi::start('run', $o);
            $say('Запуск №' . $id . ' (полный проход, новых — не больше ' . ($o['max_new'] ?: 'без ограничения') . ', запросов — не больше ' . $o['max_requests'] . ')');
        }
        HitObuvi::drive($id, $say, 6 * 3600.0);
        $run = HitObuvi::run($id) ?? $fail('запуск не найден');
        if ($run['status'] === 'error') $fail((string) $run['error']);
        $s = $run['stats'];
        $say('Запуск №' . $id . ': ' . HitObuvi::STATUSES[$run['status']] . ', добавлено ' . $s['created'] . ', изменено ' . $s['updated'] . ', запросов ' . $s['requests']
            . ', отложено по лимиту новых ' . (int) ($s['skip']['limit_new'] ?? 0));
        if ($run['status'] === 'running') exit(0);                            // время вышло / шаги у другого процесса — продолжить
        if ($run['status'] === 'stopped') { $say('Запуск остановлен («Остановить» в админке) — загрузка не продолжается'); exit(3); }
        $all = $run['status'] === 'done' && !empty($run['state']['complete']) && ($run['state']['mode'] ?? '') === 'full' && empty($s['skip']['limit_new']);
        if ($all) $say('Всё загружено: полный проход без отложенных по лимиту новых товаров');
        exit($all ? 2 : 0);
    }
    if (isset($opts['report'])) {
        $run = HitObuvi::run((int) $opts['report']) ?? $fail('запуск не найден');
        $say('Запуск №' . $run['id'] . ' (' . HitObuvi::KINDS[$run['kind']] . ', ' . HitObuvi::STATUSES[$run['status']] . ')');
        foreach (App::db()->query('SELECT level, message FROM supplier_run_log WHERE run_id = ? ORDER BY id', [(int) $run['id']]) as $l) echo str_pad($l['level'], 7) . ' ' . $l['message'] . PHP_EOL;
        exit(0);
    }

    $src = (string) ($opts['source'] ?? 'web');
    $o = ['origin' => 'cli', 'limit' => $limit, 'ids' => $ids, 'mode' => $mode];
    if ($src === 'last') {
        foreach (App::db()->col("SELECT id FROM supplier_runs WHERE supplier = ? AND src = 'web' AND kind IN ('run', 'dry') ORDER BY id DESC", [HitObuvi::CODE]) as $rid) {
            if (glob(HitObuvi::dir((int) $rid) . '/page-*.html')) { $o['src'] = 'file'; $o['dir'] = HitObuvi::dir((int) $rid); $say('Страницы запуска №' . $rid); break; }
        }
        if (empty($o['dir'])) $fail('нет сохранённых страниц прошлых запусков с сайта');
    } elseif ($src !== 'web') {
        if (!is_dir($src)) $fail('папка не найдена: ' . $src);
        $o['src'] = 'file';
        $o['dir'] = $src;
    } else {
        $o['src'] = 'web';
        if (isset($opts['cache'])) $o['cache'] = (string) $opts['cache'];
    }

    if (isset($opts['dry-run'])) {
        $id = HitObuvi::start('dry', $o);
    } elseif (isset($opts['write'])) {
        if (($o['src'] ?? 'web') !== 'file') $fail('--write — только с --source=<папка>|last (боевой запуск с сайта — --now)');
        $id = HitObuvi::start('run', $o);
    } elseif (isset($opts['now'])) {
        if (($o['src'] ?? 'web') !== 'web') $fail('--now — боевой запуск с сайта поставщика; для сохранённых страниц — --write');
        $id = HitObuvi::start('run', $o);
    } else {
        HitObuvi::cron($say);
        exit(0);
    }
    $say('Запуск №' . $id);
    HitObuvi::drive($id, $say, 6 * 3600.0);
    $run = HitObuvi::run($id);
    if ($run && $run['status'] === 'error') $fail((string) $run['error']);
    $say('Отчёт: /admin/suppliers/hitobuvi/runs/' . $id . '/  или  php bin/supplier-hitobuvi.php --report=' . $id);
} catch (\Throwable $e) {
    $fail($e->getMessage());
}
