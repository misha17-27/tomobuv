<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\Str;

/**
 * Информационные страницы (/o-kompanii/, /dostavka-i-oplata/…).
 * pages.url — путь без ведущего «/», но с «/» в конце: 'o-kompanii/', 'pages/o-kompanii/'.
 * Страницы 'pages/…' — дубли со старого сайта (приложение «Сайт» Webasyst), показываются с пометкой.
 * Украинская версия (/ua/…) — колонки *_uk; пустое поле → на /ua/ показывается русский текст.
 */
final class PagesController extends BaseController
{
    /** Первые сегменты адресов, занятые разделами сайта (страница с таким адресом не откроется) */
    public const RESERVED = [
        'admin', 'ua', 'category', 'product', 'products', 'brand', 'search', 'compare', 'cart', 'order', 'checkout',
        'quickorder', 'request', 'login', 'signup', 'forgotpassword', 'logout', 'my', 'blog', 'reviews',
        'wa-data', 'wa-apps', 'wa-content', 'assets', 'uploads', 'sitemap', 'robots', 'favicon', 'index',
    ];

    /** Поля украинской версии: колонка → максимальная длина (0 — без ограничения) */
    public const UK_FIELDS = ['name_uk' => 255, 'title_uk' => 500, 'h1_uk' => 500, 'meta_description_uk' => 0, 'meta_keywords_uk' => 0, 'content_uk' => 0];

