<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\SeoAudit;

/**
 * SEO-обзор (как «SEO» в админке ARG FLEX): что видят поисковики на каждом адресе из sitemap.xml.
 *   /admin/seo/                    — итоги, покрытие украинской версии, шаблоны, служебное, таблица всех адресов;
 *   ?show=none|warn|auto|closed    — чип-фильтр по состоянию;
 *   ?group=pages|categories|brands|blog|products — одна группа; товары — по 50 на страницу, поиск ?q=;
 *   ?lang=uk                       — таблица глазами украинской версии (/ua/…: поля *_uk, шаблоны «seo.*.uk»);
 *   ?sample=ID                     — товар для превью шаблонов;
 *   POST /admin/seo/refresh/       — пересчитать сейчас (итоги кэшируются на 10 минут).
 * Экран только показывает — правки делаются на экранах товара/категории/страницы и в «Настройки → SEO-шаблоны»,
 * поэтому доступен и менеджерам.
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
                : ['rows' => [], 'total' => 0, 'counts' => SeoAudit::productCounts($st), 'page' => 1, 'found' => null, 'limited' => false];
            return ['rows' => $o['rows'], 'prod' => $prod];
        });
        $rows = $view['rows'];
        $prod = $view['prod'];
        $pager = $group === 'products' ? new Paginator($prod['total'], SeoAudit::PER_PAGE, $prod['page']) : null;

        // счётчики: вкладки групп — под текущий чип, чипы — под текущую группу
        $groupCount = array_fill_keys(array_keys(SeoAudit::GROUPS), 0);
        $groupAll = $groupCount;
        $chipCount = array_fill_keys(array_keys(SeoAudit::FILTERS), 0);
        foreach ($rows as $r) {
            if (SeoAudit::matches($r, $filter)) $groupCount[$r['group']]++;
            if (SeoAudit::matches($r, '')) $groupAll[$r['group']]++;
            if ($group !== '' && $group !== $r['group']) continue;
            foreach ($chipCount as $f => $_) if (SeoAudit::matches($r, $f)) $chipCount[$f]++;
        }
        $groupCount['products'] = $prod['counts'][$filter] ?? 0;
        $groupAll['products'] = $prod['counts'][''] ?? 0;
        if ($group === '' || $group === 'products') {
            foreach ($chipCount as $f => $_) $chipCount[$f] += $prod['counts'][$f] ?? 0;
        }

        $listed = ($group === 'products') ? [] : array_values(array_filter($rows,
            static fn($r) => ($group === '' || $r['group'] === $group) && SeoAudit::matches($r, $filter)));

        $actions = '<form method="post" action="/admin/seo/refresh/" class="seo-refresh">' . self::tokenField()
            . '<button class="btn" type="submit" title="Итоги считаются раз в 10 минут">'
            . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/></svg>'
            . 'Пересчитать</button></form>'
            . '<a class="btn" href="' . e(SeoAudit::SETTINGS_URL) . '">SEO-шаблоны</a>';

        return $this->render('admin/seo/index', [
            'title'      => 'SEO',
            'actions'    => $actions,
            'styles'     => ['admin/seo.css'],
            'scripts'    => ['admin/seo.js'],
            'filter'     => $filter,
            'group'      => $group,
            'lang'       => $lang,
            'q'          => $q,
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
