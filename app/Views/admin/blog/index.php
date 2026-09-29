<?php
/**
 * Список статей блога.
 * @var array $rows @var App\Core\Paginator $pg @var string $q @var string $status @var int $total @var array $counts
 * @var string $seoFilter фильтр «SEO» (?seo=) @var array $seoCounts счётчики чипов @var array $seoCells [id => ячейки Title/Description]
 */
use App\Controllers\Admin\BaseController;

$tabs = ['' => ['Все', array_sum($counts)], 'published' => ['Опубликованные', $counts['published'] ?? 0], 'draft' => ['Черновики', $counts['draft'] ?? 0]];
$qs = static fn(array $p) => ($s = http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null))) ? '?' . $s : '';
?>
<nav class="tabs" aria-label="Статус статей">
  <?php foreach ($tabs as $k => [$label, $n]): ?>
    <a href="/admin/blog/<?= e($qs(['status' => $k, 'q' => $q, 'seo' => $seoFilter])) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= e($label) ?><i><?= (int) $n ?></i></a>
  <?php endforeach; ?>
</nav>
<form class="toolbar" method="get" action="/admin/blog/" role="search">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <?php if ($seoFilter !== ''): ?><input type="hidden" name="seo" value="<?= e($seoFilter) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Заголовок или адрес" aria-label="Поиск статей">
  <button class="btn btn-p">Найти</button>
  <?php if ($q !== ''): ?><a class="btn" href="/admin/blog/<?= e($qs(['status' => $status, 'seo' => $seoFilter])) ?>">Сбросить</a><?php endif; ?>
  <span class="sp"></span><span class="muted">Найдено: <?= (int) $total ?></span>
</form>
<?= $view->partial('admin/partials/seo-filter') ?>

<?php if (!$rows): ?>
  <div class="card empty-card"><h2>Статей не найдено</h2><p>Измените условия поиска<?= $seoFilter !== '' ? ' или фильтр SEO' : '' ?> или напишите новую статью.</p><a class="btn btn-p" href="/admin/blog/new/">+ Новая статья</a></div>
<?php else: ?>
<div class="card flush">
<div class="table-scroll">
<table class="grid">
  <thead><tr><th class="thumb opt">Фото</th><th>Заголовок</th><th>UA</th><th class="seo-col">Title</th><th class="seo-col">Description</th><th>Статус</th><th class="opt">Дата публикации</th><th><span class="sr">Действия</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $pub = $r['status'] === 'published'; ?>
    <tr class="<?= $pub ? '' : 'is-draft' ?>">
      <td class="thumb opt"><?php if ($r['image']): ?><img src="<?= e(media($r['image'])) ?>" alt="" loading="lazy" width="46" height="46"><?php else: ?><span class="noimg" aria-hidden="true"><?= icon('doc') ?></span><?php endif; ?></td>
      <td><a href="/admin/blog/<?= (int) $r['id'] ?>/"><b><?= e($r['title']) ?></b></a><small>/blog/<?= e($r['url']) ?>/</small></td>
      <td><?php if ((int) $r['has_uk']): ?><span class="pill ok" title="<?= e($r['title_uk'] ?: 'Заголовок не переведён') ?>">есть</span><?php elseif ($r['title_uk']): ?><span class="pill" title="Переведён только заголовок">заголовок</span><?php else: ?><span class="muted" title="На /ua/ показывается русский текст">—</span><?php endif; ?></td>
      <?= $view->partial('admin/partials/seo-cells', ['seoCell' => $seoCells[(int) $r['id']] ?? null, 'seoEdit' => '/admin/blog/' . (int) $r['id'] . '/#h-seo']) ?>
      <td><?= $pub ? '<span class="st st-completed">опубликована</span>' : '<span class="st st-deleted">черновик</span>' ?>
        <?php if ($pub && strtotime((string) $r['published_at']) > time()): ?><small>отложена</small><?php endif; ?></td>
      <td class="nowrap opt"><?= e(date('d.m.Y H:i', strtotime((string) $r['published_at']))) ?></td>
      <td class="nowrap right">
        <?php if ($pub): ?><a class="btn btn-sm opt-inline" href="/blog/<?= e(rawurlencode((string) $r['url'])) ?>/" target="_blank" rel="noopener" aria-label="Открыть статью на сайте" title="Открыть на сайте"><?= icon('eye', 'width:15px;height:15px') ?></a><?php endif; ?>
        <a class="btn btn-sm" href="/admin/blog/<?= (int) $r['id'] ?>/">Изменить</a>
        <form method="post" action="/admin/blog/<?= (int) $r['id'] ?>/delete/" class="inline" data-confirm="Удалить статью «<?= e($r['title']) ?>»?">
          <?= BaseController::tokenField() ?><button class="btn btn-sm btn-d" aria-label="Удалить статью <?= e($r['title']) ?>" title="Удалить"><?= icon('trash', 'width:15px;height:15px') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?= $pg->html() ?>
<?= $view->partial('admin/partials/seo-legend', ['seoType' => 'blog']) ?>
<?php endif; ?>
