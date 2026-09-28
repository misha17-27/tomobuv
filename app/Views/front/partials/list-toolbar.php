<?php
/**
 * Тулбар списка товаров: количество, сортировка, вид (плитка / таблица для опта),
 * на мобильном — кнопка «Фильтры» (открывает панель #cat-filters).
 * @var App\Services\Listing $L
 * @var bool $sorting         показывать сортировку
 * @var bool $withDefaultSort пункт «По умолчанию» (ручная сортировка категории)
 * @var bool $filterBtn       есть панель фильтров
 */
$sorting ??= true;
$withDefaultSort ??= false;
$filterBtn ??= false;
$nActive = array_sum(array_map('count', $L->features)) + (($L->priceMin !== null || $L->priceMax !== null) ? 1 : 0);
$n = $L->total;
?>
<div class="toolbar ltb">
  <div class="ltb-l">
    <?php if ($filterBtn): ?>
      <button type="button" class="btn btn-g btn-sm fbtn" data-open="cat-filters" aria-controls="cat-filters"><?= icon('filter', 'width:18px;height:18px') ?><?= e(t('Фильтры')) ?><?php if ($nActive): ?> <b class="fcnt"><?= $nActive ?></b><?php endif; ?></button>
    <?php endif; ?>
    <span class="count"><?= $L->hasFilters() ? e(t('Найдено')) . ': ' : '' ?><b><?= e(number_format($n, 0, '.', ' ')) ?></b> <?= e(plural($n, t('товар'), t('товара'), t('товаров'))) ?></span>
  </div>
  <?php if ($n > 0): ?>
  <div class="ltb-r">
    <?php if ($sorting && $n > 1): ?>
      <label class="visually-hidden" for="ltb-sort"><?= e(t('Сортировка')) ?></label>
      <select class="select" id="ltb-sort" data-nav>
        <?php foreach ($L->sortOptions($withDefaultSort) as $o): ?>
          <option value="<?= e($o['url']) ?>"<?= $o['on'] ? ' selected' : '' ?>><?= e($o['label']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <div class="viewtg" role="group" aria-label="<?= e(t('Вид списка')) ?>">
      <a href="<?= e($L->url(['view' => 'grid'])) ?>" class="<?= $L->view === 'grid' ? 'on' : '' ?>" rel="nofollow" aria-label="<?= e(t('Плиткой')) ?>" title="<?= e(t('Плиткой')) ?>"<?= $L->view === 'grid' ? ' aria-current="true"' : '' ?>><?= icon('grid', 'width:20px;height:20px') ?></a>
      <a href="<?= e($L->url(['view' => 'table'])) ?>" class="<?= $L->view === 'table' ? 'on' : '' ?>" rel="nofollow" aria-label="<?= e(t('Таблицей — удобно для опта')) ?>" title="<?= e(t('Таблицей — удобно для опта')) ?>"<?= $L->view === 'table' ? ' aria-current="true"' : '' ?>><?= icon('list', 'width:20px;height:20px') ?></a>
    </div>
  </div>
  <?php endif; ?>
</div>
