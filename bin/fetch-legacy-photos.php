<?php
/**
 * Фото товаров со старого сайта (Webasyst) — в локальные оригиналы, чтобы сайт не зависел от images.remote_base.
 * Оригинал кладётся туда, где его ждёт код (Image::originalPath):
 *   public/wa-data/protected/shop/products/{id%100}/{id/100%100}/{id}/images/{image_id}.{ext}
 * Миниатюры (400, 750x0, 970, 96x96…) сайт делает из него сам при первом обращении (ImageController).
 * Берутся все строки product_images товаров сайта, у которых файла оригинала ещё нет (уже лежащие не трогаются — кэш).
 *
 * Источник — один из:
 *   --from-dir=ПАПКА  копия старого wa-data: корень старого сайта, папка wa-data, wa-data/protected или
 *                     wa-data/protected/shop/products. Настоящие оригиналы, без сети (архив со старого хостинга).
 *   --base=URL        старый сайт по HTTP (по умолчанию images.remote_base): самый крупный общедоступный размер
 *                     {URL}/wa-data/public/shop/products/{…}/{id}/images/{image_id}/{image_id}.970.{ext} — у Webasyst
 *                     image_max_size = 970 и маленькие фото не увеличиваются, так что для фото до 970 px это полный размер;
 *                     оригиналы в wa-data/protected по HTTP закрыты. Бережно: один поток, пауза --pause (≥ 0,5 с),
 *                     User-Agent TomobuvLoader, повтор при сбое (429/5xx/таймаут — до 3 повторов с паузами 5, 15, 30 с),
 *                     3 фото подряд без ответа — остановка запуска; 404 — фото записывается в «не скачалось» и больше не запрашивается.
 *
 *   php bin/fetch-legacy-photos.php --stats                  — сколько фото, сколько на месте, сколько не скачалось и почему
 *   php bin/fetch-legacy-photos.php --from-dir=… [--dry-run]
 *   php bin/fetch-legacy-photos.php --base=https://… [--dry-run] [--limit=500] [--time=240] [--pause=1]
 *   другие параметры: --product=ID (только этот товар), --retry-failed (снова попробовать не скачавшиеся из этого источника)
 *
 * Порциями и с продолжением: запуск берёт не больше --limit фото (HTTP — 500, папка — без ограничения) и не дольше --time
 * секунд (240 — под задание Cron Jobs раз в 5 минут; 0 — без ограничения), следующий продолжает. Два запуска сразу
 * не идут (блокировка). Состояние — storage/fetch-legacy-photos.json. После добавленных фото сбрасывается кэш страниц.
 * Код выхода: 0 — порция сделана, ещё есть что переносить; 2 — переносить больше нечего (всё на месте или не скачалось); 1 — ошибка.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;

if (PHP_SAPI !== 'cli') exit("Только из командной строки\n");
const KNOWN = ['from-dir', 'base', 'limit', 'time', 'pause', 'product', 'retry-failed', 'dry-run', 'stats', 'help'];
const UA = 'TomobuvLoader/1.0 (+https://tomobuv.com.ua)';
const SIZE = '970';                     // самый крупный размер, который отдаёт старый сайт (image_max_size Webasyst)
const MAX_BYTES = 15 * 1024 * 1024;     // больше — не фото товара
const RETRY_WAIT = [5, 15, 30];         // паузы между попытками при сбое (с)
const STATE = STORAGE . '/fetch-legacy-photos.json';

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $a, $m) && in_array($m[1], KNOWN, true)) $opts[$m[1]] = $m[2] ?? '1';
    else { fwrite(STDERR, "Непонятный параметр: $a (см. --help)\n"); exit(1); }
}
if (isset($opts['help'])) {
    preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $m);
    echo preg_replace('/^\s*\* ?/m', '', trim($m[1])) . PHP_EOL;
    exit(0);
}
$say = static function (string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL; };
$num = static fn($n): string => number_format((int) $n, 0, ',', ' ');
$mb = static fn(int $b): string => number_format($b / 1048576, 1, ',', ' ') . ' МБ';

/** Состояние: не скачавшиеся по источникам [источник => [image_id => причина]] и итоги последних запусков */
$loadState = static function (): array {
    $s = is_file(STATE) ? json_decode((string) file_get_contents(STATE), true) : null;
    return is_array($s) ? $s + ['failed' => [], 'runs' => []] : ['failed' => [], 'runs' => []];
};
$saveState = static function (array $s): void {
    $tmp = STATE . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if (!@rename($tmp, STATE)) { @unlink(STATE); rename($tmp, STATE); }
};

