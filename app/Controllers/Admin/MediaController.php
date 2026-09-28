<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Media;

/**
 * «Изображения» — медиатека в стиле «Images» админки ARG FLEX: сетка файлов из public/uploads/ (новые сверху,
 * по 60 на странице, поиск по имени, фильтр по папке/месяцу), загрузка нескольких файлов перетаскиванием или кнопкой,
 * ссылка в буфер, размеры и вес, где используется, удаление (только администратор, с предупреждением).
 *
 * JSON для пикера картинок (public/assets/admin/media.js — кнопка data-media-pick на любом экране):
 *   GET  /admin/media/list.json?page=&q=&folder=  → {ok, items:[{url, path, name, width, height, size, size_h, date}], page, pages, total, limit, folders}
 *   POST /admin/media/upload/ (file)              → {ok, url, name, width, height, size, size_h} | {ok:false, error}
 *   GET  /admin/media/usage.json?f=               → {ok, count, usage:[{label, title, link}]}
 *   POST /admin/media/delete/ (f | f[], force)    → {ok, deleted, blocked, missing, error}
 * Менеджер видит и загружает файлы; удаляет только администратор.
 */
final class MediaController extends BaseController
{
    private const SORTS = ['new' => 'Сначала новые', 'old' => 'Сначала старые', 'name' => 'По имени', 'big' => 'Сначала тяжёлые'];
    private const ASSETS = ['styles' => ['admin/media.css'], 'scripts' => ['admin/media.js']];

    /**
     * Запрос больше post_max_size: PHP отбрасывает тело целиком (и файлы, и _token), и BaseController ответил бы
     * «Сессия устарела». Сотруднику (с разрешённого IP) отвечаем по делу — «файл слишком большой»; ничего не выполняется.
     */
    public function __construct()
    {
        if (Request::isPost() && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $ips = self::allowedIps();
            if (!$ips || in_array(Request::ip(), $ips, true)) {
                Session::start();
                if (Auth::isStaff()) {
                    $err = 'Слишком большой файл: можно до ' . Media::limitText() . ' за раз. Уменьшите картинку или загрузите файлы по одному.';
                    if (Request::isAjax()) { Response::json(['ok' => false, 'error' => $err], 413)->send(); exit; }
                    Session::flash('error', $err);
                    Response::redirect('/admin/media/')->send();
                    exit;
                }
            }
        }
        parent::__construct();
    }

    public function index(): Response
    {
        $folders = Media::folders();
        $f = $this->filters($folders);
        $res = Media::query($f + ['counts' => true], Request::page());
        return $this->render('admin/media/index', [
            'title' => 'Изображения',
            'stats' => Media::stats(),
            'missing' => $f['q'] === '' && $f['folder'] === null && $f['use'] === '' && $res['page'] === 1 ? Media::missing() : ['total' => 0, 'items' => []],
            'res' => $res,
            'pg' => new Paginator($res['total'], $res['per'], $res['page']),
            'f' => $f, 'folders' => $folders, 'sorts' => self::SORTS,
            'isAdmin' => Auth::isAdmin(),
            'limit' => Media::limitBytes(), 'limitText' => Media::limitText(),
            'actions' => '<label class="btn btn-p" for="md-files">+ Загрузить изображения</label>',
        ] + self::ASSETS);
    }

    /** Карточка файла: большой просмотр, свойства, ссылки, где используется, удаление */
    public function file(): Response
    {
        $item = Media::find(Request::get('f'));
        if ($item === null) {
            $r = $this->render('admin/forbidden', ['title' => 'Файл не найден', 'back' => ['/admin/media/', 'Все изображения'],
                'message' => 'Такого файла нет в медиатеке — возможно, его уже удалили или переименовали.']);
            $r->status = 404;
            return $r;
        }
        $backUrl = '/admin/media/' . ($item['dir'] !== '' ? '?folder=' . rawurlencode($item['dir']) : '');
        return $this->render('admin/media/file', [
            'title' => $item['name'],
            'item' => $item,
            'usage' => Media::usage($item['path']),
            'fullUrl' => url($item['url']),
            'isAdmin' => Auth::isAdmin(),
            'back' => [$backUrl, 'Все изображения'],
            // «Копировать ссылку» — <a>: у <button class="btn btn-p"> admin.css (button.btn) перебивает оранжевый фон
            'actions' => '<a class="btn" href="' . e($item['url']) . '" target="_blank" rel="noopener">Открыть файл ↗</a>'
                . ' <a class="btn btn-p" href="' . e($item['url']) . '" data-md-copy="' . e($item['url']) . '" role="button">Копировать ссылку</a>',
        ] + self::ASSETS);
    }

