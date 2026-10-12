<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Suppliers\SupplierOverview;

/**
 * Общее у страниц поставщиков (SuppliersController — Jong•Golf, HitObuviController — Хит обуви): вкладка «Обзор»,
 * опрос хода запуска (GET …/progress/), кнопки «Запустить сейчас» / «Остановить» в шапке, фильтр отчёта запуска.
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
            $data['prev'] = $prev;
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
