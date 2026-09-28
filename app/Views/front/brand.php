<?php
/**
 * Страница бренда /brand/{имя}/: товары бренда с фильтрами, сортировкой и пагинацией,
 * слева — разделы каталога с товарами бренда (ссылки на категорию с фильтром по бренду).
 * @var App\Core\Seo $seo @var array $brand @var array $full @var App\Services\Listing $L @var array $products
 * @var array $groups @var bool $hasPrice @var array $priceRange @var array $cats [['name','url','count','root']]
 * @var bool $showText @var View $view
 */
$hasFilters = $hasPrice || $groups || $cats;
$text = $showText ? trim(content_html($full['description'] ?? '') . content_html($full['seo_description'] ?? '')) : '';
$summary = trim((string) ($full['summary'] ?? ''));
?>
<div class="wrap">
  <div class="page-head brand-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Бренды'), 'url' => '/brand/'], ['name' => (string) $brand['name']]]]) ?>
    <div class="bh">
      <?php if (!empty($brand['image'])): ?><span class="bh-logo"><img src="<?= e(media((string) $brand['image'])) ?>" alt="<?= e($brand['name']) ?>" width="120" height="60"></span><?php endif; ?>
      <div>
        <h1><?= e($seo->h1) ?></h1>
        <?php if ($summary !== '' && $showText): ?><p class="ph-note"><?= e($summary) ?></p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="<?= $hasFilters ? 'layout-2' : 'cat-one' ?> catalog" data-catalog>
    <?php if ($hasFilters): ?>
      <?= $view->partial('front/partials/filters', ['L' => $L, 'groups' => $groups, 'hasPrice' => $hasPrice, 'priceRange' => $priceRange,
          'extraLinks' => $cats, 'extraTitle' => t('Разделы каталога')]) ?>
    <?php endif; ?>
    <div class="cat-main">
      <?= $view->partial('front/partials/list-toolbar', ['L' => $L, 'sorting' => true, 'filterBtn' => $hasFilters]) ?>
      <?= $view->partial('front/partials/listing', [
          'L' => $L, 'products' => $products, 'grid' => $hasFilters ? 'grid g4' : 'grid', 'itemsOnly' => false,
          'emptyTitle' => t('Товаров этого бренда сейчас нет'),
          'emptyText' => t('Новые поступления появляются каждый день. Посмотрите другие бренды.'),
          'emptyLink' => ['/brand/', t('Все бренды')],
      ]) ?>
    </div>
  </div>

  <?php if ($text !== ''): ?>
    <section class="seo cat-text" aria-label="<?= e(t('О бренде')) ?>"><div class="prose"><?= $text ?></div></section>
  <?php endif; ?>
</div>
