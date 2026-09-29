<?php
/**
 * Список информационных страниц. Дубли /pages/… (адреса со старого сайта) — с пометкой «дубль».
 * @var array $rows @var string $filter @var string $q @var array $counts
 * @var string $seoFilter фильтр «SEO» (?seo=) @var array $seoCounts счётчики чипов @var array $seoCells [id => ячейки Title/Description]
 */
use App\Controllers\Admin\BaseController;

$tabs = ['' => ['Все', $counts['all']], 'main' => ['Основные', $counts['main']], 'dup' => ['Дубли /pages/…', $counts['dup']], 'hidden' => ['Скрытые', $counts['hidden']]];
// вкладка сохраняет фильтр «SEO»
$tabUrl = static fn(string $k): string => '/admin/pages/' . (($p = http_build_query(array_filter(['f' => $k, 'seo' => $seoFilter]))) !== '' ? '?' . $p : '');
?>
<nav class="tabs" aria-label="Фильтр страниц">
  <?php foreach ($tabs as $k => [$label, $n]): ?>
    <a href="<?= e($tabUrl($k)) ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= e($label) ?><i><?= (int) $n ?></i></a>
  <?php endforeach; ?>
</nav>
<form class="toolbar" method="get" action="/admin/pages/" role="search">
  <?php if ($filter !== ''): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
  <?php if ($seoFilter !== ''): ?><input type="hidden" name="seo" value="<?= e($seoFilter) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Название или адрес" aria-label="Поиск страниц">
  <button class="btn btn-p">Найти</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= e(App\Core\Request::withQuery(['q' => null])) ?>">Сбросить</a><?php endif; ?>
</form>
<?= $view->partial('admin/partials/seo-filter') ?>

<?php if (!$rows): ?>
  <div class="card empty-card"><h2>Страниц не найдено</h2><p>Измените условия поиска<?= $seoFilter !== '' ? ' или фильтр SEO' : '' ?> или создайте новую страницу.</p><a class="btn btn-p" href="/admin/pages/new/">+ Новая страница</a></div>
<?php else: ?>
<div class="card flush">
<div class="table-scroll">
<table class="grid pg-tbl">
  <thead><tr><th>Название</th><th class="opt">Адрес</th><th>UA</th><th class="seo-col">Title</th><th class="seo-col">Description</th><th class="opt">В меню</th><th>Статус</th><th class="right opt">Порядок</th><th class="opt">Изменена</th><th><span class="sr">Действия</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= (int) $r['status'] ? '' : 'is-draft' ?>">
      <td><a href="/admin/pages/<?= (int) $r['id'] ?>/"><b><?= e($r['name']) ?></b></a>
        <?php if ($r['dup']): ?>
          <span class="pill dup-pill" title="Копия страницы со старого сайта (приложение «Сайт» Webasyst). Адрес сохранён для поисковиков.">дубль</span>
          <small>
            <?php if ($r['original_id']): ?>основная: <a href="/admin/pages/<?= (int) $r['original_id'] ?>/">/<?= e($r['original']) ?></a><?php else: ?>основной страницы нет<?php endif; ?>
            · <?= $r['canonical'] ? 'canonical → ' . e($r['canonical']) : '<span class="warn-txt">canonical не указан</span>' ?>
          </small>
        <?php endif; ?>
        <small class="show-sm">/<?= e($r['url']) ?></small>
      </td>
      <td class="opt"><a href="/<?= e($r['url']) ?>" target="_blank" rel="noopener">/<?= e($r['url']) ?></a></td>
      <td><?php if ((int) $r['has_uk']): ?><span class="pill ok" title="<?= e($r['name_uk'] ?: 'Название не переведено') ?>">есть</span><?php elseif ($r['name_uk']): ?><span class="pill" title="Переведено только название">название</span><?php else: ?><span class="muted" title="На /ua/ показывается русский текст">—</span><?php endif; ?></td>
      <?= $view->partial('admin/partials/seo-cells', ['seoCell' => $seoCells[(int) $r['id']] ?? null, 'seoEdit' => '/admin/pages/' . (int) $r['id'] . '/#h-seo']) ?>
      <td class="opt"><?= (int) $r['in_menu'] ? '<span class="pill ok">да</span>' : '<span class="muted">—</span>' ?></td>
      <td><?= (int) $r['status'] ? '<span class="st st-completed">опубликована</span>' : '<span class="st st-deleted">скрыта</span>' ?></td>
      <td class="right opt"><?= (int) $r['sort'] ?></td>
      <td class="nowrap muted opt"><?= $r['updated_at'] ? e(date('d.m.Y', strtotime((string) $r['updated_at']))) : '—' ?></td>
      <td class="nowrap right">
        <a class="btn btn-sm" href="/admin/pages/<?= (int) $r['id'] ?>/">Изменить</a>
        <form method="post" action="/admin/pages/<?= (int) $r['id'] ?>/delete/" class="inline" data-confirm="Удалить страницу «<?= e($r['name']) ?>»? Адрес /<?= e($r['url']) ?> перестанет открываться.">
          <?= BaseController::tokenField() ?><button class="btn btn-sm btn-d" aria-label="Удалить страницу <?= e($r['name']) ?>" title="Удалить"><?= icon('trash', 'width:15px;height:15px') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?= $view->partial('admin/partials/seo-legend', ['seoType' => 'page']) ?>
<?php endif; ?>
<p class="hint">Страницы с отметкой «в меню» выводятся в верхней строке сайта и в подвале (по порядку). Колонка UA — переведено ли содержимое для украинской версии (/ua/…).
  Дубли /pages/… — адреса со старого сайта: оставьте их с canonical на основную страницу или удалите и добавьте <a href="/admin/redirects/">редирект</a>.</p>
