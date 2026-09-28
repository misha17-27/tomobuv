<?php
/**
 * Строка табличного вида каталога (удобно оптовикам заказывать много позиций).
 * @var array $p
 */
?>
<tr data-id="<?= (int) $p['id'] ?>" data-max="<?= \App\Services\Cart::maxBoxes($p) ?>">
  <td><a href="<?= e($p['link']) ?>"><img src="<?= e($p['img_small']) ?>" alt="" loading="lazy" width="56" height="56"></a></td>
  <td><a class="link" style="color:var(--ink)" href="<?= e($p['link']) ?>"><?= e($p['name']) ?></a><div class="muted" style="font-size:12px"><?= e($p['brand']) ?><?= $p['off'] ? ' · <b style="color:var(--orange)">−' . (int) $p['off'] . '%</b>' : '' ?><?= $p['in_stock'] ? '' : ' · ' . e(t('нет в наличии')) ?></div></td>
  <td><?= e($p['size']) ?></td>
  <td class="num hide-m"><?= (int) $p['box_qty'] ?></td>
  <td class="num hide-m"><?= price_html($p['price']) ?></td>
  <td class="num"><b><?= price_html($p['box_price']) ?></b></td>
  <td><div class="qty"><button type="button" data-q="-1" aria-label="<?= e(t('Меньше')) ?>">−</button><input value="1" inputmode="numeric" aria-label="<?= e(t('Ящиков')) ?>"><button type="button" data-q="1" aria-label="<?= e(t('Больше')) ?>">+</button></div></td>
  <td><button class="btn btn-b btn-sm" data-act="cart" aria-label="<?= e(t('В корзину')) ?>"<?= $p['in_stock'] ? '' : ' disabled' ?>><?= icon('cart', 'width:16px') ?></button></td>
  <td class="hide-m"><div style="display:flex;gap:4px"><button class="btn btn-g btn-sm fav" data-act="fav" aria-label="<?= e(t('В избранное')) ?>" style="padding:0 10px"><?= icon('heart', 'width:16px') ?></button><button class="btn btn-g btn-sm" data-act="cmp" aria-label="<?= e(t('К сравнению')) ?>" style="padding:0 10px"><?= icon('cmp', 'width:16px') ?></button></div></td>
</tr>
