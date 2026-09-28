<?php
use App\Core\Lang;
use App\Core\Settings;
use App\Services\Catalog;
use App\Services\Content;

$phones = Settings::json('phones', []);
?>
<div class="ov" id="ui-ov"></div>
<aside class="drawer" id="ui-cart" aria-label="<?= e(t('Корзина')) ?>" aria-hidden="true" inert>
  <div class="hd"><h3><?= e(t('Корзина')) ?></h3><button data-close aria-label="<?= e(t('Закрыть')) ?>"><?= icon('x') ?></button></div>
  <div class="bd"><div class="empty"><?= e(t('Загрузка…')) ?></div></div>
  <div class="ft">
    <div data-ship></div>
    <div class="tot"><span><?= e(t('Итого')) ?>:</span><span data-cart-total>0 грн.</span></div>
    <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:8px"><a class="btn btn-g btn-block" href="/cart/"><?= e(t('Корзина')) ?></a><a class="btn btn-o btn-block" href="/cart/#order"><?= e(t('Оформить заказ')) ?></a></div>
  </div>
</aside>
<aside class="drawer left" id="ui-mnav" aria-label="<?= e(t('Меню')) ?>" aria-hidden="true" inert>
  <div class="hd"><h3><?= e(t('Меню')) ?></h3><button data-close aria-label="<?= e(t('Закрыть')) ?>"><?= icon('x') ?></button></div>
  <div class="bd mnav">
    <?php foreach (Catalog::roots() as $c): $kids = Catalog::children((int) $c['id']); ?>
      <?php if ($kids): ?>
        <details><summary><?= e($c['name']) ?><?= icon('chev', 'width:16px') ?></summary>
          <a href="<?= e(Catalog::categoryUrl($c)) ?>"><?= e(t('Все товары')) ?></a>
          <?php foreach ($kids as $s): ?><a href="<?= e(Catalog::categoryUrl($s)) ?>"><?= e($s['name']) ?></a><?php endforeach; ?>
        </details>
      <?php else: ?>
        <a href="<?= e(Catalog::categoryUrl($c)) ?>"><?= e($c['name']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php foreach (Content::menuPages() as $p): ?><a href="/<?= e($p['url']) ?>"><?= e($p['name']) ?></a><?php endforeach; ?>
    <a href="/brand/"><?= e(t('Бренды')) ?></a><a href="/my/"><?= e(t('Личный кабинет')) ?></a><a href="/compare/"><?= e(t('Сравнение')) ?></a>
    <a href="/search/?_balance_type=favorites"><?= e(t('Избранное')) ?></a><a href="/search/?_balance_type=viewed"><?= e(t('Просмотренные товары')) ?></a>
    <div class="mnav-langs"><?php foreach (Lang::NAMES as $lg => $nm): ?><a href="<?= e(Lang::alternate($lg)) ?>" hreflang="<?= $lg ?>" class="<?= Lang::current() === $lg ? 'on' : '' ?>"><?= $lg === 'uk' ? 'Українська' : 'Русский' ?></a><?php endforeach; ?></div>
    <?php foreach ($phones as $p): ?><a href="tel:+<?= e(preg_replace('/\D/', '', $p)) ?>"><?= e($p) ?></a><?php endforeach; ?>
  </div>
</aside>
<div class="modal" id="callback" role="dialog" aria-modal="true" aria-labelledby="cb-t" aria-hidden="true" inert>
  <button class="x" data-close aria-label="<?= e(t('Закрыть')) ?>"><?= icon('x') ?></button>
  <h3 id="cb-t"><?= e(t('Обратный звонок')) ?></h3>
  <p><?= e(t('Оставьте номер — менеджер перезвонит в рабочее время')) ?></p>
  <form data-request="callback" novalidate>
    <input class="input" name="name" placeholder="<?= e(t('Ваше имя')) ?>" autocomplete="name" style="margin-bottom:10px">
    <input class="input" name="phone" type="tel" required placeholder="+38 (0__) ___-__-__" autocomplete="tel" style="margin-bottom:10px">
    <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off">
    <button class="btn btn-o btn-block"><?= e(t('Жду звонка')) ?></button>
  </form>
</div>
<div class="modal" id="ui-dialog" role="dialog" aria-modal="true" aria-hidden="true" inert><button class="x" data-close aria-label="<?= e(t('Закрыть')) ?>"><?= icon('x') ?></button><div class="dlg-body"></div></div>
<button class="totop" id="ui-totop" aria-label="<?= e(t('Наверх')) ?>"><?= icon('up') ?></button>
