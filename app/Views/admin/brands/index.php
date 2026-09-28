<?php
/**
 * Список брендов.
 * @var array $rows @var int $total @var App\Core\Paginator $pg @var string $q @var string $show @var string $sort
 * @var array $sorts @var array $counts
 */
use App\Core\Request;
use App\Services\Catalog;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$tab = static fn(string $v) => e(Request::withQuery(['show' => $v, 'page' => null]));
?>
<nav class="tabs" aria-label="Отбор брендов">
  <a href="<?= $tab('') ?>" class="<?= $show === '' ? 'on' : '' ?>">Все <i><?= $fmt($counts['all']) ?></i></a>
  <a href="<?= $tab('visible') ?>" class="<?= $show === 'visible' ? 'on' : '' ?>">На сайте <i><?= $fmt($counts['visible']) ?></i></a>
  <a href="<?= $tab('hidden') ?>" class="<?= $show === 'hidden' ? 'on' : '' ?>">Скрытые <i><?= $fmt($counts['hidden']) ?></i></a>
  <a href="<?= $tab('empty') ?>" class="<?= $show === 'empty' ? 'on' : '' ?>">Без товаров <i><?= $fmt($counts['empty']) ?></i></a>
  <a href="<?= $tab('nouk') ?>" class="<?= $show === 'nouk' ? 'on' : '' ?>" title="Есть описание, но нет украинского перевода">Без перевода UA <i><?= $fmt($counts['nouk'] ?? 0) ?></i></a>
</nav>

<form class="filter-bar" method="get" action="/admin/brands/" role="search">
  <?php if ($show !== ''): ?><input type="hidden" name="show" value="<?= e($show) ?>"><?php endif; ?>
  <label class="sr-only" for="b-q">Поиск бренда</label>
  <input type="search" id="b-q" name="q" value="<?= e($q) ?>" placeholder="Название, адрес или №">
  <label class="sr-only" for="b-sort">Сортировка</label>
  <select name="sort" id="b-sort"><?php foreach ($sorts as $k => $label): ?><option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  <button class="btn btn-p" type="submit">Найти</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= e(Request::withQuery(['q' => null, 'page' => null])) ?>">Сбросить</a><?php endif; ?>
</form>

<div class="card flush">
  <div class="card-hd"><h2>Найдено: <?= $fmt($total) ?></h2><?php if ($pg->pages > 1): ?><span class="muted ac-small">страница <?= $pg->page ?> из <?= $pg->pages ?></span><?php endif; ?></div>
  <?php if (!$rows): ?>
    <div class="empty-card"><h2>Бренды не найдены</h2><p>Измените условия поиска.</p><a class="btn" href="/admin/brands/">Все бренды</a></div>
  <?php else: ?>
  <div class="table-scroll">
    <table class="tbl ac-brands">
      <thead><tr><th>Лого</th><th>Бренд</th><th class="num">Товаров</th><th>Тексты</th><th>Статус</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $b): $bid = (int) $b['id']; ?>
        <tr class="<?= (int) $b['hidden'] ? 'is-draft' : '' ?>">
          <td><a href="/admin/brands/<?= $bid ?>/" aria-label="Открыть бренд «<?= e($b['name']) ?>»"><?php if (!empty($b['image'])): ?><img class="ac-logo" src="<?= e(media((string) $b['image'])) ?>" alt="" width="46" height="46" loading="lazy"><?php else: ?><span class="ac-nophoto">нет лого</span><?php endif; ?></a></td>
          <td class="ac-pname"><a href="/admin/brands/<?= $bid ?>/"><?= e($b['name']) ?></a><small>№<?= $bid ?> · <?= e(rawurldecode(Catalog::brandUrl($b))) ?></small></td>
          <td class="num"><?php if ((int) $b['product_count']): ?><a href="/admin/products/?brand=<?= $bid ?>"><?= $fmt($b['product_count']) ?></a><?php else: ?><span class="muted">0</span><?php endif; ?></td>
          <td class="nowrap ac-small">
            <span class="seo-dot <?= trim((string) $b['title']) !== '' ? 'ok' : 'auto' ?>" title="Title: <?= trim((string) $b['title']) !== '' ? 'свой' : 'по названию' ?>"></span>
            <span class="seo-dot <?= trim((string) $b['meta_description']) !== '' ? 'ok' : 'none' ?>" title="Description: <?= trim((string) $b['meta_description']) !== '' ? 'заполнен' : 'пусто' ?>"></span>
            <span class="seo-dot <?= (int) $b['has_desc'] ? 'ok' : 'none' ?>" title="Описание: <?= (int) $b['has_desc'] ? 'есть' : 'нет' ?>"></span>
            <?php if ((int) $b['has_uk']): ?><span class="ac-ltag uk" title="Есть украинский перевод">UA</span><?php endif; ?>
          </td>
          <td><?= (int) $b['hidden'] ? '<span class="pill">скрыт</span>' : '<span class="pill ok">на сайте</span>' ?></td>
          <td class="nowrap"><a class="btn btn-sm" href="<?= e(Catalog::brandUrl($b)) ?>" target="_blank" rel="noopener" aria-label="Открыть «<?= e($b['name']) ?>» на сайте">На сайте ↗</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?= $pg->html() ?>
<p class="hint">Точки: Title · Description · описание на странице бренда (зелёная — заполнено, серая — по названию, красная — пусто).</p>
