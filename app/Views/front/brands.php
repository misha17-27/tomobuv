<?php
/**
 * Все бренды: буквы (?letter=), популярные бренды с логотипами, список по алфавиту, быстрый поиск по названию.
 * @var App\Core\Seo $seo @var array $groups [буква => [бренды]] @var array $letters @var string $letter
 * @var array $top популярные бренды (только без фильтра по букве) @var int $total @var View $view
 */
use App\Services\Catalog;

$crumbs = $letter !== '' ? [['name' => t('Бренды'), 'url' => '/brand/'], ['name' => $letter]] : [['name' => t('Бренды')]];
$shown = array_sum(array_map('count', $groups));
$cnt = static fn(int $n): string => number_format($n, 0, '.', ' ') . ' ' . plural($n, t('товар'), t('товара'), t('товаров'));
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => $crumbs]) ?>
    <h1><?= e($seo->h1) ?></h1>
    <?php $nb = $letter !== '' ? $shown : $total; ?>
    <p class="ph-note"><?= e(number_format($nb, 0, '.', ' ') . ' ' . plural($nb, t('бренд'), t('бренда'), t('брендов')) . ' ' . t('детской, подростковой, женской и мужской обуви оптом')) ?></p>
  </div>

  <div class="br-tools">
    <nav class="br-letters" aria-label="<?= e(t('Бренды по первой букве')) ?>">
      <a href="/brand/" class="<?= $letter === '' ? 'on' : '' ?>"<?= $letter === '' ? ' aria-current="page"' : '' ?>><?= e(t('Все')) ?></a>
      <?php foreach ($letters as $l): ?><a href="/brand/?letter=<?= e(rawurlencode($l)) ?>" class="<?= $l === $letter ? 'on' : '' ?>"<?= $l === $letter ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
    </nav>
    <label class="br-find"><span class="visually-hidden"><?= e(t('Найти бренд')) ?></span><?= icon('search', 'width:18px;height:18px') ?><input class="input" type="search" placeholder="<?= e(t('Найти бренд')) ?>" autocomplete="off" data-brand-find></label>
  </div>

  <?php if ($top): ?>
    <section class="br-top" data-brand-block>
      <h2 class="br-h"><?= e(t('Популярные бренды')) ?></h2>
      <div class="br-tiles">
        <?php foreach ($top as $b): ?>
          <a class="br-tile" href="<?= e(Catalog::brandUrl($b)) ?>" data-name="<?= e(mb_strtolower((string) $b['name'])) ?>">
            <span class="br-logo"><?php if (!empty($b['image'])): ?><img src="<?= e(media((string) $b['image'])) ?>" alt="" loading="lazy" width="120" height="60"><?php else: ?><span><?= e(mb_strtoupper(mb_substr(trim((string) $b['name']), 0, 2))) ?></span><?php endif; ?></span>
            <b><?= e($b['name']) ?></b><small><?= e($cnt((int) $b['product_count'])) ?></small>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php foreach ($groups as $l => $list): ?>
    <section class="br-group" id="l-<?= e(rawurlencode((string) $l)) ?>" data-brand-block>
      <h2 class="br-h"><?= e($l) ?></h2>
      <ul class="br-list">
        <?php foreach ($list as $b): ?>
          <li data-name="<?= e(mb_strtolower((string) $b['name'])) ?>"><a href="<?= e(Catalog::brandUrl($b)) ?>"><span><?= e($b['name']) ?></span><small><?= (int) $b['product_count'] ?></small></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>

  <div class="empty-state hidden" data-brand-empty>
    <div class="ic"><?= icon('search', 'width:30px;height:30px') ?></div>
    <h2><?= e(t('Бренд не найден')) ?></h2>
    <p><?= e(t('Проверьте написание или поищите товары по названию.')) ?></p>
  </div>
</div>
