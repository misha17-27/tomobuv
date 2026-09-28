<?php
/**
 * Письмо покупателю «Заказ #100… оформлен» (на старом сайте — «Заказ Оформлен (Покупатель)»).
 * Рендерится на языке заказа (orders.lang): Orders::notify переключает Lang, поэтому все тексты через t().
 * @var array $order Orders::find()  @var string $orderUrl ссылка на страницу заказа (с подписью и префиксом языка)
 */
use App\Core\Lang;
use App\Core\Settings;
use App\Services\Orders;

$o = $order;
$abs = static fn(string $u): string => $u === '' ? '' : (preg_match('#^https?://#', $u) ? $u : url($u));
$pl = static fn(int $n, string $one, string $few, string $many): string => t(plural($n, $one, $few, $many));
$pickup = !empty($o['params']['pickup']);
$free = (int) Settings::get('free_shipping_boxes', 20);
$shipCost = $o['shipping_method'] === null ? t('уточнит менеджер') : ($pickup || (int) $o['boxes'] >= $free ? t('бесплатно') : t('по тарифам перевозчика'));
$address = (string) preg_replace('/^Когда заберут: /u', '', (string) $o['address']);
$td = 'padding:10px 6px;border-top:1px solid #e3e8ec;vertical-align:middle;font-size:14px';
?>
<h1 style="margin:0 0 6px;font-size:22px;line-height:1.3"><?= e(t('Спасибо за заказ!')) ?></h1>
<p style="margin:0 0 16px;color:#5d6b76"><?= t('Ваш заказ {n} от {date} оформлен в магазине «{store}». Менеджер свяжется с вами для подтверждения заказа и расчёта доставки.', [
    'n' => '<b style="color:#14212b">' . e($o['number']) . '</b>', 'date' => e(date('d.m.Y H:i', strtotime((string) $o['created_at']))),
    'store' => e(Settings::get('store_name', 'Tomobuv'))]) ?></p>
<p style="margin:0 0 20px"><a href="<?= e($orderUrl) ?>" style="display:inline-block;background:#c05500;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px"><?= e(t('Посмотреть заказ')) ?></a></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">
  <tr>
    <th align="left" colspan="2" style="padding:6px;font-size:12px;color:#5d6b76"><?= e(t('Товар')) ?></th>
    <th align="right" style="padding:6px;font-size:12px;color:#5d6b76"><?= e(t('Ящиков')) ?></th>
    <th align="right" style="padding:6px;font-size:12px;color:#5d6b76"><?= e(t('Сумма')) ?></th>
  </tr>
  <?php foreach ($o['items'] as $i): ?>
  <tr>
    <td width="56" style="<?= $td ?>"><img src="<?= e($abs($i['img'])) ?>" alt="" width="48" height="48" style="display:block;border:1px solid #e3e8ec;border-radius:6px;object-fit:contain"></td>
    <td style="<?= $td ?>">
      <?php if ($i['link']): ?><a href="<?= e(url(Lang::path($i['link']))) ?>" style="color:#14212b;font-weight:bold;text-decoration:none"><?= e($i['name']) ?></a><?php else: ?><b><?= e($i['name']) ?></b><?php endif; ?>
      <div style="color:#5d6b76;font-size:12px"><?= $i['size'] !== '' ? e(t('Размеры:')) . ' ' . e($i['size']) . ' · ' : '' ?><?= e(t('{n} {pairs} в ящике', ['n' => (int) $i['box_qty'], 'pairs' => $pl((int) $i['box_qty'], 'пара', 'пары', 'пар')])) ?> · <?= e(price_format($i['price'])) ?> / <?= e(t('пара')) ?></div>
    </td>
    <td align="right" style="<?= $td ?>;white-space:nowrap"><?= (int) $i['boxes'] ?> <?= e(t('ящ.')) ?><div style="color:#5d6b76;font-size:12px"><?= (int) $i['quantity'] ?> <?= e($pl((int) $i['quantity'], 'пара', 'пары', 'пар')) ?></div></td>
    <td align="right" style="<?= $td ?>;white-space:nowrap;font-weight:bold"><?= e(price_format($i['sum'])) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:8px;border-top:2px solid #e3e8ec">
  <tr><td style="padding:6px 0;color:#5d6b76"><?= e(t('Ящиков / пар')) ?></td><td align="right" style="padding:6px 0"><?= (int) $o['boxes'] ?> / <?= (int) $o['pairs'] ?></td></tr>
  <tr><td style="padding:6px 0;color:#5d6b76"><?= e(t('Товары на сумму')) ?></td><td align="right" style="padding:6px 0"><?= e(price_format($o['subtotal'])) ?></td></tr>
  <?php if ($o['discount'] > 0): ?><tr><td style="padding:6px 0;color:#5d6b76"><?= e(t('Скидка')) ?><?= $o['coupon_code'] !== '' ? ' (' . e($o['coupon_code']) . ')' : '' ?></td><td align="right" style="padding:6px 0;color:#1a8f55">−<?= e(price_format($o['discount'])) ?></td></tr><?php endif; ?>
  <tr><td style="padding:6px 0;color:#5d6b76"><?= e(t('Доставка')) ?></td><td align="right" style="padding:6px 0"><?= e($shipCost) ?></td></tr>
  <tr><td style="padding:8px 0;font-size:18px;font-weight:bold"><?= e(t('Итого')) ?></td><td align="right" style="padding:8px 0;font-size:18px;font-weight:bold"><?= e(price_format($o['total'])) ?></td></tr>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:16px;background:#f5f7f9;border-radius:10px">
  <tr><td style="padding:14px 16px;font-size:14px;line-height:1.7">
    <b><?= e(t('Получатель')) ?>:</b> <?= e(trim($o['name'] . ', ' . Orders::formatPhone($o['phone']), ', ')) ?><br>
    <?php if ($o['shipping_title'] !== ''): ?><b><?= e(t('Доставка')) ?>:</b> <?= e(implode(', ', array_filter([$o['shipping_title'], $o['region_name'], (string) $o['city'], $address], static fn($v) => $v !== ''))) ?><br><?php endif; ?>
    <?php if ($o['payment_title'] !== ''): ?><b><?= e(t('Оплата')) ?>:</b> <?= e($o['payment_title']) ?><br><?php endif; ?>
    <?php if ($o['comment']): ?><b><?= e(t('Комментарий')) ?>:</b> <?= nl2br(e($o['comment'])) ?><?php endif; ?>
  </td></tr>
</table>
<p style="margin:16px 0 0;color:#5d6b76;font-size:13px"><?= e(t('Если вы не оформляли этот заказ, просто ответьте на письмо или позвоните нам.')) ?></p>
