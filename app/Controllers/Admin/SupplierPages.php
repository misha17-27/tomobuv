<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Suppliers\SupplierOverview;

/**
 * Общее у страниц поставщиков (SuppliersController — Jong•Golf, HitObuviController — Хит обуви, TommController — Tom.m):
 * вкладка «Обзор», опрос хода запуска (GET …/progress/), кнопки «Запустить сейчас» / «Остановить» в шапке, фильтр отчёта
 * запуска; для загрузчиков со страниц сайта (WebSupplier) — и действия: сохранить настройки, проверить связь, пробный
 * прогон / запуск, отчёт запуска, шаг, остановка (у Jong•Golf — свои, методы контроллера важнее методов трейта).
 * Контроллер задаёт sup() — класс адаптера (наследник Supplier) и supBase() — адрес страницы поставщика.
 */
trait SupplierPages
{
    /** @return class-string<\App\Services\Suppliers\Supplier> */
    abstract protected static function sup(): string;

    abstract protected static function supBase(): string;

    /** Данные вкладки «Обзор»: сводка, ход запуска, последние запуски; ?list=… — полный список с фильтрами и страницами */
    protected function overviewData(): array
    {
        $cls = static::sup();
        $list = Request::get('list');
        if (!isset(SupplierOverview::LISTS[$list])) $list = '';
        $dir = Request::get('dir', 'all');
        $days = Request::getInt('days', 30);
        $f = ['dir' => isset(SupplierOverview::DIRS[$dir]) ? $dir : 'all', 'days' => isset(SupplierOverview::PERIODS[$days]) ? $days : 30];
        $live = SupplierOverview::live($cls);
        $go = Request::getInt('go');
        $data = ['ov' => SupplierOverview::summary($cls), 'live' => $live, 'ovRuns' => SupplierOverview::runs($cls, 10), 'list' => $list, 'f' => $f,
            'go' => $go && $go === $live['id'] ? $go : 0, 'canRun' => $cls::manualAllowed(), 'runHint' => $cls::manualHint(), 'sup' => $cls];
        if ($list !== '') {
            $page = Request::page();
            [$rows, $total] = SupplierOverview::list($cls, $list, $f, $page, 50);
            $data += ['rows' => $rows, 'total' => $total, 'pg' => new Paginator($total, 50, $page)];
        } else {
            $prev = [];
            foreach (['price', 'hidden', 'missing', 'back'] as $k) $prev[$k] = SupplierOverview::list($cls, $k, ['days' => 30], 1, 8);
            if ($cls::TRACKS_STOCK) $prev['out'] = SupplierOverview::list($cls, 'out', [], 1, 8);
            if ($cls::ownBrandIds()) $prev['unlinked'] = SupplierOverview::list($cls, 'unlinked', [], 1, 8);
            $data['prev'] = $prev;
            $data['excluded'] = SupplierOverview::excluded($cls);
        }
        return $data;
    }

    /** GET …/progress/ — ход запуска для «Обзора» и шапки (опрос раз в несколько секунд; шагов не выполняет) */
    public function progress(): Response
    {
        return Response::json(SupplierOverview::live(static::sup()));
    }

    /**
     * Кнопки в шапке страницы поставщика (на всех вкладках): «Запустить сейчас» (неактивна, пока идёт запуск, и без условий
     * адаптера — наценки, включённой загрузки), «Остановить» (только когда идёт запуск). Обработчик — suppliers.js.
     */
    protected function headerActions(?array $live = null): string
    {
        $cls = static::sup();
        $base = static::supBase();
        $live ??= SupplierOverview::live($cls);
        $hint = $cls::manualHint();
        $busy = $live['running'] || $live['paused'];
        $runAttr = $busy ? ' disabled title="Идёт запуск №' . (int) $live['id'] . '"' : ($hint !== '' ? ' disabled title="' . e($hint) . '"' : '');
        return '<span class="sup-head" data-sup-base="' . e($base) . '" data-sup-progress="' . e($base . 'progress/') . '" data-sup-id="' . (int) $live['id'] . '"'
            . ' data-sup-running="' . ($live['running'] ? '1' : ($live['paused'] ? 'p' : '0')) . '">'
            . '<button class="btn btn-p" type="button" data-sup-run="' . e($base . 'start/') . '" data-ask="Запустить загрузку «' . e($cls::TITLE) . '» сейчас? Новые товары будут добавлены на сайт, цены и наличие загруженных — обновлены."'
            . $runAttr . '>Запустить сейчас</button>'
            . '<button class="btn btn-d" type="button" data-sup-stop' . ($busy ? '' : ' hidden') . '>Остановить</button></span>';
    }

    // ------------------------------------------------------------------ действия загрузчиков со страниц сайта (WebSupplier)

    public function saveSettings(): Response
    {
        $cls = static::sup();
        $errors = $cls::saveSettings($_POST);
        if ($errors) {
            $this->flash('Проверьте поля формы: ' . implode('; ', array_map(static fn($k, $v) => $k . ' — ' . $v, array_keys($errors), $errors)), true);
            return Response::redirect(static::supBase() . '?tab=settings');
        }
        $this->log($cls::CODE . '_settings', 'settings', null, ['enabled' => $cls::on('enabled'), 'markup' => $cls::cfg('markup_k')]);
        $this->flash('Настройки сохранены.' . ($cls::enabled() ? ' Загрузка «' . $cls::TITLE . '» ВКЛЮЧЕНА — запуск по расписанию из cron.' : ' Загрузка выключена.'));
        return Response::redirect(static::supBase() . '?tab=settings');
    }

