<?php
/**
 * SEO-обзор (как «SEO» в админке ARG FLEX): итоги, покрытие украинской версии, служебное, шаблоны,
 * таблица всех адресов sitemap.xml (русская версия или глазами /ua/).
 * @var App\Core\View $view
 * @var string $filter @var string $group @var string $lang @var string $q @var array $rows @var array $prod @var ?App\Core\Paginator $pager
 * @var array $groupCount @var array $groupAll @var array $chipCount @var array $tally @var array $split @var array $sitemap @var array $cover
 * @var int $at @var array $sample @var int $sampleId @var array $templates @var array $service
 * @var bool $withHidden чип «Закрыто от индексации»: база «из M» — все адреса вместе со скрытыми
 */
use App\Services\SeoAudit;

if (!defined('ADMIN_SERP_JS')) define('ADMIN_SERP_JS', true);   // seo.js уже подключён макетом ($scripts)

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$dots = SeoAudit::STATES;
$lim = ['title' => [SeoAudit::TITLE_MIN, SeoAudit::TITLE_MAX], 'desc' => [SeoAudit::DESC_MIN, SeoAudit::DESC_MAX]];
$isUk = $lang === 'uk';
// ссылка на этот экран с изменёнными параметрами (страница списка сбрасывается)
$u = static function (array $ch = [], string $hash = '#list') use ($filter, $group, $q, $lang, $sampleId): string {
    $p = array_merge(['group' => $group, 'show' => $filter, 'q' => $group === 'products' ? $q : '', 'lang' => $lang === 'ru' ? '' : $lang,
        'sample' => $sampleId ?: ''], $ch);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return '/admin/seo/' . ($p ? '?' . http_build_query($p) : '') . $hash;
};
// ячейка title/description: точка, текст (свой — обычным, по шаблону — серым курсивом), длина
$cell = static function (array $r, string $k) use ($dots, $lim, $fmt): string {
    $st = $r[$k . '_state'];
    $txt = (string) $r[$k];
    $h = '<div class="seo-line"><i class="seo-dot ' . e($st) . '" title="' . e($dots[$st] ?? '') . '"></i>';
    if ($txt === '') {
        return $h . '<span class="auto">' . ($k === 'title' ? 'Нет' : 'Нет описания') . '</span></div><em>пусто, шаблона нет</em>';
    }
    $len = mb_strlen($txt);
    $short = str_limit($txt, 62);
    $h .= '<span' . ($r[$k . '_own'] ? '' : ' class="auto"') . ($short !== $txt ? ' title="' . e($txt) . '"' : '') . '>' . e($short) . '</span></div>';
    $em = match ($st) {
        'warn'  => $fmt($len) . ' симв. — ' . ($len < $lim[$k][0] ? 'короче ' . $lim[$k][0] : 'длиннее ' . $lim[$k][1]),
        'auto'  => 'по шаблону · ' . $fmt($len) . ' симв.',
        default => $fmt($len) . ' симв.',
    };
    return $h . '<em' . ($st === 'warn' ? ' class="warn"' : '') . '>' . e($em) . '</em>';
};
$shownPath = static fn(string $p): string => rawurldecode($p);     // /brand/Mona+Lisa/ — как в адресной строке
// полоса из сегментов .seo-bar: [состояние-точка => число]
$bar = static function (array $parts, array $names, string $label) use ($fmt): string {
    $sum = max(1, array_sum($parts));
    $h = '<span class="seo-bar" role="img" aria-label="' . e($label . ': ' . implode(', ', array_map(static fn($s) => $names[$s] . ' ' . $parts[$s], array_keys($parts)))) . '">';
    foreach ($parts as $s => $n) {
        if ($n) $h .= '<i class="' . e($s) . '" style="width:' . round($n / $sum * 100, 2) . '%" title="' . e($names[$s] . ': ' . $fmt($n)) . '"></i>';
    }
    return $h . '</span>';
};

