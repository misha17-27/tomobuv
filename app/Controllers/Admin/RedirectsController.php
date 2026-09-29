<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AdminCatalog;

/**
 * Редиректы со старых адресов (срабатывают в ErrorController::notFound — только для адресов,
 * которых нет на сайте). from_url — путь с «/» в начале, как его видит сайт (раскодированный,
 * со «/» в конце, если это не файл); to_url — путь или полный адрес https://…
 * Проверки: from ≠ to, нет циклов (A→B→A, в т.ч. через цепочки), код только 301/302.
 * После изменений — Cache::flush(): страницы витрины заменяют в тексте ссылки со старых адресов на новые.
 */
final class RedirectsController extends BaseController
{
    private const PER_PAGE = 50;
    private const SORTS = ['new' => 'id DESC', 'old' => 'id ASC', 'hits' => 'hits DESC, id DESC', 'from' => 'from_url ASC'];
    /** Хосты, которые считаются «своими»: полный адрес на них превращается в путь */
    private const OWN_HOSTS = ['tomobuv.com.ua', 'www.tomobuv.com.ua'];

    /** Карта редиректов для проверки циклов: from(lower) → путь назначения (lower) или null для внешних */
    private static ?array $map = null;

    public function index(): Response
    {
        $db = App::db();
        $q = Request::get('q');
        $sort = array_key_exists(Request::get('sort'), self::SORTS) ? Request::get('sort') : 'new';
        $where = '1';
        $params = [];
        if ($q !== '') {
            $where = '(from_url LIKE ? OR to_url LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = [$like, $like];
        }
        $total = (int) $db->value("SELECT COUNT(*) FROM redirects WHERE $where", $params);
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $rows = $db->all("SELECT id, from_url, to_url, code, hits FROM redirects WHERE $where ORDER BY " . self::SORTS[$sort]
            . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, $params);
        return $this->render('admin/redirects/index', [
            'title' => 'Редиректы', 'rows' => $rows, 'pg' => $pg, 'q' => $q, 'sort' => $sort, 'total' => $total,
            'all' => $q === '' ? $total : (int) $db->value('SELECT COUNT(*) FROM redirects'),
            'report' => Session::flash('redirects_report'),
            'form' => Session::flash('redirects_form') ?: ['from' => '', 'to' => '', 'code' => 301],
            'actions' => $total ? '<a class="btn" href="/admin/redirects/export/">Скачать CSV</a>' : '',
            'styles' => ['admin/content.css'], 'scripts' => ['admin/content.js'],
        ]);
    }

    /** Добавить/обновить один редирект */
    public function add(): Response
    {
        $from = Request::post('from_url');
        $to = Request::post('to_url');
        $code = Request::postInt('code', 301);
        $res = self::check($from, $to, $code);
        if (isset($res['error'])) {
            $this->flash('Не сохранено: ' . $res['error'] . '.', true);
            Session::flash('redirects_form', ['from' => $from, 'to' => $to, 'code' => $code]);
            return Response::redirect('/admin/redirects/');
        }
        $exists = App::db()->value('SELECT id FROM redirects WHERE from_url = ?', [$res['from']]);
        self::upsert([[$res['from'], $res['to'], $res['code']]]);
        Cache::flush();   // ссылки в текстах страниц ведут сразу на новый адрес (PageController::resolveRedirects)
        $this->log('redirect_save', 'redirect', $exists ? (int) $exists : null, ['from' => $res['from'], 'to' => $res['to'], 'code' => $res['code']]);
        $msg = ($exists ? 'Редирект обновлён: ' : 'Редирект добавлен: ') . $res['from'] . ' → ' . $res['to'] . ' (' . $res['code'] . ').';
        if ($res['warn']) $msg .= ' Внимание: ' . implode('; ', $res['warn']) . '.';
        $this->flash($msg);
        return Response::redirect('/admin/redirects/?q=' . rawurlencode($res['from']));
    }

