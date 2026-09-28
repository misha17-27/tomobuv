<?php
/**
 * Результаты поиска, избранное и просмотренные товары (/search/, /search/?_balance_type=favorites|viewed).
 * @var App\Core\Seo $seo @var App\Services\Listing $L @var array $products
 * @var string $query @var string $mode search|favorites|viewed @var View $view
 */
$isSearch = $mode === 'search';
$n = $L->total;
if ($isSearch) {
    $empty = $query !== ''
        ? [t('Ничего не найдено'), t('Попробуйте изменить запрос: укажите только артикул, бренд или название модели.'), 'search']
        : [t('Введите запрос'), t('Ищите по артикулу, названию модели или бренду.'), 'search'];
} elseif ($mode === 'favorites') {
    $empty = [t('В избранном пока пусто'), t('Нажимайте на сердечко в карточке товара — понравившиеся модели сохранятся здесь.'), 'heart'];
} else {
    $empty = [t('Вы ещё не смотрели товары'), t('Здесь появятся модели, которые вы открывали на сайте.'), 'eye'];
}
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => $isSearch ? t('Поиск') : $seo->h1]]]) ?>
    <h1><?= e($seo->h1) ?></h1>
  </div>

  <?php if ($isSearch && ($query === '' || $n === 0)): ?>
    <form class="srch-form" action="/search/" method="get" role="search">
      <label class="visually-hidden" for="srch-q"><?= e(t('Поиск товаров')) ?></label>
      <input class="input" id="srch-q" type="search" name="query" value="<?= e($query) ?>" placeholder="<?= e(t('Артикул, название или бренд')) ?>" autocomplete="off" maxlength="100">
      <button class="btn btn-b" type="submit"><?= icon('search', 'width:18px;height:18px') ?><?= e(t('Найти')) ?></button>
    </form>
  <?php elseif (!$isSearch): ?>
    <div class="bal-bar">
      <nav class="tabline bal-tabs" aria-label="<?= e(t('Мои списки')) ?>">
        <a href="/search/?_balance_type=favorites" class="<?= $mode === 'favorites' ? 'on' : '' ?>"<?= $mode === 'favorites' ? ' aria-current="page"' : '' ?>><?= icon('heart', 'width:18px;height:18px') ?><?= e(t('Избранное')) ?></a>
        <a href="/search/?_balance_type=viewed" class="<?= $mode === 'viewed' ? 'on' : '' ?>"<?= $mode === 'viewed' ? ' aria-current="page"' : '' ?>><?= icon('eye', 'width:18px;height:18px') ?><?= e(t('Просмотренные')) ?></a>
        <a href="/compare/"><?= icon('cmp', 'width:18px;height:18px') ?><?= e(t('Сравнение')) ?></a>
      </nav>
      <?php if ($products): ?>
        <button type="button" class="btn btn-g btn-sm" data-clear="<?= $mode === 'favorites' ? 'fav' : 'viewed' ?>"><?= icon('trash', 'width:16px;height:16px') ?><?= e(t('Очистить')) ?></button>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="cat-one catalog" data-catalog data-balance="<?= $isSearch ? '' : e($mode === 'favorites' ? 'fav' : 'viewed') ?>">
    <div class="cat-main">
      <?php if ($n > 0): ?>
        <?= $view->partial('front/partials/list-toolbar', ['L' => $L, 'sorting' => $isSearch, 'filterBtn' => false]) ?>
      <?php endif; ?>
      <?= $view->partial('front/partials/listing', [
          'L' => $L, 'products' => $products, 'grid' => 'grid', 'itemsOnly' => false,
          'emptyTitle' => $empty[0], 'emptyText' => $empty[1], 'emptyIcon' => $empty[2],
          'emptyLink' => ['/category/dyetskaya-obuv/', t('Перейти в каталог')],
      ]) ?>
    </div>
  </div>
</div>
