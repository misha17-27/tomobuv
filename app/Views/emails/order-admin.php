<?php
/**
 * Письмо администратору «Новый заказ #100…» (на старом сайте — «Заказ оформлен (Администратор магазина)»).
 * Всегда на русском (Orders::notify рендерит его с Lang = ru), язык покупателя — отдельной строкой.
 * @var array $order Orders::find()  @var string $adminUrl ссылка на заказ в админке
 */
use App\Services\Orders;

$o = $order;
$abs = static fn(string $u): string => $u === '' ? '' : (preg_match('#^https?://#', $u) ? $u : url($u));
$src = Orders::SOURCES[$o['source']] ?? $o['source'];
$p = $o['params'];
$utm = array_filter(array_intersect_key($p, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'landing', 'referer'])));
$td = 'padding:8px 6px;border-top:1px solid #e3e8ec;vertical-align:middle;font-size:14px';
$row = static fn(string $k, string $v): string => $v === '' ? '' : '<tr><td style="padding:5px 12px 5px 0;color:#5d6b76;white-space:nowrap;vertical-align:top">' . e($k)
    . '</td><td style="padding:5px 0;vertical-align:top">' . nl2br(e($v)) . '</td></tr>';
?>
<h1 style="margin:0 0 4px;font-size:22px;line-height:1.3">Новый заказ <?= e($o['number']) ?></h1>
<p style="margin:0 0 16px;color:#5d6b76"><?= e(date('d.m.Y H:i', strtotime((string) $o['created_at']))) ?> · <?= e($src) ?> · <?= (int) $o['boxes'] ?> ящ. / <?= (int) $o['pairs'] ?> пар · <b style="color:#14212b"><?= e(price_format($o['total'])) ?></b></p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:18px;font-size:14px">
  <?= $row('Покупатель', (string) $o['name']) ?>
  <?= $row('Телефон', Orders::formatPhone($o['phone'])) ?>
  <?= $row('E-mail', (string) $o['email']) ?>
  <?= $row('Доставка', (string) $o['shipping_name']) ?>
  <?= $row('Область', (string) $o['region_name']) ?>
  <?= $row('Город', (string) $o['city']) ?>
  <?= $row(!empty($p['pickup']) ? 'Самовывоз' : 'Отделение / адрес', (string) $o['address']) ?>
  <?= $row('Оплата', (string) $o['payment_name']) ?>
  <?= $row('Промокод', $o['coupon_code'] !== '' ? $o['coupon_code'] . ' (скидка ' . price_format($o['discount']) . ')' : '') ?>
  <?= $row('Комментарий', (string) $o['comment']) ?>
  <?= $row('Язык сайта', $o['lang'] === 'uk' ? 'украинский (/ua/)' : '') ?>
  <?= $row('Клиент', $o['customer_id'] ? '№ ' . $o['customer_id'] : '') ?>
  <?= $row('IP', (string) $o['ip']) ?>
  <?php foreach ($utm as $k => $v): ?><?= $row($k, (string) $v) ?><?php endforeach; ?>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">
  <tr>
    <th align="left" colspan="2" style="padding:6px;font-size:12px;color:#5d6b76">Товар</th>
    <th align="right" style="padding:6px;font-size:12px;color:#5d6b76">Цена / пара</th>
    <th align="right" style="padding:6px;font-size:12px;color:#5d6b76">Ящиков</th>
    <th align="right" style="padding:6px;font-size:12px;color:#5d6b76">Сумма</th>
  </tr>
  <?php foreach ($o['items'] as $i): ?>
  <tr>
    <td width="52" style="<?= $td ?>"><img src="<?= e($abs($i['img'])) ?>" alt="" width="44" height="44" style="display:block;border:1px solid #e3e8ec;border-radius:6px"></td>
    <td style="<?= $td ?>">
      <?php if ($i['link']): ?><a href="<?= e(url($i['link'])) ?>" style="color:#0b7fc1;font-weight:bold;text-decoration:none"><?= e($i['name']) ?></a><?php else: ?><b><?= e($i['name']) ?></b><?php endif; ?>
      <div style="color:#5d6b76;font-size:12px">ID <?= (int) $i['product_id'] ?><?= $i['sku'] !== '' ? ' · арт. ' . e($i['sku']) : '' ?><?= $i['size'] !== '' ? ' · р. ' . e($i['size']) : '' ?> · <?= (int) $i['box_qty'] ?> пар в ящике</div>
    </td>
    <td align="right" style="<?= $td ?>;white-space:nowrap"><?= e(price_format($i['price'])) ?></td>
    <td align="right" style="<?= $td ?>;white-space:nowrap"><?= (int) $i['boxes'] ?><div style="color:#5d6b76;font-size:12px"><?= (int) $i['quantity'] ?> <?= plural((int) $i['quantity'], 'пара', 'пары', 'пар') ?></div></td>
    <td align="right" style="<?= $td ?>;white-space:nowrap;font-weight:bold"><?= e(price_format($i['sum'])) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if ($o['discount'] > 0): ?>
  <tr>
    <td colspan="4" style="padding:8px 6px;border-top:2px solid #e3e8ec;color:#5d6b76">Товары на сумму <?= e(price_format($o['subtotal'])) ?>, скидка по промокоду <?= e($o['coupon_code']) ?></td>
    <td align="right" style="padding:8px 6px;border-top:2px solid #e3e8ec;white-space:nowrap;color:#1a8f55">−<?= e(price_format($o['discount'])) ?></td>
  </tr>
  <?php endif; ?>
  <tr>
    <td colspan="3" style="padding:10px 6px;border-top:2px solid #e3e8ec;font-size:16px;font-weight:bold">Итого</td>
    <td align="right" style="padding:10px 6px;border-top:2px solid #e3e8ec;white-space:nowrap"><?= (int) $o['boxes'] ?> ящ.</td>
    <td align="right" style="padding:10px 6px;border-top:2px solid #e3e8ec;font-size:16px;font-weight:bold;white-space:nowrap"><?= e(price_format($o['total'])) ?></td>
  </tr>
</table>
<p style="margin:20px 0 0"><a href="<?= e($adminUrl) ?>" style="display:inline-block;background:#0b7fc1;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px">Открыть заказ в админке</a></p>
