<?php
/**
 * Статья блога /blog/{url}/.
 * @var array $post  анонс (BlogController::teaser) + html — подготовленный текст статьи
 * @var array $others другие статьи  @var ?array $cat категория для призыва в каталог  @var View $view
 */
use App\Core\Settings;
use App\Services\Catalog;
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Блог'), 'url' => '/blog/'], ['name' => $post['title']]]]) ?>
    <h1><?= e($post['title']) ?></h1>
    <div class="bl-meta"><time class="bl-date" datetime="<?= e(date('c', strtotime($post['published_at']))) ?>"><?= icon('clock', 'width:15px;height:15px') ?><?= e($post['date']) ?></time></div>
  </div>

  <div class="layout-2r bl-layout">
    <article class="panel prose bl-text"><?= $post['html'] ?></article>

    <aside class="bl-aside">
      <?php if ($others): ?>
      <nav class="panel bl-others" aria-labelledby="bl-others-h">
        <h2 id="bl-others-h"><?= e(t('Другие статьи')) ?></h2>
        <ul>
          <?php foreach ($others as $o): ?>
            <li><a href="/blog/<?= e($o['url']) ?>/"><?= e($o['title']) ?></a><small><?= e($o['date']) ?></small></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-g btn-sm btn-block" href="/blog/"><?= e(t('Все статьи')) ?></a>
      </nav>
      <?php endif; ?>
      <?php if ($cat): ?>
      <div class="panel bl-cta">
        <h2><?= e(t('{name} оптом', ['name' => nice_case((string) $cat['name'])])) ?></h2>
        <p><?= e(t('Ящиками от производителей. Доставка по всей Украине, от {n} ящиков — бесплатно.', ['n' => (int) Settings::get('free_shipping_boxes', 20)])) ?></p>
        <a class="btn btn-o btn-block" href="<?= e(Catalog::categoryUrl($cat)) ?>"><?= e(t('Перейти в каталог')) ?> <?= icon('arrow', 'width:18px') ?></a>
      </div>
      <?php endif; ?>
    </aside>
  </div>
</div>
