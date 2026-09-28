<?php
/**
 * Корзина + оформление заказа на одной странице (/cart/), как «Корзина + заказ в 1 шаг» на старом сайте:
 * шаг 1 — позиции (здесь), шаги 2–4 — форма (front/checkout), справа — итоги, промокод и кнопка «Оформить заказ».
 * Количество — в ящиках; цены за пару и за ящик. Форма отправляется на /order/ (работает и без JS).
 * @var array $items @var array $sum @var int $removed @var int $free @var array $shipping @var array $payment
 * @var array $form @var array $errors @var ?array $user @var array $phones @var bool $couponOn @var View $view
 */
$ship = $shipping[$form['shipping']] ?? null;
$pickup = $ship && $ship['pickup'];
$left = max(0, $free - $sum['count']);
$pct = $free > 0 ? (int) min(100, round($sum['count'] / $free * 100)) : 100;
$shipLine = $pickup || $left === 0 ? t('бесплатно') : t('по тарифам перевозчика');
$pl = static fn(int $n, string $one, string $few, string $many): string => t(plural($n, $one, $few, $many));
$phone = $phones[0] ?? '';
$lines = count($items);
?>
<div class="wrap co-page">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Оформление заказа')]]]) ?>
    <h1><?= e(t('Оформление заказа')) ?></h1>
  </div>

<?php if (!$items): ?>
  <div class="empty-state co-empty">
    <div class="ic"><?= icon('cart', 'width:30px;height:30px') ?></div>
    <h2><?= e(t('Ваша корзина пуста')) ?></h2>
    <p><?= $removed ? e(t('Товары из вашей корзины сняты с продажи.')) . ' ' : '' ?><?= e(t('Добавьте товары из каталога — цены указаны за пару и за ящик, заказ оформляется ящиками.')) ?></p>
    <div class="co-empty-a"><a class="btn btn-o" href="/category/dyetskaya-obuv/"><?= e(t('Перейти в каталог')) ?></a><a class="btn btn-g" href="/"><?= e(t('На главную')) ?></a></div>
  </div>
