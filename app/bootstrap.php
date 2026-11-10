<?php
/**
 * Загрузка приложения: конфиг, автозагрузка классов, обработка ошибок, хелперы.
 * Подключается из public/index.php и из CLI-скриптов bin/*.php.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP', ROOT . '/app');
define('STORAGE', ROOT . '/storage');
define('PUBLIC_DIR', ROOT . '/public');
define('START_TIME', microtime(true));

// Скрипты bin/*.php (перенос базы, установка, импорт) — только из командной строки,
// даже если сервер по ошибке отдаёт папку bin/ по HTTP (проект в public_html без mod_rewrite)
if (PHP_SAPI !== 'cli' && str_starts_with(strtr((string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')), '\\', '/'), strtr(ROOT, '\\', '/') . '/bin/')) {
    http_response_code(403);
    exit('Forbidden');
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Kiev');

// Автозагрузка: App\Core\Router → app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) return;
    $file = APP . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

require APP . '/helpers.php';

$cfgFile = ROOT . '/config/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Не найден config/config.php — скопируйте config/config.example.php и заполните.');
}
// config.php испорчен (не возвращает массив настроек — например, в файле текст вместо PHP): не отдавать его вывод с кодом 200 —
// Cloudflare и поисковики сохранили бы его как страницу; 503 без кэширования, причина — в журнал сервера
ob_start();
$cfg = require $cfgFile;
$cfgOut = (string) ob_get_clean();
if (!is_array($cfg)) {
    error_log('tomobuv: config/config.php не возвращает массив настроек (' . gettype($cfg) . ', вывод ' . strlen($cfgOut) . ' байт: '
        . substr(preg_replace('/\s+/', ' ', $cfgOut), 0, 40) . ') — восстановите файл (docs/DEPLOY-CPANEL.md)');
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "config/config.php испорчен: не возвращает массив настроек\n"); exit(1); }
    http_response_code(503);
    header('Retry-After: 600');
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Сайт временно недоступен</title><p style="font:16px sans-serif;margin:40px">Сайт временно недоступен — скоро вернёмся.</p>');
}
App\Core\App::init($cfg);
unset($cfg, $cfgOut);

$debug = (bool) App\Core\App::config('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE . '/logs/php-error.log');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    App\Core\Log::error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, (string) $e . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) http_response_code(500);
    if ($debug) {
        echo '<pre style="white-space:pre-wrap">' . e((string) $e) . '</pre>';
    } else {
        $f = APP . '/Views/errors/500.php';
        if (is_file($f)) include $f; else echo 'Ошибка сервера. Попробуйте позже.';
    }
});