/** Папка с оригиналами Webasyst (…/protected/shop/products) из любого уровня копии старого сайта */
$productsDir = static function (string $dir): ?string {
    if (str_starts_with($dir, '~/') && ($home = getenv('HOME') ?: getenv('USERPROFILE'))) $dir = $home . substr($dir, 1);
    $dir = rtrim(strtr($dir, '\\', '/'), '/');
    foreach (['/wa-data/protected/shop/products', '/protected/shop/products', '/shop/products', ''] as $tail) {
        $d = $dir . $tail;
        if (is_dir($d) && basename($d) === 'products' && glob($d . '/[0-9][0-9]', GLOB_ONLYDIR)) return $d;
    }
    return null;
};

/** Картинка ли это и того ли она типа, что расширение в базе: [ширина, высота] или причина отказа */
$checkImage = static function (string $data, string $ext): array|string {
    $info = @getimagesizefromstring($data);
    if (!$info) return 'не картинка';
    $want = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][strtolower($ext)] ?? '';
    if ($info['mime'] !== $want) return 'тип ' . $info['mime'] . ' вместо ' . ($want ?: $ext);
    return [(int) $info[0], (int) $info[1]];
};

/**
 * Один GET с бережным клиентом: ['code' => HTTP-код (0 — нет ответа), 'body' => тело, 'type' => Content-Type,
 * 'retry' => Retry-After (с), 'cf' => проверка Cloudflare, 'error' => текст ошибки сети]
 */
$httpGet = static function (string $url): array {
    $h = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => UA,
            CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/*;q=0.9,*/*;q=0.5'],
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$h): int {
                if (str_starts_with($line, 'HTTP/')) $h = [];   // после редиректа — заголовки последнего ответа
                elseif (($p = strpos($line, ':')) !== false) $h[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn($c, $dlTotal, $dlNow): int => $dlNow > MAX_BYTES ? 1 : 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $body === false ? curl_error($ch) : '';
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 60, 'ignore_errors' => true, 'follow_location' => 1,
            'max_redirects' => 3, 'header' => "User-Agent: " . UA . "\r\nAccept: image/*\r\n"]]);
        $body = @file_get_contents($url, false, $ctx, 0, MAX_BYTES + 1);
        $code = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $code = (int) $m[1]; $h = []; }
            elseif (($p = strpos($line, ':')) !== false) $h[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
        }
        $err = $body === false ? 'нет ответа' : '';
    }
    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'type' => (string) ($h['content-type'] ?? ''),
        'retry' => ctype_digit((string) ($h['retry-after'] ?? '')) ? (int) $h['retry-after'] : 0,
        'cf' => isset($h['cf-mitigated']), 'error' => $err];
};

/** Записать файл атомарно (временный рядом → переименование): посетитель не увидит полузаписанное фото */
$put = static function (string $dst, string $data, ?int $mtime = null): bool {
    @mkdir(dirname($dst), 0775, true);
    $tmp = $dst . '.' . getmypid() . '.part';
    if (file_put_contents($tmp, $data) !== strlen($data)) { @unlink($tmp); return false; }
    if ($mtime) @touch($tmp, $mtime);
    if (!@rename($tmp, $dst)) { @unlink($tmp); return false; }
    return true;
};