<?php else: ?>
  <div class="layout-2r co" data-free="<?= (int) $free ?>">
    <div class="co-main">
      <section class="panel co-sec co-cart" id="cart" aria-labelledby="co-h1">
        <div class="co-sec-h">
          <span class="co-step" aria-hidden="true">1</span><h2 id="co-h1"><?= e(t('Корзина')) ?></h2>
          <span class="co-lines muted" data-t-lines-text><?= $lines ?> <?= e($pl($lines, 'позиция', 'позиции', 'позиций')) ?></span>
        </div>
        <?php if ($removed): ?><p class="note warn co-note"><?= e(t('Некоторые товары сняты с продажи и удалены из корзины: {n}.', ['n' => (int) $removed])) ?></p><?php endif; ?>
        <div class="tblwrap co-tblwrap">
          <table class="tbl co-tbl">
            <thead><tr>
              <th scope="col"><span class="visually-hidden"><?= e(t('Фото')) ?></span></th><th scope="col"><?= e(t('Товар')) ?></th>
              <th scope="col" class="num c-hide"><?= e(t('Пар в ящике')) ?></th><th scope="col" class="num c-hide"><?= e(t('Цена за пару')) ?></th><th scope="col" class="num c-hide"><?= e(t('Цена за ящик')) ?></th>
              <th scope="col"><?= e(t('Ящиков')) ?></th><th scope="col" class="num"><?= e(t('Сумма')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Удалить')) ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $i): $over = $i['available'] && $i['boxes'] > $i['max_boxes']; ?>
              <tr data-line="<?= (int) $i['id'] ?>" data-bq="<?= (int) $i['box_qty'] ?>" class="<?= $i['available'] ? '' : 'off' ?>">
                <td class="c-img"><a href="<?= e($i['link']) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e($i['img_small']) ?>" alt="" width="64" height="64" loading="lazy"></a></td>
                <td class="c-name">
                  <a class="co-nm" href="<?= e($i['link']) ?>"><?= e($i['name']) ?></a>
                  <div class="co-attr"><?php if ($i['size'] !== ''): ?><span><?= e(t('Размеры:')) ?> <b><?= e($i['size']) ?></b></span><?php endif; ?><?php if ($i['brand'] !== ''): ?><span><?= e($i['brand']) ?></span><?php endif; ?></div>
                  <div class="co-meta"><span><?= e(t('{n} {pairs} в ящике', ['n' => (int) $i['box_qty'], 'pairs' => $pl((int) $i['box_qty'], 'пара', 'пары', 'пар')])) ?></span> · <span><?= price_html($i['price']) ?> / <?= e(t('пара')) ?></span> · <span><?= price_html($i['box_price']) ?> / <?= e(t('ящик')) ?></span></div>
                  <?php if (!$i['available']): ?><div class="co-warn"><?= e(t('Нет в наличии — не войдёт в заказ')) ?></div>
                  <?php elseif ($over): ?><div class="co-warn"><?= e(t('В наличии только {n} {boxes}', ['n' => (int) $i['max_boxes'], 'boxes' => $pl((int) $i['max_boxes'], 'ящик', 'ящика', 'ящиков')])) ?></div><?php endif; ?>
                </td>
                <td class="num c-hide"><?= (int) $i['box_qty'] ?></td>
                <td class="num c-hide"><?= price_html($i['price']) ?></td>
                <td class="num c-hide"><?= price_html($i['box_price']) ?></td>
                <td class="c-qty">
                  <?php if ($i['available']): ?>
                  <div class="qty co-qty"><button type="button" data-step="-1" aria-label="<?= e(t('Меньше ящиков')) ?>">−</button><input value="<?= (int) $i['boxes'] ?>" inputmode="numeric" pattern="[0-9]*" aria-label="<?= e(t('Количество ящиков: {name}', ['name' => $i['name']])) ?>" data-boxes data-max="<?= (int) $i['max_boxes'] ?>"><button type="button" data-step="1" aria-label="<?= e(t('Больше ящиков')) ?>">+</button></div>
                  <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td class="num c-sum"><b><?= price_html($i['sum']) ?></b><small data-pairs><?= (int) $i['pairs'] ?> <?= e($pl((int) $i['pairs'], 'пара', 'пары', 'пар')) ?></small></td>
                <td class="c-del"><button type="button" class="co-del" data-remove aria-label="<?= e(t('Удалить «{name}» из корзины', ['name' => $i['name']])) ?>" title="<?= e(t('Удалить')) ?>"><?= icon('trash', 'width:18px;height:18px') ?></button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="co-foot">
          <div class="co-ship">
            <div class="co-ship-t" data-ship-text><?php if ($left > 0): ?><?= t('До бесплатной доставки осталось {left}', ['left' => '<b>' . $left . ' ' . e($pl($left, 'ящик', 'ящика', 'ящиков')) . '</b>']) ?><?php else: ?><b><?= e(t('Доставка бесплатная')) ?></b> — <?= e(t('в заказе от {n} ящ.', ['n' => $free])) ?><?php endif; ?></div>
            <div class="co-bar" role="progressbar" aria-label="<?= e(t('До бесплатной доставки')) ?>" aria-valuemin="0" aria-valuemax="<?= $free ?>" aria-valuenow="<?= min($free, $sum['count']) ?>"><i style="width:<?= $pct ?>%"></i></div>
          </div>
          <div class="co-total"><?= e(t('Итого:')) ?> <b data-t-boxes><?= $sum['count'] ?></b> <?= e(t('ящ.')) ?> · <b data-t-pairs><?= $sum['pairs'] ?></b> <?= e(t('пар')) ?> · <?= price_html($sum['total'], 'b', 'co-t-sum co-total-sum') ?></div>
        </div>
        <div class="co-actions">
          <a class="btn btn-g btn-sm" href="/"><?= icon('back', 'width:16px;height:16px') ?><?= e(t('Продолжить покупки')) ?></a>
          <button type="button" class="btn btn-g btn-sm co-clear" data-cart-clear><?= icon('trash', 'width:16px;height:16px') ?><?= e(t('Очистить корзину')) ?></button>
        </div>
      </section>

      <?= $view->partial('front/checkout') ?>
    </div>

    <aside class="co-side" aria-label="<?= e(t('Ваш заказ')) ?>">
      <div class="panel summary sticky co-sum">
        <h2><?= e(t('Ваш заказ')) ?></h2>
        <div class="line"><span><?= e(t('Позиций')) ?></span><b data-t-lines><?= $sum['lines'] ?></b></div>
        <div class="line"><span><?= e(t('Ящиков')) ?></span><b data-t-boxes><?= $sum['count'] ?></b></div>
        <div class="line"><span><?= e(t('Пар')) ?></span><b data-t-pairs><?= $sum['pairs'] ?></b></div>
        <div class="line"><span><?= e(t('Товары на сумму')) ?></span><?= price_html($sum['total'], 'b', 'co-t-sum') ?></div>
        <div class="line"><span><?= e(t('Доставка')) ?></span><span data-t-ship><?= e($shipLine) ?></span></div>
        <div class="line co-disc" data-t-disc-line hidden><span><?= e(t('Скидка')) ?> <small data-t-disc-code></small></span><b class="co-disc-v">−<span data-uah="0" data-t-disc>0</span></b></div>
        <div class="line total"><span><?= e(t('Итого')) ?></span><?= price_html($sum['total'], 'span', 'co-t-total') ?></div>
        <?php if ($couponOn): $cErr = $errors['coupon'] ?? ''; ?>
        <details class="co-coupon"<?= $form['coupon'] !== '' || $cErr !== '' ? ' open' : '' ?>>
          <summary><?= e(t('Есть промокод?')) ?></summary>
          <div class="co-coupon-f">
            <label class="visually-hidden" for="co-coupon"><?= e(t('Промокод')) ?></label>
            <input class="input<?= $cErr !== '' ? ' err' : '' ?>" id="co-coupon" name="coupon" form="order" value="<?= e($form['coupon']) ?>" maxlength="32" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="<?= e(t('Промокод')) ?>" aria-describedby="e-coupon"<?= $cErr !== '' ? ' aria-invalid="true"' : '' ?> data-coupon>
            <button type="button" class="btn btn-b btn-sm" data-coupon-apply><?= e(t('Применить')) ?></button>
          </div>
          <div class="errtxt" id="e-coupon" role="alert"<?= $cErr !== '' ? '>' . e($cErr) : ' hidden>' ?></div>
          <div class="co-coupon-ok" data-coupon-ok hidden></div>
        </details>
        <?php endif; ?>
        <button type="submit" form="order" class="btn btn-o btn-block co-submit" data-submit><?= e(t('Оформить заказ')) ?></button>
        <button type="button" class="btn btn-g btn-block btn-sm co-quick" data-quick-cart><?= e(t('Купить в 1 клик')) ?></button>
        <p class="co-help"><?= e(t('Менеджер свяжется с вами для подтверждения заказа и расчёта доставки.')) ?><?php if ($phone !== ''): ?> <?= e(t('Вопросы по заказу:')) ?> <a href="tel:+<?= e(preg_replace('/\D/', '', $phone)) ?>"><?= e($phone) ?></a><?php endif; ?></p>
      </div>
    </aside>
  </div>
<?php endif; ?>
</div>
