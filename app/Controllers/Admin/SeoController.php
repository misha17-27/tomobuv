<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\SeoAudit;
use App\Services\SeoFix;

/**
 * SEO-обзор (как «SEO» в админке ARG FLEX): что видят поисковики на каждом адресе из sitemap.xml.
 *   /admin/seo/                    — итоги, покрытие украинской версии, шаблоны, служебное, таблица всех адресов;
 *   ?show=none|warn|auto|closed    — чип-фильтр по состоянию;
 *   ?group=pages|categories|brands|blog|products — одна группа; товары — по 50 на страницу, поиск ?q=;
 *   ?lang=uk                       — таблица глазами украинской версии (/ua/…: поля *_uk, шаблоны «seo.*.uk»);
 *   ?sample=ID                     — товар для превью шаблонов;
 *   POST /admin/seo/refresh/       — пересчитать сейчас (итоги кэшируются на 10 минут);
 *   GET  /admin/seo/autofix/       — «Исправить автоматически»: предпросмотр (что изменится по группам, 50 примеров) и пакеты;
 *   POST /admin/seo/autofix/       — применить одним пакетом; POST /admin/seo/autofix/{id}/revert/ — откатить пакет.
 * Обзор только показывает — правки делаются на экранах товара/категории/страницы и в «Настройки → SEO-шаблоны»,
 * поэтому доступен и менеджерам; автоисправление (App\Services\SeoFix) — только администратору.
 */
final class SeoController extends BaseController
{
    public function index(): Response
    {
        $filter = Request::get('show');
        if (!array_key_exists($filter, SeoAudit::FILTERS)) $filter = '';
        $group = Request::get('group');
        if (!array_key_exists($group, SeoAudit::GROUPS)) $group = '';
        $lang = Request::get('lang');
        if (!array_key_exists($lang, SeoAudit::LANGS)) $lang = 'ru';
        $q = mb_substr(Request::get('q'), 0, 100);
        if ($q !== '') $group = 'products';

        // итоги, покрытие переводов и служебное — по русской версии (в ней же посчитаны агрегаты товаров обеих версий)
        $ru = SeoAudit::overview();
        $sampleId = max(0, Request::getInt('sample'));
        $sample = SeoAudit::sample($sampleId);

        // таблица — глазами выбранной версии сайта
        $view = SeoAudit::inLang($lang, static function () use ($lang, $ru, $filter, $group, $q): array {
            $o = $lang === 'ru' ? $ru : SeoAudit::overview();
            $st = $o['products'];
            // товары: на вкладке «Товары» — страница N (с поиском), во «Всём» — первые 50 под фильтр, на прочих — только счётчики
            $prod = ($group === '' || $group === 'products')
                ? SeoAudit::products($filter, $q, $group === 'products' ? Request::page() : 1, $st)
                : ['rows' => [], 'total' => 0, 'counts' => SeoAudit::productCounts($st), 'page' => 1, 'found' => null, 'found_hidden' => 0, 'limited' => false];
            return ['rows' => $o['rows'], 'prod' => $prod];
        });
        $rows = $view['rows'];
        $prod = $view['prod'];
        $pager = $group === 'products' ? new Paginator($prod['total'], SeoAudit::PER_PAGE, $prod['page']) : null;

        // счётчики: вкладки групп — под текущий чип, чипы — под текущую группу.
        // groupAll — база «из M» в заголовке списка: для «Всё» и чипов состояния — адреса «Всё»; для «Закрыто от индексации» —
        // все адреса вместе со скрытыми (скрытые товары и бренды без товаров во «Всё» не входят, а в «Закрыто» — входят)
        $groupCount = array_fill_keys(array_keys(SeoAudit::GROUPS), 0);
        $groupAll = $groupCount;
        $chipCount = array_fill_keys(array_keys(SeoAudit::FILTERS), 0);
        foreach ($rows as $r) {
            if (SeoAudit::matches($r, $filter)) $groupCount[$r['group']]++;
            if ($filter === 'closed' || SeoAudit::matches($r, '')) $groupAll[$r['group']]++;
            if ($group !== '' && $group !== $r['group']) continue;
            foreach ($chipCount as $f => $_) if (SeoAudit::matches($r, $f)) $chipCount[$f]++;
        }
        $groupCount['products'] = $prod['counts'][$filter] ?? 0;
        $groupAll['products'] = ($prod['counts'][''] ?? 0) + ($filter === 'closed' ? $prod['counts']['closed'] ?? 0 : 0);
        if ($group === '' || $group === 'products') {
            foreach ($chipCount as $f => $_) $chipCount[$f] += $prod['counts'][$f] ?? 0;
        }

        $listed = ($group === 'products') ? [] : array_values(array_filter($rows,
            static fn($r) => ($group === '' || $r['group'] === $group) && SeoAudit::matches($r, $filter)));

        $actions = '<form method="post" action="/admin/seo/refresh/" class="seo-refresh">' . self::tokenField()
            . '<button class="btn" type="submit" title="Итоги считаются раз в 10 минут">'
            . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/></svg>'
            . 'Пересчитать</button></form>'
            . '<a class="btn" href="' . e(SeoAudit::SETTINGS_URL) . '">SEO-шаблоны</a>'
            . (Auth::isAdmin() ? '<a class="btn btn-p" href="/admin/seo/autofix/" title="Сначала предпросмотр: до «Применить» ничего не меняется">Исправить автоматически</a>' : '');

        return $this->render('admin/seo/index', [
            'title'      => 'SEO',
            'actions'    => $actions,
            'styles'     => ['admin/seo.css'],
            'scripts'    => ['admin/seo.js'],
            'filter'     => $filter,
            'group'      => $group,
            'lang'       => $lang,
            'q'          => $q,
            'withHidden' => $filter === 'closed',
            'rows'       => $listed,
            'prod'       => $prod,
            'pager'      => $pager,
            'groupCount' => $groupCount,
            'groupAll'   => $groupAll,
            'chipCount'  => $chipCount,
            'tally'      => SeoAudit::tally($ru['rows'], $ru['products']),
            'split'      => SeoAudit::split($ru['rows'], $ru['products']),
            'sitemap'    => SeoAudit::sitemap($ru['rows'], $ru['products']),
            'cover'      => SeoAudit::ukCoverage($ru['rows'], $ru['cover']),
            'at'         => (int) $ru['at'],
            'sample'     => $sample,
            'sampleId'   => $sampleId,
            'templates'  => SeoAudit::templates($sample),
            'service'    => SeoAudit::service($ru['rows']),
        ]);
    }

