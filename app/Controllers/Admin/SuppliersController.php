<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\Import\Importer;
use App\Services\Suppliers\JongGolf;
use App\Services\Suppliers\JongGolfMap;

/**
 * «Каталог → Поставщики → Jong•Golf» (только администратор): настройки автозагрузки по API (как cfg старого
 * загрузчика), таблица соответствия категорий, коэффициенты цен, проверка связи, пробный прогон, запуск, журнал.
 * Шаги запуска выполняет suppliers.js (POST …/runs/{id}/step/), как задания импорта.
 */
final class SuppliersController extends BaseController
{
    protected const MANAGER_ALLOWED = false;

    private const BASE = '/admin/suppliers/jonggolf/';
    private const MAP_PER_PAGE = 50;
    private const LOG_PER_PAGE = 100;
    public const TABS = ['settings' => 'Настройки', 'map' => 'Категории', 'prices' => 'Цены', 'runs' => 'Журнал'];
    public const LEVELS = ['all' => 'Все', 'add' => 'Добавлено', 'update' => 'Изменено', 'hide' => 'Скрыто', 'same' => 'Без изменений',
        'skip' => 'Пропущено', 'problem' => 'Ошибки и предупреждения', 'info' => 'Сообщения'];

    public function home(): Response
    {
        return Response::redirect(self::BASE);
    }

    public function index(): Response
    {
        JongGolf::ensureSchema();
        $tab = Request::get('tab', 'settings');
        if (!isset(self::TABS[$tab])) $tab = 'settings';
        $db = App::db();
        $data = [
            'title' => 'Поставщики → ' . JongGolf::TITLE, 'tab' => $tab, 'base' => self::BASE,
            'styles' => ['admin/suppliers.css'], 'scripts' => ['admin/suppliers.js'],
            'cfg' => array_combine(array_keys(JongGolf::DEFAULTS), array_map([JongGolf::class, 'cfg'], array_keys(JongGolf::DEFAULTS))),
            'enabled' => JongGolf::enabled(), 'keyHint' => JongGolf::keyHint(), 'debug' => App::isDebug(),
            'due' => JongGolf::due(), 'next' => JongGolf::nextRun(), 'active' => JongGolf::active(), 'last' => JongGolf::runs(1)[0] ?? null,
            'lastFinished' => JongGolf::lastFinished(), 'mapCount' => JongGolfMap::count(),
            'products' => $db->row('SELECT COUNT(*) total, SUM(status = 1) active FROM products WHERE supplier = ?', [JongGolf::CODE]),
            'manual' => (int) $db->value("SELECT COUNT(*) FROM products p WHERE (p.supplier IS NULL OR p.supplier = '') AND p.brand_id = (SELECT id FROM brands WHERE name = ? LIMIT 1)", [JongGolf::TITLE]),
            'hasDict' => is_file(JongGolf::dir() . '/product_setting.json'), 'dictTime' => @filemtime(JongGolf::dir() . '/product_setting.json') ?: null,
            'hasPages' => (bool) $this->lastPages(), 'errors' => [], 'post' => null,
        ];
        if ($tab === 'settings') {
            [$attr, $attrErr] = JongGolf::attributes();
            $data += ['attrErr' => $attrErr];
        } elseif ($tab === 'map') {
            $filter = Request::get('f') === 'unresolved' ? 'unresolved' : 'all';
            $q = mb_substr(Request::get('q'), 0, 100);
            $page = Request::page();
            [$rows, $total] = JongGolfMap::page($filter, $q, $page, self::MAP_PER_PAGE);
            $data += ['rows' => $rows, 'filter' => $filter, 'q' => $q, 'pg' => new Paginator($total, self::MAP_PER_PAGE, $page),
                'choices' => Importer::categoryChoices(), 'unmapped' => $data['lastFinished']['stats']['unmapped'] ?? []];
        } elseif ($tab === 'prices') {
            $data += ['targets' => JongGolfMap::targets(), 'prices' => JongGolf::prices()];
        } else {
            $data += ['runs' => JongGolf::runs(60)];
        }
        return $this->render('admin/suppliers/jonggolf', $data);
    }

