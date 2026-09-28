<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Log;
use App\Core\Str;

/**
 * Медиатека — картинки в public/uploads/ (страницы, статьи, баннеры, категории, бренды, описания товаров).
 *
 *  - Список файлов берётся с диска (как в админке ARG FLEX): что лежит в папке, то и видно, в т.ч. залитое по FTP
 *    (старые /uploads/user/… с Webasyst). Индекс хранится в storage/cache/media-index.php и сам обновляется, когда меняется
 *    время изменения любой из папок — это 20–30 вызовов filemtime вместо обхода тысяч файлов.
 *  - Загрузка (Media::store): только JPG/PNG/WEBP/GIF до 10 МБ; тип определяется по содержимому (getimagesize),
 *    картинка пересохраняется через GD (всё постороннее внутри файла пропадает) с уменьшением до 2400 px,
 *    имя — транслит Str::slug + «-2», «-3» при совпадении, папка uploads/ГГГГ/ММ/.
 *  - Где используется файл (Media::usage / Media::usedMap): поиск ссылок «/uploads/…» в контенте сайта.
 *
 * Пути внутри сервиса — относительные к uploads/: «2026/09/krossovki.jpg». Ссылка на сайте — Media::url($rel).
 */
final class Media
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_SIDE = 2400;
    /** Защита от «бомб»: картинки больше 40 Мп не открываем */
    public const MAX_PIXELS = 40_000_000;
    public const PER_PAGE = 60;
    /** Что показываем в медиатеке */
    public const LIST_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    /** Что принимаем при загрузке (по имени; реальный тип всё равно проверяется по содержимому) */
    public const UPLOAD_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    /** «Исполняемые» расширения в середине имени (shell.php.jpg) — такие файлы отклоняем целиком */
    private const DANGER_EXT = '/^(php\d*|phtml|pht|phar|phps|inc|cgi|fcgi|pl|py|rb|sh|bash|asp|aspx|ashx|jsp|jspx|exe|dll|bat|cmd|com|js|mjs|html?|xhtml|shtml|svgz?|xml|htaccess|htpasswd|ini|user)$/i';

    private const MONTHS = ['01' => 'Январь', '02' => 'Февраль', '03' => 'Март', '04' => 'Апрель', '05' => 'Май', '06' => 'Июнь',
        '07' => 'Июль', '08' => 'Август', '09' => 'Сентябрь', '10' => 'Октябрь', '11' => 'Ноябрь', '12' => 'Декабрь'];

    /**
     * Где на сайте могут стоять ссылки на загруженные картинки.
     * [таблица, id-колонка, заголовок, колонки с текстом/путём, что это, ссылка в админке]
     * Колонки *_uk — украинская версия (database/migrations/i18n.sql); колонок, которых нет в базе, не спрашиваем.
     */
    private const SOURCES = [
        ['pages',      'id', 'name',  ['content', 'content_uk'], 'Страница', '/admin/pages/%s/'],
        ['blog_posts', 'id', 'title', ['image', 'text_before_cut', 'text', 'text_before_cut_uk', 'text_uk'], 'Статья блога', '/admin/blog/%s/'],
        ['banners',    'id', 'title', ['image'], 'Баннер', '/admin/banners/%s/'],
        ['categories', 'id', 'name',  ['image', 'banner', 'description', 'seo_description', 'description_uk', 'seo_description_uk'], 'Категория', '/admin/categories/%s/'],
        ['brands',     'id', 'name',  ['image', 'description', 'seo_description', 'description_uk', 'seo_description_uk'], 'Бренд', '/admin/brands/%s/'],
        ['product_texts', 'product_id', null, ['description', 'summary', 'description_uk', 'summary_uk'], 'Товар', '/admin/products/%s/'],
        ['settings',   'name', 'name', ['value'], 'Настройка', '/admin/settings/'],
    ];

    private static ?array $index = null;

    // ------------------------------------------------------------------ пути

    public static function root(): string
    {
        return PUBLIC_DIR . '/uploads';
    }

    /** Ссылка на файл: 2026/09/foto.jpg → /uploads/2026/09/foto.jpg (сегменты кодируются — пробелы, кириллица) */
    public static function url(string $rel): string
    {
        return '/uploads/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    /**
     * Привести ввод к относительному пути внутри uploads/ или null.
     * Принимает «2026/09/a.jpg», «/uploads/2026/09/a.jpg», «https://site/uploads/…». Никаких «..», «.файлов», «\».
     */
    public static function clean(string $s): ?string
    {
        $s = trim($s);
        if ($s === '' || strlen($s) > 600 || str_contains($s, "\0")) return null;
        if (preg_match('#^https?://[^/]+(/.*)$#i', $s, $m)) $s = $m[1];
        $s = (string) preg_replace('/[?#].*$/s', '', $s);
        if (str_contains($s, '%')) $s = rawurldecode($s);
        if (str_contains($s, '\\') || str_contains($s, "\0")) return null;
        if (str_starts_with($s, '/uploads/')) $s = substr($s, 9);
        elseif (str_starts_with($s, 'uploads/')) $s = substr($s, 8);
        $s = ltrim($s, '/');
        if ($s === '') return null;
        $parts = explode('/', $s);
        if (count($parts) > 8) return null;
        foreach ($parts as $p) {
            if ($p === '' || $p[0] === '.' || !preg_match('/^[\p{L}\p{N} _.,()+\-\[\]@&!~\']{1,160}$/u', $p)) return null;
        }
        $ext = strtolower(pathinfo(end($parts), PATHINFO_EXTENSION));
        return in_array($ext, self::LIST_EXT, true) ? implode('/', $parts) : null;
    }

    /** Абсолютный путь существующего файла медиатеки или null (проверка realpath — файл строго внутри uploads/) */
    public static function path(string $rel): ?string
    {
        $rel = self::clean($rel);
        if ($rel === null) return null;
        $root = realpath(self::root());
        if ($root === false) return null;
        $abs = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
        if ($abs === false || !is_file($abs) || is_link($abs)) return null;
        return str_starts_with($abs, $root . DIRECTORY_SEPARATOR) ? $abs : null;
    }

    // ------------------------------------------------------------------ список файлов

    /**
     * Все файлы: [[rel, bytes, mtime], …], новые сверху.
     * Индекс сам становится недействительным, если изменилась любая папка (добавили/удалили файл — у папки меняется mtime),
     * поэтому хранится отдельным файлом storage/cache/media-index.php, а не в Cache: Cache::flush() после любого
     * сохранения в админке не должен заставлять заново обходить тысячи файлов (≈300 мс на 3000 файлов).
     */
    public static function index(): array
    {
        if (self::$index !== null) return self::$index;
        $file = self::indexFile();
        $c = is_file($file) ? @include $file : null;
        if (is_array($c) && isset($c['files'], $c['dirs'], $c['at'], $c['root']) && $c['root'] === self::root()
            && self::fresh($c['dirs'], (int) $c['at'])) {
            return self::$index = $c['files'];
        }
        $s = self::scan();
        self::saveIndex($s + ['root' => self::root()]);
        return self::$index = $s['files'];
    }

    /** Забыть индекс (после загрузки/удаления) */
    public static function forget(): void
    {
        self::$index = null;
        clearstatcache();
        @unlink(self::indexFile());
    }

    private static function indexFile(): string
    {
        return STORAGE . '/cache/media-index.php';
    }

    /** Запись индекса: временный файл + rename (без «полузаписанного» файла при параллельных запросах) */
    private static function saveIndex(array $data): void
    {
        $file = self::indexFile();
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, '<?php return ' . var_export($data, true) . ';', LOCK_EX) === false) return;
        if (!@rename($tmp, $file)) { @unlink($tmp); return; }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    }

    /**
     * Индекс актуален, если ни одна папка не менялась. mtime — в целых секундах, поэтому папку,
     * изменённую в ту же секунду, что и обход (файл мог добавиться сразу после), не считаем надёжной.
     */
    private static function fresh(array $dirs, int $at): bool
    {
        $root = self::root();
        if (!$dirs) return !is_dir($root);
        foreach ($dirs as $rel => $mt) {
            if ($mt >= $at - 1 || @filemtime($rel === '' ? $root : $root . '/' . $rel) !== $mt) return false;
        }
        return true;
    }

    private static function scan(): array
    {
        $root = self::root();
        $at = time();
        if (!is_dir($root)) return ['files' => [], 'dirs' => [], 'at' => $at];
        self::protect();                                       // папку могли залить по FTP — защитим и её
        $files = [];
        $dirs = ['' => (int) filemtime($root)];
        $len = strlen($root) + 1;
        $dirIt = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        // скрытые файлы и папки (.htaccess, .git …) и ссылки не показываем
        $filter = new \RecursiveCallbackFilterIterator($dirIt, static fn(\SplFileInfo $f) => $f->getFilename()[0] !== '.' && !$f->isLink());
        $it = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
        $it->setMaxDepth(7);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $rel = str_replace('\\', '/', substr($f->getPathname(), $len));
            if ($f->isDir()) { $dirs[$rel] = (int) $f->getMTime(); continue; }
            if (!in_array(strtolower($f->getExtension()), self::LIST_EXT, true)) continue;
            if (self::clean($rel) !== $rel) continue;          // имя с «%», «#» и т.п. — ссылкой/удалением не управляется
            $files[] = [$rel, (int) $f->getSize(), (int) $f->getMTime()];
        }
        usort($files, static fn($a, $b) => [$b[2], $b[0]] <=> [$a[2], $a[0]]);
        return ['files' => $files, 'dirs' => $dirs, 'at' => $at];
    }

    /** Папки с количеством файлов: ['2026/09' => 12, 'user' => 7, '' => 1], месяцы — от новых к старым */
    public static function folders(): array
    {
        $out = [];
        foreach (self::index() as [$rel]) {
            $d = self::dirOf($rel);
            $out[$d] = ($out[$d] ?? 0) + 1;
        }
        uksort($out, static function ($a, $b) {
            $ma = (bool) preg_match('#^\d{4}/\d{2}$#', $a);
            $mb = (bool) preg_match('#^\d{4}/\d{2}$#', $b);
            if ($ma !== $mb) return $ma ? -1 : 1;
            return $ma ? strcmp($b, $a) : strnatcasecmp($a, $b);
        });
        return $out;
    }

    public static function folderLabel(string $dir): string
    {
        if ($dir === '') return 'uploads/ (корень)';
        if (preg_match('#^(\d{4})/(\d{2})$#', $dir, $m) && isset(self::MONTHS[$m[2]])) return self::MONTHS[$m[2]] . ' ' . $m[1];
        return $dir . '/';
    }

    /** Имя файла без папки (без basename(): он зависит от локали и портит кириллицу) */
    public static function base(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $p = strrpos($path, '/');
        return $p === false ? $path : substr($path, $p + 1);
    }

    public static function dirOf(string $rel): string
    {
        $p = strrpos($rel, '/');
        return $p === false ? '' : substr($rel, 0, $p);
    }

    /**
     * Выборка для экрана и пикера.
     * $f: q (поиск по имени, в т.ч. транслитом: «кроссовки» найдёт krossovki-…), folder (null — все папки,
     * '' — корень uploads/, '2026/09' …), use (''|used|unused), sort (new|old|name|big), counts (bool — считать вкладки)
     * → ['items' => [...], 'total' => N, 'page' => p, 'pages' => n, 'counts' => [all, used, unused]]
     */
    public static function query(array $f, int $page = 1, int $per = self::PER_PAGE): array
    {
        $files = self::index();
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $slug = $q !== '' ? Str::slug($q) : '';
        $folder = isset($f['folder']) ? (string) $f['folder'] : null;
        $useFilter = in_array($f['use'] ?? '', ['used', 'unused'], true) ? $f['use'] : '';
        $withCounts = !empty($f['counts']) || $useFilter !== '';
        $used = $withCounts ? self::usedMap() : [];

        if ($q !== '' || $folder !== null) {
            $files = array_values(array_filter($files, static function ($row) use ($q, $slug, $folder) {
                if ($folder !== null && self::dirOf($row[0]) !== $folder) return false;
                if ($q === '') return true;
                $name = mb_strtolower(self::base($row[0]));
                return str_contains($name, $q) || ($slug !== '' && $slug !== 'item' && str_contains($name, $slug));
            }));
        }
        $counts = ['all' => count($files), 'used' => 0, 'unused' => 0];
        if ($withCounts) {
            foreach ($files as $row) isset($used[$row[0]]) ? $counts['used']++ : $counts['unused']++;
        }
        if ($useFilter !== '') {
            $want = $useFilter === 'used';
            $files = array_values(array_filter($files, static fn($row) => isset($used[$row[0]]) === $want));
        }
        switch ($f['sort'] ?? 'new') {
            case 'old':  $files = array_reverse($files); break;
            case 'name': usort($files, static fn($a, $b) => strnatcasecmp(self::base($a[0]), self::base($b[0]))); break;
            case 'big':  usort($files, static fn($a, $b) => $b[1] <=> $a[1]); break;
        }
        $total = count($files);
        $pages = max(1, (int) ceil($total / max(1, $per)));
        $page = max(1, min($page, $pages));
        $items = [];
        foreach (array_slice($files, ($page - 1) * $per, $per) as $row) {
            $items[] = self::item($row, $used[$row[0]] ?? 0);
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'counts' => $counts];
    }

    /** Карточка файла для вывода */
    public static function item(array $row, int $usedCount = 0): array
    {
        [$rel, $size, $mtime] = $row;
        $abs = self::root() . '/' . $rel;
        $dim = str_ends_with(strtolower($rel), '.svg') ? false : @getimagesize($abs);
        return [
            'path'   => $rel,
            'url'    => self::url($rel),
            'name'   => self::base($rel),
            'dir'    => self::dirOf($rel),
            'size'   => $size,
            'size_h' => self::sizeText($size),
            'mtime'  => $mtime,
            'date'   => date('d.m.Y H:i', $mtime),
            'width'  => $dim ? (int) $dim[0] : null,
            'height' => $dim ? (int) $dim[1] : null,
            'mime'   => $dim ? (string) $dim['mime'] : (str_ends_with(strtolower($rel), '.svg') ? 'image/svg+xml' : ''),
            'used'   => $usedCount,
        ];
    }

    /** Один файл по относительному пути (или null, если его нет) */
    public static function find(string $rel): ?array
    {
        $rel = self::clean($rel);
        $abs = $rel === null ? null : self::path($rel);
        if ($abs === null) return null;
        return self::item([$rel, (int) filesize($abs), (int) filemtime($abs)]);
    }

    /** Показатели для шапки: файлов, байт, за текущий месяц, не используются */
    public static function stats(): array
    {
        $files = self::index();
        $used = self::usedMap();
        $month = date('Y/m') . '/';
        $s = ['files' => count($files), 'bytes' => 0, 'month' => 0, 'unused' => 0];
        foreach ($files as [$rel, $size]) {
            $s['bytes'] += $size;
            if (str_starts_with($rel, $month)) $s['month']++;
            if (!isset($used[$rel])) $s['unused']++;
        }
        return $s;
    }

    /**
     * Ссылки в контенте на файлы uploads/, которых нет на диске (например, /uploads/user/… со старого сайта
     * не скопированы) → ['total' => N, 'items' => [['path' => …, 'url' => …, 'count' => мест], …]]
     */
    public static function missing(int $limit = 100): array
    {
        $have = [];
        foreach (self::index() as [$rel]) $have[$rel] = true;
        $out = [];
        foreach (self::usedMap() as $rel => $n) {
            if (!isset($have[$rel])) $out[] = ['path' => (string) $rel, 'url' => self::url((string) $rel), 'count' => $n];
        }
        usort($out, static fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
        return ['total' => count($out), 'items' => array_slice($out, 0, $limit)];
    }

    public static function sizeText(int $bytes): string
    {
        if ($bytes >= 1048576) return str_replace('.', ',', (string) round($bytes / 1048576, 1)) . ' МБ';
        if ($bytes >= 1024) return (int) round($bytes / 1024) . ' КБ';
        return $bytes . ' Б';
    }

    // ------------------------------------------------------------------ где используется

    /**
     * Где на сайте стоит ссылка на файл: [['label' => 'Страница', 'title' => 'Доставка', 'link' => '/admin/pages/2/'], …]
     * Ищем «/uploads/путь» (и в кодированном виде, и с экранированными «\/» из JSON-настроек), затем
     * уточняем совпадение разбором ссылок — «foto.jpg» не совпадёт с «foto.jpg.webp» или «my-foto.jpg».
     */
    public static function usage(string $rel, int $limit = 50): array
    {
        $rel = self::clean($rel);
        if ($rel === null) return [];
        $variants = array_values(array_unique(['/uploads/' . $rel, self::url($rel)]));
        foreach ($variants as $v) $variants[] = str_replace('/', '\\/', $v);
        $likes = array_map(static fn($v) => '%' . addcslashes($v, '\\%_') . '%', $variants);
        $out = [];
        foreach (self::SOURCES as $src) {
            try {
                foreach (self::sourceRows($src, $likes, $limit + 1) as $r) {
                    if (!in_array($rel, self::extract((string) $r['body']), true)) continue;
                    $out[] = ['label' => $src[4], 'title' => (string) ($r['title'] ?? '') ?: '#' . $r['id'], 'link' => sprintf($src[5], rawurlencode((string) $r['id']))];
                }
            } catch (\Throwable $e) {
                Log::error('media usage ' . $src[0] . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Карта использования всех файлов: ['2026/09/a.jpg' => 3, …] — один запрос на таблицу (без запросов в цикле).
     * Кэш на 10 минут; любое сохранение контента в админке делает Cache::flush() → карта пересчитается.
     */
    public static function usedMap(bool $fresh = false): array
    {
        $build = static function (): array {
            $map = [];
            $likes = ['%uploads%'];
            foreach (self::SOURCES as $src) {
                try {
                    foreach (self::sourceRows($src, $likes, 0) as $r) {
                        foreach (self::extract((string) $r['body']) as $rel) $map[$rel] = ($map[$rel] ?? 0) + 1;
                    }
                } catch (\Throwable $e) {
                    Log::error('media usedMap ' . $src[0] . ': ' . $e->getMessage());
                }
            }
            return $map;
        };
        if ($fresh) {
            $map = $build();
            Cache::set('media.used', $map, 600);
            return $map;
        }
        return Cache::remember('media.used', 600, $build);
    }

    /**
     * Какие колонки источников есть в базе: ['pages' => ['content' => true, …], …] — одним запросом, кэш на сутки.
     * Без этого одна отсутствующая колонка (не выполнена миграция i18n) выключила бы поиск по всей таблице.
     */
    private static function columns(): array
    {
        return Cache::remember('media.columns', 86400, static function (): array {
            $tables = array_values(array_unique(array_column(self::SOURCES, 0)));
            $out = [];
            try {
                $rows = App::db()->all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
                    . implode(',', array_fill(0, count($tables), '?')) . ')', $tables);
                foreach ($rows as $r) $out[(string) $r['t']][(string) $r['c']] = true;
            } catch (\Throwable $e) {
                Log::error('media columns: ' . $e->getMessage());
            }
            return $out;
        });
    }

    /** Строки источника, где хотя бы одна колонка похожа на один из LIKE-шаблонов. $limit = 0 — без ограничения */
    private static function sourceRows(array $src, array $likes, int $limit): array
    {
        [$table, $idCol, $titleCol, $cols] = $src;
        $have = self::columns();
        if ($have) {                                           // список колонок не получили — спрашиваем как есть
            if (!isset($have[$table])) return [];
            $cols = array_values(array_filter($cols, static fn($c) => isset($have[$table][$c])));
            if (!$cols) return [];
        }
        $where = [];
        $params = [];
        foreach ($cols as $c) {
            foreach ($likes as $l) { $where[] = "t.`$c` LIKE ?"; $params[] = $l; }
        }
        $body = 'CONCAT_WS(\' \', ' . implode(', ', array_map(static fn($c) => "t.`$c`", $cols)) . ')';
        if ($table === 'product_texts') {
            $sql = "SELECT t.product_id AS id, p.name AS title, $body AS body FROM product_texts t JOIN products p ON p.id = t.product_id";
        } else {
            $sql = "SELECT t.`$idCol` AS id, t.`$titleCol` AS title, $body AS body FROM `$table` t";
        }
        $sql .= ' WHERE ' . implode(' OR ', $where) . ($limit > 0 ? ' LIMIT ' . $limit : '');
        return App::db()->all($sql, $params);
    }

    /**
     * Все относительные пути uploads/, на которые есть ссылки в тексте (HTML, JSON, CSS url(), просто путь).
     * Путь — до расширения картинки, за которым идёт конец ссылки: так находятся и имена с пробелами/скобками
     * («/uploads/user/фото (1).jpg» из файлов, залитых по FTP), а «a.jpg» не путается с «a.jpg.webp».
     */
    public static function extract(string $text): array
    {
        if ($text === '' || !str_contains($text, 'uploads')) return [];
        $text = str_replace('\\/', '/', $text);
        if (str_contains($text, '&')) $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $re = '#/uploads/([^\s"\'<>\\\\?\#][^"\'<>\\\\?\#\r\n]{0,590}?\.(?:jpe?g|png|gif|webp|svg))(?=[\s"\'<>)\\\\?\#&,;:!]|\.(?![\p{L}\p{N}])|$)#iu';
        if (!preg_match_all($re, $text, $m)) return [];
        $out = [];
        foreach ($m[1] as $p) {
            if (preg_match('#(^|/)\s|\s(/|$)|\s{2}#u', $p)) continue;          // «/uploads/ … текст … foto.jpg» — не ссылка
            $rel = self::clean($p);
            if ($rel !== null) $out[$rel] = true;
        }
        return array_keys($out);
    }

    // ------------------------------------------------------------------ загрузка

    /** Предел размера файла с учётом настроек PHP (upload_max_filesize / post_max_size), байт */
    public static function limitBytes(): int
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        $lim = array_filter([$toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size'))]);
        return $lim ? min(min($lim), self::MAX_BYTES) : self::MAX_BYTES;
    }

    public static function limitText(): string
    {
        return self::sizeText(self::limitBytes());
    }

    /** Проверка имени: расширение из белого списка, без «исполняемых» частей (shell.php.jpg) и скрытых файлов */
    public static function checkName(string $name): ?string
    {
        $name = trim(self::base($name));
        if ($name === '' || $name[0] === '.') return 'недопустимое имя файла';
        $parts = explode('.', $name);
        $ext = strtolower((string) array_pop($parts));
        if (!$parts || !in_array($ext, self::UPLOAD_EXT, true)) return 'можно загружать только JPG, PNG, WEBP или GIF';
        array_shift($parts);                                   // само имя
        foreach ($parts as $p) {
            $p = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $p));   // «php%00», «php » → php
            if ($p !== '' && preg_match(self::DANGER_EXT, $p)) return 'файл с двойным расширением (.' . $p . '.' . $ext . ') не принимается';
        }
        return null;
    }

    /**
     * Сохранить загруженный файл (элемент $_FILES).
     * → ['ok' => true, 'url', 'path', 'name', 'width', 'height', 'size', 'size_h'] | ['ok' => false, 'error' => '…']
     */
    public static function store(?array $file): array
    {
        if (!$file || !isset($file['error']) || is_array($file['error'])) return self::fail('Файл не выбран');
        $orig = (string) ($file['name'] ?? '');
        $label = $orig !== '' ? '«' . mb_substr(self::base($orig), 0, 80) . '»: ' : '';
        $err = (int) $file['error'];
        if ($err === UPLOAD_ERR_NO_FILE) return self::fail('Файл не выбран');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return self::fail($label . 'файл слишком большой (максимум ' . self::limitText() . ')');
        if ($err === UPLOAD_ERR_PARTIAL) return self::fail($label . 'файл загрузился не полностью — попробуйте ещё раз');
        if ($err !== UPLOAD_ERR_OK) return self::fail($label . 'не удалось загрузить файл (код ' . $err . ')');
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) return self::fail($label . 'не удалось загрузить файл');
        if ($e = self::checkName($orig)) return self::fail($label . $e);
        $bytes = (int) filesize($tmp);
        if ($bytes <= 0) return self::fail($label . 'пустой файл');
        if ($bytes > self::MAX_BYTES) return self::fail($label . 'файл больше 10 МБ');
        return self::process($tmp, $orig, $label);
    }

    /** Пересохранение картинки через GD в uploads/ГГГГ/ММ/ */
    private static function process(string $src, string $origName, string $label = ''): array
    {
        $info = @getimagesize($src);
        if (!$info || !isset(self::TYPES[$info[2]])) return self::fail($label . 'это не картинка JPG, PNG, WEBP или GIF (тип проверяется по содержимому файла)');
        [$w, $h, $type] = $info;
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) return self::fail($label . "слишком большое разрешение ({$w}×{$h}) — уменьшите картинку перед загрузкой");
        if (!function_exists('imagecreatetruecolor')) return self::fail('На сервере нет расширения GD — загрузка картинок невозможна');

        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        if (!self::memoryFor(($w * $h + $tw * $th) * 5 + 8 * 1048576)) {
            return self::fail($label . "не хватает памяти сервера для картинки {$w}×{$h} — уменьшите её перед загрузкой");
        }
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_GIF  => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default        => false,
        };
        if (!$img) return self::fail($label . 'файл повреждён или это не картинка');
        $ext = self::TYPES[$type];
        // GIF → PNG (сохраняется прозрачность; анимация GD не поддерживается), WEBP без поддержки в GD → PNG
        if ($ext === 'gif' || ($ext === 'webp' && !function_exists('imagewebp'))) $ext = 'png';

        // поворот фото с телефона по EXIF
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($src)['Orientation'] ?? 1);
            $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($deg && ($rot = imagerotate($img, $deg, 0))) {
                imagedestroy($img);
                $img = $rot;
                [$w, $h] = [imagesx($img), imagesy($img)];
                $scale = min(1, self::MAX_SIDE / max($w, $h));
                $tw = max(1, (int) round($w * $scale));
                $th = max(1, (int) round($h * $scale));
            }
        }

        $out = imagecreatetruecolor($tw, $th);
        if ($ext === 'jpg') {
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        } else {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($img);

        $dirRel = date('Y') . '/' . date('m');
        $dir = self::root() . '/' . $dirRel;
        if (!self::ensureDir($dir)) { imagedestroy($out); return self::fail('Нет доступа к папке public/uploads — проверьте права'); }

        $file = self::base($origName);
        $dot = strrpos($file, '.');
        $base = rtrim(Str::slug($dot === false ? $file : substr($file, 0, $dot), 80), '-');   // обрезка до 80 могла оставить «-» в конце
        if ($base === '' || $base === 'item') $base = 'image';
        [$name, $dst] = self::reserve($dir, $base, $ext);
        if ($name === null) { imagedestroy($out); return self::fail('Не удалось создать файл в папке uploads'); }
        $ok = match ($ext) {
            'png'  => imagepng($out, $dst, 6),
            'webp' => imagewebp($out, $dst, 85),
            default => imagejpeg($out, $dst, (int) App::config('images.jpeg_quality', 85)),
        };
        imagedestroy($out);
        clearstatcache(true, $dst);
        if (!$ok || !filesize($dst)) { @unlink($dst); return self::fail('Не удалось сохранить картинку'); }
        self::forget();
        $rel = $dirRel . '/' . $name;
        $size = (int) filesize($dst);
        return ['ok' => true, 'url' => self::url($rel), 'path' => $rel, 'name' => $name, 'width' => $tw, 'height' => $th,
            'size' => $size, 'size_h' => self::sizeText($size), 'resized' => $scale < 1];
    }

    /** Уникальное имя в папке: foto.jpg, foto-2.jpg, … — файл сразу создаётся (fopen «x»), чтобы параллельная загрузка его не заняла */
    private static function reserve(string $dir, string $base, string $ext): array
    {
        for ($i = 1; $i < 1000; $i++) {
            $name = $base . ($i > 1 ? '-' . $i : '') . '.' . $ext;
            $dst = $dir . '/' . $name;
            if (file_exists($dst)) continue;
            $fh = @fopen($dst, 'x');
            if ($fh) { fclose($fh); return [$name, $dst]; }
        }
        return [null, ''];
    }

    /** Папка + защитный .htaccess в корне uploads */
    private static function ensureDir(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        self::protect();
        return true;
    }

    /**
     * .htaccess в public/uploads/: скрипты там не исполняются и не отдаются; SVG (старые файлы, залитые по FTP)
     * отдаются с CSP без скриптов — открытый напрямую SVG не выполнит JavaScript. Пишется один раз, если файла нет.
     */
    private static function protect(): void
    {
        $ht = self::root() . '/.htaccess';
        if (is_file($ht) || !is_dir(self::root())) return;
        @file_put_contents($ht, "# Медиатека (App\\Services\\Media): в папке загрузок ничего не исполняется, отдаются только картинки\n"
            . "<FilesMatch \"(?i)\\.(php\\d*|phtml|pht|phar|phps|inc|cgi|fcgi|pl|py|rb|sh|asp|aspx|ashx|jsp|jspx|shtml|html?|js|htaccess|ini)$\">\n"
            . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
            . "</FilesMatch>\n"
            . "<IfModule mod_headers.c>\n  <FilesMatch \"(?i)\\.svgz?$\">\n"
            . "    Header set Content-Security-Policy \"default-src 'none'; style-src 'unsafe-inline'; img-src data:\"\n"
            . "  </FilesMatch>\n</IfModule>\n"
            . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n", LOCK_EX);
        clearstatcache();
    }

    /** Хватит ли памяти на обработку (при необходимости поднимаем memory_limit) */
    private static function memoryFor(int $need): bool
    {
        $lim = trim((string) ini_get('memory_limit'));
        if ($lim === '' || $lim === '-1') return true;
        $n = (int) $lim;
        $bytes = match (strtolower(substr($lim, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        $want = memory_get_usage(true) + $need;
        if ($want <= $bytes) return true;
        return @ini_set('memory_limit', (string) (int) ceil($want / 1048576 + 16) . 'M') !== false;
    }

    // ------------------------------------------------------------------ удаление

    /** Удалить файл медиатеки (проверку «используется ли» делает вызывающий) */
    public static function delete(string $rel): bool
    {
        $abs = self::path($rel);
        if ($abs === null) return false;
        $ok = @unlink($abs);
        if ($ok) self::forget();
        return $ok;
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
