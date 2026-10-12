<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Import\Importer;
use App\Services\Suppliers\HitObuvi;
use App\Services\Suppliers\HitObuviMap;

/**
 * «Каталог → Поставщики → Хит обуви» (только администратор): настройки загрузки со страниц сайта поставщика (наценка,
 * расписание, бережность к сайту), таблица категорий поставщика → категории сайта, проверка связи, пробный прогон,
 * запуск, журнал. Шаги запуска выполняет suppliers.js (POST …/runs/{id}/step/), как у Jong•Golf.
 */
final class HitObuviController extends BaseController
{
    use SupplierPages;

    protected const MANAGER_ALLOWED = false;

    private const BASE = '/admin/suppliers/hitobuvi/';
    private const LOG_PER_PAGE = 100;
    public const TABS = ['overview' => 'Обзор', 'settings' => 'Настройки', 'map' => 'Категории', 'runs' => 'Журнал'];

    protected static function sup(): string
    {
        return HitObuvi::class;
    }

    protected static function supBase(): string
    {
        return self::BASE;
    }

    public function index(): Response
    {
        HitObuvi::ensureSchema();
        HitObuviMap::seed();
        $tab = Request::get('tab', 'overview');
        if (!isset(self::TABS[$tab])) $tab = 'overview';
        $cfg = array_combine(array_keys(HitObuvi::DEFAULTS), array_map([HitObuvi::class, 'cfg'], array_keys(HitObuvi::DEFAULTS)));
        $db = App::db();
        $data = [
            'title' => 'Поставщики → ' . HitObuvi::TITLE, 'tab' => $tab, 'base' => self::BASE,
            'styles' => ['admin/suppliers.css'], 'scripts' => ['admin/suppliers.js'],
            'cfg' => $cfg, 'enabled' => HitObuvi::enabled(), 'markup' => HitObuvi::markup(), 'debug' => App::isDebug(),
            'due' => HitObuvi::due(), 'next' => HitObuvi::nextRun(), 'nextMode' => HitObuvi::autoMode(), 'active' => HitObuvi::active(), 'last' => HitObuvi::runs(1)[0] ?? null,
            'lastFinished' => HitObuvi::lastFinished(), 'mapCount' => HitObuviMap::count(), 'products' => HitObuvi::productCounts(), 'links' => HitObuvi::linkCounts(),
            'roots' => HitObuviMap::roots(), 'hasPages' => (bool) HitObuvi::lastWeb(),
            'forsage' => (int) $db->value("SELECT COUNT(*) FROM products WHERE supplier = 'forsage'"),
        ];
        if ($tab === 'overview') {
            $data += $this->overviewData();
        } elseif ($tab === 'settings') {
            [, $attrErr] = HitObuvi::attributes();
            $data += ['attrErr' => $attrErr];
        } elseif ($tab === 'map') {
            [$cats, $types] = HitObuviMap::all();
            $data += ['cats' => $cats, 'types' => $types, 'choices' => Importer::categoryChoices(), 'unmapped' => $data['lastFinished']['stats']['unmapped'] ?? [],
                'newCats' => $data['lastFinished']['stats']['new_cats'] ?? []];
        } else {
            $data += ['runs' => HitObuvi::runs(60)];
        }
        $data['actions'] = $this->headerActions($data['live'] ?? null);
        return $this->render('admin/suppliers/hitobuvi', $data);
    }

    public function saveSettings(): Response
    {
        $errors = HitObuvi::saveSettings($_POST);
        if ($errors) {
            $this->flash('Проверьте поля формы: ' . implode('; ', array_map(static fn($k, $v) => $k . ' — ' . $v, array_keys($errors), $errors)), true);
            return Response::redirect(self::BASE . '?tab=settings');
        }
        $this->log('hitobuvi_settings', 'settings', null, ['enabled' => HitObuvi::on('enabled'), 'markup' => HitObuvi::cfg('markup_k')]);
        $this->flash('Настройки сохранены.' . (HitObuvi::enabled() ? ' Загрузка «Хит обуви» ВКЛЮЧЕНА — запуск по расписанию из cron.' : ' Загрузка выключена.'));
        return Response::redirect(self::BASE . '?tab=settings');
    }

    public function test(): Response
    {
        $r = HitObuvi::test((int) Auth::id());
        $this->log('hitobuvi_test', 'settings', null, ['ok' => $r['ok']]);
        return Response::json($r);
    }

