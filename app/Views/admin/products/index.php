<?php
/**
 * Список товаров.
 * @var array $f @var array $rows @var int $total @var App\Core\Paginator $pg @var array $cats @var array $brands
 * @var array $sorts @var array $bulk @var string $query @var ?array $fvLabel
 * @var int $capped поиск упёрся в лимит (сколько показано) или 0
 * @var array $seoCells [id => ячейки Title/Description] (SeoAudit::listCells)
 */
use App\Controllers\Admin\BaseController;
use App\Services\AdminCatalog;
use App\Services\SeoAudit;

$catOptions = static function (array $cats, $selected): string {
    $h = '';
    foreach ($cats as $c) {
        $label = str_repeat('— ', (int) $c['depth']) . $c['name'] . ((int) $c['type'] === 1 ? ' (по условию)' : '') . (!(int) $c['status'] ? ' (скрыта)' : '');
        $h .= '<option value="' . (int) $c['id'] . '"' . ((string) $selected === (string) $c['id'] ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h;
};
$paths = AdminCatalog::paths($cats);
$hasFilter = $f['q'] !== '' || $f['category'] !== '' || $f['brand'] || $f['status'] !== '' || $f['stock'] !== '' || $f['sale'] || $f['nophoto'] || $f['nouk'] || $f['ff'] || $f['seo'] !== '';
?>
<form class="card ac-filters" method="get" action="/admin/products/" role="search">
  <div class="ac-frow">
    <label class="fld ac-q"><span>Поиск</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="id, название, артикул или адрес"></label>
    <label class="fld"><span>Категория</span>
      <select name="category"><option value="">Все категории</option><option value="none"<?= $f['category'] === 'none' ? ' selected' : '' ?>>Без категории</option><?= $catOptions($cats, $f['category']) ?></select></label>
    <label class="fld"><span>Бренд</span>
      <select name="brand"><option value="">Все бренды</option><option value="-1"<?= $f['brand'] === -1 ? ' selected' : '' ?>>Без бренда</option>
        <?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>"<?= $f['brand'] === (int) $b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></label>
  </div>
  <div class="ac-frow">
    <label class="fld"><span>Статус</span><select name="status"><option value="">Любой</option><option value="1"<?= $f['status'] === '1' ? ' selected' : '' ?>>На сайте</option><option value="0"<?= $f['status'] === '0' ? ' selected' : '' ?>>Скрытые</option></select></label>
    <label class="fld"><span>Наличие</span><select name="stock"><option value="">Любое</option><option value="1"<?= $f['stock'] === '1' ? ' selected' : '' ?>>В наличии</option><option value="0"<?= $f['stock'] === '0' ? ' selected' : '' ?>>Нет в наличии</option></select></label>
    <label class="fld" title="Свои title и description товара — как точки в колонках Title и Description"><span>SEO</span><select name="seo">
      <?php foreach (SeoAudit::LIST_FILTERS as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['seo'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="fld"><span>Сортировка</span><select name="sort"><?php foreach ($sorts as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['sort'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <div class="ac-checks">
      <label class="chk"><input type="checkbox" name="sale" value="1"<?= $f['sale'] ? ' checked' : '' ?>> Со скидкой</label>
      <label class="chk"><input type="checkbox" name="nophoto" value="1"<?= $f['nophoto'] ? ' checked' : '' ?>> Без фото</label>
      <label class="chk" title="Не заполнено украинское название — на /ua/ показывается русское"><input type="checkbox" name="nouk" value="1"<?= $f['nouk'] ? ' checked' : '' ?>> Без перевода на украинский</label>
      <label class="chk" title="Только товары, привязанные прямо к выбранной категории"><input type="checkbox" name="direct" value="1"<?= $f['direct'] ? ' checked' : '' ?>> Без подкатегорий</label>
    </div>
    <?php if ($f['ff'] && $f['fv']): ?><input type="hidden" name="ff" value="<?= (int) $f['ff'] ?>"><input type="hidden" name="fv" value="<?= (int) $f['fv'] ?>">
      <span class="chip on ac-mb"><?= e(($fvLabel['name'] ?? 'Характеристика') . ': ' . ($fvLabel['value'] ?? '№' . $f['fv'])) ?> <a href="<?= e(App\Core\Request::withQuery(['ff' => null, 'fv' => null, 'page' => null])) ?>" aria-label="Убрать фильтр по характеристике" style="color:#fff">×</a></span><?php endif; ?>
    <div class="ac-fbtns"><button class="btn btn-p" type="submit">Найти</button><?php if ($hasFilter): ?><a class="btn" href="/admin/products/">Сбросить</a><?php endif; ?></div>
  </div>
</form>

<form method="post" action="/admin/products/bulk/" id="bulk-form" class="card flush" data-total="<?= $total ?>">
  <?= BaseController::tokenField() ?>
  <input type="hidden" name="filter" value="<?= e($query) ?>">
  <div class="card-hd">
    <h2>Найдено: <?= number_format($total, 0, '', ' ') ?><?= $capped ? '+' : '' ?> <?= plural($total, 'товар', 'товара', 'товаров') ?></h2>
    <?php if ($capped): ?><span class="muted ac-small">по запросу больше <?= number_format($capped, 0, '', ' ') ?> совпадений — учтены первые <?= number_format($capped, 0, '', ' ') ?>, уточните запрос</span>
    <?php elseif ($pg->pages > 1): ?><span class="muted ac-small">страница <?= $pg->page ?> из <?= number_format($pg->pages, 0, '', ' ') ?></span><?php endif; ?>
  </div>
  <div class="bulk-bar" id="bulk-bar" hidden>
    <span class="ac-bulk-n">Выбрано: <b id="bulk-n">0</b></span>
    <?php if ($total > count($rows)): ?><label class="chk"><input type="checkbox" name="all" value="1" id="bulk-all"> все найденные (<?= number_format($total, 0, '', ' ') ?>)</label><?php endif; ?>
    <label class="sr-only" for="bulk-action">Действие</label>
    <select name="action" id="bulk-action"><option value="">Действие…</option><?php foreach ($bulk as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    <span class="ac-bulk-extra" data-for="addcat delcat" hidden><label class="sr-only" for="bulk-cat">Категория</label><select name="category_id" id="bulk-cat"><option value="">Категория…</option><?= $catOptions($cats, '') ?></select></span>
    <span class="ac-bulk-extra" data-for="price" hidden><label class="sr-only" for="bulk-pct">Процент</label><input type="text" inputmode="decimal" name="percent" id="bulk-pct" placeholder="+10 или -5" class="ac-pct"> %
      <label class="chk"><input type="checkbox" name="round" value="1" checked> до целых грн</label></span>
    <button class="btn btn-p btn-sm" type="submit">Применить</button>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-card"><h2>Товары не найдены</h2><p>Измените условия поиска<?= $hasFilter ? ' или сбросьте фильтры' : '' ?>.</p>
      <?php if ($hasFilter): ?><a class="btn" href="/admin/products/">Сбросить фильтры</a><?php else: ?><a class="btn btn-p" href="/admin/products/new/">Добавить товар</a><?php endif; ?></div>
  <?php else: ?>
  <div class="table-scroll">
    <table class="tbl ac-ptable">
      <thead><tr>
        <th class="tick"><input type="checkbox" id="check-all" aria-label="Выбрать все на странице"></th>
        <th>Фото</th><th>Товар</th><th>Категория · бренд</th><th class="num">Цена/пара</th><th class="num">Ящик</th><th class="num">Пар</th><th>Размеры</th><th>Наличие</th><th class="seo-col">Title</th><th class="seo-col">Description</th><th>Статус</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $id = (int) $r['id'];
        $thumb = AdminCatalog::thumb($id, $r['image_id'] ? (int) $r['image_id'] : null, $r['image_ext'], '96x96');
        $cat = $r['category_id'] ? ($cats[(int) $r['category_id']] ?? null) : null;
        $brand = $r['brand_id'] ? ($brands[(int) $r['brand_id']] ?? null) : null;
        $box = (float) $r['price'] * max(1, (int) $r['box_qty']); ?>
        <tr class="<?= (int) $r['status'] ? '' : 'is-draft' ?>">
          <td class="tick"><input type="checkbox" name="ids[]" value="<?= $id ?>" aria-label="Выбрать товар №<?= $id ?>"></td>
          <td><a href="/admin/products/<?= $id ?>/" aria-label="Открыть товар №<?= $id ?>"><?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" width="46" height="46" loading="lazy"><?php else: ?><span class="ac-nophoto">нет фото</span><?php endif; ?></a></td>
          <td class="ac-pname">
            <a href="/admin/products/<?= $id ?>/"><?= e($r['name']) ?></a>
            <small>№<?= $id ?><?= $r['sku'] !== '' ? ' · арт. ' . e($r['sku']) : '' ?><?php if ($r['badge']): ?> · <?= e(AdminCatalog::BADGES[$r['badge']] ?? $r['badge']) ?><?php endif; ?><?php if ((string) $r['name_uk'] !== ''): ?> · <span class="ac-ltag uk" title="<?= e($r['name_uk']) ?>">UA</span><?php endif; ?></small>
          </td>
          <td class="ac-small"><?= $cat ? '<span title="' . e($paths[(int) $cat['id']] ?? $cat['name']) . '">' . e($cat['name']) . '</span>' : '<span class="muted">без категории</span>' ?><?php if ($brand): ?><small><?= e($brand['name']) ?></small><?php endif; ?></td>
          <td class="num"><?= e(price_format($r['price'])) ?><?php if ((float) $r['compare_price'] > (float) $r['price']): ?><small><s><?= e(price_format($r['compare_price'])) ?></s></small><?php endif; ?></td>
          <td class="num"><?= e(price_format($box)) ?></td>
          <td class="num"><?= (int) $r['box_qty'] ?></td>
          <td class="nowrap"><?= e($r['size']) ?></td>
          <td class="nowrap"><?= (int) $r['in_stock'] ? '<span class="in-stock">в наличии</span>' : '<span class="out-stock">нет</span>' ?>
            <?php if ($r['stock'] !== null): ?><small>остаток: <?= (int) $r['stock'] ?></small><?php endif; ?></td>
          <?= $view->partial('admin/partials/seo-cells', ['seoCell' => $seoCells[$id] ?? null, 'seoEdit' => '/admin/products/' . $id . '/#h-seo']) ?>
          <td><?= (int) $r['status'] ? '<span class="pill ok">на сайте</span>' : '<span class="pill">скрыт</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</form>
<?= $pg->html() ?>
<?php if ($rows): ?><?= $view->partial('admin/partials/seo-legend', ['seoType' => 'product', 'seoFilter' => $f['seo']]) ?><?php endif; ?>
