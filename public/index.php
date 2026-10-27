<?php
/**
 * Единая точка входа сайта.
 * 1) Готовая страница из кэша отдаётся сразу — без подключения к базе (1–3 мс).
 * 2) Иначе — роутер → контроллер → шаблон; результат кэшируется для следующих посетителей.
 */
declare(strict_types=1);

// Режим «Технические работы» (App\Services\Maintenance): есть файл storage/maintenance → 503 с Retry-After (поисковики
// не выкидывают страницы из индекса). Админка работает — в ней загружают данные; статика (assets, wa-data, uploads,
// .well-known) отдаётся Apache без PHP. Включает и выключает администратор («Состояние системы»; JSON: заголовок, текст,
// токен предпросмотра) или bin/deploy.sh (пустая база — слово «auto»). Включивший видит сайт по cookie с токеном —
// ответы ему private, no-store (MAINT_PREVIEW): Cloudflare не сохранит открытую страницу для посетителей.
if (is_file(__DIR__ . '/../storage/maintenance')
    && !preg_match('#^/(admin|ua/admin)(/|$)#', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH))) {
    $mt = json_decode((string) @file_get_contents(__DIR__ . '/../storage/maintenance'), true);
    $mtok = is_array($mt) ? (string) ($mt['token'] ?? '') : '';
    if ($mtok !== '' && hash_equals($mtok, (string) ($_COOKIE['tomobuv_preview'] ?? ''))) {
        define('MAINT_PREVIEW', true);
    } else {
        http_response_code(503);
        header('Retry-After: 3600');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $title = is_array($mt) && trim((string) ($mt['title'] ?? '')) !== '' ? (string) $mt['title'] : 'Сайт обновляется';
        $text = is_array($mt) && trim((string) ($mt['text'] ?? '')) !== '' ? (string) $mt['text'] : '';
        $page = (string) @file_get_contents(__DIR__ . '/../app/Views/errors/maintenance.html');
        $page = str_replace('{{title}}', $h($title), $page);
        // свой текст (абзацы — по пустой строке или переносу) вместо текста по умолчанию между <!--text--> … <!--/text-->
        if ($text !== '') $page = (string) preg_replace('#<!--text-->.*?<!--/text-->#s', '<p>' . implode('</p><p>', array_map($h, preg_split('/\R+/u', trim($text)) ?: [])) . '</p>', $page);
        echo $page;
        exit;
    }
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\PageCache;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$rawPath = Request::path();

// Старые адреса без завершающего слэша → со слэшем (как в Webasyst), 301
if (in_array(Request::method(), ['GET', 'HEAD'], true) && $rawPath !== '/' && !str_ends_with($rawPath, '/')
    && !preg_match('#\.(xml|txt|html?|php|jpe?g|png|gif|webp|svg|ico|css|js|json|pdf|csv|woff2?)$#i', $rawPath)) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    // адрес в том виде, в каком пришёл (как Webasyst): /brand/Mona+Lisa → /brand/Mona+Lisa/, а не /brand/Mona%2BLisa/;
    // символы вне RFC 3986 (пробел, «\», кириллица…) кодируются, начальные слэши схлопываются — без редиректа на //чужой-сайт
    $to = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $to = $to !== '' ? '/' . ltrim((string) preg_replace_callback("#[^A-Za-z0-9\\-._~!$&'()*+,;=:@/%]#",
        static fn($m) => rawurlencode($m[0]), $to), '/') : str_replace('%2F', '/', rawurlencode($rawPath));
    Response::redirect($to . '/' . ($qs !== '' ? '?' . $qs : ''), 301)->send();
    exit;
}

// Язык: /ua/… — украинская версия; дальше роутер работает с адресом без префикса
$path = App\Core\Lang::detect($rawPath);
Request::setPath($path);
App\Core\DB::$localize = App\Core\Lang::isUk();

// Cloudflare: отложенная очистка страниц (сброс кэша был меньше минуты после прошлой) — в конце этого запроса, после ответа
if (\App\Services\Cloudflare::due()) \App\Services\Cloudflare::arm();

if (PageCache::serve()) exit;

$router = new Router();
require APP . '/routes.php';
$router->dispatch(Request::method() === 'HEAD' ? 'GET' : Request::method(), $path)->send();
