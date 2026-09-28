<?php
/**
 * Блог: список статей /blog/ (?page=N).
 * Этот же шаблон рисует одну карточку анонса, если передан $teaserOnly (страница «Статьи», список блога).
 * @var array $posts анонсы (BlogController::teaser)  @var App\Core\Paginator $pg
 * @var ?array $teaserOnly  @var ?string $teaserTag h2|h3  @var View $view
 */
if (isset($teaserOnly)):
    $p = $teaserOnly;
    $href = '/blog/' . $p['url'] . '/';
    $ht = ($teaserTag ?? 'h2') === 'h3' ? 'h3' : 'h2';
?>
<article class="bl-card">
  <a class="bl-ph" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
    <span class="bl-noimg"><?= icon('doc', 'width:34px;height:34px') ?></span>
    <?php if ($p['image']): ?><img src="<?= e($p['image']) ?>" alt="" loading="lazy" width="400" height="225" onerror="this.remove()"><?php endif; ?>
  </a>
  <div class="bl-b">
    <time class="bl-date" datetime="<?= e(date('Y-m-d', strtotime($p['published_at']))) ?>"><?= icon('clock', 'width:15px;height:15px') ?><?= e($p['date']) ?></time>
    <<?= $ht ?> class="bl-t"><a href="<?= e($href) ?>"><?= e($p['title']) ?></a></<?= $ht ?>>
    <?php if ($p['excerpt'] !== ''): ?><p><?= e($p['excerpt']) ?></p><?php endif; ?>
    <a class="link bl-more" href="<?= e($href) ?>" aria-label="<?= e(t('Читать статью «{title}»', ['title' => $p['title']])) ?>"><?= e(t('Читать далее')) ?> →</a>
  </div>
</article>
<?php return; endif; ?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Блог')]]]) ?>
    <h1><?= e(t('Блог')) ?></h1>
    <p class="bl-lead"><?= e(t('Статьи и советы: как выбрать детскую обувь, уход, обзоры брендов')) ?></p>
  </div>

  <?php if ($posts): ?>
    <div class="bl-grid bl-list-page">
      <?php foreach ($posts as $p) echo $view->partial('front/blog', ['teaserOnly' => $p]); ?>
    </div>
    <?= $pg->html() ?>
  <?php else: ?>
    <div class="empty-state">
      <div class="ic"><?= icon('doc', 'width:30px;height:30px') ?></div>
      <h2><?= e(t('Статей пока нет')) ?></h2>
      <p><?= e(t('Загляните позже — мы готовим полезные материалы для покупателей.')) ?></p>
      <a class="btn btn-o" href="/"><?= e(t('На главную')) ?></a>
    </div>
  <?php endif; ?>
</div>
