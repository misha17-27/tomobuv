<?php
/**
 * Кабинет: заказ покупателя — состав, доставка, оплата, история статусов, «Повторить заказ».
 * @var array $order @var array $items @var array $history @var ?string $flash @var View $view
 */
use App\Controllers\Front\AccountController as A;

$cur = (string) $order['currency'];
$num = A::number((int) $order['id']);
$boxes = 0; $pairs = 0; $subtotal = 0.0;
foreach ($items as $it) { $boxes += $it['boxes']; $pairs += $it['pairs']; $subtotal += $it['sum']; }
$repeat = [];                                                  // для «Повторить заказ»: товар → ящиков (дубли суммируются)
foreach ($items as $it) {
    if (!$it['available']) continue;
    $pid = (int) $it['product_id'];
    $repeat[$pid] = ['id' => $pid, 'boxes' => min(999, ($repeat[$pid]['boxes'] ?? 0) + (int) $it['repeat_boxes'])];
}
$repeat = array_values($repeat);
$skipped = count($items) - count(array_filter($items, static fn($i) => $i['available']));
$addr = array_values(array_filter([(string) $order['region'], (string) $order['city'], (string) $order['address']], static fn($s) => trim($s) !== ''));
$ship = (float) $order['shipping_cost'];
$freeShip = $boxes >= (int) setting('free_shipping_boxes', 20);
$repeatBtn = static fn(string $cls) => '<button type="button" class="btn ' . $cls . '" data-repeat="' . e(json_encode($repeat)) . '" data-skipped="' . (int) $skipped . '">'
    . icon('refresh', 'width:18px') . e(t('Повторить заказ')) . '</button>';
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Личный кабинет'), 'url' => '/my/orders/'], ['name' => t('Мои заказы'), 'url' => '/my/orders/'], ['name' => t('Заказ {n}', ['n' => $num])]]]) ?>
    <div class="acc-oh">
      <h1><?= e(t('Заказ {n}', ['n' => $num])) ?></h1>
      <span class="acc-st acc-st-<?= e($order['status']) ?>"><?= e(A::statusName((string) $order['status'])) ?></span>
    </div>
    <p class="acc-sub"><?= e(t('от {date}', ['date' => A::date($order['created_at'])])) ?></p>
  </div>
  <div class="layout-2 acc">
    <?= $view->partial('front/partials/account-nav', ['active' => 'order']) ?>
    <div class="acc-main">
      <?php if (!empty($flash)): ?><div class="note ok acc-flash" role="status"><?= e($flash) ?></div><?php endif; ?>

      <section class="panel" aria-labelledby="o-items">
        <div class="acc-ph">
          <h2 id="o-items"><?= e(t('Состав заказа')) ?></h2>
          <?php if ($repeat): ?><?= $repeatBtn('btn-o btn-sm') ?><?php endif; ?>
        </div>
        <?php if (!$items): ?>
          <p class="muted"><?= e(t('Позиции заказа не сохранились.')) ?></p>
        <?php else: ?>
          <div class="tblwrap acc-items-wrap">
            <table class="tbl acc-items">
              <thead><tr>
                <th scope="col"><span class="visually-hidden"><?= e(t('Фото')) ?></span></th>
                <th scope="col"><?= e(t('Товар')) ?></th>
                <th scope="col" class="num hide-m"><?= e(t('Цена за пару')) ?></th>
                <th scope="col" class="num hide-m"><?= e(t('Пар')) ?></th>
                <th scope="col" class="num"><?= e(t('Ящиков')) ?></th>
                <th scope="col" class="num"><?= e(t('Сумма')) ?></th>
              </tr></thead>
              <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><?php if ($it['link']): ?><a href="<?= e($it['link']) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e($it['img']) ?>" alt="" width="56" height="56" loading="lazy"></a><?php else: ?><img src="<?= e($it['img']) ?>" alt="" width="56" height="56" loading="lazy"><?php endif; ?></td>
                  <td>
                    <?php if ($it['link']): ?><a class="acc-iname" href="<?= e($it['link']) ?>"><?= e($it['name']) ?></a><?php else: ?><span class="acc-iname"><?= e($it['name']) ?></span><?php endif; ?>
                    <small class="muted acc-imeta"><?php /* в перенесённых заказах артикулом записан размерный ряд (32-37) — подписываем его «р.» */ ?><?= $it['sku'] !== '' ? e(preg_match('/^\d{2}(\.5)?\s*[-–]\s*\d{2}(\.5)?$/u', (string) $it['sku']) ? t('р.') : t('Арт.')) . ' ' . e($it['sku']) . ' · ' : '' ?><?= e(t('{n} пар в ящике', ['n' => (int) $it['box_qty']])) ?><span class="acc-m-price"><?= e(t('Пар: {n}', ['n' => (int) $it['pairs']])) ?> · <?= e(A::money($it['price'], $cur)) ?>/<?= e(t('пара')) ?></span><?= $it['available'] ? '' : ' · <span class="acc-na">' . e(t('нет в наличии')) . '</span>' ?></small>
                  </td>
                  <td class="num hide-m"><?= e(A::money($it['price'], $cur)) ?></td>
                  <td class="num hide-m"><?= (int) $it['pairs'] ?></td>
                  <td class="num"><?= (int) $it['boxes'] ?></td>
                  <td class="num"><b><?= e(A::money($it['sum'], $cur)) ?></b></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <div class="summary acc-sum">
          <div class="line"><span><?= e(t('Товары ({b} ящ., {p} пар)', ['b' => $boxes, 'p' => $pairs])) ?></span><span><?= e(A::money((float) $order['subtotal'] > 0 ? $order['subtotal'] : $subtotal, $cur)) ?></span></div>
          <?php if ((float) $order['discount'] > 0): ?><div class="line"><span><?= e(t('Скидка')) ?></span><span>−<?= e(A::money($order['discount'], $cur)) ?></span></div><?php endif; ?>
          <div class="line"><span><?= e(t('Доставка')) ?></span><span><?= $ship > 0 ? e(A::money($ship, $cur)) : e($freeShip ? t('бесплатно') : t('по тарифам перевозчика')) ?></span></div>
          <div class="line total"><span><?= e(t('Итого')) ?></span><span><?= e(A::money($order['total'], $cur)) ?></span></div>
        </div>
      </section>

      <div class="acc-2">
        <section class="panel" aria-labelledby="o-ship">
          <h2 id="o-ship"><?= e(t('Доставка и оплата')) ?></h2>
          <dl class="acc-dl">
            <?php if ($order['name'] !== ''): ?><dt><?= e(t('Получатель')) ?></dt><dd><?= e($order['name']) ?></dd><?php endif; ?>
            <?php if ($order['phone'] !== ''): ?><dt><?= e(t('Телефон')) ?></dt><dd><?= e(A::phoneView((string) $order['phone'])) ?></dd><?php endif; ?>
            <dt><?= e(t('Доставка')) ?></dt><dd><?= e($order['shipping_name'] ?: '—') ?></dd>
            <?php if ($addr): ?><dt><?= e(t('Адрес')) ?></dt><dd><?= e(implode(', ', $addr)) ?></dd><?php endif; ?>
            <dt><?= e(t('Оплата')) ?></dt><dd><?= e(trim((string) $order['payment_name']) ?: '—') ?></dd>
            <?php if (trim((string) $order['comment']) !== ''): ?><dt><?= e(t('Комментарий')) ?></dt><dd><?= nl2br(e(trim((string) $order['comment']))) ?></dd><?php endif; ?>
          </dl>
        </section>
        <section class="panel" aria-labelledby="o-hist">
          <h2 id="o-hist"><?= e(t('История заказа')) ?></h2>
          <ol class="acc-hist">
            <?php foreach ($history as $h): ?>
              <li class="hs-<?= e($h['status']) ?>"><b><?= e(A::statusName($h['status'])) ?></b><small><?= e(A::date($h['at'])) ?></small></li>
            <?php endforeach; ?>
          </ol>
        </section>
      </div>

      <div class="acc-actions">
        <a class="btn btn-g" href="/my/orders/"><?= icon('back', 'width:18px') ?><?= e(t('Все заказы')) ?></a>
        <?php if ($repeat): ?><?= $repeatBtn('btn-o') ?><?php endif; ?>
      </div>
      <p class="muted acc-q"><?= e(t('Вопросы по заказу? Позвоните нам и назовите номер {n}.', ['n' => $num])) ?></p>
    </div>
  </div>
</div>