    /** Список для пикера */
    public function listJson(): Response
    {
        $folders = Media::folders();
        $f = $this->filters($folders);
        $per = max(12, min(120, Request::getInt('per', Media::PER_PAGE)));
        $res = Media::query($f, Request::page(), $per);
        $items = array_map(static fn(array $i) => [
            'url' => $i['url'], 'path' => $i['path'], 'name' => $i['name'], 'width' => $i['width'], 'height' => $i['height'],
            'size' => $i['size'], 'size_h' => $i['size_h'], 'date' => $i['date'],
        ], $res['items']);
        $folderList = [];
        foreach ($folders as $dir => $n) {
            $folderList[] = ['value' => (string) $dir === '' ? '/' : (string) $dir, 'label' => Media::folderLabel((string) $dir), 'count' => $n];
        }
        return Response::json(['ok' => true, 'items' => $items, 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'],
            'limit' => Media::limitBytes(), 'limit_h' => Media::limitText(), 'folders' => $folderList]);
    }

    /** Где используется файл (перед удалением) */
    public function usageJson(): Response
    {
        $rel = Media::clean(Request::get('f'));
        if ($rel === null || Media::path($rel) === null) return Response::json(['ok' => false, 'error' => 'Файл не найден'], 404);
        $usage = Media::usage($rel);
        return Response::json(['ok' => true, 'path' => $rel, 'count' => count($usage), 'usage' => $usage]);
    }

    /** Загрузка: поле file (одна картинка — ответ {ok, url, …}) или files[] (несколько) */
    public function upload(): Response
    {
        $files = self::uploadedFiles();
        $ajax = Request::isAjax();
        if (!$files) {
            $err = 'Выберите файлы для загрузки';
            if ($ajax) return Response::json(['ok' => false, 'error' => $err], 422);
            $this->flash($err, true);
            return Response::redirect('/admin/media/');
        }
        $results = [];
        foreach (array_slice($files, 0, 50) as $file) $results[] = Media::store($file);
        $ok = array_values(array_filter($results, static fn($r) => $r['ok']));
        $bad = array_values(array_filter($results, static fn($r) => !$r['ok']));
        if ($ok) $this->log('media_upload', 'media', null, ['files' => array_column($ok, 'url')]);

        if ($ajax) {
            if (count($results) === 1) return Response::json($results[0], $results[0]['ok'] ? 200 : 422);
            return Response::json(['ok' => !$bad, 'files' => $results, 'error' => $bad ? implode("\n", array_column($bad, 'error')) : null], $ok ? 200 : 422);
        }
        if ($ok) $this->flash('Загружено: ' . count($ok) . ' ' . plural(count($ok), 'файл', 'файла', 'файлов') . '.');
        if ($bad) $this->flash(implode(' ', array_column($bad, 'error')), true);
        return Response::redirect('/admin/media/');
    }

