<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\Import\Importer;
use App\Services\Suppliers\HitObuvi;
use App\Services\Suppliers\HitObuviMap;

/**
 * «Каталог → Поставщики → Хит обуви» (только администратор): настройки загрузки со страниц сайта поставщика (наценка,
 * расписание, бережность к сайту), таблица категорий поставщика → категории сайта, проверка связи, пробный прогон,
 * запуск, журнал (действия — общие, SupplierPages). Шаги запуска выполняет suppliers.js (POST …/runs/{id}/step/), как у Jong•Golf.
 */
final class HitObuviController extends BaseController
{
    use SupplierPages;

    protected const MANAGER_ALLOWED = false;

    private const BASE = '/admin/suppliers/hitobuvi/';
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
            $data['mass'] = self::massStatus();
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

    /**
     * Массовая загрузка (bin/load-hitobuvi.sh из Cron Jobs хостинга, раз в 5 мин): журнал storage/logs/hitobuvi-load.log
     * (последние строки), пауза после ошибок (storage/hitobuvi-load.errors: 3 подряд — раз в 6 ч), отметка «всё загружено»
     * (storage/hitobuvi-load.done), идёт ли сейчас (блокировка storage/hitobuvi-load.lock) — чтобы видеть без cPanel.
     */
    private static function massStatus(): array
    {
        $log = STORAGE . '/logs/hitobuvi-load.log';
        $tail = [];
        if (is_file($log) && ($fh = @fopen($log, 'rb'))) {
            $size = (int) filesize($log);
            fseek($fh, max(0, $size - 16384));
            $chunk = (string) stream_get_contents($fh);
            fclose($fh);
            $lines = preg_split('/\r?\n/', rtrim($chunk)) ?: [];
            if ($size > 16384) array_shift($lines);                        // первая строка куска — обрывок
            $tail = array_slice($lines, -30);
        }
        $err = STORAGE . '/hitobuvi-load.errors';
        $errN = is_file($err) ? (int) @file_get_contents($err) : 0;
        $running = false;
        $lk = STORAGE . '/hitobuvi-load.lock';
        if (is_file($lk) && ($fh = @fopen($lk, 'r'))) {
            $running = !flock($fh, LOCK_SH | LOCK_NB);                      // скрипт держит блокировку — идёт загрузка
            if (!$running) flock($fh, LOCK_UN);
            fclose($fh);
        }
        return [
            'log_at' => is_file($log) ? (int) filemtime($log) : 0, 'tail' => $tail, 'running' => $running,
            'errors' => $errN, 'paused_until' => $errN >= 3 ? (int) filemtime($err) + 355 * 60 : 0,
            'done' => is_file(STORAGE . '/hitobuvi-load.done') ? trim((string) @file_get_contents(STORAGE . '/hitobuvi-load.done')) : '',
            'min_id' => (int) HitObuvi::cfg('min_id'),
        ];
    }

    /** POST …/massload/reset/ — снять паузу после ошибок и отметку «всё загружено»: следующий вызов скрипта (до 5 мин) продолжит загрузку */
    public function massReset(): Response
    {
        @unlink(STORAGE . '/hitobuvi-load.errors');
        @unlink(STORAGE . '/hitobuvi-load.done');
        $this->log('supplier_massload_reset', 'supplier', null, ['supplier' => HitObuvi::CODE]);
        $this->flash('Массовая загрузка: пауза и отметка «всё загружено» сняты — скрипт продолжит в течение 5 минут (если задание есть в Cron Jobs).');
        return Response::redirect(self::BASE);
    }

    // настройки, проверка связи, запуск, отчёт, шаг, остановка — общие (SupplierPages)

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
