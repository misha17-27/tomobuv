<?php
/**
 * Главная (дизайн 1).
 * @var array $slides @var array $wide @var array $cats @var array $new @var array $promo
 * @var ?array $women @var array $womenProducts @var array $brands @var array $posts @var View $view
 */
use App\Core\Settings;
use App\Services\Catalog;

$free = (int) Settings::get('free_shipping_boxes', 20);
$since = (int) Settings::get('since_year', 2011);
?>
<section class="hero"><div class="wrap">
  <div class="slider" id="slider" aria-roledescription="carousel">
    <?php foreach ($slides as $i => $s): ?>
      <div class="slide<?= $i ? '' : ' on' ?>" aria-hidden="<?= $i ? 'true' : 'false' ?>">
        <div class="txt">
          <span class="tag"><?= e(t($i === 0 ? 'Новый сезон' : ($i === 1 ? 'Акция' : 'Украинское'))) ?></span>
          <div class="h"><?= e($s['title']) ?></div>
          <?php if ($s['text']): ?><p><?= e($s['text']) ?></p><?php endif; ?>
          <a class="btn btn-o" href="<?= e($s['link']) ?>"<?= $i ? ' tabindex="-1"' : '' ?>><?= e($s['button'] ?: t('Смотреть')) ?> <?= icon('arrow', 'width:18px') ?></a>
        </div>
        <?php /* на телефоне картинка слайда скрыта (.slide .pic{display:none}): пустышка вместо неё — 140 КБ не отнимают канал у первого экрана */ ?>
        <div class="pic"><picture><source media="(max-width:680px)" srcset="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="><img src="<?= e(media($s['image'])) ?>" alt="" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?> width="762" height="350"></picture></div>
      </div>
    <?php endforeach; ?>
    <div class="dots"><?php foreach ($slides as $i => $s): ?><button class="<?= $i ? '' : 'on' ?>" data-i="<?= $i ?>" aria-label="Слайд <?= $i + 1 ?>"></button><?php endforeach; ?></div>
  </div>
  <div class="side">
    <div class="card c1">
      <div><div class="big">0 ₴</div><h3><?= e(t('Доставка от {n} ящиков бесплатно', ['n' => $free])) ?></h3></div>
      <a href="/dostavka-i-oplata/" class="more" style="color:var(--orange-d);font-weight:700"><?= e(t('Условия доставки')) ?> →</a>
      <?php if (!empty($promo[0])): ?><img src="<?= e($promo[0]['img']) ?>" alt="" width="170" height="170"><?php endif; ?>
    </div>
    <div class="card c2">
      <h3><?= e(t('Опт обуви в Одессе с {year} года', ['year' => $since])) ?></h3>
      <div class="stats"><div><b><?= date('Y') - $since ?>+</b><small><?= e(t('лет на рынке')) ?></small></div><div><b><?= count(array_filter(Catalog::brands(), static fn($b) => $b['product_count'] > 0)) ?></b><small><?= e(t('брендов')) ?></small></div><div><b><?= e(t('ящиками')) ?></b><small><?= e(t('от 6 пар')) ?></small></div></div>
    </div>
  </div>
</div></section>

<section class="features"><div class="wrap">
  <a class="feat" href="/dostavka-i-oplata/"><span class="ic"><?= icon('card') ?></span><span><b><?= e(t('Способы оплаты')) ?></b><p><?= e(t('На карту, наложенным платежом или наличными')) ?></p></span></a>
  <a class="feat" href="/dostavka-i-oplata/"><span class="ic"><?= icon('truck') ?></span><span><b><?= e(t('Доставка по всей Украине')) ?></b><p><?= e(t('Всеми почтами. От {n} ящиков — бесплатно!', ['n' => $free])) ?></p></span></a>
  <a class="feat" href="/reviews/"><span class="ic"><?= icon('star') ?></span><span><b><?= e(t('Отзывы')) ?></b><p><?= e(t('Отзывы наших заказчиков о работе магазина')) ?></p></span></a>
  <a class="feat" href="/usloviya-sotrudnichestva/"><span class="ic"><?= icon('box') ?></span><span><b><?= e(t('Продажа ящиками')) ?></b><p><?= e(t('Цена за пару и за ящик — видно сразу в карточке')) ?></p></span></a>
</div></section>