    public function index(): Response
    {
        $db = App::db();
        $filter = Request::get('f');
        if (!in_array($filter, ['', 'main', 'dup', 'hidden'], true)) $filter = '';
        $q = mb_strtolower(Request::get('q'));
        // страниц единицы — фильтруем в PHP (без тяжёлого content)
        $rows = $db->all("SELECT id, url, name, name_uk, status, in_menu, sort, canonical, updated_at,
                (content_uk IS NOT NULL AND content_uk <> '') AS has_uk FROM pages ORDER BY sort, id");
        $byUrl = array_column($rows, 'id', 'url');
        $counts = ['all' => count($rows), 'main' => 0, 'dup' => 0, 'hidden' => 0];
        foreach ($rows as &$r) {
            $r['dup'] = str_starts_with((string) $r['url'], 'pages/');
            $r['original'] = $r['dup'] ? substr((string) $r['url'], 6) : null;
            $r['original_id'] = $r['original'] !== null ? ($byUrl[$r['original']] ?? null) : null;
            $counts[$r['dup'] ? 'dup' : 'main']++;
            if (!(int) $r['status']) $counts['hidden']++;
        }
        unset($r);
        $rows = array_values(array_filter($rows, static function ($r) use ($filter, $q) {
            if ($filter === 'main' && $r['dup']) return false;
            if ($filter === 'dup' && !$r['dup']) return false;
            if ($filter === 'hidden' && (int) $r['status']) return false;
            if ($q !== '' && !str_contains(mb_strtolower($r['name'] . ' ' . $r['name_uk'] . ' ' . $r['url']), $q)) return false;
            return true;
        }));
        return $this->render('admin/pages/index', [
            'title' => 'Страницы', 'rows' => $rows, 'filter' => $filter, 'q' => Request::get('q'), 'counts' => $counts,
            'actions' => '<a class="btn btn-p" href="/admin/pages/new/">+ Новая страница</a>',
            'styles' => ['admin/content.css'], 'scripts' => ['admin/content.js'],
        ]);
    }

    public function edit(string $id = ''): Response
    {
        $db = App::db();
        $pageId = (int) $id;
        $page = $pageId ? $db->row('SELECT * FROM pages WHERE id = ?', [$pageId]) : null;
        if ($id !== '' && !$page) return $this->notFoundPage();
        $isNew = $page === null;
        $page ??= ['id' => 0, 'url' => '', 'name' => '', 'title' => '', 'h1' => '', 'meta_description' => '', 'meta_keywords' => '',
            'content' => '', 'status' => 1, 'in_menu' => 1, 'sort' => (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM pages WHERE url NOT LIKE ?', ['pages/%']),
            'canonical' => '', 'updated_at' => null] + array_fill_keys(array_keys(self::UK_FIELDS), '');
        $errors = [];
        $lang = Request::post('_lang') === 'uk' ? 'uk' : 'ru';

        if (Request::isPost()) {
            $old = $page;
            $data = [
                'name'             => mb_substr(Request::post('name'), 0, 255),
                'url'              => self::normalizeUrl(Request::post('url')),
                'title'            => mb_substr(Request::post('title'), 0, 500),
                'h1'               => mb_substr(Request::post('h1'), 0, 500),
                'meta_description' => Request::post('meta_description'),
                'meta_keywords'    => Request::post('meta_keywords'),
                'content'          => self::html('content'),
                'status'           => Request::post('status') === '1' ? 1 : 0,
                'in_menu'          => Request::post('in_menu') === '1' ? 1 : 0,
                'sort'             => max(-99999, min(99999, Request::postInt('sort'))),
                'canonical'        => self::normalizeCanonical(Request::post('canonical')),
            ] + self::ukValues(self::UK_FIELDS, ['content_uk']);
            if ($data['name'] === '') $errors['name'] = 'Укажите название страницы';
            if ($data['url'] === '' && $data['name'] !== '') $data['url'] = Str::slug($data['name'], 120) . '/';
            if ($err = self::urlError($data['url'], $pageId)) $errors['url'] = $err;
            if ($data['canonical'] === false) { $errors['canonical'] = 'Канонический адрес — путь с «/» в начале или полный адрес https://…'; $data['canonical'] = Request::post('canonical'); }

            if (!$errors) {
                $row = $data;
                foreach (['title', 'h1', 'meta_description', 'meta_keywords', 'canonical'] as $k) $row[$k] = $row[$k] === '' ? null : $row[$k];
                foreach (array_keys(self::UK_FIELDS) as $k) $row[$k] = trim((string) $row[$k]) === '' ? null : $row[$k];
                $row['updated_at'] = date('Y-m-d H:i:s');
                if ($isNew) {
                    $pageId = $db->insert('pages', $row);
                    $this->log('page_create', 'page', $pageId, ['url' => $row['url'], 'name' => $row['name']]);
                } else {
                    $db->update('pages', $row, 'id = ?', [$pageId]);
                    $this->log('page_update', 'page', $pageId, ['url' => $row['url'], 'name' => $row['name']]);
                }
                $msg = $isNew ? 'Страница создана.' : 'Страница сохранена.';
                // Адрес изменился — старый адрес ведёт на новый (301)
                if (!$isNew && $old['url'] !== $row['url'] && Request::post('make_redirect') === '1') {
                    // редирект с нового адреса (если был) больше не нужен — там теперь страница; иначе получится цикл
                    $db->delete('redirects', 'from_url = ?', ['/' . $row['url']]);
                    $msg .= ' ' . RedirectsController::put('/' . $old['url'], '/' . $row['url'], 301);
                    $this->log('redirect_save', 'redirect', null, ['from' => '/' . $old['url'], 'to' => '/' . $row['url']]);
                }
                Cache::flush();
                $this->flash($msg);
                return Response::redirect('/admin/pages/' . $pageId . '/' . ($lang === 'uk' ? '?lang=uk' : ''));
            }
            $page = $data + $page;
        } elseif (Request::get('lang') === 'uk') {
            $lang = 'uk';
        }

        $dup = str_starts_with((string) $page['url'], 'pages/');
        $original = $dup ? $db->row('SELECT id, url, name FROM pages WHERE url = ?', [substr((string) $page['url'], 6)]) : null;
        $redirectHere = $page['url'] !== '' ? $db->row('SELECT id, to_url FROM redirects WHERE from_url = ?', ['/' . $page['url']]) : null;
        // подсказки: что подставится по SEO-шаблонам, если поля пустые (для RU и UA)
        $all = Settings::all();
        $store = Seo::storeInfo();
        $tpl = static function (string $key, string $name, bool $uk) use ($all, $store): string {
            $t = (string) ($uk && ($all[$key . '.uk'] ?? '') !== '' ? $all[$key . '.uk'] : ($all[$key] ?? ''));
            return Seo::tpl($t, ['page' => ['name' => $name], 'store_info' => $store]);
        };
        $nameRu = $page['name'] ?: 'Название';
        $nameUk = ($page['name_uk'] ?? '') ?: $nameRu;
        $hints = [
            'ru' => ['title' => $tpl('seo.page_meta_title', $nameRu, false), 'desc' => $tpl('seo.page_meta_description', $nameRu, false), 'keys' => $tpl('seo.page_meta_keywords', $nameRu, false)],
            'uk' => ['title' => $tpl('seo.page_meta_title', $nameUk, true), 'desc' => $tpl('seo.page_meta_description', $nameUk, true), 'keys' => $tpl('seo.page_meta_keywords', $nameUk, true)],
        ];
        // на /ua/ пустое поле *_uk берёт русское значение страницы (DB::$localize), и только если пусто и оно — шаблон
        foreach (['title' => 'title', 'desc' => 'meta_description', 'keys' => 'meta_keywords'] as $h => $col) {
            $own = trim((string) ($page[$col] ?? ''));
            if ($own !== '') $hints['uk'][$h] = $own;
        }
        // шаблоны для страниц выключены — без своих значений title = название, description/keywords пустые
        if ((string) ($all['seo.page_is_enabled'] ?? '1') === '0') {
            foreach (['ru' => $nameRu, 'uk' => $nameUk] as $l => $n) {
                if (trim((string) $page['title']) === '') $hints[$l]['title'] = $n;
                foreach (['desc' => 'meta_description', 'keys' => 'meta_keywords'] as $h => $col) if (trim((string) $page[$col]) === '') $hints[$l][$h] = '';
            }
        }

        $actions = '';
        if (!$isNew && (int) $page['status']) {
            $actions = '<a class="btn" href="/' . e($page['url']) . '" target="_blank" rel="noopener">Открыть на сайте ↗</a>'
                . '<a class="btn" href="/ua/' . e($page['url']) . '" target="_blank" rel="noopener">UA ↗</a>';
        }
        $r = $this->render('admin/pages/edit', [
            'title' => $isNew ? 'Новая страница' : 'Страница: ' . $page['name'],
            'page' => $page, 'isNew' => $isNew, 'errors' => $errors, 'dup' => $dup, 'original' => $original, 'lang' => $lang,
            'redirectHere' => $redirectHere, 'hints' => $hints,
            'actions' => $actions, 'back' => ['/admin/pages/', 'Все страницы'],
            'styles' => ['admin/content.css'], 'scripts' => ['admin/content.js', 'admin/media.js'],
        ]);
        if ($errors) $r->status = 422;
        return $r;
    }

    public function delete(string $id): Response
    {
        $db = App::db();
        $page = $db->row('SELECT id, url, name FROM pages WHERE id = ?', [(int) $id]);
        if (!$page) return $this->notFoundPage();
        $db->delete('pages', 'id = ?', [(int) $page['id']]);
        Cache::flush();
        $this->log('page_delete', 'page', (int) $page['id'], ['url' => $page['url'], 'name' => $page['name']]);
        $msg = 'Страница «' . $page['name'] . '» удалена. Чтобы адрес /' . $page['url'] . ' не отдавал 404, добавьте редирект.';
        // редиректы, которые вели на эту страницу, теперь ведут на 404
        $to = (int) $db->value('SELECT COUNT(*) FROM redirects WHERE to_url = ?', ['/' . $page['url']]);
        if ($to) $msg .= ' Внимание: на этот адрес ведут редиректы (' . $to . ') — измените их в разделе «Редиректы».';
        $this->flash($msg);
        return Response::redirect('/admin/pages/');
    }

    /** « /O-kompanii » → 'O-kompanii/'; полный адрес сайта → путь */
    public static function normalizeUrl(string $u): string
    {
        $u = trim($u);
        $u = (string) preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', $u);
        $u = (string) preg_replace('/[?#].*$/s', '', $u);
        $u = rawurldecode($u);
        $u = (string) preg_replace('#/+#', '/', trim($u, " /\t\n\r"));
        return $u === '' ? '' : $u . '/';
    }

    /** Текст ошибки адреса или null */
    public static function urlError(string $url, int $exceptId = 0): ?string
    {
        if ($url === '') return 'Укажите адрес страницы';
        if (strlen($url) > 255) return 'Слишком длинный адрес';
        if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*/$#i', $url)) return 'В адресе допустимы латинские буквы, цифры, «-», «_» и «/» (например: o-kompanii/)';
        $first = strtolower(explode('/', $url)[0]);
        if (in_array($first, self::RESERVED, true)) return 'Адрес /' . $first . '/… занят разделом сайта — выберите другой';
        $busy = App::db()->row('SELECT id, name FROM pages WHERE url = ? AND id <> ?', [$url, $exceptId]);
        if ($busy) return 'Этот адрес уже занят страницей «' . $busy['name'] . '»';
        return null;
    }

    /**
     * Значения украинских полей из POST: [колонка => строка], с обрезкой по длине.
     * $html — колонки с HTML (не обрезаются trim'ом по краям).
     */
    public static function ukValues(array $fields, array $html = []): array
    {
        $out = [];
        foreach ($fields as $k => $max) {
            $v = in_array($k, $html, true) ? self::html($k) : Request::post($k);
            $out[$k] = $max > 0 ? mb_substr($v, 0, $max) : $v;
        }
        return $out;
    }

    /** HTML-поле формы как есть (пустое — если одни пробелы) */
    public static function html(string $key): string
    {
        $v = $_POST[$key] ?? '';
        $v = is_string($v) ? str_replace("\r\n", "\n", $v) : '';
        return trim($v) === '' ? '' : $v;
    }

    /** Канонический адрес: '' | '/path/' | 'https://…'; false — неверный */
    private static function normalizeCanonical(string $c): string|false
    {
        $c = trim($c);
        if ($c === '') return '';
        if (preg_match('#^https?://[^\s<>"]+$#i', $c)) return mb_substr($c, 0, 255);
        if (!str_starts_with($c, '/')) $c = '/' . $c;
        if (!preg_match('#^/[^\s<>"]*$#', $c)) return false;
        return mb_substr($c, 0, 255);
    }

    private function notFoundPage(): Response
    {
        $r = $this->render('admin/forbidden', ['title' => 'Страница не найдена', 'message' => 'Такой страницы нет — возможно, её уже удалили.']);
        $r->status = 404;
        return $r;
    }
}
