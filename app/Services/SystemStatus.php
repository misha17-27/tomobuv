<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Settings;
use App\Controllers\Admin\BaseController;

/**
 * Проверки экрана «Состояние системы» (/admin/status/) и рекомендаций раздела «Безопасность».
 *
 * Каждая проверка — строка ['state' => ok|warn|bad, 'label', 'value', 'note', 'href'?, 'probe'?]:
 *   bad  — сайт работает неправильно или небезопасно, исправить до запуска;
 *   warn — работает, но стоит посмотреть (или проверить на хостинге);
 *   ok   — всё хорошо.
 * Значения — обычный текст (шаблон экранирует). Тяжёлые проверки каталога и размера папок
 * кэшируются на 5–10 минут (Cache сбрасывается кнопкой «Очистить кэш» и после перестройки индекса).
 */
final class SystemStatus
{
    /** Служебные адреса, которые на хостинге должны отдавать 403/404 */
    public const PROBE_PATHS = ['/config/config.php', '/storage/cron-last', '/app/bootstrap.php', '/bin/cron.php', '/database/schema.sql'];

    /** Подписи основных таблиц для карточки «Таблицы базы» */
    public const TABLES = [
        'products' => 'Товары', 'product_features' => 'Характеристики товаров', 'catalog_index' => 'Индекс каталога',
        'feature_values' => 'Значения характеристик', 'product_images' => 'Фото товаров', 'category_products' => 'Товары в категориях',
        'orders' => 'Заказы', 'order_items' => 'Позиции заказов', 'order_log' => 'История заказов', 'customers' => 'Клиенты и сотрудники',
        'category_facets' => 'Фильтры категорий', 'product_texts' => 'Описания товаров', 'categories' => 'Категории', 'brands' => 'Бренды',
        'requests' => 'Заявки', 'product_reviews' => 'Отзывы о товарах', 'store_reviews' => 'Отзывы о магазине', 'cart_items' => 'Корзины',
        'redirects' => 'Редиректы', 'admin_log' => 'Журнал админки', 'rate_limits' => 'Счётчики попыток', 'settings' => 'Настройки',
        'pages' => 'Страницы', 'blog_posts' => 'Блог', 'banners' => 'Баннеры',
        'import_seen' => 'Импорт: товары из загруженных прайсов', 'import_jobs' => 'Импорт: задания', 'import_rows' => 'Импорт: строки',
        'import_errors' => 'Импорт: ошибки', 'import_images' => 'Импорт: фото', 'import_profiles' => 'Импорт: профили',
        'customer_emails' => 'Дополнительные e-mail клиентов', 'product_set_items' => 'Товары в подборках', 'product_sets' => 'Подборки товаров',
        'product_related' => 'Сопутствующие товары', 'coupons' => 'Промокоды', 'coupon_usages' => 'Использование промокодов',
        'notify_log' => 'Журнал WhatsApp-уведомлений', 'features' => 'Характеристики',
    ];

    public static function row(string $state, string $label, string $value, string $note = '', array $extra = []): array
    {
        return ['state' => $state, 'label' => $label, 'value' => $value, 'note' => $note] + $extra;
    }

    /**
     * Все группы проверок: ['Сервер и PHP' => [строки], …].
     * $defer: если проверка каталога ещё не посчитана (кэш пуст), группа «Каталог» = null —
     * страница отдаётся сразу, а строки догружает JS (/admin/status/catalog.json), т.к. на 100 тыс. товаров это ~0,3 с.
     */
    public static function groups(bool $fresh = false, bool $defer = false): array
    {
        if ($fresh) {
            Cache::forget('sys.status.catalog.v4');
            Cache::forget('sys.status.dirs');
        }
        return [
            'Сервер и PHP'             => self::server(),
            'База данных'              => self::database(),
            'Каталог'                  => $defer && !self::catalogReady() ? null : self::catalog(),
            'Папки и права на запись'  => self::folders(),
            'Обслуживание'             => self::maintenance(),
            'Настройки и безопасность' => self::settings(),
        ];
    }

    /** Проверка каталога уже посчитана и лежит в кэше? */
    public static function catalogReady(): bool
    {
        return Cache::get('sys.status.catalog.v4') !== null;
    }

    /** Счётчики ok/warn/bad */
    public static function tally(array $groups): array
    {
        $t = ['ok' => 0, 'warn' => 0, 'bad' => 0];
        foreach ($groups as $rows) foreach ($rows ?? [] as $r) $t[$r['state']]++;
        return $t;
    }

    // ------------------------------------------------------------------ сервер

