<?php
/**
 * Список брендов.
 * @var array $rows @var int $total @var App\Core\Paginator $pg @var string $q @var string $show @var string $sort
 * @var array $sorts @var array $counts
 * @var array $all [id => все товары бренда] @var array $twins [id => дубль с тем же ключом] @var array $locked [id => категория]
 * @var array $deleted удалённые бренды (вкладка «Удалённые»)
 */
use App\Controllers\Admin\BaseController;
use App\Core\Request;
use App\Services\Catalog;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$dt = static fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
// вопрос перед удалением: с товарами — сколько удалится; дубль — товары пойдут в оставшийся бренд
$ask = static function (array $b, int $n, ?string $twin) use ($fmt): string {
    $q = $n ? 'Удалить бренд «' . $b['name'] . '» и все его товары (' . $fmt($n) . ' шт., включая скрытые)?' . "\n\n" . 'Товары удалятся с сайта навсегда.'
        : 'Удалить бренд «' . $b['name'] . '»?';
    return $q . "\n\n" . ($twin !== null ? 'Такой же бренд «' . $twin . '» остаётся — новые товары поставщиков пойдут в него.'
        : 'Поставщики не будут загружать ' . ($n ? 'ни эти, ни новые товары бренда' : 'товары этого бренда') . '. Вернуть — вкладка «Удалённые».');
};
$tab = static fn(string $v) => e(Request::withQuery(['show' => $v, 'page' => null]));
?>
<nav class="tabs" aria-label="Отбор брендов">
  <a href="<?= $tab('') ?>" class="<?= $show === '' ? 'on' : '' ?>">Все <i><?= $fmt($counts['all']) ?></i></a>
  <a href="<?= $tab('visible') ?>" class="<?= $show === 'visible' ? 'on' : '' ?>">На сайте <i><?= $fmt($counts['visible']) ?></i></a>
  <a href="<?= $tab('hidden') ?>" class="<?= $show === 'hidden' ? 'on' : '' ?>">Скрытые <i><?= $fmt($counts['hidden']) ?></i></a>
  <a href="<?= $tab('empty') ?>" class="<?= $show === 'empty' ? 'on' : '' ?>">Без товаров <i><?= $fmt($counts['empty']) ?></i></a>
  <a href="<?= $tab('nouk') ?>" class="<?= $show === 'nouk' ? 'on' : '' ?>" title="Есть описание, но нет украинского перевода">Без перевода UA <i><?= $fmt($counts['nouk'] ?? 0) ?></i></a>
  <?php if ($counts['deleted'] || $show === 'deleted'): ?><a href="<?= $tab('deleted') ?>" class="<?= $show === 'deleted' ? 'on' : '' ?>" title="Удалённые бренды — поставщики их не загружают">Удалённые <i><?= $fmt($counts['deleted']) ?></i></a><?php endif; ?>
</nav>
<form id="bact" method="post" action="/admin/brands/"><?= BaseController::tokenField() ?></form>

<?php if ($show === 'deleted'): ?>
<div class="card flush">
  <div class="card-hd"><h2>Удалённые бренды: <?= $fmt($total) ?></h2></div>
  <?php if (!$deleted): ?>
    <div class="empty-card"><h2>Удалённых брендов нет</h2><p>Бренд, удалённый в списке, появится здесь — поставщики его товары не загружают.</p><a class="btn" href="/admin/brands/">Все бренды</a></div>
  <?php else: ?>
  <p class="hint pad">Поставщики («Хит обуви», Jong•Golf, Tom.m) новые товары этих брендов не загружают. «Вернуть» — снова загружать новые товары бренда (бренд появится сам с первым товаром), «с товарами» — и удалённые товары бренда (если они ещё есть у поставщика).</p>
  <div class="table-scroll">
    <table class="tbl">
      <thead><tr><th>Бренд</th><th>Удалён</th><th class="num">Товаров удалено</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($deleted as $d): $did = (int) $d['id']; ?>
        <tr>
          <td class="ac-pname"><?= e((string) $d['name']) ?><?php if ($d['brand_id'] !== null): ?><small>был №<?= (int) $d['brand_id'] ?></small><?php endif; ?></td>
          <td class="nowrap"><?= e($dt($d['created_at'])) ?></td>
          <td class="num"><?= (int) $d['products'] ? $fmt($d['products']) : '<span class="muted">0</span>' ?></td>
          <td class="nowrap right">
            <button class="btn btn-sm" type="submit" form="bact" formaction="/admin/brands/deleted/<?= $did ?>/restore/" data-confirm="Снова загружать бренд «<?= e((string) $d['name']) ?>»? Поставщики добавят его новые товары при следующих загрузках.">Вернуть</button>
            <?php if ((int) $d['left']): ?><button class="btn btn-sm" type="submit" form="bact" formaction="/admin/brands/deleted/<?= $did ?>/restore/" name="products" value="1" data-confirm="Снова загружать бренд «<?= e((string) $d['name']) ?>» и его удалённые товары (<?= $fmt($d['left']) ?>)? Поставщики добавят те, что у них ещё есть.">Вернуть с товарами (<?= $fmt($d['left']) ?>)</button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>

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
          <td class="nowrap"><a class="btn btn-sm" href="<?= e(Catalog::brandUrl($b)) ?>" target="_blank" rel="noopener" aria-label="Открыть «<?= e($b['name']) ?>» на сайте">На сайте ↗</a>
            <?php if (isset($locked[$bid])): ?><button class="btn btn-sm btn-d" type="button" disabled title="Используется в условии категории «<?= e($locked[$bid]) ?>» — сначала измените условие">Удалить</button>
            <?php else: $n = (int) ($all[$bid] ?? 0); ?><button class="btn btn-sm btn-d" type="submit" form="bact" formaction="/admin/brands/<?= $bid ?>/delete/" name="n" value="<?= $n ?>" data-confirm="<?= e($ask($b, $n, $twins[$bid] ?? null)) ?>" aria-label="Удалить бренд «<?= e($b['name']) ?>»<?= $n ? ' и его товары' : '' ?>">Удалить</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?= $pg->html() ?>
<p class="hint">Точки: Title · Description · описание на странице бренда (зелёная — заполнено, серая — по названию, красная — пусто).
  «Удалить» — бренд и все его товары (и скрытые): поставщики не загружают ни их, ни новые товары бренда; вернуть — вкладка «Удалённые».</p>
<?php endif; ?>
