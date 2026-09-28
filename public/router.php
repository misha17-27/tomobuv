<?php
/**
 * Только для локальной разработки: php -S localhost:8080 -t public public/router.php
 * Существующие файлы (css, js, картинки) отдаёт встроенный сервер, остальное — index.php.
 * На хостинге эту роль выполняет public/.htaccess.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// как в public/.htaccess: PHP в папках фото/загрузок, служебные файлы (.htaccess…) и оригиналы фото не отдаются
if (preg_match('#^/(uploads|wa-data)/.*\.(php\d*|phtml|pht|phar)$#i', (string) $path)
    || preg_match('#(^|/)\.(?!well-known)#', rawurldecode((string) $path))
    || preg_match('#^/wa-data/protected/#i', rawurldecode((string) $path))) {
    http_response_code(403);
    exit('Forbidden');
}
if ($path !== '/' && is_file(__DIR__ . rawurldecode($path))) {
    return false;
}
require __DIR__ . '/index.php';