    /**
     * Удаление (только администратор). Используемые на сайте файлы без force=1 не удаляются —
     * в ответе они перечислены в blocked (для одного файла — с подробностями usage).
     */
    public function delete(): Response
    {
        $ajax = Request::isAjax();
        if (!Auth::isAdmin()) return $this->deleteResult($ajax, 403, 'Удалять файлы может только администратор.');

        $raw = Request::postArray('f');
        if (!$raw && Request::post('f') !== '') $raw = [Request::post('f')];
        $list = [];
        foreach (array_slice($raw, 0, 300) as $r) {
            $rel = is_string($r) ? Media::clean($r) : null;
            if ($rel !== null) $list[$rel] = true;
        }
        $list = array_keys($list);
        if (!$list) return $this->deleteResult($ajax, 422, 'Не выбраны файлы для удаления.');

        $force = Request::post('force') === '1';
        $used = $force ? [] : Media::usedMap(true);           // свежая карта — одним проходом по таблицам
        $deleted = $blocked = $missing = [];
        foreach ($list as $rel) {
            if (Media::path($rel) === null) { $missing[] = $rel; continue; }
            if (isset($used[$rel])) { $blocked[] = ['path' => $rel, 'name' => Media::base($rel), 'count' => $used[$rel]]; continue; }
            if (Media::delete($rel)) $deleted[] = $rel; else $missing[] = $rel;
        }
        if ($deleted) $this->log('media_delete', 'media', null, ['files' => $deleted, 'force' => $force]);

        $msg = [];
        if ($blocked) {
            $names = implode(', ', array_map(static fn($b) => '«' . $b['name'] . '»', array_slice($blocked, 0, 5))) . (count($blocked) > 5 ? ' и ещё ' . (count($blocked) - 5) : '');
            $msg[] = (count($blocked) === 1 ? 'Файл ' . $names . ' используется' : 'Файлы ' . $names . ' используются') . ' на сайте — подтвердите удаление.';
        }
        if ($missing) $msg[] = 'Не найдено: ' . count($missing) . ' ' . plural(count($missing), 'файл', 'файла', 'файлов') . '.';
        $error = $msg ? implode(' ', $msg) : null;

        if ($ajax) {
            $out = ['ok' => !$blocked && !$missing, 'deleted' => $deleted, 'blocked' => $blocked, 'missing' => $missing, 'error' => $error];
            if (count($list) === 1 && $blocked) $out['usage'] = Media::usage($list[0]);
            // «используется — подтвердите» — ожидаемый шаг, а не ошибка: 200 с ok:false и списком blocked
            return Response::json($out, $deleted || $blocked || !$missing ? 200 : 404);
        }
        if ($deleted) $this->flash('Удалено: ' . count($deleted) . ' ' . plural(count($deleted), 'файл', 'файла', 'файлов') . '.');
        if ($error) $this->flash($error . ($blocked ? ' Чтобы удалить, отметьте «Удалить, даже если используется».' : ''), true);
        // из карточки файла после удаления — в список (карточки больше нет)
        if ($deleted && Request::post('from') === 'file') return Response::redirect('/admin/media/');
        return $this->back('/admin/media/');
    }

    private function deleteResult(bool $ajax, int $status, string $error): Response
    {
        if ($ajax) return Response::json(['ok' => false, 'error' => $error], $status);
        $this->flash($error, true);
        return $this->back('/admin/media/');
    }

    /** Фильтры из GET: только допустимые значения (папка — из существующих, сортировка — из белого списка) */
    private function filters(array $folders): array
    {
        $fp = Request::get('folder');
        $folder = null;
        if ($fp === '/') $folder = isset($folders['']) ? '' : null;
        elseif ($fp !== '' && isset($folders[$fp])) $folder = $fp;
        $sort = Request::get('sort', 'new');
        $use = Request::get('use');
        return [
            'q' => mb_substr(Request::get('q'), 0, 100),
            'folder' => $folder,
            'use' => in_array($use, ['used', 'unused'], true) ? $use : '',
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'new',
        ];
    }

    /** $_FILES['file'] и $_FILES['files'][] → плоский список файлов */
    private static function uploadedFiles(): array
    {
        $out = [];
        foreach (['file', 'files'] as $key) {
            $f = $_FILES[$key] ?? null;
            if (!is_array($f) || !isset($f['error'])) continue;
            if (!is_array($f['error'])) {
                if ((int) $f['error'] !== UPLOAD_ERR_NO_FILE) $out[] = $f;
                continue;
            }
            foreach ($f['error'] as $i => $err) {
                if (is_array($err) || (int) $err === UPLOAD_ERR_NO_FILE) continue;
                $out[] = ['name' => (string) ($f['name'][$i] ?? ''), 'type' => (string) ($f['type'][$i] ?? ''),
                    'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''), 'error' => (int) $err, 'size' => (int) ($f['size'][$i] ?? 0)];
            }
        }
        return $out;
    }
}
