<?php
/**
 * Инфо-страница (О компании, Доставка и оплата, Контакты, Статьи …) и HTML-карта сайта /sitemap/.
 * @var array  $page     ['name', 'h1', 'html'] — html уже подготовлен (PageController::prepareHtml)
 * @var array  $menu     [['url', 'name', 'on'], …] — меню инфо-страниц
 * @var ?array $contacts блок контактов, карта и форма (только «Контакты»)
 * @var array  $posts    анонсы статей блога (только «Статьи»)
 * @var ?array $map      разделы HTML-карты сайта (только /sitemap/)
 * @var View   $view
 */
use App\Services\Catalog;

// Дерево категорий для карты сайта (из кэша справочника, без запросов)
$tree = static function (array $cats) use (&$tree): string {
    if (!$cats) return '';
    $h = '<ul>';
    foreach ($cats as $c) {
        $h .= '<li><a href="' . e(Catalog::categoryUrl($c)) . '">' . e(nice_case((string) $c['name'])) . '</a>'
            . $tree(Catalog::children((int) $c['id'])) . '</li>';
    }
    return $h . '</ul>';
};
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => $page['name']]]]) ?>
    <h1><?= e($page['h1']) ?></h1>
  </div>

  <div class="layout-2 cp-layout">
    <aside class="cp-aside">
      <nav class="panel side-nav cp-nav" aria-label="<?= e(t('Информация о магазине')) ?>">
        <?php foreach ($menu as $m): ?>
          <a href="<?= e($m['url']) ?>"<?= $m['on'] ? ' class="on" aria-current="page"' : '' ?>><?= e($m['name']) ?></a>
        <?php endforeach; ?>
      </nav>
    </aside>

    <div class="cp-main">
      <?php if ($contacts): ?>
        <?= $view->partial('front/contacts', ['c' => $contacts]) ?>
      <?php endif; ?>

      <?php if ($map): ?>
        <div class="panel sm-map">
          <section class="sm-sec">
            <h2><?= e(t('Информация')) ?></h2>
            <ul>
              <?php foreach ($map['pages'] as $p): ?><li><a href="/<?= e($p['url']) ?>"><?= e($p['name']) ?></a></li><?php endforeach; ?>
              <li><a href="/brand/"><?= e(t('Бренды')) ?></a></li>
              <li><a href="/blog/"><?= e(t('Блог')) ?></a></li>
              <li><a href="/reviews/"><?= e(t('Отзывы о магазине')) ?></a></li>
            </ul>
          </section>
          <section class="sm-sec">
            <h2><?= e(t('Каталог')) ?></h2>
            <div class="sm-tree"><?= $tree($map['roots']) ?></div>
          </section>
          <?php if ($map['posts']): ?>
          <section class="sm-sec">
            <h2><?= e(t('Статьи')) ?></h2>
            <ul><?php foreach ($map['posts'] as $p): ?><li><a href="/blog/<?= e($p['url']) ?>/"><?= e($p['title']) ?></a></li><?php endforeach; ?></ul>
          </section>
          <?php endif; ?>
          <?php if ($map['brands']): ?>
          <section class="sm-sec">
            <h2><?= e(t('Бренды')) ?></h2>
            <ul class="sm-cols"><?php foreach ($map['brands'] as $b): ?><li><a href="<?= e(Catalog::brandUrl($b)) ?>"><?= e($b['name']) ?></a></li><?php endforeach; ?></ul>
          </section>
          <?php endif; ?>
        </div>
      <?php elseif (trim($page['html']) !== ''): ?>
        <article class="panel prose cp-content"><?= $page['html'] ?></article>
      <?php endif; ?>

      <?php if ($posts): ?>
        <section class="cp-posts" aria-labelledby="cp-posts-h">
          <div class="cp-posts-head"><h2 id="cp-posts-h"><?= e(t('Статьи блога')) ?></h2><a class="link" href="/blog/"><?= e(t('Все записи')) ?> →</a></div>
          <div class="bl-grid">
            <?php foreach ($posts as $p) echo $view->partial('front/blog', ['teaserOnly' => $p, 'teaserTag' => 'h3']); ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</div>
