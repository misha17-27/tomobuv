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
App\Core\App::init(require $cfgFile);

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