    public function saveSettings(): Response
    {
        $errors = JongGolf::saveSettings($_POST);
        if ($errors) {
            $this->flash('Проверьте поля формы: ' . implode('; ', array_map(static fn($k, $v) => $k . ' — ' . $v, array_keys($errors), $errors)), true);
            return Response::redirect(self::BASE);
        }
        $this->log('jonggolf_settings', 'settings', null, ['enabled' => JongGolf::on('enabled')]);
        $this->flash('Настройки сохранены.' . (JongGolf::enabled() ? ' Автозагрузка ВКЛЮЧЕНА — cron старого сайта должен быть отключён.' : ' Автозагрузка выключена.'));
        return Response::redirect(self::BASE);
    }

    /** Перенос настроек из wa_loader_jonggolf.cfg.php (файл только читается как текст) */
    public function importCfg(): Response
    {
        $f = $_FILES['cfg'] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) || (int) $f['size'] > 200000) {
            $this->flash('Выберите файл wa_loader_jonggolf.cfg.php (до 200 КБ).', true);
            return Response::redirect(self::BASE);
        }
        [$set, $skipped] = JongGolf::importOldConfig((string) file_get_contents((string) $f['tmp_name']));
        @unlink((string) $f['tmp_name']);
        $this->log('jonggolf_import_cfg', 'settings', null, array_keys($set));
        $this->flash($set ? 'Перенесено настроек: ' . count($set) . (isset($set['api_key']) ? ', в том числе ключ API' : '')
            . '. Логин и пароль старой админки не переносятся. Автозагрузка по-прежнему ' . (JongGolf::on('enabled') ? 'включена' : 'выключена') . '.'
            : 'В файле не найдено настроек загрузчика Jong•Golf.', !$set);
        return Response::redirect(self::BASE);
    }

    /** Справочники поставщика (product_setting.json из tmp/ старого загрузчика) — для пробного прогона по файлу без связи с API */
    public function uploadDictionary(): Response
    {
        $f = $_FILES['dict'] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) || (int) $f['size'] > 2000000) {
            $this->flash('Выберите файл product_setting.json (до 2 МБ).', true);
            return Response::redirect(self::BASE);
        }
        $raw = (string) file_get_contents((string) $f['tmp_name']);
        $j = json_decode($raw, true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'success' || !is_array($j['answer']['seasons'] ?? null)) {
            $this->flash('Это не справочники Jong•Golf (нужен ответ export_product_setting: type = success, answer.seasons).', true);
            return Response::redirect(self::BASE);
        }
        file_put_contents(JongGolf::dir() . '/product_setting.json', $raw);
        $this->flash('Справочники сохранены: сезонов ' . count($j['answer']['seasons']) . '.');
        return Response::redirect(self::BASE);
    }

    public function test(): Response
    {
        $r = JongGolf::test((int) Auth::id());
        $this->log('jonggolf_test', 'settings', null, ['ok' => $r['ok']]);
        return Response::json($r);
    }

    /** Начать пробный прогон (mode=dry: source api | last | upload) или боевой запуск (mode=run) */
    public function start(): Response
    {
        $mode = Request::post('mode') === 'run' ? 'run' : 'dry';
        $source = Request::post('source', 'api');
        $o = ['origin' => 'admin', 'user_id' => (int) Auth::id(), 'src' => 'api'];
        if ($mode === 'dry' && $source === 'last') {
            $files = $this->lastPages();
            if (!$files) return Response::json(['ok' => false, 'error' => 'Нет сохранённых страниц прошлых запусков.']);
            $o['src'] = 'file';
            $o['files'] = $files;
        } elseif ($mode === 'dry' && $source === 'upload') {
            $f = $_FILES['feed'] ?? null;
            if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) return Response::json(['ok' => false, 'error' => 'Выберите файл с ответом export_product (JSON).']);
            if ((int) $f['size'] > 50 * 1048576) return Response::json(['ok' => false, 'error' => 'Файл больше 50 МБ.']);
            $dest = JongGolf::dir() . '/upload-' . Str::random(6) . '.json';
            if (!move_uploaded_file((string) $f['tmp_name'], $dest)) return Response::json(['ok' => false, 'error' => 'Не удалось сохранить файл.']);
            $o['src'] = 'file';
            $o['files'] = [$dest];
        }
        $limit = Request::postInt('limit');
        if ($limit > 0) $o['limit'] = $limit;
        try {
            $id = JongGolf::start($mode, $o);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()]);
        }
        $this->log($mode === 'run' ? 'jonggolf_run' : 'jonggolf_dry', 'settings', $id);
        return Response::json(['ok' => true, 'id' => $id, 'redirect' => self::BASE . 'runs/' . $id . '/?go=1']);
    }

    public function showRun(string $id): Response
    {
        $run = JongGolf::run((int) $id);
        if (!$run) return Response::redirect(self::BASE . '?tab=runs');
        $level = Request::get('level', 'all');
        if (!isset(self::LEVELS[$level])) $level = 'all';
        $db = App::db();
        $counts = $db->pairs('SELECT level, COUNT(*) FROM supplier_run_log WHERE run_id = ? GROUP BY level', [(int) $run['id']]);
        $where = 'run_id = ?';
        $p = [(int) $run['id']];
        if ($level === 'problem') $where .= " AND level IN ('error', 'warn')";
        elseif ($level !== 'all') { $where .= ' AND level = ?'; $p[] = $level; }
        $total = (int) $db->value("SELECT COUNT(*) FROM supplier_run_log WHERE $where", $p);
        $pg = new Paginator($total, self::LOG_PER_PAGE, Request::page());
        $lines = $db->all("SELECT id, level, product_id, message, data, created_at FROM supplier_run_log WHERE $where ORDER BY id LIMIT " . self::LOG_PER_PAGE . ' OFFSET ' . $pg->offset, $p);
        foreach ($lines as &$l) { $d = json_decode((string) $l['data'], true); $l['data'] = is_array($d) ? $d : null; }
        unset($l);
        return $this->render('admin/suppliers/run', [
            'title' => JongGolf::KINDS[$run['kind']] . ' №' . $run['id'], 'back' => [self::BASE . '?tab=runs', 'Журнал запусков ' . JongGolf::TITLE],
            'styles' => ['admin/suppliers.css'], 'scripts' => ['admin/suppliers.js'], 'base' => self::BASE,
            'run' => $run, 'progress' => JongGolf::progress($run), 'lines' => $lines, 'level' => $level, 'counts' => $counts, 'pg' => $pg,
            'autostart' => Request::get('go') === '1' && $run['status'] === 'running',
        ]);
    }

    public function step(string $id): Response
    {
        $run = JongGolf::run((int) $id);
        if (!$run) return Response::json(['ok' => false, 'error' => 'Запуск не найден', 'done' => true]);
        return Response::json(JongGolf::step((int) $id, 12.0));
    }

    public function stop(string $id): Response
    {
        JongGolf::stop((int) $id);
        $this->log('jonggolf_stop', 'settings', (int) $id);
        if (Request::isAjax()) return Response::json(['ok' => true]);
        $this->flash('Запуск остановлен.');
        return Response::redirect(self::BASE . 'runs/' . (int) $id . '/');
    }

    // ------------------------------------------------------------------ таблица соответствия

    public function mapUpload(): Response
    {
        $f = $_FILES['table'] ?? null;
        $back = self::BASE . '?tab=map';
        if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) { $this->flash('Выберите файл таблицы (XLS, XLSX или CSV).', true); return Response::redirect($back); }
        $name = (string) $f['name'];
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), JongGolfMap::EXTENSIONS, true) || (int) $f['size'] > 20 * 1048576) {
            $this->flash('Подходят файлы XLS, XLSX и CSV до 20 МБ — как table/category_table_jonggolf.xls старого загрузчика.', true);
            return Response::redirect($back);
        }
        $tmp = Importer::dir() . '/tmp/' . Str::random(8) . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!move_uploaded_file((string) $f['tmp_name'], $tmp)) { $this->flash('Не удалось сохранить файл.', true); return Response::redirect($back); }
        try {
            $st = JongGolfMap::import(JongGolfMap::readFile($tmp, $name));
        } catch (\Throwable $e) {
            @unlink($tmp);
            $this->flash('Таблица не загружена: ' . $e->getMessage(), true);
            return Response::redirect($back);
        }
        @unlink($tmp);
        $this->log('jonggolf_map_upload', 'settings', null, ['rows' => $st['rows'], 'keys' => $st['keys']]);
        $msg = 'Таблица загружена: строк ' . $st['rows'] . ', сочетаний ' . $st['keys'] . ($st['duplicates'] ? ' (повторов ' . $st['duplicates'] . ' — действует последняя строка'
            . ($st['conflicts'] ? '; с другой категорией: ' . implode('; ', array_slice($st['conflicts'], 0, 3)) . (count($st['conflicts']) > 3 ? '…' : '') : '') . ')' : '') . '.';
        if ($st['unresolved']) $msg .= ' Без категории сайта: ' . $st['unresolved'] . ' (пути ' . implode(', ', array_map(static fn($p) => '«' . $p . '»', array_slice(array_keys($st['paths_missing']), 0, 5))) . ' нет на сайте) — выберите категории ниже.';
        $this->flash($msg, (bool) $st['unresolved']);
        return Response::redirect($back . ($st['unresolved'] ? '&f=unresolved' : ''));
    }

    public function mapSave(): Response
    {
        $changes = [];
        foreach (Request::postArray('cat') as $id => $cid) if (ctype_digit((string) $id) && is_scalar($cid)) $changes[(int) $id] = (int) $cid;
        $n = JongGolfMap::assign($changes);
        $this->flash($n ? 'Сохранено сопоставлений: ' . $n . '.' : 'Изменений нет.');
        return $this->back(self::BASE . '?tab=map');
    }

    public function mapAdd(): Response
    {
        $size = Request::post('any_size') === '1' ? '' : Request::post('size');
        $err = JongGolfMap::put(Request::post('season'), Request::post('category'), Request::post('gender'), $size, Request::postInt('category_id'));
        $this->flash($err ?? 'Сопоставление добавлено — сработает со следующего запуска.', $err !== null);
        return $this->back(self::BASE . '?tab=map');
    }

    public function mapDelete(string $id): Response
    {
        JongGolfMap::delete((int) $id);
        $this->flash('Строка таблицы удалена.');
        return $this->back(self::BASE . '?tab=map');
    }

    public function mapCsv(): Response
    {
        return Response::text(JongGolfMap::csv(), 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="category_table_jonggolf.csv"');
    }

    public function savePrices(): Response
    {
        $n = JongGolf::savePrices(Request::postArray('k'), Request::postArray('plus'));
        $this->log('jonggolf_prices', 'settings', null, ['rules' => $n]);
        $this->flash($n ? 'Коэффициенты сохранены: категорий с наценкой — ' . $n . '. Цены изменятся при следующем запуске.' : 'Наценки нет: цены — как у поставщика.');
        return Response::redirect(self::BASE . '?tab=prices');
    }

    /** Страницы последнего запуска по API (для пробного прогона «по последним данным») */
    private function lastPages(): array
    {
        foreach (App::db()->col("SELECT id FROM supplier_runs WHERE supplier = ? AND src = 'api' AND kind IN ('run', 'dry') ORDER BY id DESC LIMIT 10", [JongGolf::CODE]) as $rid) {
            $d = JongGolf::dir() . '/runs/' . (int) $rid;
            $files = is_dir($d) ? (glob($d . '/page-*.json') ?: []) : [];
            if ($files) { natsort($files); return array_values($files); }
        }
        return [];
    }
}
