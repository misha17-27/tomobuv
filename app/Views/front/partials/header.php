<?php
/**
 * Шапка сайта. Не содержит персональных данных (страница кэшируется для всех):
 * счётчики корзины/избранного/сравнения, валюта и «Кабинет/Войти» заполняются JS из cookie.
 * @var bool $showAlpha
 */
use App\Core\Lang;
use App\Core\Settings;
use App\Services\Catalog;
use App\Services\Content;

$phones = Settings::json('phones', ['+38 (093) 275-3070']);
$tel = static fn(string $p) => 'tel:+' . preg_replace('/\D/', '', $p);
$roots = Catalog::roots();
$menuPages = Content::menuPages();
?>
<div class="topbar"><div class="wrap">
  <nav aria-label="<?= e(t('Информация')) ?>"><?php foreach ($menuPages as $p): ?><a href="/<?= e($p['url']) ?>"><?= e($p['name']) ?></a><?php endforeach; ?></nav>
  <a class="cb" href="#" data-open="callback"><?= icon('phone', 'width:16px;height:16px') ?><?= e(t('Обратный звонок')) ?></a>
  <a href="<?= e($tel($phones[0])) ?>"><?= e($phones[0]) ?></a>
  <div class="langs" role="group" aria-label="<?= e(t('Язык')) ?>"><?php foreach (Lang::NAMES as $lg => $nm): ?><a href="<?= e(Lang::alternate($lg)) ?>" hreflang="<?= $lg ?>" class="<?= Lang::current() === $lg ? 'on' : '' ?>"<?= Lang::current() === $lg ? ' aria-current="true"' : '' ?>><?= $nm ?></a><?php endforeach; ?></div>
  <div class="cur" id="ui-cur" role="group" aria-label="<?= e(t('Валюта')) ?>"><button data-cur="UAH" class="on">UAH</button><button data-cur="USD">USD</button><button data-cur="EUR">EUR</button></div>
</div></div>
<header class="main">
  <div class="wrap">
    <button class="burger" data-open="ui-mnav" aria-label="<?= e(t('Меню')) ?>"><?= icon('menu') ?></button>
    <a class="logo" href="/"><img src="/assets/img/logo.png" alt="<?= e(t('Tomobuv — оптовый интернет-магазин обуви')) ?>" width="170" height="44"></a>
    <button class="catbtn" id="ui-catbtn" aria-expanded="false" aria-controls="ui-mega"><?= icon('menu') ?><span><?= e(t('Каталог')) ?></span></button>
    <form class="search" id="ui-search" role="search" action="/search/" method="get">
      <input type="search" name="query" placeholder="<?= e(t('Поиск: артикул, бренд, размер…')) ?>" autocomplete="off" aria-label="<?= e(t('Поиск товаров')) ?>" value="<?= e(is_scalar($_GET['query'] ?? null) ? (string) $_GET['query'] : '') ?>">
      <button class="go" aria-label="<?= e(t('Найти')) ?>"><?= icon('search') ?></button>
      <div class="suggest"></div>
    </form>
    <div class="hicons">
      <a class="hicon" href="/my/" data-auth-link><?= icon('user') ?><span class="l" data-auth-label><?= e(t('Войти')) ?></span></a>
      <a class="hicon cmp" href="/compare/"><?= icon('cmp') ?><span class="l"><?= e(t('Сравнение')) ?></span><span class="n" data-count="cmp"></span></a>
      <a class="hicon" href="/search/?_balance_type=favorites"><?= icon('heart') ?><span class="l"><?= e(t('Избранное')) ?></span><span class="n" data-count="fav"></span></a>
    </div>
    <button class="cartbtn" data-open="ui-cart" aria-label="<?= e(t('Корзина')) ?>"><?= icon('cart') ?><span class="n" data-count="cart"></span><span class="t"><b data-cart-total>0 грн.</b><small><?= e(t('Корзина')) ?></small></span></button>
  </div>
  <div class="mega" id="ui-mega"><div class="wrap">
    <div class="l">
      <?php foreach ($roots as $i => $c): ?>
        <a href="<?= e(Catalog::categoryUrl($c)) ?>" data-panel="<?= $i ?>" class="<?= (int) $c['type'] === 1 ? 'hot' : '' ?><?= $i === 2 ? ' on' : '' ?>"><?= e($c['name']) ?><?= $c['children'] ? icon('chev', 'width:16px') : '' ?></a>
      <?php endforeach; ?>
    </div>
    <?php foreach ($roots as $i => $c): ?>
      <div class="r<?= $i === 2 ? '' : ' hidden' ?>" data-panel="<?= $i ?>">
        <div class="g"><a href="<?= e(Catalog::categoryUrl($c)) ?>" style="color:var(--blue)"><?= e(t('Все товары раздела')) ?> →</a></div>
        <?php foreach (Catalog::children((int) $c['id']) as $s): ?>
          <div class="g"><a href="<?= e(Catalog::categoryUrl($s)) ?>"><?= e($s['name']) ?></a>
            <?php $sizes = Catalog::children((int) $s['id']); if ($sizes): ?><div class="chips"><?php foreach ($sizes as $z): ?><a class="chip" href="<?= e(Catalog::categoryUrl($z)) ?>"><?= e($z['name']) ?></a><?php endforeach; ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div></div>
</header>
<nav class="navrow" aria-label="<?= e(t('Разделы каталога')) ?>"><div class="wrap">
  <?php foreach ($roots as $c): ?><a class="<?= (int) $c['type'] === 1 && $c['url'] === 'aktsiya' ? 'hot' : '' ?>" href="<?= e(Catalog::categoryUrl($c)) ?>"><?= e(nice_case($c['name'])) ?></a><?php endforeach; ?>
  <?php foreach (array_slice($menuPages, 1, 2) as $p): ?><a href="/<?= e($p['url']) ?>"><?= e($p['name']) ?></a><?php endforeach; ?>
</div></nav>
<?php if (!empty($showAlpha)): ?>
<div class="alpha"><div class="wrap"><span><?= e(t('Бренды')) ?>:</span><?php foreach (Content::brandLetters() as $l): ?><a href="/brand/?letter=<?= e(rawurlencode($l)) ?>"><?= e($l) ?></a><?php endforeach; ?><a class="all" href="/brand/"><?= e(t('Все бренды')) ?></a></div></div>
<?php endif; ?>