$listedTotal = $group === 'products' ? $prod['total'] : count($rows) + ($group === '' ? $prod['total'] : 0);
$groupTotal = $group === '' ? array_sum($groupAll) : ($groupAll[$group] ?? 0);
$hdTitle = SeoAudit::FILTERS[$filter] . ($group !== '' ? ' · ' . SeoAudit::GROUPS[$group] : '') . ($isUk ? ' · /ua/' : '');
$tplBy = array_column($templates, null, 'key');
$sp = $sample['product'] ?? null;
$sc = $sample['category'] ?? null;

// покрытие украинской версии: итог по всем группам
$ukNames = SeoAudit::UK_STATES;
$ukDots = SeoAudit::UK_DOTS;
$coverAll = ['n' => 0, 'title' => array_fill_keys(array_keys($ukNames), 0), 'desc' => array_fill_keys(array_keys($ukNames), 0)];
foreach ($cover as $c) {
    $coverAll['n'] += $c['n'];
    foreach (['title', 'desc'] as $f) foreach ($c[$f] as $s => $n) $coverAll[$f][$s] += $n;
}
$ukCell = static function (array $x, string $label) use ($bar, $ukNames, $ukDots, $fmt): string {
    $parts = [];
    foreach ($ukDots as $s => $dot) $parts[$dot] = $x[$s];
    $names = [];
    foreach ($ukDots as $s => $dot) $names[$dot] = $ukNames[$s];
    $has = $x['own'] + $x['tpl'] + $x['ru'];
    $pct = $has ? (int) floor(($x['own'] + $x['tpl']) / $has * 100) : null;
    $txt = [];
    foreach (['own' => 'свой', 'tpl' => 'сам', 'ru' => 'рус.', 'none' => 'пусто'] as $s => $short) if ($x[$s]) $txt[] = $short . ' ' . $fmt($x[$s]);
    return '<div class="seo-cover-cell">' . $bar($parts, $names, $label) . '<b>' . ($pct === null ? '—' : $pct . '%') . '</b></div>'
        . '<small>' . e(implode(' · ', $txt) ?: 'нет адресов') . '</small>';
};
// шаблоны, которые витрина применяет, но у них нет украинского варианта
$noUk = array_values(array_filter($templates, static fn($t) => $t['used'] && $t['tpl'] !== '' && !$t['uk']));
?>
<div class="two-col reverse">
  <div class="seo-main">
    <div class="card pad-card seo-overview">
      <h2>Что видят поисковики</h2>
      <p class="muted">В <a href="/sitemap.xml" target="_blank" rel="noopener">sitemap.xml</a> — <b><?= $fmt($sitemap['total']) ?></b>
        <?= plural((int) $sitemap['total'], 'адрес', 'адреса', 'адресов') ?> русской версии (и столько же украинской, /ua/…), у каждого поисковик берёт title (заголовок в выдаче) и description (текст под ним).
        Пустое поле — не всегда ошибка: если своего нет, сайт строит его из SEO-шаблона — это <b>По шаблону</b>, исправлять не нужно.
        <b>Нет</b> — пусто, и шаблона тоже нет; <b>Длина</b> — своё значение (или результат шаблона) короче или длиннее, чем покажет Google
        (title <?= SeoAudit::TITLE_MIN ?>–<?= SeoAudit::TITLE_MAX ?>, description <?= SeoAudit::DESC_MIN ?>–<?= SeoAudit::DESC_MAX ?> символов).</p>

      <div class="seo-map">
        <?php foreach ([['Главная', $sitemap['home'], 'pages'], ['Категории', $sitemap['categories'], 'categories'], ['Бренды с товарами', $sitemap['brands'], 'brands'],
                        ['Инфо-страницы', $sitemap['pages'], 'pages'], ['Блог и статьи', $sitemap['blog'], 'blog'], ['Товары на сайте', $sitemap['products'], 'products']] as [$label, $n, $g]): ?>
          <a href="<?= e($u(['group' => $g, 'show' => '', 'q' => ''])) ?>"><b><?= $fmt($n) ?></b><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="seo-tally">
        <?php foreach (['none', 'warn', 'ok', 'auto'] as $k):
                // «Задан» — не фильтр таблицы (исправлять нечего), поэтому без ссылки, как в ARG FLEX
                $inner = '<i class="seo-dot ' . $k . '"></i><b>' . $fmt($tally[$k]) . '</b> ' . e($dots[$k]); ?>
          <?php if (isset(SeoAudit::FILTERS[$k])): ?><a class="seo-count" href="<?= e($u(['show' => $k, 'group' => '', 'q' => '', 'lang' => ''])) ?>"><?= $inner ?></a>
          <?php else: ?><span class="seo-count"><?= $inner ?></span><?php endif; ?>
        <?php endforeach; ?>
        <span class="muted">из <?= $fmt(array_sum($tally)) ?> title и description</span>
      </div>

      <div class="seo-split">
        <?php foreach (['title' => 'Title', 'desc' => 'Description'] as $k => $label): $order = ['ok' => $split[$k]['ok'], 'warn' => $split[$k]['warn'], 'auto' => $split[$k]['auto'], 'none' => $split[$k]['none']]; ?>
          <div class="seo-split-row">
            <span class="seo-split-lbl"><?= $label ?></span>
            <?= $bar($order, $dots, $label) ?>
            <small><?php $parts = []; foreach ($order as $s => $n) if ($n) $parts[] = e(mb_strtolower($dots[$s])) . ' ' . $fmt($n); echo implode(' · ', $parts); ?></small>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="hint">Посчитано в <?= e(date('H:i', $at)) ?>. Итоги обновляются раз в 10 минут и сразу после правок в админке; кнопка «Пересчитать» — вверху.</p>
    </div>

    <div class="card seo-uk" id="uk">
      <div class="card-hd"><h2>Украинская версия /ua/</h2><a href="<?= e($u(['lang' => 'uk'])) ?>">Таблица глазами /ua/</a></div>
      <div class="pad">
        <p class="muted">Те же адреса с префиксом /ua/ — в файлах sitemap-ua-*.xml. На /ua/ берётся своё украинское значение (Title UA, Description UA),
          если оно заполнено; пусто — своё русское значение, а если нет и его — шаблон в украинском варианте. Процент — сколько из заполненных мета-тегов на /ua/ будут по-украински (пустые — красные точки — пусты и в русской версии).</p>
        <div class="seo-tally">
          <?php foreach ($ukDots as $s => $dot): ?>
            <span class="seo-count"><i class="seo-dot <?= $dot ?>"></i><b><?= $fmt($coverAll['title'][$s] + $coverAll['desc'][$s]) ?></b> <?= e($ukNames[$s]) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="table-scroll">
        <table class="grid seo-cover">
          <thead><tr><th>Группа</th><th class="right">Адресов</th><th>Title на /ua/</th><th>Description на /ua/</th></tr></thead>
          <tbody>
          <?php foreach ($cover as $g => $c): ?>
            <tr>
              <td><a href="<?= e($u(['group' => $g, 'show' => '', 'q' => '', 'lang' => 'uk'])) ?>"><b><?= e(SeoAudit::GROUPS[$g]) ?></b></a></td>
              <td class="right"><?= $fmt($c['n']) ?></td>
              <td><?= $ukCell($c['title'], 'Title') ?></td>
              <td><?= $ukCell($c['desc'], 'Description') ?></td>
            </tr>
          <?php endforeach; ?>
            <tr class="seo-cover-sum">
              <td><b>Всего</b></td>
              <td class="right"><b><?= $fmt($coverAll['n']) ?></b></td>
              <td><?= $ukCell($coverAll['title'], 'Title') ?></td>
              <td><?= $ukCell($coverAll['desc'], 'Description') ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="pad seo-uk-foot">
        <p class="hint"><i class="seo-dot ok"></i> свой — заполнено поле UA на экране товара, категории, страницы;
          <i class="seo-dot auto"></i> сам — своего нет ни на одном языке, значение строится из украинского шаблона или названия;
          <i class="seo-dot warn"></i> рус. — на /ua/ окажется русский текст: своё русское значение без перевода или шаблон без украинского варианта.</p>
        <?php if ($noUk): ?>
          <p class="hint">Без украинского варианта: <?= e(implode(', ', array_map(static fn($t) => $t['where'] . ' — ' . $t['field'], $noUk))) ?>.
            <a href="<?= e(SeoAudit::SETTINGS_URL) ?>">Перевести в SEO-шаблонах</a></p>
        <?php else: ?>
          <p class="hint">У всех применяемых SEO-шаблонов есть украинский вариант.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <aside class="card seo-service">
    <div class="card-hd"><h2>Служебное</h2></div>
    <div class="pad">
      <?php foreach ($service['warn'] as $w): ?><div class="flash warn"><?= e($w) ?></div><?php endforeach; ?>
      <h3>sitemap.xml</h3>
      <p><a href="/sitemap.xml" target="_blank" rel="noopener">/sitemap.xml ↗</a> — <?= $fmt($sitemap['all']) ?> <?= plural((int) $sitemap['all'], 'адрес', 'адреса', 'адресов') ?>:
        <?= $fmt($sitemap['total']) ?> русской версии и столько же украинской (/ua/…), в <?= $fmt($sitemap['files']) ?> <?= plural((int) $sitemap['files'], 'файле', 'файлах', 'файлах') ?>
        (по 10 000 адресов, вместе с картами фото товаров). Строится сам из базы: новые товары и категории попадают туда без ваших действий.</p>
      <h3>robots.txt</h3>
      <p><a href="/robots.txt" target="_blank" rel="noopener">/robots.txt ↗</a> — <?= $service['robots_custom'] ? 'свой текст из настроек' : 'стандартный' ?>,
        закрыто разделов: <?= (int) $service['disallow'] ?> (кабинет, корзина, оформление, админка…)<?= $service['sitemaps'] ? ', ссылка на sitemap.xml есть' : '' ?>.</p>
      <details class="seo-robots"><summary>Показать текст</summary><pre class="log"><?= e(trim($service['robots_text'])) ?></pre></details>
      <h3>Google Search Console</h3>
      <p>Отправьте адрес <code><?= e($service['base'] . '/sitemap.xml') ?></code> в Search Console → «Файлы Sitemap» — один раз после запуска сайта;
        дальше Google сам перечитывает карту (украинские файлы в ней уже есть). Там же видно, сколько страниц в индексе и какие с ошибками.</p>
      <p><a class="btn btn-sm" href="https://search.google.com/search-console/sitemaps" target="_blank" rel="noopener">Открыть Search Console ↗</a></p>
    </div>
  </aside>
