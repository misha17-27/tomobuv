<?php
/**
 * Глобальные хелперы для шаблонов и контроллеров.
 */
declare(strict_types=1);

use App\Core\App;

/** Экранирование для HTML. Использовать для ЛЮБОГО вывода данных в шаблонах. */
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Перевод фразы интерфейса на текущий язык: t('Корзина') → «Кошик» на /ua/.
 * Ключ — русский текст (словарь lang/uk.php); подстановки: t('Найдено {n} товаров', ['n' => 5]).
 */
function t(string $ru, array $vars = []): string
{
    return \App\Core\Lang::t($ru, $vars);
}

/** Внутренний путь с префиксом языка (нужно только в JS/JSON и редиректах — HTML-ссылки префикс получают сами) */
function lurl(string $path): string
{
    return \App\Core\Lang::path($path);
}

/** Абсолютный URL сайта: url('/category/x/') → https://tomobuv.com.ua/category/x/ */
function url(string $path = '/'): string
{
    return rtrim((string) App::config('base_url', ''), '/') . '/' . ltrim($path, '/');
}

/** Цена в формате старого сайта: 1 020 грн. (неразрывный пробел не используется — как в Webasyst) */
function price_format($uah, bool $withCurrency = true): string
{
    $s = number_format((float) $uah, 0, '.', ' ');
    return $withCurrency ? $s . ' грн.' : $s;
}

/** Цена для карточек: <span data-uah="1020">1 020 грн.</span> — JS пересчитает в выбранную валюту */
function price_html($uah, string $tag = 'span', string $class = ''): string
{
    $uah = (float) $uah;
    return '<' . $tag . ($class ? ' class="' . e($class) . '"' : '') . ' data-uah="' . (int) round($uah) . '">'
        . e(price_format($uah)) . '</' . $tag . '>';
}

/** Склонение: plural(5, 'товар', 'товара', 'товаров') */
function plural(int $n, string $one, string $few, string $many): string
{
    $a = $n % 10; $b = $n % 100;
    if ($a === 1 && $b !== 11) return $one;
    if ($a >= 2 && $a <= 4 && ($b < 10 || $b >= 20)) return $few;
    return $many;
}

/** SVG-иконка из спрайта (спрайт выводится в layouts/front.php) */
function icon(string $name, string $style = ''): string
{
    return '<svg class="i" aria-hidden="true"' . ($style ? ' style="' . e($style) . '"' : '') . '><use href="#i-' . e($name) . '"/></svg>';
}

/** Версия статики для сброса кэша браузера: asset('css/app.css') → /assets/css/app.css?v=mtime */
function asset(string $path): string
{
    $file = PUBLIC_DIR . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

/**
 * Ссылка на загруженный файл (баннеры, картинки категорий и брендов, фото в статьях — всё в /wa-data/…).
 * Если файла нет локально, а в конфиге задан images.remote_base (локальная разработка без копии wa-data),
 * ссылка ведёт на живой сайт.
 */
function media(?string $path): string
{
    $path = (string) $path;
    if ($path === '' || preg_match('#^https?://#', $path)) return $path;
    $remote = (string) App::config('images.remote_base', '');
    if ($remote !== '' && (str_starts_with($path, '/wa-data/') || str_starts_with($path, '/uploads/user/')) && !is_file(PUBLIC_DIR . $path)) {
        return rtrim($remote, '/') . $path;
    }
    return $path;
}

/**
 * HTML из базы (страницы, статьи, описания товаров/категорий) для вывода на витрине:
 * ссылки на /wa-data/… через media() (локально без копии wa-data — с живого сайта),
 * картинкам добавляется loading="lazy". Содержимое пишет администратор, поэтому не экранируется.
 */
function content_html(?string $html): string
{
    $html = (string) $html;
    if ($html === '') return '';
    $html = preg_replace_callback('#(src|href)=(["\'])(/wa-data/[^"\']+)\2#i',
        static fn($m) => $m[1] . '=' . $m[2] . e(media(html_entity_decode($m[3]))) . $m[2], $html);
    return (string) preg_replace('#<img(?![^>]*\bloading=)#i', '<img loading="lazy"', (string) $html);
}

/** CSRF-поле для форм */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(\App\Core\Csrf::token()) . '">';
}

/** Текущий путь запроса без query: /category/x/ */
function current_path(): string
{
    return \App\Core\Request::path();
}

/** Обрезка текста без HTML */
function str_limit(?string $s, int $len = 160): string
{
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s)));
    return mb_strlen($s) > $len ? rtrim(mb_substr($s, 0, $len - 1)) . '…' : $s;
}

/** «ДЕТСКАЯ ОБУВЬ» → «Детская обувь», «угги» → «Угги» (для меню; в H1 и SEO — как в базе) */
function nice_case(string $s): string
{
    $low = mb_strtolower($s);
    if ($s === mb_strtoupper($s) || $s === $low) {
        return mb_strtoupper(mb_substr($low, 0, 1)) . mb_substr($low, 1);
    }
    return $s;
}

function setting(string $name, $default = null)
{
    return \App\Core\Settings::get($name, $default);
}

if (!function_exists('dd')) {
    function dd(...$v): void
    {
        if (!App::config('debug')) return;
        echo '<pre>'; foreach ($v as $x) var_dump($x); echo '</pre>';
        exit;
    }
}
