<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\Import\CsvReader;
use App\Services\Import\Importer;
use App\Services\Import\Reader;

/**
 * Админка → «Импорт / экспорт»: загрузка файлов поставщиков, сопоставление колонок, предпросмотр,
 * пошаговая обработка (AJAX, по 300–500 строк за запрос), журнал, профили поставщиков.
 */
final class ImportController extends BaseController
{
    private const JOBS_PER_PAGE = 20;
    private const LOG_PER_PAGE = 100;
    private const LEVELS = ['error' => 'Ошибки', 'conflict' => 'Изменены на сайте', 'skip' => 'Пропущено', 'warn' => 'Предупреждения'];
    /** Сколько товаров, изменённых на сайте после выгрузки, показывать списком в итоге (остальные — в журнале) */
    private const CONFLICTS_SHOWN = 50;

    public function __construct()
    {
        parent::__construct();
        Importer::ensureSchema();
    }

    // ======================================================================= список заданий и загрузка

    public function index(): Response
    {
        $db = App::db();
        if (random_int(1, 20) === 1) Importer::cleanup();                 // изредка — уборка старых заданий
        $total = (int) $db->value('SELECT COUNT(*) FROM import_jobs');
        $pg = new Paginator($total, self::JOBS_PER_PAGE, Request::page());
        $jobs = $db->all('SELECT j.id, j.name, j.format, j.status, j.total, j.processed, j.created, j.updated, j.unchanged, j.skipped, j.error_count,
                j.created_at, j.finished_at, j.updated_at, p.name profile_name
            FROM import_jobs j LEFT JOIN import_profiles p ON p.id = j.profile_id
            ORDER BY j.id DESC LIMIT ' . self::JOBS_PER_PAGE . ' OFFSET ' . $pg->offset);
        return $this->render('admin/import/index', [
            'title'     => 'Импорт / экспорт',
            'actions'   => '<a class="btn" href="/admin/export/">Экспорт товаров в CSV</a>',
            'styles'    => ['admin/import.css'],
            'scripts'   => ['admin/import.js'],
            'jobs'      => $jobs,
            'pg'        => $pg,
            'profiles'  => Importer::profiles(),
            'maxSize'   => Importer::maxFileSize(),
            'chunkSize' => self::chunkSize(),
            'formLimit' => Importer::maxUploadSize(),
            'statuses'  => Importer::STATUSES,
            'keys'      => Importer::KEYS,
        ]);
    }

    /** Размер части файла при загрузке по частям — меньше лимитов php.ini (upload_max_filesize, post_max_size) */
    private static function chunkSize(): int
    {
        return (int) max(262144, min(8 * 1048576, Importer::maxUploadSize() - 131072));
    }

    /**
     * Загрузка файла: обычная форма (multipart), по частям из import.js (upload_id + offset + size)
     * или по ссылке (source_url). Файлы — в storage/import (не в public), с безопасным именем.
     */
    public function upload(): Response
    {
        $profileId = Request::postInt('profile_id');
        $profile = Importer::profile($profileId);
        if ($profileId && !$profile) return $this->uploadFail('Профиль не найден — обновите страницу.');

        // 1. по частям (AJAX)
        $uid = Request::post('upload_id');
        if ($uid !== '') return $this->uploadChunk($uid, $profile);

        // 2. по ссылке
        $url = Request::post('source_url');
        $file = $_FILES['file'] ?? null;
        $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$hasFile && $url !== '') {
            $url = Importer::cleanSourceUrl($url);
            if ($url === '') return $this->uploadFail('Неверная ссылка — нужен адрес вида https://…');
            $dest = Importer::dir() . '/tmp/' . Str::random(8) . '.dl';
            if (function_exists('set_time_limit')) @set_time_limit(300);                                            // большой фид качается дольше 30 с
            try {
                Importer::download($url, $dest);
            } catch (\Throwable $e) {
                @unlink($dest);
                return $this->uploadFail($e->getMessage());
            }
            $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) ?: (string) parse_url($url, PHP_URL_HOST);
            if (!isset(Reader::EXTENSIONS[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) $name .= '.' . self::sniffExt($dest);
            return $this->finalize($dest, $name, $profile, $url);
        }

        // 3. обычная форма
        if (!$hasFile) return $this->uploadFail('Выберите файл или укажите ссылку на файл поставщика.');
        $err = (int) $file['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return $this->uploadFail('Файл больше ' . self::mb(Importer::maxUploadSize()) . ' — сервер не принимает такие одним запросом. Включите JavaScript (файл загрузится частями) или загрузите по ссылке.');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) return $this->uploadFail('Файл не загрузился (код ' . $err . ') — попробуйте ещё раз.');
        $name = (string) $file['name'];
        if (($e = self::checkName($name, (int) $file['size'])) !== null) return $this->uploadFail($e);
        $dest = Importer::dir() . '/tmp/' . Str::random(8) . '.up';
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) return $this->uploadFail('Не удалось сохранить файл на сервере.');
        return $this->finalize($dest, $name, $profile, '');
    }

    /** Приём очередной части файла. Повтор уже принятой части (после обрыва) не ломает файл. */
    private function uploadChunk(string $uid, ?array $profile): Response
    {
        if (!preg_match('/^[a-f0-9]{16,40}$/', $uid)) return Response::json(['ok' => false, 'error' => 'Неверный идентификатор загрузки'], 400);
        $name = Request::post('name');
        $size = Request::postInt('size');
        $offset = Request::postInt('offset');
        if (($e = self::checkName($name, $size)) !== null) return Response::json(['ok' => false, 'error' => $e], 400);
        $chunk = $_FILES['chunk'] ?? null;
        if (!is_array($chunk) || (int) ($chunk['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $chunk['tmp_name'])) {
            return Response::json(['ok' => false, 'error' => 'Часть файла не дошла до сервера — повторите загрузку.'], 400);
        }
        $part = Importer::dir() . '/tmp/' . $uid . '.part';
        clearstatcache(true, $part);
        $have = is_file($part) ? (int) filesize($part) : 0;
        $len = (int) filesize((string) $chunk['tmp_name']);
        if ($offset === 0 && $have > 0 && $have !== $len) { @unlink($part); $have = 0; }   // начали заново
        if ($offset + $len > $size) return Response::json(['ok' => false, 'error' => 'Размер частей не совпадает с размером файла.'], 400);
        if ($have === $offset) {
            $in = fopen((string) $chunk['tmp_name'], 'rb');
            $out = fopen($part, 'ab');
            if (!$in || !$out) return Response::json(['ok' => false, 'error' => 'Не удалось сохранить часть файла.'], 500);
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $have += $len;
        } elseif ($have !== $offset + $len) {                               // не та часть — пусть клиент продолжит с $have
            return Response::json(['ok' => true, 'done' => false, 'received' => $have]);
        }
        if ($have < $size) return Response::json(['ok' => true, 'done' => false, 'received' => $have]);
        return $this->finalize($part, $name, $profile, '');
    }

    /** Имя и размер файла: расширение из списка, размер в пределах лимита. null — всё в порядке. */
    private static function checkName(string $name, int $size): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !isset(Reader::EXTENSIONS[$ext])) {
            return $ext === 'xls' ? 'Старый формат XLS не поддерживается — откройте файл в Excel и сохраните как XLSX или CSV.'
                : 'Подходят файлы CSV, TXT, XLSX, XML и YML.';
        }
        if ($size <= 0) return 'Файл пустой.';
        if ($size > Importer::maxFileSize()) return 'Файл больше ' . self::mb(Importer::maxFileSize()) . '.';
        return null;
    }

    /** Формат скачанного по ссылке файла без расширения — по первым байтам */
    private static function sniffExt(string $path): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 512);
        if (str_starts_with($head, "PK\x03\x04")) return 'xlsx';
        return str_starts_with(ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $head)), '<') ? 'xml' : 'csv';
    }

    /** Проверка содержимого, перенос в storage/import, создание задания */
    private function finalize(string $tmp, string $name, ?array $profile, string $sourceUrl): Response
    {
        $format = Reader::detect($tmp, $name);
        if ($format === null) {
            @unlink($tmp);
            return $this->uploadFail('Содержимое файла не похоже на ' . strtoupper(pathinfo($name, PATHINFO_EXTENSION)) . ' — проверьте файл.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $safe = date('Ymd-His') . '-' . Str::random(4) . '.' . ($ext === 'yml' ? 'yml' : ($ext === 'tsv' || $ext === 'txt' ? 'csv' : $ext));
        $path = Importer::dir() . '/' . $safe;
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return $this->uploadFail('Не удалось сохранить файл на сервере.');
        }
        $popt = $profile ? (json_decode((string) $profile['options'], true) ?: []) : [];
        try {
            $meta = Reader::prepare($format, $path, $popt);
        } catch (\Throwable $e) {
            @unlink($path);
            return $this->uploadFail($e->getMessage());
        }
        if ($sourceUrl !== '') $meta['source_url'] = $sourceUrl;
        $name = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))), 0, 200);
        $id = Importer::createJob($path, $name, $format, $meta, $profile, Auth::id());
        $this->log('import_upload', 'import_job', $id, ['file' => $name, 'size' => (int) filesize($path), 'profile' => $profile['id'] ?? null]);
        $to = '/admin/import/' . $id . '/';
        if (Request::isAjax()) return Response::json(['ok' => true, 'done' => true, 'redirect' => $to]);
        return Response::redirect($to);
    }

    private function uploadFail(string $msg): Response
    {
        if (Request::isAjax()) return Response::json(['ok' => false, 'error' => $msg], 422);
        $this->flash($msg, true);
        return Response::redirect('/admin/import/');
    }

    private static function mb(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' МБ' : max(1, (int) round($bytes / 1024)) . ' КБ';
    }

    // ======================================================================= задание

    private function jobOr404(string $id): ?array
    {
        return ctype_digit($id) ? Importer::job((int) $id) : null;
    }

    private function notFoundPage(): Response
    {
        return Response::html($this->render('admin/forbidden', ['title' => 'Задание не найдено',
            'message' => 'Такого задания импорта нет — возможно, оно удалено.', 'back' => ['/admin/import/', 'Импорт / экспорт']])->body, 404);
    }

    /** Страница задания: разбор файла → сопоставление и предпросмотр → прогресс → (итог в журнале) */
    public function show(string $id): Response
    {
        $job = $this->jobOr404($id);
        if (!$job) return $this->notFoundPage();
        if ($job['status'] === 'done' || ($job['status'] === 'error' && $job['total'] > 0 && $job['processed'] > 0)) {
            return Response::redirect('/admin/import/' . $job['id'] . '/log/');
        }
        $base = [
            'job'     => $job,
            'title'   => 'Импорт: ' . ($job['name'] !== '' ? $job['name'] : 'задание №' . $job['id']),
            'back'    => ['/admin/import/', 'Импорт / экспорт'],
            'styles'  => ['admin/import.css'],
            'scripts' => ['admin/import.js'],
        ];
        if ($job['status'] !== 'new' && $job['status'] !== 'error') {
            return $this->render('admin/import/progress', $base + ['autostart' => $job['status'] === 'parsing' || Request::get('go') === '1',
                'progress' => Importer::progress($job)]);
        }
        $sample = Importer::sample($job['id'], 20);
        $preview = null;
        if ($job['status'] === 'new') {
            try {
                $preview = Importer::preview($job, 20);
            } catch (\Throwable $e) {
                $preview = ['rows' => [], 'summary' => [], 'badCategories' => [], 'error' => $e->getMessage()];
            }
        }
        return $this->render('admin/import/show', $base + [
            'sample'     => $sample,
            'preview'    => $preview,
            'choices'    => Importer::fieldChoices(),
            'categories' => Importer::categoryChoices(),
            'profiles'   => Importer::profiles(),
            'suppliers'  => Importer::suppliers(),
            'problems'   => $job['status'] === 'new' ? Importer::validateStart($job) : [],
            'delimiters' => CsvReader::DELIMITERS,
        ]);
    }

    /** Сохранение сопоставления и настроек; do=start — запуск импорта */
    public function save(string $id): Response
    {
        $job = $this->jobOr404($id);
        if (!$job) return $this->notFoundPage();
        if (!in_array($job['status'], ['new', 'error'], true)) {
            $this->flash('Импорт уже идёт — настройки менять нельзя.', true);
            return Response::redirect('/admin/import/' . $job['id'] . '/');
        }
        [$map, $opt, $reparse] = Importer::settingsFromPost($_POST, $job);
        Importer::saveSettings($job['id'], $map, $opt);
        if ($reparse || $job['status'] === 'error') {
            Importer::restart($job['id']);
            $this->flash('Параметры чтения файла изменены — файл разбирается заново.');
            return Response::redirect('/admin/import/' . $job['id'] . '/');
        }
        $job = Importer::job($job['id']);
        if (Request::post('do') === 'start') {
            $problems = Importer::validateStart($job);
            if ($problems) {
                $this->flash(implode(' ', $problems), true);
                return Response::redirect('/admin/import/' . $job['id'] . '/');
            }
            App::db()->query("UPDATE import_jobs SET status = 'running', started_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'new'", [$job['id']]);
            $this->log('import_start', 'import_job', $job['id'], ['rows' => $job['total'], 'key' => $opt['key'], 'mode' => $opt['mode'], 'markup' => $opt['markup']]);
            return Response::redirect('/admin/import/' . $job['id'] . '/?go=1');
        }
        $this->flash('Настройки сохранены — проверьте предпросмотр ниже.');
        return Response::redirect('/admin/import/' . $job['id'] . '/#preview');
    }

    /** Один шаг задания (AJAX из import.js): разбор файла, пачка строк, завершение или порция фото */
    public function run(string $id): Response
    {
        $job = $this->jobOr404($id);
        if (!$job) return Response::json(['ok' => false, 'error' => 'Задание не найдено', 'done' => true], 404);
        session_write_close();                                                // не держим блокировку сессии во время шага
        $res = Importer::step($job['id'], 15.0);
        $res['redirect'] = in_array($res['status'] ?? '', ['done', 'error'], true) && ($res['processed'] ?? 0) > 0
            ? '/admin/import/' . $job['id'] . '/log/' : (($res['status'] ?? '') === 'new' || ($res['status'] ?? '') === 'error' ? '/admin/import/' . $job['id'] . '/' : null);
        if (!Request::isAjax()) return Response::redirect($res['redirect'] ?? '/admin/import/' . $job['id'] . '/');
        return Response::json($res);
    }

    /** Повторить: разобрать файл заново и пройти импорт с теми же настройками */
    public function restart(string $id): Response
    {
        $job = $this->jobOr404($id);
        if (!$job) return $this->notFoundPage();
        if (!is_file(Importer::dir() . '/' . basename((string) $job['file']))) {
            $this->flash('Файл этого задания уже удалён (файлы хранятся 7 дней) — загрузите его заново.', true);
            return Response::redirect('/admin/import/' . $job['id'] . '/log/');
        }
        if (in_array($job['status'], ['running', 'finishing', 'images'], true) && strtotime((string) $job['updated_at']) > time() - 60) {
            $this->flash('Импорт сейчас идёт — дождитесь окончания или остановите его.', true);
            return Response::redirect('/admin/import/' . $job['id'] . '/');
        }
        $overwrite = Request::post('overwrite') === '1';                     // «Повторить с перезаписью» из итога импорта
        if ($overwrite) Importer::saveSettings($job['id'], $job['mapping'], ['overwrite' => 1] + $job['options']);
        Importer::restart($job['id']);
        $this->log('import_restart', 'import_job', $job['id'], $overwrite ? ['overwrite' => 1] : null);
        $this->flash($overwrite
            ? 'Файл разбирается заново с галочкой «Перезаписать всё равно» — проверьте предпросмотр и запустите импорт.'
            : 'Файл разбирается заново — затем проверьте настройки и запустите импорт.');
        return Response::redirect('/admin/import/' . $job['id'] . '/');
    }

    public function skipImages(string $id): Response
    {
        $job = $this->jobOr404($id);
        if (!$job) return Response::json(['ok' => false, 'error' => 'Задание не найдено'], 404);
        Importer::skipImages($job['id']);
        if (Request::isAjax()) return Response::json(Importer::progress(Importer::job($job['id'])));
        return Response::redirect('/admin/import/' . $job['id'] . '/');
    }

    public function delete(string $id): Response
    {
        $job = $this->jobOr404($id);
        if (!$job) return $this->notFoundPage();
        Importer::deleteJob($job['id']);
        $this->log('import_delete', 'import_job', $job['id'], ['file' => $job['name']]);
        $this->flash('Задание удалено. Товары, которые оно создало или изменило, остались на сайте.');
        return Response::redirect('/admin/import/');
    }

    // ======================================================================= журнал

    public function journal(string $id): Response
    {
        $job = $this->jobOr404($id);
        if (!$job) return $this->notFoundPage();
        $db = App::db();
        $counts = array_map('intval', $db->pairs('SELECT level, COUNT(*) FROM import_errors WHERE job_id = ? GROUP BY level', [$job['id']]));
        $level = Request::get('level');
        if (!isset(self::LEVELS[$level])) {
            $level = 'warn';
            foreach (['error', 'conflict', 'skip'] as $l) if (isset($counts[$l])) { $level = $l; break; }
        }
        $total = (int) ($counts[$level] ?? 0);
        $pg = new Paginator($total, self::LOG_PER_PAGE, Request::page());
        $rows = $total ? $db->all('SELECT n, message FROM import_errors WHERE job_id = ? AND level = ? ORDER BY n, id LIMIT '
            . self::LOG_PER_PAGE . ' OFFSET ' . $pg->offset, [$job['id'], $level]) : [];
        $created = $db->col('SELECT s.product_id FROM import_seen s WHERE s.job_id = ? AND s.created = 1 ORDER BY s.product_id LIMIT 30', [$job['id']]);
        $createdRows = [];
        if ($created) {
            [$ph, $vals] = $db->in($created);
            $createdRows = $db->all("SELECT id, name, url, price, status FROM products WHERE id IN ($ph) ORDER BY id", $vals);
        }
        // товары, изменённые на сайте после выгрузки файла (колонка updated_at): не перезаписаны
        $conflictTotal = (int) $db->value('SELECT COUNT(*) FROM import_seen WHERE job_id = ? AND conflict = 1', [$job['id']]);
        $conflictRows = $conflictTotal ? $db->all('SELECT p.id, p.name, p.status, p.updated_at FROM import_seen s JOIN products p ON p.id = s.product_id
            WHERE s.job_id = ? AND s.conflict = 1 ORDER BY p.updated_at DESC, p.id LIMIT ' . self::CONFLICTS_SHOWN, [$job['id']]) : [];
        $active = in_array($job['status'], ['parsing', 'running', 'finishing', 'images'], true);
        return $this->render('admin/import/log', [
            'title'    => 'Итог импорта: ' . ($job['name'] !== '' ? $job['name'] : 'задание №' . $job['id']),
            'back'     => ['/admin/import/', 'Импорт / экспорт'],
            'styles'   => ['admin/import.css'],
            'scripts'  => ['admin/import.js'],
            'job'      => $job,
            'progress' => Importer::progress($job),
            'counts'   => $counts,
            'level'    => $level,
            'levels'   => self::LEVELS,
            'rows'     => $rows,
            'pg'       => $pg,
            'createdRows' => $createdRows,
            'conflictTotal' => max($conflictTotal, (int) ($counts['conflict'] ?? 0)),
            'conflictRows'  => $conflictRows,
            'active'   => $active,
            'fileExists' => is_file(Importer::dir() . '/' . basename((string) $job['file'])),
            'profile'  => $job['profile_id'] ? Importer::profile((int) $job['profile_id']) : null,
        ]);
    }

    /** Журнал ошибок и пропусков в CSV (для отправки поставщику) */
    public function errorsCsv(string $id): Response
    {
        $job = $this->jobOr404($id);
        if (!$job) return $this->notFoundPage();
        $names = ['error' => 'ошибка', 'conflict' => 'изменён на сайте', 'skip' => 'пропуск', 'warn' => 'предупреждение'];
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['Строка', 'Тип', 'Сообщение'], ';', '"', '');
        $last = 0;
        do {                                                                  // порциями по id — без загрузки всего журнала в память
            $rows = App::db()->all('SELECT id, n, level, message FROM import_errors WHERE job_id = ? AND id > ? ORDER BY id LIMIT 5000', [$job['id'], $last]);
            foreach ($rows as $r) {
                // сообщение начинается с названия товара из файла поставщика: «=HYPERLINK(…)» не должно стать формулой в Excel
                $msg = (string) $r['message'];
                if ($msg !== '' && str_contains("=+-@\t\r", $msg[0])) $msg = "'" . $msg;
                fputcsv($h, [(int) $r['n'], $names[$r['level']] ?? $r['level'], $msg], ';', '"', '');
                $last = (int) $r['id'];
            }
        } while (count($rows) === 5000);
        rewind($h);
        $body = (string) stream_get_contents($h);
        fclose($h);
        return Response::text($body, 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="import-' . $job['id'] . '-errors.csv"');
    }

    // ======================================================================= профили

    /** Сохранить настройки задания как профиль поставщика (кнопка на странице задания) */
    public function saveProfile(): Response
    {
        $job = Importer::job(Request::postInt('job_id'));
        if (!$job) {
            $this->flash('Задание не найдено.', true);
            return Response::redirect('/admin/import/');
        }
        if (in_array($job['status'], ['new', 'error'], true)) {                 // сначала — текущее состояние формы
            [$map, $opt] = Importer::settingsFromPost($_POST, $job);
            Importer::saveSettings($job['id'], $map, $opt);
            $job = Importer::job($job['id']);
        }
        $pid = Request::postInt('profile_id');
        $name = trim(Request::post('profile_name'));
        if ($pid && ($p = Importer::profile($pid)) && $name === '') $name = (string) $p['name'];
        if ($name === '') {
            $this->flash('Укажите название профиля, например, имя поставщика.', true);
            return Response::redirect('/admin/import/' . $job['id'] . '/');
        }
        $pid = Importer::saveProfile($pid, $name, $job);
        $this->log('import_profile_save', 'import_profile', $pid, ['name' => $name]);
        $this->flash('Профиль «' . $name . '» сохранён — выберите его при следующей загрузке файла этого поставщика.');
        return Response::redirect('/admin/import/' . $job['id'] . '/');
    }

    public function deleteProfile(string $id): Response
    {
        $p = ctype_digit($id) ? Importer::profile((int) $id) : null;
        if (!$p) {
            $this->flash('Профиль не найден.', true);
            return Response::redirect('/admin/import/');
        }
        App::db()->delete('import_profiles', 'id = ?', [(int) $p['id']]);
        App::db()->query('UPDATE import_jobs SET profile_id = NULL WHERE profile_id = ?', [(int) $p['id']]);
        $this->log('import_profile_delete', 'import_profile', (int) $p['id'], ['name' => $p['name']]);
        $this->flash('Профиль «' . $p['name'] . '» удалён.');
        return Response::redirect('/admin/import/');
    }
}
