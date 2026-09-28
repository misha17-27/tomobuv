<?php
/**
 * Карточка товара (плитка). $p — результат Products::cards()/decorate().
 * Состояние «в избранном / в сравнении» отмечает JS по cookie (страница кэшируется для всех).
 * @var array $p
 * @var int $eager  необязательно: 1 — фото первого экрана (без loading="lazy"), 2 — ещё и fetchpriority="high" (LCP)
 */
$badge = $p['off'] ? '<span class="badge">−' . (int) $p['off'] . '%</span>' : ($p['is_new'] ? '<span class="badge nw">' . e(t('Новинка')) . '</span>' : '');
?>
<article class="pc" data-id="<?= (int) $p['id'] ?>" data-max="<?= \App\Services\Cart::maxBoxes($p) ?>">
  <a class="ph" href="<?= e($p['link']) ?>"><img src="<?= e($p['img']) ?>" alt="<?= e($p['name']) ?>" <?= empty($eager) ? 'loading="lazy"' : ($eager > 1 ? 'fetchpriority="high"' : 'decoding="async"') ?> width="240" height="240"><?= $badge ?></a>
  <div class="acts"><button class="fav" data-act="fav" aria-label="<?= e(t('В избранное')) ?>" title="<?= e(t('В избранное')) ?>"><?= icon('heart') ?></button><button data-act="cmp" aria-label="<?= e(t('К сравнению')) ?>" title="<?= e(t('К сравнению')) ?>"><?= icon('cmp') ?></button></div>
  <div class="b">
    <a class="nm" href="<?= e($p['link']) ?>"><?= e($p['name']) ?></a>
    <div class="meta"><?php if ($p['size'] !== ''): ?><span><?= e(t('р.')) ?> <?= e($p['size']) ?></span><?php endif; ?><span><?= (int) $p['box_qty'] ?> <?= e(t('пар')) ?></span><?php if ($p['brand'] !== ''): ?><span><?= e($p['brand']) ?></span><?php endif; ?></div>
    <?php if ($p['in_stock']): ?><div class="stock"><?= e(t('В наличии')) ?></div><?php else: ?><div class="stock out"><?= e(t('Нет в наличии')) ?></div><?php endif; ?>
    <div class="pr">
      <?= price_html($p['box_price'], 'div', 'box') ?>
      <div class="pair"><?= e(t('за ящик')) ?> · <?= price_html($p['price']) ?> / <?= e(t('пара')) ?><?php if ($p['off']): ?> <s data-uah="<?= (int) round($p['compare_price']) ?>"><?= e(price_format($p['compare_price'])) ?></s><?php endif; ?></div>
    </div>
    <div class="buy">
      <div class="qty"><button type="button" data-q="-1" aria-label="<?= e(t('Меньше ящиков')) ?>">−</button><input value="1" inputmode="numeric" aria-label="<?= e(t('Количество ящиков')) ?>"><button type="button" data-q="1" aria-label="<?= e(t('Больше ящиков')) ?>">+</button></div>
      <button class="btn btn-b" data-act="cart"<?= $p['in_stock'] ? '' : ' disabled' ?>><?= icon('cart', 'width:18px') ?><?= e(t('В корзину')) ?></button>
    </div>
  </div>
</article>
