<?php
/**
 * Накладная для печати (отдельная страница без макета админки).
 * @var array $order @var string $num @var array $items @var string $addr @var array $store
 */
use App\Controllers\Admin\OrdersController as O;

$shipName = O::methodName($order['shipping_method'], $order['shipping_name'], O::methods('shipping_methods'));
$payName = O::methodName($order['payment_method'], $order['payment_name'], O::methods('payment_methods'));
$region = O::region($order['region']);
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Накладная <?= e($num) ?> — <?= e($store['name']) ?></title>
<link rel="stylesheet" href="<?= asset('admin/sales.css') ?>">
</head>
<body class="inv">
<div class="inv-bar"><button type="button" class="inv-btn" onclick="window.print()">Печать</button> <a href="/admin/orders/<?= (int) $order['id'] ?>/">← к заказу</a></div>
<main class="inv-page">
  <header class="inv-head">
    <div>
      <div class="inv-store"><?= e($store['name']) ?></div>
      <div><?= e($store['site']) ?><?= $store['email'] ? ' · ' . e($store['email']) : '' ?></div>
      <?php if ($store['phones']): ?><div><?= e(implode(', ', array_map('strval', $store['phones']))) ?></div><?php endif; ?>
      <?php if ($store['address']): ?><div><?= e($store['address']) ?></div><?php endif; ?>
    </div>
    <div class="inv-no">
      <h1>Накладная <?= e($num) ?></h1>
      <div>от <?= e(date('d.m.Y', strtotime((string) $order['created_at']))) ?></div>
      <div>Статус: <?= e(O::STATUSES[$order['status']] ?? $order['status']) ?></div>
    </div>
  </header>

  <section class="inv-info">
    <div>
      <h2>Получатель</h2>
      <p><b><?= e($order['name'] ?: '—') ?></b></p>
      <p><?= e(O::phone($order['phone'])) ?></p>
      <?php if ($order['email']): ?><p><?= e($order['email']) ?></p><?php endif; ?>
    </div>
    <div>
      <h2>Доставка</h2>
      <p><b><?= e($shipName ?: '—') ?></b></p>
      <p><?= e(trim(implode(', ', array_filter([(string) $order['city'], $region])))) ?: '—' ?></p>
      <?php if ($addr): ?><p><?= e($addr) ?></p><?php endif; ?>
    </div>
    <div>
      <h2>Оплата</h2>
      <p><b><?= e($payName ?: '—') ?></b></p>
    </div>
  </section>

  <div class="inv-scroll">
  <table class="inv-tbl">
    <thead><tr><th>№</th><th>Наименование</th><th>Артикул</th><th class="n">Пар в ящ.</th><th class="n">Ящиков</th><th class="n">Пар</th><th class="n">Цена за пару</th><th class="n">Сумма</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i => $it): ?>
      <tr><td><?= $i + 1 ?></td><td><?= e($it['name']) ?></td><td><?= e($it['sku']) ?></td><td class="n"><?= (int) $it['box_qty'] ?></td>
        <td class="n"><?= (int) $it['boxes'] ?></td><td class="n"><?= (int) $it['quantity'] ?></td><td class="n"><?= e(price_format($it['price'])) ?></td><td class="n"><?= e(price_format($it['sum'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="4" class="n">Всего:</td><td class="n"><?= (int) $order['boxes'] ?></td><td class="n"><?= (int) $order['pairs'] ?></td><td class="n">Подытог</td><td class="n"><?= e(price_format($order['subtotal'])) ?></td></tr>
      <?php if ((float) $order['shipping_cost'] > 0): ?><tr><td colspan="7" class="n">Доставка</td><td class="n"><?= e(price_format($order['shipping_cost'])) ?></td></tr><?php endif; ?>
      <?php if ((float) $order['discount'] > 0): ?><tr><td colspan="7" class="n">Скидка</td><td class="n">−<?= e(price_format($order['discount'])) ?></td></tr><?php endif; ?>
      <tr class="inv-total"><td colspan="7" class="n">Итого к оплате</td><td class="n"><?= e(price_format($order['total'])) ?></td></tr>
    </tfoot>
  </table>
  </div>

  <?php if (trim((string) $order['comment']) !== ''): ?><p class="inv-comment"><b>Комментарий:</b> <?= nl2br(e($order['comment'])) ?></p><?php endif; ?>

  <footer class="inv-sign">
    <div>Отпустил: ____________________</div>
    <div>Получил: ____________________</div>
  </footer>
</main>
</body>
</html>
