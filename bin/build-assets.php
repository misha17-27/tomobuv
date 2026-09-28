<?php
/**
 * Сборка стилей витрины: public/assets/css/src/*.css (по алфавиту) → public/assets/css/app.css (минифицированный).
 * Один файл = один запрос браузера. Запускать после любых изменений в src/: php bin/build-assets.php
 */
declare(strict_types=1);

$root = dirname(__DIR__) . '/public/assets/css';
$files = glob($root . '/src/*.css') ?: [];
sort($files);
$css = '';
foreach ($files as $f) {
    $css .= "/* " . basename($f) . " */\n" . file_get_contents($f) . "\n";
}
// Простая безопасная минификация: комментарии, лишние пробелы и переводы строк
$min = preg_replace('#/\*(?!!).*?\*/#s', '', $css);
$min = preg_replace('/\s+/', ' ', (string) $min);
$min = preg_replace('/\s*([{};,>])\s*/', '$1', (string) $min);
$min = str_replace(';}', '}', (string) $min);
$tmp = $root . '/app.css.' . getmypid() . '.tmp';
file_put_contents($tmp, trim((string) $min) . "\n");
rename($tmp, $root . '/app.css');
echo 'app.css: ' . count($files) . ' файлов, ' . round(strlen((string) $min) / 1024, 1) . " КБ\n";
