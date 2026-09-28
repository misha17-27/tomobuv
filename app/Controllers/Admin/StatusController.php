<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\CatalogIndexer;
use App\Services\SystemStatus;

/**
 * «Состояние системы»: сервер, база, каталог, права на папки, cron, кэш, настройки безопасности.
 * Смотреть могут все сотрудники; «Перестроить индекс каталога» — только администратор.
 * Проверка закрытых служебных адресов (probe.json) и тяжёлая проверка каталога (catalog.json, если не в кэше)
 * идут отдельными запросами из JS, чтобы страница открывалась сразу.
 */
final class StatusController extends BaseController
{
    public function index(): Response
    {
        $fresh = Request::get('fresh') === '1';
        $groups = SystemStatus::groups($fresh, true);
        $isAdmin = Auth::isAdmin();
        $actions = '<a class="btn btn-sm" href="/admin/status/?fresh=1">Проверить заново</a>'
            . '<form method="post" action="/admin/cache/clear/" class="sys-inline">' . self::tokenField()
            . '<button class="btn btn-sm">Очистить кэш</button></form>';
        if ($isAdmin) {
            $actions .= '<form method="post" action="/admin/status/reindex/" class="sys-inline" data-reindex'
                . ' data-confirm="Перестроить индекс каталога? Это займёт несколько секунд; пока идёт перестройка, списки категорий на сайте могут быть неполными.">'
                . self::tokenField() . '<button class="btn btn-sm btn-p">Перестроить индекс каталога</button></form>';
        }
        return $this->render('admin/status/index', [
            'title' => 'Состояние системы', 'groups' => $groups, 'tally' => SystemStatus::tally($groups),
            'tables' => SystemStatus::tables(), 'isAdmin' => $isAdmin, 'local' => SystemStatus::isLocalBase(),
            'actions' => $actions, 'styles' => ['admin/system.css'], 'scripts' => ['admin/system.js'],
        ]);
    }

    /** GET /admin/status/probe.json — закрыты ли служебные адреса на хостинге */
    public function probe(): Response
    {
        return Response::json(['ok' => true, 'local' => SystemStatus::isLocalBase(), 'items' => SystemStatus::probe()]);
    }

    /** GET /admin/status/catalog.json — строки группы «Каталог» (считаются ~0,3 с, поэтому отдельным запросом) */
    public function catalog(): Response
    {
        $html = View::render('admin/status/_rows', ['rows' => SystemStatus::catalog()], null);
        return Response::json(['ok' => true, 'html' => $html]);
    }

    /** POST /admin/status/reindex/ — полная перестройка catalog_index и category_facets */
    public function reindex(): Response
    {
        if (!Auth::isAdmin()) {
            $this->flash('Перестраивать индекс может только администратор.', true);
            return Response::redirect('/admin/status/');
        }
        // на хостинге эти функции бывают в disable_functions — в PHP 8 вызов отключённой функции = фатальная ошибка
        if (function_exists('set_time_limit')) @set_time_limit(600);
        if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
        $t = microtime(true);
        try {
            // одна перестройка за раз (блокировка storage/cache/reindex.lock — общая с bin/reindex.php и импортом):
            // повторный клик, второй администратор или идущая перестройка — не ждём, сообщаем
            if (!CatalogIndexer::rebuildAll(null, 0)) {
                $this->flash('Индекс уже перестраивается — подождите минуту и обновите страницу.', true);
                return Response::redirect('/admin/status/');
            }
        } catch (\Throwable $e) {
            \App\Core\Log::error('Перестройка индекса из админки: ' . $e->getMessage());
            $this->flash('Индекс не перестроен — ошибка записана в журнал storage/logs/error-' . date('Y-m') . '.log.', true);
            return Response::redirect('/admin/status/');
        }
        $sec = round(microtime(true) - $t, 1);
        $this->log('catalog_reindex', null, null, ['sec' => $sec]);
        $this->flash('Индекс каталога перестроен за ' . str_replace('.', ',', (string) $sec) . ' с. Кэш сайта очищен.');
        return Response::redirect('/admin/status/');
    }
}