    // ------------------------------------------------------------------ автоисправление

    /**
     * Предпросмотр автоисправления: итоги «в норме» сейчас → после по группам и языкам, правила, шаблоны, 50 примеров «было → стало»,
     * что останется не в норме, пакеты с откатом. Расчёт (~10 с на 107 тыс. товаров) кэшируется на 10 минут
     * (Cache::flush после любой правки в админке сбрасывает сразу); «Применить» считает заново на свежих данных.
     */
    public function autofix(): Response
    {
        if (!Auth::isAdmin()) return $this->adminOnly();
        $ready = SeoFix::hasTables();
        $report = null;
        if ($ready) {
            $report = Request::get('fresh') === '1' ? null : Cache::get('seo.autofix.preview');
            if (!is_array($report)) {
                if (function_exists('set_time_limit')) @set_time_limit(300);
                $report = SeoFix::run(false, ['examples' => 10]) + ['at' => time()];
                Cache::set('seo.autofix.preview', $report, SeoAudit::TTL);
            }
        }
        $batches = $ready ? SeoFix::batches(20) : [];
        // откат — от последнего к первому: пакет, поля которого меняли более поздние неоткаченные, откатить пока нельзя
        $later = [];
        foreach ($batches as $b) {
            if ($b['reverted_at'] === null && ($x = SeoFix::laterOverlaps((int) $b['id']))) $later[(int) $b['id']] = array_reverse(array_keys($x));
        }
        return $this->render('admin/seo/autofix', [
            'title'    => 'SEO: исправить автоматически',
            'back'     => ['/admin/seo/', 'SEO-обзор'],
            'styles'   => ['admin/seo.css'],
            'ready'    => $ready,
            'report'   => $report,
            'examples' => $report ? SeoFix::examples($report, 50) : [],
            'batches'  => $batches,
            'later'    => $later,
        ]);
    }

    /** Применить автоисправление одним пакетом (на свежих данных, не по сохранённому предпросмотру) */
    public function autofixApply(): Response
    {
        if (!Auth::isAdmin()) return $this->adminOnly();
        if (!SeoFix::hasTables()) {
            $this->flash('Нет таблиц журнала — выполните php bin/install.php.', true);
            return Response::redirect('/admin/seo/autofix/');
        }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(600);
        try {
            $r = SeoFix::run(true, ['source' => 'admin', 'user_id' => Auth::id() ?: null, 'examples' => 1]);
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), true);
            return Response::redirect('/admin/seo/autofix/');
        }
        $this->log('seo_autofix', 'seo_fix_batch', $r['batch'], ['changes' => $r['changes'], 'rules' => $r['rules'], 'time' => $r['time']]);
        $this->flash($r['batch'] ? 'Исправлено ' . number_format($r['changes'], 0, '', ' ') . ' ' . plural($r['changes'], 'значение', 'значения', 'значений')
            . ' — пакет № ' . $r['batch'] . ' (' . $r['time'] . ' с). Кэш сайта сброшен; пакет можно откатить ниже.'
            : 'Исправлять нечего — всё уже в норме, пакет не создан.');
        return Response::redirect('/admin/seo/autofix/' . ($r['batch'] ? '#batches' : ''));
    }

    /** Откатить пакет: вернуть «было», кроме значений, изменённых после пакета */
    public function autofixRevert(string $id): Response
    {
        if (!Auth::isAdmin()) return $this->adminOnly();
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(600);
        try {
            $r = SeoFix::revert((int) $id, Auth::id() ?: null);
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), true);
            return Response::redirect('/admin/seo/autofix/#batches');
        }
        $this->log('seo_autofix_revert', 'seo_fix_batch', (int) $id, ['restored' => $r['restored'], 'skipped' => $r['skipped']]);
        $this->flash('Пакет № ' . (int) $id . ' откачен: возвращено ' . number_format($r['restored'], 0, '', ' ')
            . ($r['skipped'] ? ', пропущено ' . number_format($r['skipped'], 0, '', ' ') . ' (значение меняли после пакета — список в строке пакета)' : '') . '.');
        return Response::redirect('/admin/seo/autofix/#batches');
    }

    private function adminOnly(): Response
    {
        $this->flash('Автоисправление SEO доступно только администратору.', true);
        return Response::redirect('/admin/seo/');
    }

    /** Пересчитать итоги сейчас (сбрасывает только кэш этого экрана, обе версии сайта) */
    public function refresh(): Response
    {
        SeoAudit::forget();
        $this->log('seo_refresh');
        if (Request::isAjax()) return Response::json(['ok' => true]);
        $this->flash('SEO-обзор пересчитан.');
        return $this->back('/admin/seo/');
    }
}
