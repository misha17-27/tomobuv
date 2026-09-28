<?php
/**
 * Импорт прайса поставщика из командной строки (для cron хостинга):
 *
 *   php bin/import.php <ID профиля> <файл или ссылка>   — загрузить файл и импортировать по настройкам профиля
 *   php bin/import.php <ID профиля>                      — взять файл по ссылке, сохранённой в профиле
 *   php bin/import.php --job=<ID задания>                — продолжить прерванное задание
 *
 * Пример cron (каждую ночь в 3:30):
 *   30 3 * * * /usr/bin/php /home/USER/tomobuv/bin/import.php 2 >> /home/USER/tomobuv/storage/logs/import-cron.log 2>&1
 * Профиль создаётся в админке: «Импорт / экспорт» → задание → «Сохранить как профиль».
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Log;
use App\Core\Str;
use App\Services\Import\Importer;
use App\Services\Import\Reader;

if (PHP_SAPI !== 'cli') exit("Только из командной строки\n");

$args = array_slice($argv, 1);
$opts = [];
foreach ($args as $i => $a) {
    if (preg_match('/^--(\w+)(?:=(.*))?$/', $a, $m)) { $opts[$m[1]] = $m[2] ?? '1'; unset($args[$i]); }
}
$args = array_values($args);
$say = static function (string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL; };
$fail = static function (string $msg) use ($say): never {
    $say('ОШИБКА: ' . $msg);
    Log::error('bin/import: ' . $msg);
    exit(1);
};

Importer::ensureSchema();
$db = App::db();

if (isset($opts['job'])) {
    $jobId = (int) $opts['job'];
    $job = Importer::job($jobId) ?? $fail('задание №' . $jobId . ' не найдено');
    $profileId = (int) $job['profile_id'];
} else {
    if (!$args || !ctype_digit($args[0])) {
        echo "Использование: php bin/import.php <ID профиля> [файл или ссылка]\n       php bin/import.php --job=<ID задания>\n\nПрофили:\n";
        foreach (Importer::profiles() as $p) printf("  %3d  %s%s\n", $p['id'], $p['name'], $p['options']['source_url'] !== '' ? '  (' . $p['options']['source_url'] . ')' : '');
        exit(1);
    }
    $profileId = (int) $args[0];
    $profile = Importer::profile($profileId) ?? $fail('профиль №' . $profileId . ' не найден');
    $popt = json_decode((string) $profile['options'], true) ?: [];
    $src = $args[1] ?? (string) ($popt['source_url'] ?? '');
    if ($src === '') $fail('не указан файл, и в профиле нет ссылки на файл поставщика');
}

// один импорт профиля за раз (cron не запустит второй, пока идёт первый)
$lockFile = Importer::dir() . '/cli-' . ($profileId ?: 'job' . ($jobId ?? 0)) . '.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) $fail('импорт этого профиля уже идёт');

if (!isset($jobId)) {
    // 1. файл: по ссылке или локальный путь → storage/import
    $tmp = Importer::dir() . '/tmp/' . Str::random(8) . '.cli';
    if (preg_match('#^https?://#i', $src)) {
        $url = Importer::cleanSourceUrl($src);
        if ($url === '') $fail('неверная ссылка: ' . $src);
        $say('Скачиваю ' . $url);
        try { Importer::download($url, $tmp); } catch (\Throwable $e) { @unlink($tmp); $fail($e->getMessage()); }
        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) ?: (string) parse_url($url, PHP_URL_HOST);
        if (!isset(Reader::EXTENSIONS[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
            $head = (string) file_get_contents($tmp, false, null, 0, 512);
            $name .= str_starts_with($head, "PK\x03\x04") ? '.xlsx' : (str_starts_with(ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $head)), '<') ? '.xml' : '.csv');
        }
    } else {
        if (!is_file($src) || !is_readable($src)) $fail('файл не найден: ' . $src);
        if (filesize($src) > Importer::maxFileSize()) $fail('файл больше ' . round(Importer::maxFileSize() / 1048576) . ' МБ');
        $name = basename($src);
        if (!copy($src, $tmp)) $fail('не удалось скопировать файл');
    }
    $format = Reader::detect($tmp, $name);
    if ($format === null) { @unlink($tmp); $fail('файл не похож на CSV / XLSX / XML: ' . $name); }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $path = Importer::dir() . '/' . date('Ymd-His') . '-' . Str::random(4) . '.' . ($ext === 'yml' ? 'yml' : ($ext === 'tsv' || $ext === 'txt' ? 'csv' : $ext));
    rename($tmp, $path);
    try { $meta = Reader::prepare($format, $path, $popt); } catch (\Throwable $e) { @unlink($path); $fail($e->getMessage()); }
    if (preg_match('#^https?://#i', $src)) $meta['source_url'] = $src;
    $jobId = Importer::createJob($path, $name, $format, $meta, $profile, 0);
    $say(sprintf('Задание №%d: %s (%s, %.1f МБ), профиль «%s»', $jobId, $name, $format, filesize($path) / 1048576, $profile['name']));
}

// 2. шаги: разбор → проверка настроек → обработка → завершение → фото
$t0 = microtime(true);
$lastPct = -1;
$steps = 0;
while (true) {
    $job = Importer::job($jobId);
    if (!$job) $fail('задание исчезло');
    if ($job['status'] === 'new') {
        $problems = Importer::validateStart($job);
        if ($problems) $fail("настройки профиля не подходят к файлу:\n  - " . implode("\n  - ", $problems) . "\n  Откройте задание в админке: /admin/import/$jobId/");
        $db->query("UPDATE import_jobs SET status = 'running', started_at = NOW(), updated_at = NOW() WHERE id = ?", [$jobId]);
        $say(sprintf('Файл разобран: %d строк. Импорт…', $job['total']));
        continue;
    }
    if (in_array($job['status'], ['done', 'error'], true)) break;
    $r = Importer::step($jobId, 25.0);
    $steps++;
    if (!empty($r['busy'])) { sleep(2); continue; }                        // шаг выполняет админка
    if (isset($r['ok']) && $r['ok'] === false && ($r['status'] ?? '') !== 'error') {
        $say('Шаг не выполнен: ' . ($r['error'] ?? '?') . ' — повтор через 5 с');
        if ($steps > 5000) $fail('слишком много ошибок');
        sleep(5);
        continue;
    }
    $pct = (int) ($r['percent'] ?? 0);
    if (in_array($r['status'] ?? '', ['parsing', 'new'], true)) {                // разбор файла: только число прочитанных строк
        if (($r['status'] ?? '') === 'parsing') $say('Разбор файла: прочитано строк ' . (int) ($r['total'] ?? 0));
        continue;
    }
    if ($pct !== $lastPct && ($pct % 10 === 0 || in_array($r['status'] ?? '', ['finishing', 'images', 'done'], true))) {
        $say(sprintf('%s: %d%% — строк %d/%d, создано %d, обновлено %d, без изменений %d, пропущено %d, ошибок %d',
            $r['label'] ?? '', $pct, $r['processed'] ?? 0, $r['total'] ?? 0, $r['created'] ?? 0, $r['updated'] ?? 0,
            $r['unchanged'] ?? 0, $r['skipped'] ?? 0, $r['errors'] ?? 0));
        $lastPct = $pct;
    }
}

$job = Importer::job($jobId);
Importer::cleanup();                                                        // старые задания и временные файлы
flock($lock, LOCK_UN);
fclose($lock);
@unlink($lockFile);
if ($job['status'] === 'error') $fail((string) $job['errors']);
$conflicts = (int) $db->value('SELECT COUNT(*) FROM import_seen WHERE job_id = ? AND conflict = 1', [$jobId]);   // изменены на сайте после выгрузки
$say(sprintf('Готово за %.1f с: создано %d, обновлено %d, без изменений %d, пропущено %d, ошибок %d%s%s. Журнал: /admin/import/%d/log/',
    microtime(true) - $t0, $job['created'], $job['updated'], $job['unchanged'], $job['skipped'] - $conflicts, $job['error_count'],
    isset($job['state']['hidden']) ? ', скрыто ' . (int) $job['state']['hidden'] : '',
    $conflicts ? ', изменены на сайте после выгрузки (не перезаписаны) ' . $conflicts : '', $jobId));
Log::info(sprintf('bin/import: задание №%d готово — создано %d, обновлено %d, ошибок %d', $jobId, $job['created'], $job['updated'], $job['error_count']));
exit(0);