    public function test(): Response
    {
        $cls = static::sup();
        $r = $cls::test((int) Auth::id());
        $this->log($cls::CODE . '_test', 'settings', null, ['ok' => $r['ok']]);
        return Response::json($r);
    }

    /** Пробный прогон (mode=dry: source web | last; режим quick | full) или боевой запуск (mode=run) */
    public function start(): Response
    {
        $cls = static::sup();
        $kind = Request::post('mode') === 'run' ? 'run' : 'dry';
        $o = ['origin' => 'admin', 'user_id' => (int) Auth::id(), 'src' => 'web', 'mode' => in_array(Request::post('pass'), ['quick', 'full'], true) ? Request::post('pass') : 'auto'];
        if ($kind === 'dry' && Request::post('source') === 'last') {
            $last = $cls::lastWeb();
            $dir = $last ? $cls::dir((int) $last['id']) : '';
            if (!$last || !glob($dir . '/page-*.html')) return Response::json(['ok' => false, 'error' => 'Нет сохранённых страниц прошлых запусков с сайта.']);
            $o['src'] = 'file';
            $o['dir'] = $dir;
        }
        $limit = Request::postInt('limit');
        if ($limit > 0) $o['limit'] = $limit;
        try {
            $id = $cls::start($kind, $o);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()]);
        }
        $this->log($cls::CODE . ($kind === 'run' ? '_run' : '_dry'), 'settings', $id);
        // боевой — на «Обзор» (страница сама делает шаги; закрыли — продолжит cron), пробный — на страницу запуска с отчётом
        return Response::json(['ok' => true, 'id' => $id, 'redirect' => $kind === 'run' ? static::supBase() . '?go=' . $id : static::supBase() . 'runs/' . $id . '/?go=1']);
    }

    /** Отчёт запуска: ход, итоги, строки отчёта с фильтрами по уровню и причине пропуска */
    public function showRun(string $id): Response
    {
        $cls = static::sup();
        $base = static::supBase();
        $run = $cls::run((int) $id);
        if (!$run) return Response::redirect($base . '?tab=runs');
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
        $per = 100;
        $total = (int) $db->value("SELECT COUNT(*) FROM supplier_run_log WHERE $where", $p);
        $pg = new Paginator($total, $per, Request::page());
        $lines = $db->all("SELECT id, level, product_id, message, data, created_at FROM supplier_run_log WHERE $where ORDER BY id LIMIT " . $per . ' OFFSET ' . $pg->offset, $p);
        foreach ($lines as &$l) { $d = json_decode((string) $l['data'], true); $l['data'] = is_array($d) ? $d : null; }
        unset($l);
        return $this->render('admin/suppliers/run', [
            'title' => $cls::KINDS[$run['kind']] . ' №' . $run['id'] . ' · ' . $cls::TITLE, 'back' => [$base . '?tab=runs', 'Журнал запусков ' . $cls::TITLE],
            'styles' => ['admin/suppliers.css'], 'scripts' => ['admin/suppliers.js'], 'base' => $base, 'sup' => $cls,
            'run' => $run, 'progress' => $cls::progress($run), 'lines' => $lines, 'level' => $level, 'why' => $why, 'counts' => $counts, 'pg' => $pg,
            'autostart' => Request::get('go') === '1' && $run['status'] === 'running',
        ]);
    }

    public function step(string $id): Response
    {
        $cls = static::sup();
        if (!$cls::run((int) $id)) return Response::json(['ok' => false, 'error' => 'Запуск не найден', 'done' => true]);
        return Response::json($cls::step((int) $id, 12.0));
    }

    public function stop(string $id): Response
    {
        $cls = static::sup();
        // false — запуск уже закончился сам («Готово», «Ошибка») или его нет: в журнал админки не пишем
        $stopped = $cls::stop((int) $id);
        if ($stopped) $this->log($cls::CODE . '_stop', 'settings', (int) $id);
        if (Request::isAjax()) return Response::json(['ok' => true, 'stopped' => $stopped]);
        $this->flash($stopped ? 'Запуск остановлен.' : 'Запуск уже не идёт — останавливать нечего.');
        return Response::redirect(static::supBase() . 'runs/' . (int) $id . '/');
    }

    /** POST …/excluded/restore/ — удалённый вручную товар (code) или все (all=1) снова загружаются: следующий запуск добавит их */
    public function excludedRestore(): Response
    {
        $cls = static::sup();
        $db = App::db();
        if (Request::post('all') === '1') {
            $n = $db->query('DELETE FROM supplier_excluded WHERE supplier = ?', [$cls::CODE])->rowCount();
        } else {
            $n = $db->query('DELETE FROM supplier_excluded WHERE supplier = ? AND code = ?', [$cls::CODE, Request::post('code')])->rowCount();
        }
        if ($n) $this->log($cls::CODE . '_excluded_restore', 'settings', null, ['n' => $n, 'code' => Request::post('code')]);
        $this->flash($n ? 'Вернули к загрузке: ' . $n . ' — следующий запуск добавит, если товар есть у поставщика.' : 'Нечего возвращать.');
        return Response::redirect(static::supBase() . '#excluded');
    }

    /** Фильтр отчёта запуска по причине пропуска (?why=category, ?why=existing — есть на сайте): [условие, параметр, причина] */
    protected function whyFilter(): array
    {
        $cls = static::sup();
        $why = Request::get('why');
        if ($why === 'existing') return [' AND data LIKE ?', '%"a":"existing"%', $why];
        if ($why !== '' && isset($cls::skipReasons()[$why])) return [' AND data LIKE ?', '%"why":"' . $why . '"%', $why];
        return ['', null, ''];
    }
}