    public static function server(): array
    {
        $rows = [];
        $rows[] = self::row(version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'bad', 'Версия PHP', PHP_VERSION,
            'Нужна 8.1 или новее, рекомендуется 8.3');

        // Расширения — только те, что реально вызывает код (bad — без него сайт не работает; warn — отключится одна функция
        // или сработает запасной путь). fileinfo коду не нужен: тип загрузок проверяется getimagesize + пересохранение через GD.
        $ext = [
            'pdo_mysql' => ['bad', 'Подключение к базе данных'],
            'mbstring'  => ['bad', 'Русские и украинские тексты, поиск, обрезка строк'],
            'gd'        => ['bad', 'Миниатюры фото товаров и загрузка картинок'],
            'curl'      => ['warn', 'Загрузка прайсов и фото по ссылке (без curl — по одной, медленнее), WhatsApp-уведомления'],
            'openssl'   => ['warn', 'SMTP с SSL/TLS; HTTPS-запросы без curl (WhatsApp, прайсы по ссылке)'],
            'zip'       => ['warn', 'Импорт прайсов XLSX (без него — только CSV/XML)'],
            'xmlreader' => ['warn', 'Импорт прайсов XML/YML и XLSX'],
            'dom'       => ['warn', 'Импорт прайсов XML/YML и XLSX (разбор товара); очистка HTML от менеджеров (без него — строгая, почти без разметки)'],
            'simplexml' => ['warn', 'Импорт XLSX (список листов книги)'],
            'intl'      => ['warn', 'Сравнение названий при импорте без учёта диакритики (без него — упрощённое сравнение)'],
            'exif'      => ['warn', 'Поворот фото с телефона по EXIF при загрузке в медиатеку'],
        ];
        foreach ($ext as $name => [$ifMissing, $why]) {
            $has = extension_loaded($name);
            $value = $has ? 'Подключено' : 'Нет';
            $state = $has ? 'ok' : $ifMissing;
            if ($name === 'gd' && $has) {
                // imagewebp есть только в сборке GD с WebP (gd_info на хостинге бывает отключена)
                if (!function_exists('imagewebp')) { $state = 'warn'; $value = 'Подключено, без WebP'; $why = 'Миниатюры в WebP не создаются — сайт отдаст JPEG/PNG'; }
                else $value = 'Подключено, WebP есть';
            }
            if ($name === 'curl' && $has && (!function_exists('curl_multi_init') || !function_exists('curl_multi_exec'))) {
                // на хостинге часто отключают curl_multi_exec (disable_functions) — тогда работает запасной путь
                $state = 'warn'; $value = 'Подключено, curl_multi отключён'; $why = 'Фото и прайсы по ссылке грузятся по одному (медленнее)';
            }
            $rows[] = self::row($state, 'Расширение ' . $name, $value, $why);
        }
        // Базовые расширения, которые обычно встроены в PHP, но в урезанных сборках бывают выключены
        $core = array_values(array_filter(['ctype', 'session', 'filter', 'json', 'hash', 'pcre'], static fn($n) => !extension_loaded($n)));
        $rows[] = self::row($core ? 'bad' : 'ok', 'Базовые расширения PHP', $core ? 'Нет: ' . implode(', ', $core) : 'ctype, session, filter, json, hash, pcre',
            $core ? 'Без них сайт не работает — попросите хостинг включить' : 'Проверка форм, вход, сессии');

        $mem = (string) ini_get('memory_limit');
        $memB = self::iniBytes($mem);
        $rows[] = self::row($memB < 0 || $memB >= 128 << 20 ? 'ok' : 'warn', 'memory_limit', $mem,
            'Импорт больших прайсов и перестройка индекса — от 128M, лучше 256M');

        $met = (int) ini_get('max_execution_time');
        $rows[] = self::row($met === 0 || $met >= 60 ? 'ok' : 'warn', 'max_execution_time', $met === 0 ? 'без ограничения' : $met . ' с',
            'Перестройка индекса каталога идёт несколько секунд; для веб-запросов нужно от 60 с');

        $up = (string) ini_get('upload_max_filesize');
        $post = (string) ini_get('post_max_size');
        $min = min(self::iniBytes($up) ?: PHP_INT_MAX, self::iniBytes($post) ?: PHP_INT_MAX);
        $rows[] = self::row($min >= 16 << 20 ? 'ok' : 'warn', 'Размер загрузки', 'upload_max_filesize ' . $up . ' · post_max_size ' . $post,
            'Прайсы поставщиков и фото — нужно от 16M (меньшее из двух значений)');

        $op = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
        $opOn = is_array($op) && !empty($op['opcache_enabled']);
        $rows[] = self::row($opOn ? 'ok' : 'warn', 'OPcache', $opOn ? 'Включён' : 'Выключен',
            $opOn ? 'PHP-код и файловый кэш данных компилируются один раз' : 'Попросите хостинг включить — сайт станет заметно быстрее');

        $soft = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        $apache = (bool) preg_match('/apache|litespeed/i', $soft);
        $rows[] = self::row($apache || self::isLocalBase() ? 'ok' : 'warn', 'Веб-сервер', $soft !== '' ? $soft : 'неизвестно',
            $apache ? 'Правила .htaccess работают' : (self::isLocalBase() ? 'Локальный сервер разработки; на хостинге нужен Apache или LiteSpeed' : 'Правила .htaccess работают только на Apache/LiteSpeed — на nginx их нужно перенести в конфиг'));

        $https = \App\Core\Session::https();
        $rows[] = self::row($https || self::isLocalBase() ? 'ok' : 'bad', 'HTTPS', $https ? 'Включён' : (self::isLocalBase() ? 'Локально без HTTPS — нормально' : 'Выключен'),
            'На сайте все canonical, sitemap и письма ведут на https://');

        $free = function_exists('disk_free_space') ? @disk_free_space(ROOT) : false;
        $total = function_exists('disk_total_space') ? @disk_total_space(ROOT) : false;
        if ($free === false) {
            $rows[] = self::row('warn', 'Свободное место на диске', 'хостинг не сообщает', 'Проверьте квоту в панели хостинга');
        } else {
            $state = $free < 500 << 20 ? 'bad' : ($free < 2 << 30 ? 'warn' : 'ok');
            $rows[] = self::row($state, 'Свободное место на диске', self::size((int) $free) . ($total ? ' из ' . self::size((int) $total) : ''),
                'Нужно место под кэш страниц, миниатюры фото, журналы и файлы импорта — лучше от 2 ГБ');
        }
        return $rows;
    }

