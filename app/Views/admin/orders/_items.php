<?php
/**
 * Редактор состава заказа (карточка заказа и новый заказ).
 * @var array $items   позиции (OrdersController::itemsView)
 * @var float $shippingCost @var float $discount
 * Имена полей: items[<ключ>][id|product_id|boxes|price], shipping_cost, discount.
 * Пересчёт на лету, удаление и добавление позиций — assets/admin/sales.js (data-oi-*).
 */
?>
<div class="sl-items" data-oi>
  <div class="tblwrap">
  <table class="tbl sl-itbl sl-stack">
    <thead><tr>
      <th><span class="sl-sr">Фото</span></th><th>Товар</th><th class="num">Пар в ящ.</th><th class="num">Ящиков</th><th class="num">Пар</th>
      <th class="num">Цена за пару</th><th class="num">Сумма</th><th><span class="sl-sr">Удалить</span></th>
    </tr></thead>
    <tbody data-oi-body>
    <?php foreach ($items as $n => $it):
      $key = !empty($it['id']) ? 'e' . (int) $it['id'] : 'n' . $n;
      $pid = (int) ($it['product_id'] ?? 0); ?>
      <tr data-oi-row data-bq="<?= (int) $it['box_qty'] ?>" data-q0="<?= (int) $it['quantity'] ?>">
        <td class="sl-ph"><img src="<?= e($it['img']) ?>" alt="" width="44" height="44" loading="lazy"></td>
        <td data-l="Товар" class="sl-iname">
          <input type="hidden" name="items[<?= e($key) ?>][id]" value="<?= (int) ($it['id'] ?? 0) ?>">
          <input type="hidden" name="items[<?= e($key) ?>][product_id]" value="<?= $pid ?>">
          <?php if ($it['link']): ?><a href="<?= e($it['link']) ?>" target="_blank" rel="noopener"><?= e($it['name']) ?></a>
          <?php else: ?><span><?= e($it['name']) ?></span><?php endif; ?>
          <div class="sl-isub muted">
            <?php if ($it['sku'] !== ''): ?>Арт.: <?= e($it['sku']) ?><?php endif; ?>
            <?php if (!empty($it['size']) && $it['size'] !== $it['sku']): ?> · р. <?= e($it['size']) ?><?php endif; ?>
            <?php if ($pid && $it['exists']): ?> · <a href="/admin/products/<?= $pid ?>/">в админке</a><?php endif; ?>
            <?php if ($pid && !$it['exists']): ?> · <span class="sl-warn">товар удалён с сайта</span><?php elseif ($pid && !$it['active']): ?> · <span class="sl-warn">скрыт</span><?php endif; ?>
          </div>
        </td>
        <td data-l="Пар в ящике" class="num"><?= (int) $it['box_qty'] ?></td>
        <td data-l="Ящиков" class="num"><input class="sl-in" type="number" name="items[<?= e($key) ?>][boxes]" value="<?= (int) $it['boxes'] ?>" min="1" max="9999" step="1" required aria-label="Ящиков: <?= e($it['name']) ?>" data-oi-boxes></td>
        <td data-l="Пар" class="num" data-oi-pairs><?= (int) $it['quantity'] ?></td>
        <td data-l="Цена за пару" class="num"><input class="sl-in sl-in-price" type="number" name="items[<?= e($key) ?>][price]" value="<?= e(rtrim(rtrim(number_format((float) $it['price'], 2, '.', ''), '0'), '.')) ?>" min="0" step="0.01" required aria-label="Цена за пару: <?= e($it['name']) ?>" data-oi-price>
          <?php if ($it['current_price'] !== null && abs($it['current_price'] - (float) $it['price']) > 0.001): ?><div class="sl-isub muted" title="Цена на сайте сейчас">сейчас <?= e(price_format($it['current_price'])) ?></div><?php endif; ?></td>
        <td data-l="Сумма" class="num"><b data-oi-sum><?= e(price_format($it['sum'])) ?></b></td>
        <td class="sl-idel"><button type="button" class="btn btn-sm btn-d" data-oi-del aria-label="Удалить позицию <?= e($it['name']) ?>"><?= icon('trash') ?></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="sl-iempty muted<?= $items ? ' hidden' : '' ?>" data-oi-empty>Позиций нет — найдите и добавьте товар.</p>

  <div class="sl-picker" data-picker>
    <label class="fld"><span>Добавить товар</span>
      <input type="search" placeholder="Артикул, модель, название или ID товара" autocomplete="off" data-picker-input aria-autocomplete="list"></label>
    <div class="sl-drop hidden" data-picker-drop role="listbox" aria-label="Найденные товары"></div>
  </div>

  <div class="sl-totals">
    <div class="sl-trow"><span>Ящиков / пар</span><b data-oi-bp>0 / 0</b></div>
    <div class="sl-trow"><span>Подытог</span><b data-oi-sub>0 грн.</b></div>
    <label class="sl-trow"><span>Доставка, грн</span><input class="sl-in sl-in-price" type="number" name="shipping_cost" min="0" step="0.01" value="<?= e(rtrim(rtrim(number_format($shippingCost, 2, '.', ''), '0'), '.')) ?>" data-oi-ship></label>
    <label class="sl-trow"><span>Скидка, грн</span><input class="sl-in sl-in-price" type="number" name="discount" min="0" step="0.01" value="<?= e(rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.')) ?>" data-oi-disc></label>
    <div class="sl-trow sl-ttl"><span>Итого</span><b data-oi-total>0 грн.</b></div>
  </div>
</div>