</div>

<div class="card" id="templates">
  <div class="card-hd"><h2>SEO-шаблоны</h2><a href="<?= e(SeoAudit::SETTINGS_URL) ?>">Изменить шаблоны</a></div>
  <div class="pad">
    <p class="muted">Если у товара, категории или страницы нет своего title / description, сайт подставляет шаблон из настроек (на /ua/ — его украинский вариант).
      <?php if ($sp): ?>Пример — товар <a href="/admin/products/<?= (int) $sp['id'] ?>/"><?= e($sp['name']) ?></a> (№ <?= (int) $sp['id'] ?><?= (string) $sp['sku'] !== '' ? ', арт. ' . e($sp['sku']) : '' ?>, <?= e(price_format($sp['price'])) ?> за пару)<?php if ($sc): ?> и его категория «<?= e($sc['name']) ?>»<?php endif; ?>.<?php endif; ?></p>
    <form class="filter-bar seo-sample" method="get" action="/admin/seo/#templates">
      <?php foreach (['group' => $group, 'show' => $filter, 'q' => $group === 'products' ? $q : '', 'lang' => $isUk ? $lang : ''] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
      <input type="number" name="sample" min="1" step="1" value="<?= $sampleId ?: '' ?>" placeholder="№ товара" aria-label="Номер товара для примера">
      <button class="btn btn-sm" type="submit">Показать на другом товаре</button>
      <?php if ($sampleId): ?><a class="btn btn-sm" href="<?= e($u(['sample' => ''], '#templates')) ?>">Последний товар</a><?php endif; ?>
      <?php if ($sampleId && (!$sp || (int) $sp['id'] !== $sampleId)): ?><span class="muted">Товара № <?= $sampleId ?> нет — показан последний.</span><?php endif; ?>
    </form>
    <?php if ($sp || $sc): ?>
    <div class="grid2 seo-previews">
      <?php if ($sp): ?><div><p class="lbl">Товар без своих title и description</p>
        <?= $view->partial('admin/partials/serp', ['serp' => ['path' => '/product/' . $sp['url'] . '/', 'titleAuto' => $tplBy['seo.product_meta_title']['result'] ?: $sp['name'],
            'descAuto' => $tplBy['seo.product_meta_description']['result'], 'live' => false]]) ?></div><?php endif; ?>
      <?php if ($sc): ?><div><p class="lbl">Категория без своих title и description</p>
        <?= $view->partial('admin/partials/serp', ['serp' => ['path' => '/category/' . $sc['url'] . '/', 'titleAuto' => ($tplBy['seo.category_meta_title']['used'] ? $tplBy['seo.category_meta_title']['result'] : '') ?: $sc['name'],
            'descAuto' => $tplBy['seo.category_meta_description']['used'] ? $tplBy['seo.category_meta_description']['result'] : '', 'live' => false]]) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php // полная таблица шаблонов — под спойлером (около 3000 px): иначе список адресов ниже уезжает на пять экранов
        $tplUsed = count(array_filter($templates, static fn($t) => $t['used'] && $t['tpl'] !== '')); ?>
  <details class="seo-tpl-all"<?= $sampleId ? ' open' : '' ?>>
    <summary>Все шаблоны и результат на примере <span class="muted">— сайт применяет <?= $tplUsed ?> из <?= count($templates) ?>,
      <?= $noUk ? count($noUk) . ' без украинского варианта' : 'у всех есть украинский вариант' ?></span></summary>
  <div class="table-scroll">
    <table class="grid seo-table seo-tpl">
      <thead><tr><th>Поле</th><th>Шаблон</th><th>Результат на примере</th></tr></thead>
      <tbody>
      <?php
      // результат шаблона: точка длины, текст, длина
      $res = static function (string $text, int $len, string $state, string $kind) use ($fmt, $lim): string {
          $k = $kind === 'title' ? 'title' : 'desc';
          $h = '<div class="seo-line"><i class="seo-dot ' . e($state) . '" title="' . e($kind !== '' ? ($state === 'warn' ? 'Длина' : 'Длина в норме') : '') . '"></i><span title="' . e($text) . '">' . e($text) . '</span></div>';
          return $h . '<em' . ($state === 'warn' ? ' class="warn"' : '') . '>' . $fmt($len) . ' симв.'
              . ($state === 'warn' ? ' — ' . ($len < $lim[$k][0] ? 'коротко' : 'длинно, Google обрежет') : '') . '</em>';
      };
      $cur = null; foreach ($templates as $t): ?>
        <?php if ($t['where'] !== $cur): $cur = $t['where']; ?><tr class="seo-group"><td colspan="3"><?= e($cur) ?></td></tr><?php endif; ?>
        <tr class="<?= $t['used'] ? '' : 'is-draft' ?>">
          <td class="seo-fld"><b><?= e($t['field']) ?></b><?php if (!$t['used']): ?><small><?= $t['field'] === 'H1' ? 'не применяется: H1 = название' : 'выключен в настройках' ?></small><?php endif; ?></td>
          <td class="seo-code">
            <?= $t['tpl'] !== '' ? '<code>' . e($t['tpl']) . '</code>' : '<span class="muted">пусто</span>' ?>
            <?php if ($t['tpl_uk'] !== ''): ?><div class="seo-uk-tpl"><span class="seo-lang">UA</span><code><?= e($t['tpl_uk']) ?></code></div>
            <?php elseif ($t['tpl'] !== '' && $t['used']): ?><div class="seo-uk-tpl"><span class="seo-lang">UA</span><em class="warn">нет перевода — на /ua/ будет русский шаблон</em></div><?php endif; ?>
          </td>
          <td class="seo-cell seo-wrap">
            <?php if ($t['result'] !== ''): ?>
              <?= $res($t['result'], $t['len'], $t['state'], $t['kind']) ?>
              <?php if ($t['result_uk'] !== '' && $t['result_uk'] !== $t['result']): ?><div class="seo-uk-res"><span class="seo-lang">UA</span><div><?= $res($t['result_uk'], $t['len_uk'], $t['state_uk'], $t['kind']) ?></div></div><?php endif; ?>
            <?php else: ?>
              <div class="seo-line"><i class="seo-dot none"></i><span class="auto">Шаблон пуст<?= $t['kind'] === 'description' ? ' — у страниц без своего описания его не будет' : '' ?></span></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </details>
</div>

<div class="tabs seo-groups" id="list">
  <a class="<?= $group === '' ? 'on' : '' ?>" href="<?= e($u(['group' => '', 'q' => ''])) ?>">Все группы <i><?= $fmt(array_sum($groupCount)) ?></i></a>
  <?php foreach (SeoAudit::GROUPS as $g => $label): ?>
    <a class="<?= $group === $g ? 'on' : '' ?>" href="<?= e($u(['group' => $g, 'q' => ''])) ?>"><?= e($label) ?> <i><?= $fmt($groupCount[$g]) ?></i></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-hd">
    <h2><?= e($hdTitle) ?></h2>
    <span class="muted"><?= $fmt($listedTotal) ?> из <?= $fmt($groupTotal) ?><?= $withHidden ? ' адресов вместе со скрытыми' : '' ?></span>
  </div>
  <div class="pad seo-bar-pad">
    <nav class="subtabs seo-langs" aria-label="Версия сайта">
      <?php foreach (SeoAudit::LANGS as $l => $label): ?>
        <a class="<?= $lang === $l ? 'on' : '' ?>" href="<?= e($u(['lang' => $l === 'ru' ? '' : $l])) ?>"<?= $lang === $l ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($isUk): ?><p class="hint seo-langs-hint">Так видит поисковик украинскую версию: своё украинское значение, иначе русское своё, иначе украинский шаблон. Править — на тех же экранах, вкладка UA.</p><?php endif; ?>
    <div class="seo-filters">
      <?php foreach (SeoAudit::FILTERS as $f => $label): ?>
        <a class="chip <?= $filter === $f ? 'on' : '' ?>" href="<?= e($u(['show' => $f])) ?>"><?php if ($f !== '' && $f !== 'closed'): ?><i class="seo-dot <?= $f ?>"></i><?php endif; ?><?= e($label) ?> <b><?= $fmt($chipCount[$f]) ?></b></a>
      <?php endforeach; ?>
    </div>
    <?php if ($group === '' || $group === 'products'): ?>
      <form class="filter-bar seo-search" method="get" action="/admin/seo/#list" role="search">
        <input type="hidden" name="group" value="products">
        <?php if ($filter !== ''): ?><input type="hidden" name="show" value="<?= e($filter) ?>"><?php endif; ?>
        <?php if ($isUk): ?><input type="hidden" name="lang" value="uk"><?php endif; ?>
        <?php if ($sampleId): ?><input type="hidden" name="sample" value="<?= $sampleId ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Товар: название, артикул или №" aria-label="Поиск товара">
        <button class="btn btn-p" type="submit">Найти</button>
        <?php if ($q !== ''): ?><a class="btn" href="<?= e($u(['q' => ''])) ?>">Сбросить</a>
          <span class="muted">Найдено товаров на сайте: <?= $fmt($prod['found'] ?? 0) ?><?= !empty($prod['found_hidden']) ? ', ещё скрытых: ' . $fmt($prod['found_hidden']) . ' (они — в «Закрыто от индексации»)' : '' ?><?= !empty($prod['limited']) ? ' — показаны самые новые, уточните запрос' : '' ?></span><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>

  <div class="table-scroll">
    <table class="grid seo-table">
      <thead><tr><th>Страница</th><th>Title</th><th>Description</th><th class="right">Где редактируется</th></tr></thead>
      <tbody>
      <?php
      // строка таблицы (без лишних пробелов: во «Всём» их ~500)
      $render = static function (array $r) use ($cell, $shownPath): void {
          echo '<tr', $r['hidden'] ? ' class="is-draft"' : '', '><td class="seo-page"><b>', e($r['name']), '</b><small><a href="', e($r['path']),
              '" target="_blank" rel="noopener" class="seo-path">', e($shownPath($r['path'])), '</a>',
              $r['closed'] !== '' ? ' · <em class="seo-closed">' . e($r['closed']) . '</em>' : '', '</small>',
              $r['sub'] !== '' ? '<small>' . e($r['sub']) . '</small>' : '', '</td>',
              '<td class="seo-cell">', $cell($r, 'title'), '</td><td class="seo-cell">', $cell($r, 'desc'), '</td><td class="right seo-acts">',
              !$r['hidden'] ? '<a class="btn btn-sm" href="' . e($r['path']) . '" target="_blank" rel="noopener">Открыть</a>' : '',
              $r['edit'] !== '' ? '<a class="btn btn-sm" href="' . e($r['edit']) . '">Метаданные</a>' : '',
              $r['edit_note'] !== '' ? '<small>' . e($r['edit_note']) . '</small>' : '', "</td></tr>\n";
      };
      $cur = null;
      foreach ($rows as $r) {
          if ($r['group'] !== $cur) {
              $cur = $r['group'];
              echo '<tr class="seo-group"><td colspan="4">' . e(SeoAudit::GROUPS[$cur]) . ' <span>' . $fmt($groupCount[$cur]) . '</span></td></tr>';
          }
          $render($r);
      }
      if ($prod['rows']) {
          echo '<tr class="seo-group"><td colspan="4">' . e(SeoAudit::GROUPS['products']) . ' <span>' . $fmt($prod['total'])
              . ($group === 'products' && $pager && $pager->pages > 1 ? ' · страница ' . $pager->page . ' из ' . $fmt($pager->pages) : '') . '</span></td></tr>';
          foreach ($prod['rows'] as $r) $render($r);
          if ($group === '' && $prod['total'] > count($prod['rows'])) {
              echo '<tr class="seo-more"><td colspan="4">Показаны ' . count($prod['rows']) . ' из ' . $fmt($prod['total']) . ' товаров. <a href="'
                  . e($u(['group' => 'products'])) . '">Все товары — постранично, с поиском →</a></td></tr>';
          }
      }
      if (!$rows && !$prod['rows']): ?>
        <tr><td colspan="4" class="seo-empty"><?= $q !== '' ? 'По запросу «' . e($q) . '» товаров ' . ($filter !== '' ? 'в этом состоянии ' : '') . 'нет.'
            : ($filter === '' ? 'Здесь пусто.' : 'Здесь пусто — это ровно тот ответ, который нужен от этого фильтра.') ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pager && $pager->pages > 1): ?><div class="pad seo-pager"><?= preg_replace('/href="([^"#]*)"/', 'href="$1#list"', $pager->html()) ?></div><?php endif; ?>
</div>