    // ------------------------------------------------------------------ база и каталог

    public static function database(): array
    {
        $db = App::db();
        $rows = [];
        $ver = (string) $db->value('SELECT VERSION()');
        $isMaria = stripos($ver, 'mariadb') !== false;
        $num = preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $ver);
        $okVer = $isMaria ? version_compare($num, '10.3', '>=') : version_compare($num, '5.7', '>=');
        $rows[] = self::row($okVer ? 'ok' : 'warn', 'Версия ' . ($isMaria ? 'MariaDB' : 'MySQL'), $ver,
            'Нужна MySQL 5.7+ или MariaDB 10.3+');

        try {                                           // на части хостингов information_schema закрыта — страница не должна падать
            $sz = $db->row('SELECT COUNT(*) n, COALESCE(SUM(data_length + index_length), 0) s FROM information_schema.TABLES WHERE table_schema = DATABASE()') ?: [];
            $rows[] = self::row('ok', 'Размер базы', self::size((int) ($sz['s'] ?? 0)) . ' · таблиц: ' . (int) ($sz['n'] ?? 0),
                'Резервную копию делайте через панель хостинга (phpMyAdmin → Экспорт)');
        } catch (\Throwable $e) {
            $rows[] = self::row('warn', 'Размер базы', 'хостинг не сообщает', 'Размер видно в панели хостинга (phpMyAdmin)');
        }
        return $rows;
    }

    /** Таблицы базы: [['name','label','rows','size']] — по убыванию размера (строки InnoDB — приблизительно) */
    public static function tables(): array
    {
        try {
            $list = App::db()->all('SELECT table_name AS name, table_rows AS r, data_length + index_length AS s
                FROM information_schema.TABLES WHERE table_schema = DATABASE() ORDER BY s DESC');
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($list as $t) {
            $out[] = ['name' => (string) $t['name'], 'label' => self::TABLES[$t['name']] ?? '', 'rows' => (int) $t['r'], 'size' => (int) $t['s']];
        }
        return $out;
    }

    public static function catalog(): array
    {
        // ~0,25 с на 100 тыс. товаров — поэтому в кэше на 10 минут («Проверить заново» пересчитывает)
        $c = Cache::remember('sys.status.catalog.v4', 600, static function (): array {
            $db = App::db();
            $st = $db->pairs('SELECT status, COUNT(*) FROM products GROUP BY status');            // индекс status_created
            $ix = $db->row('SELECT COUNT(*) cats, COALESCE(SUM(n), 0) total
                FROM (SELECT category_id, COUNT(*) n FROM catalog_index GROUP BY category_id) t') ?: [];   // один проход по PRIMARY
            // активные товары, которых нет ни в одном списке каталога (индекс catalog_index.product); до 1000 id — для разбора причин
            $lost = array_map('intval', $db->col('SELECT p.id FROM products p LEFT JOIN catalog_index ci ON ci.product_id = p.id
                WHERE p.status = 1 AND ci.product_id IS NULL LIMIT 1000'));
            $withCat = 0;
            if ($lost) {
                [$ph, $vals] = $db->in($lost);
                $withCat = (int) $db->value("SELECT COUNT(DISTINCT product_id) FROM category_products WHERE product_id IN ($ph)", $vals);
            }
            return [
                'active'   => (int) ($st[1] ?? 0),
                'hidden'   => (int) ($st[0] ?? 0),
                'index'    => (int) ($ix['total'] ?? 0),
                'cats'     => (int) ($ix['cats'] ?? 0),
                'catsOn'   => (int) $db->value('SELECT COUNT(*) FROM categories WHERE status = 1'),
                // товары включённых обычных категорий, которых нет в индексе этой категории (= индекс устарел)
                'missing'  => (int) $db->value('SELECT COUNT(DISTINCT cp.product_id) FROM category_products cp
                    JOIN products p ON p.id = cp.product_id AND p.status = 1
                    JOIN categories c ON c.id = cp.category_id AND c.status = 1 AND c.type = 0
                    WHERE NOT EXISTS (SELECT 1 FROM catalog_index ci WHERE ci.category_id = cp.category_id AND ci.product_id = cp.product_id)'),
                'noindex'  => count($lost),
                'sample'   => array_slice($lost, 0, 5),
                'nocat'    => count($lost) - $withCat,         // вне каталога, потому что нет ни одной категории
                'offcat'   => $withCat,                        // категории есть, а записей в индексе нет (индекс устарел)
                'facets'   => (int) $db->value('SELECT COUNT(*) FROM category_facets'),
                'filters'  => (int) $db->value('SELECT COUNT(*) FROM features WHERE is_filter = 1'),
                'images'   => $db->all('SELECT product_id, id, ext FROM product_images ORDER BY id DESC LIMIT 5'),
                'at'       => time(),
            ];
        });
        $f = static fn(int $n): string => number_format($n, 0, '', ' ');
        $more = $c['noindex'] >= 1000 ? '+' : '';
        $rows = [];
        $rows[] = self::row($c['active'] > 0 ? 'ok' : 'bad', 'Товары', $f($c['active']) . ' на сайте · ' . $f($c['hidden']) . ' скрыто', '');
        $rows[] = self::row($c['index'] > 0 || $c['active'] === 0 ? 'ok' : 'bad', 'Индекс каталога (catalog_index)',
            $f($c['index']) . ' строк · категорий: ' . $f($c['cats']) . ' из ' . $f($c['catsOn']) . ' включённых',
            $c['index'] > 0 ? 'Списки категорий строятся по индексу за один запрос' : 'Индекс пуст — категории на сайте пустые. Нажмите «Перестроить индекс каталога»');
        // активные товары без записей в индексе: без категории (назначить категорию) или индекс устарел (перестроить)
        $stale = $c['missing'] > 0 || $c['offcat'] > 0;
        if (!$c['noindex'] && !$c['missing']) {
            $rows[] = self::row('ok', 'Активные товары вне индекса', 'Нет', 'Все активные товары есть в списках каталога');
        } else {
            $parts = [];
            if ($c['noindex']) {
                $why = [];
                if ($c['nocat']) $why[] = 'без категории — ' . $f($c['nocat']);
                if ($c['offcat']) $why[] = 'с категорией, но без записей в индексе — ' . $f($c['offcat']);
                $parts[] = $f($c['noindex']) . $more . ' ' . plural($c['noindex'], 'активный товар не виден', 'активных товара не видны', 'активных товаров не видны')
                    . ' в списках каталога (' . implode(', ', $why) . ')';
            }
            if ($c['missing']) $parts[] = $f($c['missing']) . ' ' . plural($c['missing'], 'товар не попал', 'товара не попали', 'товаров не попали') . ' в индекс своих категорий';
            $ids = $c['sample'] ? ' Например: ' . implode(', ', array_map(static fn($id) => '#' . $id, $c['sample'])) . '.' : '';
            $rows[] = self::row('warn', 'Активные товары вне индекса', implode(' · ', $parts),
                ($stale ? 'Индекс устарел (товары меняли в обход админки) — нажмите «Перестроить индекс каталога». ' : '')
                . ($c['nocat'] ? 'Товары без категории не видны в списках каталога — назначьте категорию в карточке товара.' : '') . $ids,
                $c['sample'] ? ['href' => '/admin/products/' . $c['sample'][0] . '/'] : []);
        }
        $rows[] = self::row($c['facets'] > 0 || $c['filters'] === 0 ? 'ok' : 'warn', 'Фильтры категорий',
            $f($c['facets']) . ' значений · характеристик-фильтров: ' . $c['filters'],
            $c['facets'] > 0 || $c['filters'] === 0 ? '' : 'Значения фильтров не посчитаны — перестройте индекс');

        // фото: папка со старого сайта + выборочная проверка последних фото
        $wa = (string) App::config('images.wa_data', 'wa-data');
        $dir = PUBLIC_DIR . '/' . $wa . '/protected/shop/products';
        $remote = (string) App::config('images.remote_base', '');
        $checked = 0; $found = 0;
        foreach ($c['images'] as $im) {
            $checked++;
            if (is_file(\App\Core\Image::originalPath((int) $im['product_id'], (int) $im['id'], (string) $im['ext']))) $found++;
        }
        if (is_dir($dir)) {
            $state = $checked && $found < $checked ? 'warn' : 'ok';
            $rows[] = self::row($state, 'Фото товаров перенесены', 'Папка public/' . $wa . '/protected/shop/products есть'
                . ($checked ? ' · последние фото: ' . $found . ' из ' . $checked . ' на месте' : ''),
                $state === 'ok' ? 'Адреса фото те же, что на старом сайте'
                    : ($remote !== '' ? 'Сейчас недостающие фото берутся с ' . $remote . ' (images.remote_base, режим разработки). На хостинге скопируйте wa-data со старого сервера целиком и очистите remote_base'
                        : 'Часть свежих фото не найдена — докопируйте папку wa-data со старого сервера'));
        } elseif ($remote !== '') {
            $rows[] = self::row('warn', 'Фото товаров перенесены', 'Нет папки — фото берутся с ' . $remote,
                'Режим разработки (images.remote_base). На боевом сервере скопируйте public/' . $wa . ' со старого сайта и очистите remote_base');
        } else {
            $rows[] = self::row('bad', 'Фото товаров перенесены', 'Нет папки public/' . $wa . '/protected/shop/products',
                'Скопируйте папку wa-data со старого сайта — иначе у товаров не будет фото');
        }
        return $rows;
    }

    // ------------------------------------------------------------------ папки

    public static function folders(): array
    {
        $wa = (string) App::config('images.wa_data', 'wa-data');
        $list = [
            'storage/'          => [STORAGE, 'Кэш, журналы, сессии, файлы импорта'],
            'storage/cache/'    => [STORAGE . '/cache', 'Кэш страниц и данных — главный ускоритель сайта'],
            'storage/logs/'     => [STORAGE . '/logs', 'Журналы ошибок и писем'],
            'storage/sessions/' => [STORAGE . '/sessions', 'Вход в админку и кабинет'],
            'storage/import/'   => [STORAGE . '/import', 'Загруженные прайсы поставщиков'],
            'storage/uploads/'  => [STORAGE . '/uploads', 'Служебные загрузки'],
            'public/uploads/'   => [PUBLIC_DIR . '/uploads', 'Картинки, загруженные в админке (статьи, страницы, баннеры)'],
            'public/' . $wa . '/' => [PUBLIC_DIR . '/' . $wa, 'Фото товаров и миниатюры (создаются при первом показе)'],
        ];
        $rows = [];
        foreach ($list as $label => [$path, $why]) {
            if (is_dir($path)) {
                $w = is_writable($path);
                $rows[] = self::row($w ? 'ok' : 'bad', $label, $w ? 'Запись разрешена' : 'Только чтение', $w ? $why : $why . ' — дайте права на запись (обычно 0755/0775)');
            } else {
                $parent = dirname($path);
                $can = is_dir($parent) && is_writable($parent);
                $rows[] = self::row($can ? 'ok' : 'warn', $label, $can ? 'Нет, создастся автоматически' : 'Нет папки',
                    $can ? $why : $why . ' — создайте папку с правами на запись');
            }
        }
        $cfg = ROOT . '/config/config.php';
        $w = is_writable($cfg);
        $rows[] = self::row($w ? 'warn' : 'ok', 'config/config.php', $w ? 'Доступен на запись' : 'Только чтение',
            $w ? 'Файл с паролями лучше сделать только для чтения (права 0440 или 0640)' : 'Файл с паролями защищён от изменения');
        return $rows;
    }

    // ------------------------------------------------------------------ обслуживание

    public static function maintenance(): array
    {
        $rows = [];
        $f = STORAGE . '/cron-last';
        $last = is_file($f) ? (int) trim((string) @file_get_contents($f)) : 0;
        if (!$last) {
            $rows[] = self::row('warn', 'Cron (bin/cron.php)', 'Ещё не запускался',
                'Добавьте задание раз в час: 0 * * * * php ' . self::cronPath() . ' — чистит старый кэш, корзины, сессии');
        } else {
            $ago = time() - $last;
            $rows[] = self::row($ago > 7200 ? 'warn' : 'ok', 'Cron (bin/cron.php)', 'Последний запуск ' . self::ago($ago) . ' (' . date('d.m.Y H:i', $last) . ')',
                $ago > 7200 ? 'Больше двух часов назад — проверьте задание cron в панели хостинга' : 'Запускается раз в час');
        }

        $dirs = Cache::remember('sys.status.dirs', 600, static function (): array {
            // обход папок ограничен по времени (на хостинге в кэше страниц бывают сотни тысяч файлов)
            return ['cache' => self::dirSize(STORAGE . '/cache', 0.06), 'logs' => self::dirSize(STORAGE . '/logs', 0.02), 'at' => time()];
        });
        [$bytes, $files, $complete] = $dirs['cache'];
        $rows[] = self::row('ok', 'Кэш сайта', 'Версия ' . Cache::version() . ' · ' . ($complete ? '' : 'более ') . self::size($bytes) . ', файлов: ' . ($complete ? '' : 'более ') . number_format($files, 0, '', ' '),
            'Старые версии удаляет cron через сутки. «Очистить кэш» делает все страницы свежими сразу');

        $log = STORAGE . '/logs/error-' . date('Y-m') . '.log';
        if (is_file($log)) {
            $age = time() - (int) filemtime($log);
            $rows[] = self::row($age < 86400 ? 'warn' : 'ok', 'Журнал ошибок', 'storage/logs/' . basename($log) . ' · ' . self::size((int) filesize($log))
                . ' · последняя запись ' . self::ago($age),
                $age < 86400 ? 'За последние сутки были ошибки — передайте файл разработчику' : 'Свежих ошибок нет');
        } else {
            $rows[] = self::row('ok', 'Журнал ошибок', 'В этом месяце ошибок нет', '');
        }
        [$lb] = $dirs['logs'];
        $rows[] = self::row($lb > 200 << 20 ? 'warn' : 'ok', 'Папка журналов', self::size($lb), $lb > 200 << 20 ? 'Журналы разрослись — удалите старые файлы из storage/logs' : '');
        return $rows;
    }

    // ------------------------------------------------------------------ настройки

    public static function settings(): array
    {
        $rows = [];
        $local = self::isLocalBase();
        $debug = (bool) App::config('debug', false);
        $env = (string) App::config('env', 'production');
        $rows[] = self::row(!$debug ? 'ok' : ($local ? 'warn' : 'bad'), 'Режим отладки (debug)', $debug ? 'Включён' : 'Выключен',
            $debug ? ($local ? 'Локальная разработка (env=' . $env . '). На хостинге обязательно debug => false' : 'Посетители видят тексты ошибок и пути к файлам — выключите в config/config.php')
                : 'Посетители не видят технических подробностей ошибок');

        $key = (string) App::config('app_key', '');
        $bad = $key === '' || $key === 'CHANGE_ME' || strlen($key) < 32;
        $rows[] = self::row($bad ? 'bad' : 'ok', 'Секретный ключ (app_key)', $bad ? ($key === '' || $key === 'CHANGE_ME' ? 'Не задан' : 'Слишком короткий') : 'Задан',
            $bad ? 'Сгенерируйте: php -r "echo bin2hex(random_bytes(32));" и впишите в config/config.php — ключ подписывает ссылки на заказы из писем' : 'Подписывает ссылки на заказы из писем покупателям; значение не показывается');

        $base = (string) App::config('base_url', '');
        $httpsBase = str_starts_with($base, 'https://');
        $rows[] = self::row($httpsBase ? 'ok' : ($local ? 'warn' : 'bad'), 'Адрес сайта (base_url)', $base !== '' ? $base : 'не задан',
            $httpsBase ? 'По нему строятся canonical, sitemap и ссылки в письмах' : ($local ? 'Локальный адрес — на хостинге укажите https://tomobuv.com.ua' : 'Укажите адрес с https://'));

        $rows[] = self::clientIpRow();

        $smtp = (string) Mailer::cfg('smtp_host');
        $rows[] = self::row($smtp !== '' ? 'ok' : 'warn', 'Отправка почты', $smtp !== '' ? 'SMTP через ' . $smtp : 'mail() хостинга',
            $smtp !== '' ? '' : 'Письма через mail() часто попадают в спам — настройте SMTP', ['href' => '/admin/mail/']);
        $to = Mailer::adminEmail();
        $rows[] = self::row($to !== '' ? 'ok' : 'bad', 'Адрес для уведомлений', $to !== '' ? $to : 'не задан',
            $to !== '' ? 'Сюда приходят новые заказы и заявки' : 'Уведомления о заказах никуда не уходят', ['href' => '/admin/mail/']);
        $rows[] = self::whatsapp();

        // «Сотрудники» и «Безопасность» открываются только администратору — менеджеру ссылки не показываем
        $isAdmin = \App\Core\Auth::isAdmin();
        $admins = (int) App::db()->value("SELECT COUNT(*) FROM customers WHERE role = 'admin' AND status = 1");
        $rows[] = self::row($admins > 0 ? ($admins > 5 ? 'warn' : 'ok') : 'bad', 'Администраторы', (string) $admins,
            $admins > 5 ? 'Много полных доступов — отключите лишние или сделайте менеджерами' : '', $isAdmin ? ['href' => '/admin/users/'] : []);

        $ips = BaseController::allowedIps();
        $rows[] = self::row('ok', 'Доступ к админке по IP', $ips ? 'Только с ' . count($ips) . ' ' . plural(count($ips), 'адреса', 'адресов', 'адресов') : 'С любого IP (по паролю)',
            $ips ? '' : 'Можно ограничить в разделе «Безопасность»', $isAdmin ? ['href' => '/admin/security/'] : []);

        foreach (self::PROBE_PATHS as $p) {
            $rows[] = $local
                ? self::row('warn', 'Закрыт адрес ' . $p, 'Проверить на хостинге', 'Локальный сервер (' . $base . ') отдаёт файлы иначе, чем Apache — проверка имеет смысл только на хостинге')
                : self::row('warn', 'Закрыт адрес ' . $p, 'Проверяется…', 'Должен отдавать 403 или 404', ['probe' => $p]);
        }
        return $rows;
    }

    /**
     * IP посетителей за Cloudflare/прокси (config trusted_proxies, Request::ip()): от него зависят лимиты попыток входа
     * и форм, журнал админки и доступ к админке по IP. Если запрос пришёл через прокси, а он не указан в trusted_proxies,
     * все посетители выглядят одним IP прокси — лимиты становятся общими на всех.
     */
    public static function clientIpRow(): array
    {
        $trusted = array_values(array_filter(array_map(static fn($v) => is_string($v) ? trim($v) : '', (array) App::config('trusted_proxies', []))));
        $invalid = array_values(array_filter($trusted, static function (string $r): bool {
            [$net, $bits] = array_pad(explode('/', $r, 2), 2, null);
            $bin = @inet_pton(trim($net));
            return $bin === false || ($bits !== null && (!ctype_digit(trim($bits)) || (int) $bits > strlen($bin) * 8));
        }));
        $remote = Request::remoteAddr();
        $ip = Request::ip();
        // заголовки прокси — только как признак «запрос пришёл через прокси», IP из них берёт Request::ip()
        $cf = !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
        $proxied = $cf || !empty($_SERVER['HTTP_X_FORWARDED_FOR']);
        $viaTrusted = $trusted && Request::ipMatches($remote, $trusted);
        $label = 'IP посетителей (прокси, Cloudflare)';
        if ($invalid) {
            return self::row('warn', $label, 'Ошибки в trusted_proxies: ' . implode(', ', array_slice($invalid, 0, 3)),
                'Укажите адреса или подсети вида 173.245.48.0/20 или 2400:cb00::/32 — неверные строки не учитываются');
        }
        if ($viaTrusted) {
            return self::row('ok', $label, 'Через доверенный прокси ' . $remote . ' · ваш IP ' . $ip,
                'IP посетителя берётся из X-Forwarded-For / CF-Connecting-IP (config trusted_proxies: ' . count($trusted) . ')');
        }
        if ($proxied) {
            return self::row('warn', $label, 'Запрос пришёл через ' . ($cf ? 'Cloudflare' : 'прокси') . ' с адреса ' . $remote . ' · ваш IP ' . $ip,
                ($trusted ? 'Этого адреса нет в trusted_proxies' : 'trusted_proxies не задан')
                . ' — все посетители выглядят одним IP: общие лимиты попыток входа и форм. Добавьте адреса прокси в config/config.php'
                . ' (docs/INSTALL.md). Если прокси перед сайтом нет, заголовок X-Forwarded-For добавил ваш провайдер — ничего делать не нужно');
        }
        return self::row('ok', $label, 'Напрямую · ваш IP ' . $ip,
            $trusted ? 'trusted_proxies задан (' . count($trusted) . '), но этот запрос пришёл не через прокси' : 'Сайт не за прокси — IP посетителя = адрес соединения');
    }

    /**
     * WhatsApp-уведомления (экран /admin/whatsapp/): включены ли и как прошла последняя отправка (notify_log, индекс channel).
     * Выключено — это нормально (необязательная функция); включено, но последняя отправка с ошибкой — warn.
     */
    public static function whatsapp(): array
    {
        $href = ['href' => '/admin/whatsapp/'];
        $on = WhatsApp::enabled();
        $flag = WhatsApp::cfg('enabled') === '1';
        try {
            $last = App::db()->row("SELECT ok, error, created_at FROM notify_log WHERE channel = 'whatsapp' ORDER BY created_at DESC, id DESC LIMIT 1");
            $fails = (int) App::db()->value("SELECT COUNT(*) FROM notify_log WHERE channel = 'whatsapp' AND ok = 0 AND created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)]);
        } catch (\Throwable $e) {
            return self::row($flag ? 'warn' : 'ok', 'WhatsApp', ($on ? 'Включён' : 'Выключен') . ' · журнал отправок недоступен',
                'Нет таблицы notify_log — выполните database/migrations/whatsapp.sql', $href);
        }
        $lastText = $last ? 'последняя отправка ' . date('d.m.Y H:i', strtotime((string) $last['created_at'])) . ((int) $last['ok'] ? ' — успешно' : ' — с ошибкой')
            : 'отправок ещё не было';
        if (!$on) {
            return self::row($flag ? 'warn' : 'ok', 'WhatsApp', 'Выключен' . ($last ? ' · ' . $lastText : ''),
                $flag ? 'Включён, но не настроен: нет номера получателя или ключей сервиса отправки' : 'Необязательно: новые заказы и заявки можно дублировать в WhatsApp', $href);
        }
        $bad = $last && !(int) $last['ok'];
        return self::row($bad ? 'warn' : 'ok', 'WhatsApp', 'Включён · ' . WhatsApp::PROVIDERS[WhatsApp::cfg('provider')] . ' · ' . $lastText,
            $bad ? 'Ошибка: ' . mb_substr((string) $last['error'], 0, 160) . ($fails > 1 ? ' (ошибок за сутки: ' . $fails . ')' : '') . ' — отправьте тестовое сообщение на экране WhatsApp'
                : 'Новые заказы приходят в WhatsApp', $href);
    }

    // ------------------------------------------------------------------ проверка служебных адресов

    /** Адрес сайта — локальный (localhost, 127.0.0.1, *.localhost, *.test, *.local)? */
    public static function isLocalBase(): bool
    {
        $host = strtolower((string) parse_url((string) App::config('base_url', ''), PHP_URL_HOST));
        if ($host === '') return true;
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || (bool) preg_match('/\.(localhost|test|local)$/', $host);
    }

    /**
     * HTTP-запросы к base_url + служебные пути (таймаут 3 с, параллельно через curl_multi).
     * 403/404 — ok; 200 — bad (файл открыт); остальное — warn.
     */
    public static function probe(): array
    {
        $base = rtrim((string) App::config('base_url', ''), '/');
        $out = [];
        if (self::isLocalBase()) {
            foreach (self::PROBE_PATHS as $p) $out[] = ['path' => $p, 'code' => 0, 'state' => 'warn', 'value' => 'Проверить на хостинге'];
            return $out;
        }
        $codes = [];
        // на хостинге часть функций curl бывает отключена (disable_functions, чаще всего curl_multi_exec) — тогда запасной путь через fopen
        $curl = true;
        foreach (['curl_init', 'curl_setopt_array', 'curl_multi_init', 'curl_multi_add_handle', 'curl_multi_exec', 'curl_multi_select',
            'curl_multi_info_read', 'curl_getinfo', 'curl_error', 'curl_strerror', 'curl_multi_remove_handle', 'curl_close', 'curl_multi_close'] as $fn) {
            if (!function_exists($fn)) { $curl = false; break; }
        }
        if ($curl) {
            $mh = curl_multi_init();
            $hs = [];
            foreach (self::PROBE_PATHS as $p) {
                $h = curl_init($base . $p);
                curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => 'TomobuvStatus/1.0',
                    CURLOPT_RANGE => '0-1023', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
                curl_multi_add_handle($mh, $h);
                $hs[$p] = $h;
            }
            $deadline = microtime(true) + 4;
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 0.2);
            } while ($running && $st === CURLM_OK && microtime(true) < $deadline);
            $res = [];                                   // код результата curl по каждому запросу (ошибки curl_multi не видны в curl_error)
            while (($info = curl_multi_info_read($mh)) !== false) {
                foreach ($hs as $p => $h) if ($info['handle'] === $h) $res[$p] = (int) $info['result'];
            }
            foreach ($hs as $p => $h) {
                $err = curl_error($h);
                if ($err === '') $err = isset($res[$p]) ? ($res[$p] !== CURLE_OK ? curl_strerror($res[$p]) : '') : 'нет ответа за 3 с';
                $codes[$p] = [(int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $err];
                curl_multi_remove_handle($mh, $h);
                curl_close($h);
            }
            curl_multi_close($mh);
        } elseif (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            foreach (self::PROBE_PATHS as $p) $codes[$p] = [0, 'на хостинге выключены curl и allow_url_fopen — откройте адрес в браузере'];
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3,
                'user_agent' => 'TomobuvStatus/1.0']]);
            foreach (self::PROBE_PATHS as $p) {
                $code = 0;
                $fp = @fopen($base . $p, 'r', false, $ctx);
                if ($fp) {
                    $meta = stream_get_meta_data($fp);
                    foreach (array_reverse($meta['wrapper_data'] ?? []) as $hline) {
                        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $hline, $m)) { $code = (int) $m[1]; break; }
                    }
                    fclose($fp);
                }
                $codes[$p] = [$code, $code ? '' : 'нет ответа'];
            }
        }
        foreach (self::PROBE_PATHS as $p) {
            [$code, $err] = $codes[$p] ?? [0, ''];
            if ($code === 403 || $code === 404) $out[] = ['path' => $p, 'code' => $code, 'state' => 'ok', 'value' => 'Закрыт (' . $code . ')'];
            elseif ($code >= 200 && $code < 300) $out[] = ['path' => $p, 'code' => $code, 'state' => 'bad', 'value' => 'ОТКРЫТ (' . $code . ') — проверьте .htaccess и корень сайта'];
            else $out[] = ['path' => $p, 'code' => $code, 'state' => 'warn', 'value' => $code ? 'Ответ ' . $code . ' — проверьте вручную' : 'Не удалось проверить' . ($err !== '' ? ': ' . mb_substr($err, 0, 80) : '')];
        }
        return $out;
    }

    // ------------------------------------------------------------------ помощники

    /** Размер папки с ограничением по времени: [байт, файлов, посчитано полностью] */
    public static function dirSize(string $path, float $budget = 0.12): array
    {
        if (!is_dir($path)) return [0, 0, true];
        $bytes = 0; $files = 0; $end = microtime(true) + $budget;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) { $bytes += $f->getSize(); $files++; }
                if (($files & 255) === 0 && microtime(true) > $end) return [$bytes, $files, false];
            }
        } catch (\Throwable $e) {
            return [$bytes, $files, false];
        }
        return [$bytes, $files, true];
    }

    /** «128M» → байты; -1 → -1 (без ограничения) */
    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' ) return 0;
        if ($v === '-1') return -1;
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n,
        };
    }

    public static function size(int $bytes): string
    {
        foreach ([['ГБ', 1 << 30], ['МБ', 1 << 20], ['КБ', 1 << 10]] as [$unit, $step]) {
            if ($bytes >= $step) return str_replace('.', ',', (string) round($bytes / $step, $bytes >= $step * 10 ? 0 : 1)) . ' ' . $unit;
        }
        return $bytes . ' Б';
    }

    /** «5 мин назад» */
    public static function ago(int $sec): string
    {
        if ($sec < 60) return 'только что';
        if ($sec < 3600) { $m = intdiv($sec, 60); return $m . ' ' . plural($m, 'минуту', 'минуты', 'минут') . ' назад'; }
        if ($sec < 86400) { $h = intdiv($sec, 3600); return $h . ' ' . plural($h, 'час', 'часа', 'часов') . ' назад'; }
        $d = intdiv($sec, 86400);
        return $d . ' ' . plural($d, 'день', 'дня', 'дней') . ' назад';
    }

    private static function cronPath(): string
    {
        return str_replace('\\', '/', ROOT) . '/bin/cron.php';
    }
}
