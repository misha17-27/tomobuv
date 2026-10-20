<?php
/**
 * Загрузка товаров бренда Tom.m с официального сайта tomm.ua из командной строки.
 *
 *   php bin/supplier-tomm.php                 — по расписанию, как из cron: продолжить прерванный запуск или начать новый,
 *                                               если загрузка включена, задана наценка, час в окне и прошёл период (bin/cron.php делает то же)
 *   php bin/supplier-tomm.php --now [--mode=quick|full] [--limit=N]
 *                                             — боевой запуск сейчас, вне расписания (нужна наценка; автозагрузка может быть выключена)
 *   php bin/supplier-tomm.php --test          — проверка связи: robots.txt и первая страница списка (2 запроса)
 *   php bin/supplier-tomm.php --dry-run [--source=web|last|<папка>] [--mode=quick|full] [--limit=N] [--ids=24334,24332] [--cache=<папка>]
 *                                             — пробный прогон: в каталог не пишет; web — страницы с сайта бренда (бережно: пауза,
 *                                               robots.txt), last — страницы последнего запуска, <папка> — сохранённые страницы
 *                                               (кэш разбора: поиск с products_per_page, без сети); --cache — сквозной кэш ответов
 *   php bin/supplier-tomm.php --write --source=<папка>|last [--ids=…] [--limit=N]
 *                                             — запись по сохранённым страницам (проверка на своих данных; нужна наценка; не скрывает)
 *   php bin/supplier-tomm.php --seed-map [--force]  — таблица разделов из черновика (database/suppliers/tomm.php);
 *                                               --force — заменить правки владельца черновиком
 *   php bin/supplier-tomm.php --report=<ID>   — отчёт запуска
 *
 * cron — общий bin/cron.php (раз в час); отдельно (необязательно):
 *   20 * * * * /usr/bin/php /home/USER/tomobuv/bin/supplier-tomm.php >> /home/USER/tomobuv/storage/logs/tomm-cron.log 2>&1
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Services\Suppliers\Tomm;
use App\Services\Suppliers\TommMap;

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

Tomm::ensureSchema();

try {
    if (isset($opts['test'])) {
        $r = Tomm::test(0, 'cli');
        $say($r['ok'] ? $r['message'] : 'Нет связи: ' . $r['error']);
        exit($r['ok'] ? 0 : 1);
    }
    if (isset($opts['seed-map'])) {
        $st = TommMap::seed(isset($opts['force']));
        if (!$st['cats']) { $say('Таблица разделов уже заполнена — без --force не меняется'); exit(0); }
        $say('Таблица разделов: разделов бренда ' . $st['cats'] . ($st['unresolved'] ? '; нет на сайте: ' . implode(', ', $st['unresolved']) : ''));
        exit(0);
    }
    if (isset($opts['report'])) {
        $run = Tomm::run((int) $opts['report']) ?? $fail('запуск не найден');
        $say('Запуск №' . $run['id'] . ' (' . Tomm::KINDS[$run['kind']] . ', ' . Tomm::STATUSES[$run['status']] . ')');
        foreach (App::db()->query('SELECT level, message FROM supplier_run_log WHERE run_id = ? ORDER BY id', [(int) $run['id']]) as $l) echo str_pad($l['level'], 7) . ' ' . $l['message'] . PHP_EOL;
        exit(0);
    }

    $src = (string) ($opts['source'] ?? 'web');
    $o = ['origin' => 'cli', 'limit' => $limit, 'ids' => $ids, 'mode' => $mode];
    if ($src === 'last') {
        foreach (App::db()->col("SELECT id FROM supplier_runs WHERE supplier = ? AND src = 'web' AND kind IN ('run', 'dry') ORDER BY id DESC", [Tomm::CODE]) as $rid) {
            if (Tomm::savedPages(Tomm::dir((int) $rid))) { $o['src'] = 'file'; $o['dir'] = Tomm::dir((int) $rid); $say('Страницы запуска №' . $rid); break; }
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
        $id = Tomm::start('dry', $o);
    } elseif (isset($opts['write'])) {
        if (($o['src'] ?? 'web') !== 'file') $fail('--write — только с --source=<папка>|last (боевой запуск с сайта — --now)');
        $id = Tomm::start('run', $o);
    } elseif (isset($opts['now'])) {
        if (($o['src'] ?? 'web') !== 'web') $fail('--now — боевой запуск с сайта бренда; для сохранённых страниц — --write');
        $id = Tomm::start('run', $o);
    } else {
        Tomm::cron($say);
        exit(0);
    }
    $say('Запуск №' . $id);
    Tomm::drive($id, $say, 6 * 3600.0);
    $run = Tomm::run($id);
    if ($run && $run['status'] === 'error') $fail((string) $run['error']);
    $say('Отчёт: /admin/suppliers/tomm/runs/' . $id . '/  или  php bin/supplier-tomm.php --report=' . $id);
} catch (\Throwable $e) {
    $fail($e->getMessage());
}
