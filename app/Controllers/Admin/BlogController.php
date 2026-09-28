<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Str;
use App\Services\AdminCatalog;
use App\Services\HtmlSanitizer;

/**
 * Блог: статьи /blog/{url}/ — список, создание, редактирование, удаление.
 * Украинская версия (/ua/blog/…) — колонки *_uk; пустое поле → показывается русский текст.
 */
final class BlogController extends BaseController
{
    private const PER_PAGE = 30;
    public const STATUSES = ['published' => 'Опубликована', 'draft' => 'Черновик'];
    /** Поля украинской версии: колонка → максимальная длина (0 — без ограничения) */
    public const UK_FIELDS = ['title_uk' => 255, 'text_before_cut_uk' => 0, 'text_uk' => 0, 'meta_title_uk' => 500, 'meta_description_uk' => 0, 'meta_keywords_uk' => 0];

    public function index(): Response
    {
        $db = App::db();
        $q = Request::get('q');
        $status = array_key_exists(Request::get('status'), self::STATUSES) ? Request::get('status') : '';
        $where = ['1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(title LIKE ? OR title_uk LIKE ? OR url LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
        $w = implode(' AND ', $where);
        $total = (int) $db->value("SELECT COUNT(*) FROM blog_posts WHERE $w", $params);
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $rows = $db->all("SELECT id, url, title, title_uk, image, status, published_at, updated_at,
                (text_uk IS NOT NULL AND text_uk <> '') AS has_uk FROM blog_posts WHERE $w
            ORDER BY published_at DESC, id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . $pg->offset, $params);
        $counts = $db->pairs('SELECT status, COUNT(*) FROM blog_posts GROUP BY status');
        return $this->render('admin/blog/index', [
            'title' => 'Блог', 'rows' => $rows, 'pg' => $pg, 'q' => $q, 'status' => $status, 'total' => $total, 'counts' => $counts,
            'actions' => '<a class="btn btn-p" href="/admin/blog/new/">+ Новая статья</a>',
            'styles' => ['admin/content.css'], 'scripts' => ['admin/content.js'],
        ]);
    }

    public function edit(string $id = ''): Response
    {
        $db = App::db();
        $postId = (int) $id;
        $post = $postId ? $db->row('SELECT * FROM blog_posts WHERE id = ?', [$postId]) : null;
        if ($id !== '' && !$post) return $this->missing();
        $isNew = $post === null;
        $post ??= ['id' => 0, 'url' => '', 'title' => '', 'text_before_cut' => '', 'text' => '', 'meta_title' => '', 'meta_description' => '',
            'meta_keywords' => '', 'image' => '', 'status' => 'published', 'published_at' => date('Y-m-d H:i:00'), 'updated_at' => null]
            + array_fill_keys(array_keys(self::UK_FIELDS), '');
        $errors = [];
        $lang = Request::post('_lang') === 'uk' || (!Request::isPost() && Request::get('lang') === 'uk') ? 'uk' : 'ru';

        if (Request::isPost()) {
            $data = [
                'title'            => mb_substr(Request::post('title'), 0, 255),
                'url'              => trim(Request::post('url'), " /\t"),
                'text_before_cut'  => PagesController::html('text_before_cut', $post),   // без правок — как в базе, без очистки
                'text'             => PagesController::html('text', $post),
                'meta_title'       => mb_substr(Request::post('meta_title'), 0, 500),
                'meta_description' => Request::post('meta_description'),
                'meta_keywords'    => Request::post('meta_keywords'),
                'image'            => Request::post('image'),
                'status'           => array_key_exists(Request::post('status'), self::STATUSES) ? Request::post('status') : 'draft',
                'published_at'     => self::parseDate(Request::post('published_at')),
            ] + PagesController::ukValues(self::UK_FIELDS, ['text_before_cut_uk', 'text_uk'], $post);
            $data = AdminCatalog::keepUnchanged($data, $post);   // без правок — байт в байт (CRLF в текстах из Webasyst)
            if ($data['title'] === '') $errors['title'] = 'Укажите заголовок статьи';
            if ($data['url'] === '' && $data['title'] !== '') $data['url'] = Str::slug($data['title'], 120);
            if ($err = self::urlError($data['url'], $postId)) $errors['url'] = $err;
            if ($data['published_at'] === null) { $errors['published_at'] = 'Неверная дата'; $data['published_at'] = $post['published_at']; }

            // Картинка: загрузка файла, удаление или путь вручную
            if (Request::post('image_remove') === '1') $data['image'] = '';
            $file = $_FILES['image_file'] ?? null;
            if ($file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $up = UploadController::save($file);
                if ($up['ok']) $data['image'] = $up['url']; else $errors['image'] = $up['error'];
            }
            if ($data['image'] !== '' && !preg_match('#^(/(?!/)[^\s"<>]*|https?://[^\s"<>]+)$#i', $data['image'])) $errors['image'] = 'Путь к картинке должен начинаться с «/» или https://';
            $data['image'] = mb_substr($data['image'], 0, 255);

            if (!$errors) {
                $row = $data;
                foreach (['text_before_cut', 'meta_title', 'meta_description', 'meta_keywords', 'image'] as $k) $row[$k] = trim((string) $row[$k]) === '' ? null : $row[$k];
                foreach (array_keys(self::UK_FIELDS) as $k) $row[$k] = trim((string) $row[$k]) === '' ? null : $row[$k];
                $row['updated_at'] = date('Y-m-d H:i:s');
                if ($isNew) {
                    $postId = $db->insert('blog_posts', $row);
                    $this->log('blog_create', 'blog_post', $postId, ['url' => $row['url'], 'title' => $row['title']]);
                } else {
                    $db->update('blog_posts', $row, 'id = ?', [$postId]);
                    $this->log('blog_update', 'blog_post', $postId, ['url' => $row['url'], 'title' => $row['title']]);
                }
                $msg = $isNew ? 'Статья создана.' : 'Статья сохранена.';
                if (!$isNew && $post['url'] !== $row['url'] && $post['status'] === 'published' && Request::post('make_redirect') === '1') {
                    $db->delete('redirects', 'from_url = ?', ['/blog/' . $row['url'] . '/']);
                    $msg .= ' ' . RedirectsController::put('/blog/' . $post['url'] . '/', '/blog/' . $row['url'] . '/', 301);
                }
                Cache::flush();
                $this->flash($msg . HtmlSanitizer::notice());   // HTML-поля очищает PagesController::html() (только изменённые)
                return Response::redirect('/admin/blog/' . $postId . '/' . ($lang === 'uk' ? '?lang=uk' : ''));
            }
            $post = $data + $post;
        }

        $actions = !$isNew && $post['status'] === 'published'
            ? '<a class="btn" href="/blog/' . e(rawurlencode((string) $post['url'])) . '/" target="_blank" rel="noopener">Открыть на сайте ↗</a>'
              . '<a class="btn" href="/ua/blog/' . e(rawurlencode((string) $post['url'])) . '/" target="_blank" rel="noopener">UA ↗</a>' : '';
        $r = $this->render('admin/blog/edit', [
            'title' => $isNew ? 'Новая статья' : 'Статья: ' . $post['title'],
            'post' => $post, 'isNew' => $isNew, 'errors' => $errors, 'actions' => $actions, 'back' => ['/admin/blog/', 'Все статьи'],
            'uploadLimit' => UploadController::limitText(), 'lang' => $lang, 'blogName' => self::blogNames(),
            'styles' => ['admin/content.css'], 'scripts' => ['admin/content.js', 'admin/media.js'],
        ]);
        if ($errors) $r->status = 422;
        return $r;
    }

    /**
     * Название блога для title статьи без своего meta_title (как Front\BlogController::blogName():
     * «Название » Заголовок»): ['ru' => …, 'uk' => …] — настройки blog.name(.uk), иначе название магазина.
     */
    private static function blogNames(): array
    {
        $all = Settings::all();
        $ru = (string) (($all['blog.name'] ?? '') !== '' ? $all['blog.name'] : (($all['store_name'] ?? '') !== '' ? $all['store_name'] : 'Tomobuv'));
        $uk = (string) (($all['blog.name.uk'] ?? '') !== '' ? $all['blog.name.uk'] : $ru);
        return ['ru' => $ru, 'uk' => $uk];
    }

    public function delete(string $id): Response
    {
        $db = App::db();
        $post = $db->row('SELECT id, url, title FROM blog_posts WHERE id = ?', [(int) $id]);
        if (!$post) return $this->missing();
        $db->delete('blog_posts', 'id = ?', [(int) $post['id']]);
        Cache::flush();
        $this->log('blog_delete', 'blog_post', (int) $post['id'], ['url' => $post['url'], 'title' => $post['title']]);
        $this->flash('Статья «' . $post['title'] . '» удалена.');
        return Response::redirect('/admin/blog/');
    }

    /** Адреса /blog/…/, занятые самим блогом: /blog/rss/ — RSS-лента (статья с таким адресом не откроется) */
    private const RESERVED = ['rss'];

    private static function urlError(string $url, int $exceptId): ?string
    {
        if ($url === '') return 'Укажите адрес статьи';
        if (strlen($url) > 190) return 'Слишком длинный адрес';
        if (!preg_match('#^[a-z0-9_-]+$#i', $url)) return 'В адресе допустимы латинские буквы, цифры, «-» и «_» (без «/»)';
        if (in_array(strtolower($url), self::RESERVED, true)) return 'Адрес /blog/' . strtolower($url) . '/ занят (RSS-лента блога) — выберите другой';
        $busy = App::db()->row('SELECT id, title FROM blog_posts WHERE url = ? AND id <> ?', [$url, $exceptId]);
        return $busy ? 'Этот адрес уже занят статьёй «' . $busy['title'] . '»' : null;
    }

    /** «2026-09-27T10:30» (datetime-local) → «2026-09-27 10:30:00» */
    private static function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return date('Y-m-d H:i:00');
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d', 'd.m.Y H:i', 'd.m.Y'] as $f) {
            $d = \DateTime::createFromFormat('!' . $f, $s);
            if ($d && $d->format($f) === $s) return $d->format('Y-m-d H:i:s');
        }
        return null;
    }

    private function missing(): Response
    {
        $r = $this->render('admin/forbidden', ['title' => 'Статья не найдена', 'message' => 'Такой статьи нет — возможно, её уже удалили.']);
        $r->status = 404;
        return $r;
    }
}