try {
    $lock = fopen(STORAGE . '/fetch-legacy-photos.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $say('Перенос фото уже идёт в другом процессе — пропуск'); exit(0); }
    $state = $loadState();
    $db = App::db();

    // все фото товаров сайта (строки без товара не берём); у кого оригинала нет — очередь
    $where = isset($opts['product']) ? ' WHERE i.product_id = ?' : '';
    $rows = $db->all('SELECT i.id, i.product_id, i.ext, i.width, i.height FROM product_images i
        JOIN products p ON p.id = i.product_id' . $where . ' ORDER BY i.id', isset($opts['product']) ? [(int) $opts['product']] : []);
    $have = 0; $haveBytes = 0; $missing = [];
    foreach ($rows as $r) {
        $f = Image::originalPath((int) $r['product_id'], (int) $r['id'], (string) $r['ext']);
        if (is_file($f)) { $have++; $haveBytes += (int) filesize($f); } else $missing[] = $r;
    }

    if (isset($opts['stats'])) {
        $say('Фото товаров сайта: ' . $num(count($rows)) . ' (товаров ' . $num(count(array_unique(array_column($rows, 'product_id')))) . ')'
            . '; оригинал на месте: ' . $num($have) . ' (' . $mb($haveBytes) . '); нет оригинала: ' . $num(count($missing)));
        $miss = array_flip(array_map(static fn($r) => (string) $r['id'], $missing));
        foreach ($state['failed'] as $src => $list) {
            $list = array_intersect_key($list, $miss);   // уже перенесённые из другого источника — не считаем
            if (!$list) continue;
            $by = array_count_values($list); arsort($by);
            $say('  не скачалось из ' . $src . ': ' . $num(count($list)) . ' — ' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . $num($v), array_keys($by), $by)));
        }
        foreach (array_slice($state['runs'], -3) as $run) $say('  запуск ' . $run['at'] . ': ' . $run['summary']);
        exit(0);
    }

    // источник
    if (isset($opts['from-dir'])) {
        $dir = $productsDir((string) $opts['from-dir']);
        if ($dir === null) throw new RuntimeException('В ' . $opts['from-dir'] . ' нет папки wa-data/protected/shop/products со старого сайта');
        $source = 'dir:' . $dir;
    } else {
        $base = rtrim((string) ($opts['base'] ?? App::config('images.remote_base', '')), '/');
        if ($base === '') throw new RuntimeException('Укажите источник: --from-dir=папка копии wa-data или --base=адрес старого сайта (images.remote_base пуст)');
        if (!preg_match('~^https?://[^/?#]+$~i', $base)) throw new RuntimeException('--base — только адрес сайта: https://старый-адрес (без пути)');
        // адрес самого сайта — это уже новый сайт, а не старый: фото, которых нет здесь, он не отдаст
        $host = static fn(string $u): string => preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
        if ($host($base) === $host((string) App::config('base_url', ''))) {
            throw new RuntimeException($base . ' — адрес этого же сайта. Нужен адрес, где ещё работает старый сайт (временный адрес старого хостинга), или --from-dir');
        }
        $source = $base;
    }
    $failed = $state['failed'][$source] ?? [];
    if (isset($opts['retry-failed'])) $failed = [];
    $queue = array_values(array_filter($missing, static fn($r) => !isset($failed[(string) $r['id']])));
    $http = !str_starts_with($source, 'dir:');
    $limit = (int) ($opts['limit'] ?? ($http ? 500 : 0));
    $budget = (float) ($opts['time'] ?? 240);
    $pause = max(0.5, (float) ($opts['pause'] ?? 1));
    $srcOf = static fn(array $r): string => $http
        ? $source . '/wa-data/public/shop/products/' . Image::productDir((int) $r['product_id']) . '/images/' . $r['id'] . '/' . $r['id'] . '.' . SIZE . '.' . $r['ext']
        : substr($source, 4) . '/' . Image::productDir((int) $r['product_id']) . '/images/' . $r['id'] . '.' . $r['ext'];

    $say('Фото товаров сайта: ' . $num(count($rows)) . ', оригинал на месте: ' . $num($have) . ', нет: ' . $num(count($missing))
        . ', из них уже не скачалось из этого источника: ' . $num(count($missing) - count($queue)) . ' → к переносу ' . $num(count($queue))
        . ($limit > 0 && count($queue) > $limit ? ' (за этот запуск — до ' . $num($limit) . ')' : ''));
    $say('Источник: ' . ($http ? $source . ' (размер ' . SIZE . ', пауза ' . $pause . ' с)' : substr($source, 4)));
    if (isset($opts['dry-run'])) {
        foreach (array_slice($queue, 0, 5) as $r) $say('  ' . $srcOf($r) . ' → ' . Image::originalPath((int) $r['product_id'], (int) $r['id'], (string) $r['ext']));
        $say('Пробный режим — ничего не записано');
        exit(0);
    }

    $t0 = microtime(true);
    $done = 0; $bytes = 0; $why = []; $resized = 0; $smaller = 0; $tried = 0; $inRow = 0; $stop = ''; $transient = 0;
    foreach ($queue as $r) {
        if ($limit > 0 && $tried >= $limit) break;
        if ($budget > 0 && microtime(true) - $t0 > $budget) { $stop = 'время запуска (' . $budget . ' с) вышло'; break; }
        $tried++;
        $pid = (int) $r['product_id']; $iid = (int) $r['id']; $ext = (string) $r['ext'];
        $dst = Image::originalPath($pid, $iid, $ext);
        $src = $srcOf($r);
        $fail = null; $data = null; $mtime = null;
        if (!$http) {
            if (!is_file($src)) $fail = 'нет в папке';
            elseif (filesize($src) > MAX_BYTES) $fail = 'больше 15 МБ';
            else { $data = (string) file_get_contents($src); $mtime = (int) filemtime($src); }
        } else {
            if ($tried > 1) usleep((int) ($pause * 1e6));
            for ($try = 0; ; $try++) {
                $res = $httpGet($src);
                $ok = $res['code'] === 200 && $res['body'] !== '';
                $soft = !$ok && ($res['code'] === 0 || $res['code'] === 429 || $res['code'] >= 500);   // сбой — стоит повторить
                if (!$soft || $try >= count(RETRY_WAIT)) break;
                $wait = max(RETRY_WAIT[$try], min(120, $res['retry']));
                $say('  ' . $src . ': ' . ($res['code'] ?: $res['error']) . ' — повтор через ' . $wait . ' с');
                sleep($wait);
            }
            if ($res['cf'] || ($res['code'] === 403 && stripos($res['body'], 'challenge') !== false)) {
                $stop = 'Cloudflare просит проверку (403) — нужна настройка защиты на старом сайте'; $tried--; break;
            }
            if ($ok) { $data = $res['body']; $inRow = 0; }
            elseif ($soft) {
                // старый сайт не отвечает: в «не скачалось» не пишем — следующий запуск попробует снова
                $transient++;
                $say('  ' . $src . ': ' . ($res['code'] ?: $res['error']) . ' после ' . (count(RETRY_WAIT) + 1) . ' попыток');
                if (++$inRow >= 3) { $stop = 'старый сайт не отвечает (3 фото подряд)'; break; }
                continue;
            } else $fail = (string) $res['code'];   // 404, 403, 410… — фото там нет
        }
        if ($data !== null) {
            $wh = $checkImage($data, $ext);
            if (is_string($wh)) $fail = $wh;
            elseif (!$put($dst, $data, $mtime)) $fail = 'не записалось';
            else {
                $done++; $bytes += strlen($data);
                // размеры в базе — по факту файла (галерея строит srcset по ним)
                if ($wh[0] !== (int) $r['width'] || $wh[1] !== (int) $r['height']) {
                    if ($wh[0] < (int) $r['width']) $smaller++;
                    $db->update('product_images', ['width' => $wh[0], 'height' => $wh[1]], 'id = ?', [$iid]);
                    $resized++;
                }
            }
        }
        if ($fail !== null) { $failed[(string) $iid] = $fail; $why[$fail] = ($why[$fail] ?? 0) + 1; }
        if ($tried % 100 === 0) $say('  … ' . $num($tried) . ' из ' . $num(count($queue)) . ': перенесено ' . $num($done) . ' (' . $mb($bytes) . ')');
    }
    if ($done > 0) Cache::flush();   // страницы с заглушками и чужими адресами фото — заново

    $left = count($queue) - $tried + $transient;
    $summary = 'перенесено ' . $num($done) . ' (' . $mb($bytes) . ')'
        . ($why ? ', не скачалось ' . $num(array_sum($why)) . ' — ' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . $num($v), array_keys($why), $why)) : '')
        . ($transient ? ', без ответа ' . $transient . ' (повторит следующий запуск)' : '')
        . ($resized ? ', размеры в базе поправлены у ' . $resized . ($smaller ? ' (меньше, чем в базе: ' . $smaller . ')' : '') : '')
        . ', осталось ' . $num($left) . sprintf(' — за %.0f с', microtime(true) - $t0);
    $state['failed'][$source] = $failed;
    if ($tried > 0) {   // пустой запуск (cron, когда всё уже перенесено) в журнал запусков не пишем
        $state['runs'][] = ['at' => date('Y-m-d H:i:s'), 'source' => $source, 'summary' => $summary];
        $state['runs'] = array_slice($state['runs'], -20);
    }
    $saveState($state);
    $say(($stop !== '' ? 'Остановлено: ' . $stop . '. ' : '') . 'Итог: ' . $summary);
    if ($stop !== '' && $left > 0 && !str_starts_with($stop, 'время')) exit(1);
    if ($left > 0) exit(0);
    $say('Переносить больше нечего: оригиналов на месте ' . $num($have + $done) . ' из ' . $num(count($rows))
        . (count($failed) ? ', не скачалось из этого источника ' . $num(count($failed)) . ' (--stats — почему; --retry-failed — ещё раз)' : ''));
    exit(2);
} catch (\Throwable $e) {
    $say('ОШИБКА: ' . $e->getMessage());
    exit(1);
}