    public function delete(string $id): Response
    {
        $r = App::db()->row('SELECT id, from_url, to_url FROM redirects WHERE id = ?', [(int) $id]);
        if ($r) {
            App::db()->delete('redirects', 'id = ?', [(int) $r['id']]);
            Cache::flush();
            $this->log('redirect_delete', 'redirect', (int) $r['id'], ['from' => $r['from_url'], 'to' => $r['to_url']]);
            $this->flash('Редирект ' . $r['from_url'] . ' удалён.');
        }
        return $this->back('/admin/redirects/');
    }

    /** Удалить отмеченные */
    public function deleteMany(): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', Request::postArray('ids')))));
        if ($ids) {
            $db = App::db();
            [$ph, $vals] = $db->in(array_slice($ids, 0, 1000));
            $n = $db->delete('redirects', "id IN ($ph)", $vals);
            if ($n) Cache::flush();
            $this->log('redirect_delete', 'redirect', null, ['ids' => $ids]);
            $this->flash('Удалено редиректов: ' . $n . '.');
        } else {
            $this->flash('Ничего не выбрано.', true);
        }
        return $this->back('/admin/redirects/');
    }

    /**
     * Импорт CSV «старый адрес;новый адрес[;код]» — из файла или из текстового поля.
     * Разделитель ; (также понимаются Tab и запятая), кодировка UTF-8 или Windows-1251 (Excel).
     */
    public function import(): Response
    {
        $text = '';
        $f = $_FILES['file'] ?? null;
        if ($f && is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                $this->flash('Не удалось загрузить файл.', true);
                return Response::redirect('/admin/redirects/');
            }
            if ((int) $f['size'] > 5 * 1024 * 1024) {
                $this->flash('Файл больше 5 МБ — разбейте его на части.', true);
                return Response::redirect('/admin/redirects/');
            }
            $text = (string) file_get_contents((string) $f['tmp_name']);
        }
        if (trim($text) === '') $text = (string) ($_POST['csv'] ?? '');
        if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
        if (!mb_check_encoding($text, 'UTF-8')) $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if (count($lines) > 20000) {
            $this->flash('Слишком много строк (больше 20 000) — разбейте файл на части.', true);
            return Response::redirect('/admin/redirects/');
        }

        $errors = []; $warns = []; $batch = []; $skipped = 0; $headerChecked = false;
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) continue;
            $delim = str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',');
            $cols = array_map('trim', str_getcsv($line, $delim, '"', ''));
            $from = $cols[0] ?? ''; $to = $cols[1] ?? '';
            // первая строка без «/» в обеих колонках — заголовок («старый адрес;новый адрес»)
            if (!$headerChecked) {
                $headerChecked = true;
                if (!str_contains($from, '/') && !str_contains($to, '/')) continue;
            }
            $code = isset($cols[2]) && $cols[2] !== '' ? (int) $cols[2] : 301;
            $res = self::check($from, $to, $code, true);
            if (isset($res['error'])) {
                $errors[] = 'Строка ' . $n . ': ' . $res['error'];
                $skipped++;
                continue;
            }
            if ($res['warn']) $warns[] = 'Строка ' . $n . ' (' . $res['from'] . '): ' . implode('; ', $res['warn']);
            $batch[mb_strtolower($res['from'])] = [$res['from'], $res['to'], $res['code']];
            self::$map[mb_strtolower($res['from'])] = $res['toKey'];   // следующие строки проверяются с учётом этой
        }

        $added = $updated = 0;
        if ($batch) {
            $db = App::db();
            $existing = [];
            foreach (array_chunk(array_column($batch, 0), 500) as $part) {
                [$ph, $vals] = $db->in($part);
                foreach ($db->col("SELECT from_url FROM redirects WHERE from_url IN ($ph)", $vals) as $u) $existing[mb_strtolower((string) $u)] = true;
            }
            $conf = self::liveConflicts(array_column($batch, 0));
            foreach ($conf as $path => $what) $warns[] = $path . ': ' . $what;
            $updated = count(array_intersect_key($batch, $existing));
            $added = count($batch) - $updated;
            self::upsert(array_values($batch));
            Cache::flush();
            $this->log('redirect_import', 'redirect', null, ['added' => $added, 'updated' => $updated, 'skipped' => $skipped]);
        }
        Session::flash('redirects_report', ['added' => $added, 'updated' => $updated, 'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 100), 'errors_total' => count($errors), 'warns' => array_slice($warns, 0, 100), 'warns_total' => count($warns)]);
        if (!$batch && !$errors) $this->flash('В файле не найдено строк вида «/старый-адрес/;/новый-адрес/».', true);
        return Response::redirect('/admin/redirects/');
    }

    /** Выгрузка всех редиректов в CSV (для Excel — с BOM и разделителем «;») */
    public function export(): Response
    {
        $out = "\xEF\xBB\xBF" . "старый адрес;новый адрес;код;переходов\r\n";
        $csv = static fn(string $s) => preg_match('/[;"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        foreach (App::db()->query('SELECT from_url, to_url, code, hits FROM redirects ORDER BY id') as $r) {
            $out .= $csv((string) $r['from_url']) . ';' . $csv((string) $r['to_url']) . ';' . (int) $r['code'] . ';' . (int) $r['hits'] . "\r\n";
        }
        return Response::text($out, 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="redirects-' . date('Y-m-d') . '.csv"');
    }

    // ------------------------------------------------------------------ проверки (используются и другими разделами)

    /**
     * Редирект при смене адреса страницы или статьи (PagesController, BlogController). Пара проверяется (check), 301 на
     * адрес сайта пишется через AdminCatalog::addRedirect — как у товаров, категорий и брендов: без цепочек (A → B, потом
     * B → C даёт A → C) и петель (редирект с нового адреса удаляется). Возвращает текст для сообщения.
     */
    public static function put(string $from, string $to, int $code = 301): string
    {
        $res = self::check($from, $to, $code);
        if (isset($res['error'])) return 'Редирект со старого адреса не создан: ' . $res['error'] . '.';
        if ($res['code'] === 301 && $res['toKey'] !== null) {
            AdminCatalog::addRedirect($res['from'], $res['to']);
            self::$map = null;                                          // цепочки переписаны — карта для проверки циклов заново
        } else {
            self::upsert([[$res['from'], $res['to'], $res['code']]]);
        }
        return 'Старый адрес ' . $res['from'] . ' перенаправляется на ' . $res['to'] . ' (' . $res['code'] . ').';
    }

    /**
     * Нормализация и проверка пары адресов.
     * → ['from', 'to', 'code', 'toKey', 'warn' => [...]] или ['error' => '…']
     */
    public static function check(string $from, string $to, int $code, bool $bulk = false): array
    {
        if (!in_array($code, [301, 302], true)) return ['error' => 'код должен быть 301 или 302'];
        $f = self::normalizeFrom($from);
        if (isset($f['error'])) return ['error' => 'старый адрес — ' . $f['error']];
        $t = self::normalizeTo($to);
        if (isset($t['error'])) return ['error' => 'новый адрес — ' . $t['error']];
        $fromPath = $f['path'];
        $toUrl = $t['url'];
        $toKey = $t['key'];
        $fromKey = mb_strtolower($fromPath);
        if ($toKey !== null && $toKey === $fromKey) return ['error' => 'старый и новый адрес совпадают (' . $fromPath . ')'];

        // Цикл: идём по цепочке редиректов от нового адреса; если вернулись к старому — цикл
        $map = self::map();
        $warn = [];
        $cur = $toKey; $seen = []; $hops = 0;
        while ($cur !== null) {
            if ($cur === $fromKey) return ['error' => 'получится цикл: ' . $toUrl . ' уже ведёт обратно на ' . $fromPath];
            if (!array_key_exists($cur, $map)) break;          // конец цепочки
            if (isset($seen[$cur])) return ['error' => 'новый адрес ' . $toUrl . ' входит в зацикленную цепочку редиректов'];
            $seen[$cur] = true;
            $cur = $map[$cur];
            $hops++;
        }
        if ($hops > 0) $warn[] = 'новый адрес сам перенаправляется дальше (цепочка из ' . ($hops + 1) . ' переходов) — лучше указать конечный адрес';
        if ($toKey !== null && str_starts_with($toKey, '/ua/')) $warn[] = 'новый адрес ведёт на украинскую версию — с русской версии покупатель тоже попадёт на неё; обычно /ua/ указывать не нужно';
        if (!$bulk) {
            foreach (self::liveConflicts([$fromPath]) as $what) $warn[] = $what;
        }
        return ['from' => $fromPath, 'to' => $toUrl, 'code' => $code, 'toKey' => $toKey, 'warn' => $warn];
    }

    /**
     * Старый адрес → путь, как его видит сайт (Request::path): без домена, раскодированный,
     * со «/» в конце (сайт сам добавляет «/» к адресам без расширения файла).
     */
    public static function normalizeFrom(string $u): array
    {
        $u = trim($u);
        if ($u === '') return ['error' => 'не указан'];
        if (preg_match('#^https?://#i', $u)) {
            $p = parse_url($u);
            if ($p === false) return ['error' => 'неверный адрес'];
            $u = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        }
        $u = (string) preg_replace('/#.*$/s', '', $u);
        if (str_contains($u, '?')) return ['error' => 'адреса с параметрами (?…) не поддерживаются — укажите путь без «?»'];
        if (!str_starts_with($u, '/')) $u = '/' . $u;
        $u = rawurldecode($u);
        if (preg_match('/[\s<>"\\\\]|[\x00-\x1F\x7F]/u', $u)) return ['error' => 'в адресе есть пробелы или недопустимые символы'];
        if ($u !== '/' && !str_ends_with($u, '/') && !preg_match('#\.(xml|txt|html?|php|jpe?g|png|gif|webp|svg|ico|css|js|json|pdf|csv|woff2?)$#i', $u)) $u .= '/';
        if ($u === '/') return ['error' => 'нельзя перенаправлять главную страницу'];
        if (preg_match('#^/admin(/|$)#i', $u)) return ['error' => 'адреса админки перенаправлять нельзя'];
        // сайт снимает /ua перед поиском редиректа — такой редирект никогда бы не сработал
        if (preg_match('#^/ua/#i', $u)) return ['error' => 'укажите адрес без /ua/ — на украинской версии редирект сработает сам (/ua/старый → /ua/новый)'];
        // уникальный индекс redirects.from_url — по первым 191 символам: длиннее — риск перезаписать чужой редирект
        if (mb_strlen($u) > 191) return ['error' => 'слишком длинный (больше 191 символа)'];
        return ['path' => $u];
    }

    /** Новый адрес: путь «/…» (можно с ?параметрами) или внешний https://… */
    public static function normalizeTo(string $u): array
    {
        $u = trim($u);
        if ($u === '') return ['error' => 'не указан'];
        if (preg_match('/[\s<>"\\\\]|[\x00-\x1F\x7F]/u', $u)) return ['error' => 'в адресе есть пробелы или недопустимые символы'];
        if (str_starts_with($u, '//')) return ['error' => 'укажите адрес полностью (https://…) или путь от корня сайта'];
        if (preg_match('#^https?://#i', $u)) {
            $p = parse_url($u);
            if ($p === false || empty($p['host'])) return ['error' => 'неверный адрес'];
            $host = strtolower($p['host']);
            $own = self::OWN_HOSTS;
            $base = parse_url((string) App::config('base_url', ''), PHP_URL_HOST);
            if ($base) $own[] = strtolower($base);
            if (!in_array($host, $own, true)) {
                if (mb_strlen($u) > 500) return ['error' => 'слишком длинный'];
                return ['url' => $u, 'key' => null];   // внешний сайт
            }
            $u = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '') . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $u)) {
            return ['error' => 'разрешены только адреса сайта или https://…'];
        }
        if (!str_starts_with($u, '/')) $u = '/' . $u;
        if (mb_strlen($u) > 500) return ['error' => 'слишком длинный'];
        // ключ — путь, который увидит сайт при переходе (для проверки циклов)
        $path = rawurldecode((string) preg_replace('/[?#].*$/s', '', $u));
        if ($path !== '/' && !str_ends_with($path, '/') && !preg_match('#\.[a-z0-9]{2,5}$#i', $path)) $path .= '/';
        return ['url' => $u, 'key' => mb_strtolower($path)];
    }

    /**
     * Адреса, по которым сейчас открывается страница сайта (редирект с них не сработает):
     * [path => 'описание'] — проверяются страницы, категории, товары, статьи блога.
     */
    public static function liveConflicts(array $paths): array
    {
        $db = App::db();
        $groups = ['page' => [], 'category' => [], 'product' => [], 'blog' => []];
        foreach ($paths as $p) {
            if (preg_match('#^/category/([^/]+)/$#', $p, $m)) $groups['category'][$m[1]] = $p;
            elseif (preg_match('#^/product/([^/]+)/$#', $p, $m)) $groups['product'][$m[1]] = $p;
            elseif (preg_match('#^/blog/([^/]+)/$#', $p, $m)) $groups['blog'][$m[1]] = $p;
            elseif (str_ends_with($p, '/')) $groups['page'][ltrim($p, '/')] = $p;
        }
        $sql = [
            'page'     => ['SELECT url FROM pages WHERE status = 1 AND url IN (%s)', 'по этому адресу открывается страница сайта — редирект сработает только после её удаления'],
            'category' => ['SELECT url FROM categories WHERE status = 1 AND url IN (%s)', 'по этому адресу есть категория — редирект не сработает, пока она существует'],
            'product'  => ['SELECT url FROM products WHERE status = 1 AND url IN (%s)', 'по этому адресу есть товар — редирект не сработает, пока он существует'],
            'blog'     => ["SELECT url FROM blog_posts WHERE status = 'published' AND url IN (%s)", 'по этому адресу есть статья блога — редирект не сработает, пока она опубликована'],
        ];
        $out = [];
        foreach ($groups as $g => $items) {
            if (!$items) continue;
            $lower = [];
            foreach ($items as $k => $p) $lower[mb_strtolower((string) $k)] = $p;
            foreach (array_chunk(array_keys($items), 500) as $part) {
                [$ph, $vals] = $db->in(array_map('strval', $part));
                foreach ($db->col(sprintf($sql[$g][0], $ph), $vals) as $u) {
                    $key = $lower[mb_strtolower((string) $u)] ?? null;
                    if ($key !== null) $out[$key] = $sql[$g][1];
                }
            }
        }
        return $out;
    }

    /** Все редиректы в памяти (для проверки циклов) */
    private static function map(): array
    {
        if (self::$map === null) {
            self::$map = [];
            foreach (App::db()->query('SELECT from_url, to_url FROM redirects') as $r) {
                $t = self::normalizeTo((string) $r['to_url']);
                self::$map[mb_strtolower((string) $r['from_url'])] = $t['key'] ?? null;
            }
        }
        return self::$map;
    }

    /** Пакетная запись [[from, to, code], …]: новые добавляются, существующие (по from_url) обновляются */
    private static function upsert(array $rows): void
    {
        $db = App::db();
        foreach (array_chunk($rows, 300) as $part) {
            $params = [];
            foreach ($part as [$f, $t, $c]) { $params[] = $f; $params[] = $t; $params[] = (int) $c; }
            $db->query('INSERT INTO redirects (from_url, to_url, code) VALUES ' . implode(',', array_fill(0, count($part), '(?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE to_url = VALUES(to_url), code = VALUES(code)', $params);
        }
        foreach ($rows as [$f, $t]) {
            if (self::$map !== null) self::$map[mb_strtolower($f)] = self::normalizeTo($t)['key'] ?? null;
        }
    }
}