<?php if ($cats): ?>
<section class="sec"><div class="wrap">
  <div class="sh"><h2><?= e(t('Популярные категории')) ?></h2></div>
  <div class="cats">
    <?php foreach ($cats as $c): $kids = Catalog::children((int) $c['id']); ?>
      <div class="cat"><a class="ph" href="<?= e(Catalog::categoryUrl($c)) ?>"><img src="<?= e(media($c['image'])) ?>" alt="<?= e($c['name']) ?>" loading="lazy" width="300" height="225"></a>
        <div class="b"><h3><a href="<?= e(Catalog::categoryUrl($c)) ?>"><?= e(nice_case($c['name'])) ?></a></h3>
          <div class="chips"><?php foreach (array_slice($kids, 0, 5) as $s): ?><a class="chip" href="<?= e(Catalog::categoryUrl($s)) ?>"><?= e(nice_case($s['name'])) ?></a><?php endforeach; ?><?php if (count($kids) > 5): ?><a class="chip" href="<?= e(Catalog::categoryUrl($c)) ?>"><?= e(t('ещё')) ?> <?= count($kids) - 5 ?></a><?php endif; ?></div>
        </div></div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<section class="sec"><div class="wrap">
  <div class="sh"><h2><?= e(t('Товары')) ?></h2><div class="tabs" role="tablist"><button class="on" role="tab" data-tab="t-new" aria-selected="true"><?= e(t('Новинки')) ?></button><button role="tab" data-tab="t-promo" aria-selected="false"><?= e(t('Промо')) ?></button></div></div>
  <div class="grid" id="t-new"><?php foreach ($new as $p) echo $view->partial('front/partials/card', ['p' => $p]); ?></div>
  <div class="grid hidden" id="t-promo"><?php foreach ($promo as $p) echo $view->partial('front/partials/card', ['p' => $p]); ?></div>
</div></section>

<?php if ($women && $womenProducts): $wk = Catalog::children((int) $women['id']); ?>
<section class="sec"><div class="wrap">
  <div class="sh"><h2><?= e(nice_case($women['name'])) ?></h2><a class="more" href="<?= e(Catalog::categoryUrl($women)) ?>"><?= e(t('Все товары')) ?> <?= icon('arrow', 'width:18px') ?></a></div>
  <div class="split">
    <div class="promo-col"><div><h3><?= e(nice_case($women['name'])) ?> <?= e(t('оптом')) ?></h3><div class="subl"><?php foreach (array_slice($wk, 0, 10) as $s): ?><a href="<?= e(Catalog::categoryUrl($s)) ?>"><?= e(nice_case($s['name'])) ?></a><?php endforeach; ?></div></div>
      <a class="btn btn-o" href="<?= e(Catalog::categoryUrl($women)) ?>"><?= e(t('Смотреть все')) ?> <?= icon('arrow', 'width:18px') ?></a></div>
    <div class="grid"><?php foreach ($womenProducts as $p) echo $view->partial('front/partials/card', ['p' => $p]); ?></div>
  </div>
</div></section>
<?php endif; ?>

<?php if ($wide): ?>
<section class="sec"><div class="wrap banners">
  <?php foreach ($wide as $b): ?><a class="bnr" href="<?= e($b['link']) ?>"><img src="<?= e(media($b['image'])) ?>" alt="" loading="lazy"><div><h3><?= e($b['title']) ?></h3><span class="btn btn-w"><?= e($b['button'] ?: t('Перейти')) ?> <?= icon('arrow', 'width:18px') ?></span></div></a><?php endforeach; ?>
</div></section>
<?php endif; ?>

<?php if ($brands): ?>
<section class="sec"><div class="wrap">
  <div class="sh"><h2><?= e(t('Популярные бренды')) ?></h2><a class="more" href="/brand/"><?= e(t('Все бренды')) ?> <?= icon('arrow', 'width:18px') ?></a></div>
  <div class="brands"><?php foreach ($brands as $b): ?><a href="<?= e(Catalog::brandUrl($b)) ?>"><?= e($b['name']) ?></a><?php endforeach; ?></div>
</div></section>
<?php endif; ?>

<section class="sec"><div class="wrap">
  <div class="sh"><h2><?= e(t('Статьи')) ?></h2><a class="more" href="/blog/"><?= e(t('Все записи')) ?> <?= icon('arrow', 'width:18px') ?></a></div>
  <div class="two">
    <?php foreach ($posts as $a): ?>
      <article class="art"><small>Tomobuv · <?= e(t('Статья')) ?></small><h3><a href="/blog/<?= e($a['url']) ?>/"><?= e($a['title']) ?></a></h3><p><?= e(str_limit($a['text_before_cut'] ?: $a['text'], 200)) ?></p><a class="more" href="/blog/<?= e($a['url']) ?>/"><?= e(t('Читать далее')) ?> →</a></article>
    <?php endforeach; ?>
    <div class="sub"><h3><?= e(t('Горячие скидки только для своих')) ?></h3><p><?= e(t('Подписчики первыми получают информацию о закрытых скидках и распродажах')) ?></p>
      <form data-request="subscribe" novalidate><input class="input" type="email" name="email" required placeholder="<?= e(t('Ваш e-mail')) ?>" autocomplete="email"><input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off"><button class="btn btn-o" style="justify-content:center"><?= e(t('Получать рассылку')) ?></button></form></div>
  </div>
</div></section>

<section class="sec"><div class="wrap"><div class="seo">
  <h1><?= e(t('Оптовый интернет-магазин обуви «Том Обувь»')) ?></h1>
  <p><?= e(t('Продаём детскую, подростковую, мужскую и женскую обувь оптом ящиками от производителей. Склад — Одесса, Промрынок 7 км. Отправляем по всей Украине всеми почтовыми службами, от {n} ящиков — доставка бесплатно. Работаем с {year} года: большой ассортимент, отличное качество, приятные цены.', ['n' => $free, 'year' => $since])) ?></p>
</div></div></section>