    /** Пробный прогон (mode=dry: source web | last; режим quick | full) или боевой запуск (mode=run) */
    public function start(): Response
    {
        $kind = Request::post('mode') === 'run' ? 'run' : 'dry';
        $o = ['origin' => 'admin', 'user_id' => (int) Auth::id(), 'src' => 'web', 'mode' => in_array(Request::post('pass'), ['quick', 'full'], true) ? Request::post('pass') : 'auto'];
        if ($kind === 'dry' && Request::post('source') === 'last') {
            $last = HitObuvi::lastWeb();
            $dir = $last ? HitObuvi::dir((int) $last['id']) : '';
            if (!$last || !glob($dir . '/page-*.html')) return Response::json(['ok' => false, 'error' => 'Нет сохранённых страниц прошлых запусков с сайта.']);
            $o['src'] = 'file';
            $o['dir'] = $dir;
        }
        $limit = Request::postInt('limit');
        if ($limit > 0) $o['limit'] = $limit;
        try {
            $id = HitObuvi::start($kind, $o);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()]);
        }
        $this->log($kind === 'run' ? 'hitobuvi_run' : 'hitobuvi_dry', 'settings', $id);
        // боевой — на «Обзор» (страница сама делает шаги; закрыли — продолжит cron), пробный — на страницу запуска с отчётом
        return Response::json(['ok' => true, 'id' => $id, 'redirect' => $kind === 'run' ? self::BASE . '?go=' . $id : self::BASE . 'runs/' . $id . '/?go=1']);
    }

    public function showRun(string $id): Response
    {
        $run = HitObuvi::run((int) $id);
        if (!$run) return Response::redirect(self::BASE . '?tab=runs');
        $level = Request::get('level', 'all');
        if (!isset(SuppliersController::LEVELS[$level])) $level = 'all';
        $db = App::db();
        $counts = $db->pairs('SELECT level, COUNT(*) FROM supplier_run_log WHERE run_id = ? GROUP BY level', [(int) $run['id']]);
        $where = 'run_id = ?';
        $p = [(int) $run['id']];
        if ($level === 'problem') $where .= " AND level IN ('error', 'warn')";
        elseif ($level !== 'all') { $where .= ' AND level = ?'; $p[] = $level; }
        [$whySql, $whyParam, $why] = $this->whyFilter();
        if ($why !== '') { $where .= $whySql; $p[] = $whyParam; }
        $total = (int) $db->value("SELECT COUNT(*) FROM supplier_run_log WHERE $where", $p);
        $pg = new Paginator($total, self::LOG_PER_PAGE, Request::page());
        $lines = $db->all("SELECT id, level, product_id, message, data, created_at FROM supplier_run_log WHERE $where ORDER BY id LIMIT " . self::LOG_PER_PAGE . ' OFFSET ' . $pg->offset, $p);
        foreach ($lines as &$l) { $d = json_decode((string) $l['data'], true); $l['data'] = is_array($d) ? $d : null; }
        unset($l);
        return $this->render('admin/suppliers/run', [
            'title' => HitObuvi::KINDS[$run['kind']] . ' №' . $run['id'] . ' · ' . HitObuvi::TITLE, 'back' => [self::BASE . '?tab=runs', 'Журнал запусков ' . HitObuvi::TITLE],
            'styles' => ['admin/suppliers.css'], 'scripts' => ['admin/suppliers.js'], 'base' => self::BASE, 'sup' => HitObuvi::class,
            'run' => $run, 'progress' => HitObuvi::progress($run), 'lines' => $lines, 'level' => $level, 'why' => $why, 'counts' => $counts, 'pg' => $pg,
            'autostart' => Request::get('go') === '1' && $run['status'] === 'running',
        ]);
    }

    public function step(string $id): Response
    {
        if (!HitObuvi::run((int) $id)) return Response::json(['ok' => false, 'error' => 'Запуск не найден', 'done' => true]);
        return Response::json(HitObuvi::step((int) $id, 12.0));
    }

    public function stop(string $id): Response
    {
        $stopped = HitObuvi::stop((int) $id);
        if ($stopped) $this->log('hitobuvi_stop', 'settings', (int) $id);
        if (Request::isAjax()) return Response::json(['ok' => true, 'stopped' => $stopped]);
        $this->flash($stopped ? 'Запуск остановлен.' : 'Запуск уже не идёт — останавливать нечего.');
        return Response::redirect(self::BASE . 'runs/' . (int) $id . '/');
    }

    // ------------------------------------------------------------------ таблица категорий

    public function mapSave(): Response
    {
        $changes = [];
        foreach (Request::postArray('map') as $id => $row) {
            if (!ctype_digit((string) $id) || !is_array($row)) continue;
            $changes[(int) $id] = ['category_id' => (int) ($row['category_id'] ?? 0), 'mode' => is_scalar($row['mode'] ?? null) ? (string) $row['mode'] : '', 'by_size' => !empty($row['by_size'])];
        }
        $n = HitObuviMap::assign($changes);
        if ($n) $this->log('hitobuvi_map', 'settings', null, ['rows' => $n]);
        $this->flash($n ? 'Сохранено строк: ' . $n . ' — сработает со следующего запуска.' : 'Изменений нет.');
        return $this->back(self::BASE . '?tab=map');
    }

    public function mapAddType(): Response
    {
        $err = HitObuviMap::putType(Request::post('root'), Request::post('type'), Request::postInt('category_id'), Request::post('by_size') === '1', Request::post('when'));
        if ($err === null) $this->log('hitobuvi_map_type', 'settings', null, ['root' => Request::post('root'), 'type' => Request::post('type')]);
        $this->flash($err ?? 'Правило «тип из названия» сохранено — сработает со следующего запуска.', $err !== null);
        return $this->back(self::BASE . '?tab=map');
    }

    public function mapDelete(string $id): Response
    {
        HitObuviMap::delete((int) $id);
        $this->flash('Правило удалено.');
        return $this->back(self::BASE . '?tab=map');
    }
}
