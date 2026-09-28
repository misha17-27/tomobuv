<?php
/**
 * Страница категории.
 * @var App\Core\Seo $seo @var array $cat @var array $full @var array $crumbs
 * @var App\Services\Listing $L @var array $products @var array $groups @var bool $hasPrice @var array $priceRange
 * @var array $subcats ['title', 'items' => [['name','url','count','on']]]
 * @var string $grid @var bool $sorting @var bool $withDefaultSort @var bool $showText @var View $view
 */
$hasFilters = $hasPrice || $groups;
// Описание и SEO-текст — под списком, как на старом сайте; только на первой «чистой» странице
$text = $showText ? trim(content_html($full['description'] ?? '') . content_html($full['seo_description'] ?? '')) : '';
$parent = $cat['parent_id'] ? App\Services\Catalog::category((int) $cat['parent_id']) : null;
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => $crumbs]) ?>
    <h1><?= e($seo->h1) ?></h1>
  </div>

  <?php if ($subcats['items']): ?>
    <nav class="subcats cat-subs" aria-label="<?= e($subcats['title'] !== '' ? t('Размерные ряды') : t('Подкатегории')) ?>">
      <?php if ($subcats['title'] !== ''): ?><span class="subs-t"><?= e($subcats['title']) ?></span><?php endif; ?>
      <?php foreach ($subcats['items'] as $s): ?>
        <a href="<?= e($s['url']) ?>" class="<?= $s['on'] ? 'on' : '' ?>"<?= $s['on'] ? ' aria-current="page"' : '' ?>><?= e(nice_case((string) $s['name'])) ?><small><?= e(number_format($s['count'], 0, '.', ' ')) ?></small></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div class="<?= $hasFilters ? 'layout-2' : 'cat-one' ?> catalog" data-catalog>
    <?php if ($hasFilters): ?>
      <?= $view->partial('front/partials/filters', ['L' => $L, 'groups' => $groups, 'hasPrice' => $hasPrice, 'priceRange' => $priceRange]) ?>
    <?php endif; ?>
    <div class="cat-main">
      <?= $view->partial('front/partials/list-toolbar', ['L' => $L, 'sorting' => $sorting, 'withDefaultSort' => $withDefaultSort, 'filterBtn' => $hasFilters]) ?>
      <?= $view->partial('front/partials/listing', [
          'L' => $L, 'products' => $products, 'grid' => $grid, 'itemsOnly' => false,
          'emptyTitle' => t('В этой категории нет ни одного товара'),
          'emptyText' => t('Новые поступления появляются каждый день. Посмотрите соседние разделы каталога.'),
          'emptyLink' => $parent ? [App\Services\Catalog::categoryUrl($parent), t('Перейти в «{name}»', ['name' => nice_case((string) $parent['name'])])] : ['/', t('На главную')],
      ]) ?>
    </div>
  </div>

  <?php if ($text !== ''): ?>
    <section class="seo cat-text" aria-label="<?= e(t('Описание категории')) ?>"><div class="prose"><?= $text ?></div></section>
  <?php endif; ?>
</div>
