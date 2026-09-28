<?php
/**
 * Единая точка входа сайта.
 * 1) Готовая страница из кэша отдаётся сразу — без подключения к базе (1–3 мс).
 * 2) Иначе — роутер → контроллер → шаблон; результат кэшируется для следующих посетителей.
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\PageCache;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$rawPath = Request::path();

// Старые адреса без завершающего слэша → со слэшем (как в Webasyst), 301
if (Request::method() === 'GET' && $rawPath !== '/' && !str_ends_with($rawPath, '/')
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
