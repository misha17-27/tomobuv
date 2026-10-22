<?php
/**
 * Единая точка входа сайта.
 * 1) Готовая страница из кэша отдаётся сразу — без подключения к базе (1–3 мс).
 * 2) Иначе — роутер → контроллер → шаблон; результат кэшируется для следующих посетителей.
 */
declare(strict_types=1);

// Режим «Сайт обновляется»: есть файл storage/maintenance → 503 с Retry-After (поисковики не выкидывают страницы
// из индекса). Админка работает — в ней загружают данные; статика (assets, wa-data, uploads, .well-known) отдаётся
// Apache без PHP. Ставит и снимает bin/deploy.sh (пустая база) или владелец — создать/удалить файл в File Manager.
if (is_file(__DIR__ . '/../storage/maintenance')
    && !preg_match('#^/(admin|ua/admin)(/|$)#', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH))) {
    http_response_code(503);
    header('Retry-After: 3600');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(__DIR__ . '/../app/Views/errors/maintenance.html');
    exit;
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

if (PageCache::serve()) exit;

$router = new Router();
require APP . '/routes.php';
$router->dispatch(Request::method() === 'HEAD' ? 'GET' : Request::method(), $path)->send();
