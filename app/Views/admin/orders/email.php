<?php
/**
 * Письмо клиенту о смене статуса заказа — на языке заказа (OrdersController::inLang, тексты через t()).
 * Стили — прямо в тегах: почтовые клиенты не читают CSS-файлы.
 * @var array $order @var string $num @var string $statusName @var string $comment @var string $store @var string $lang @var string $orderUrl
 */
$phones = \App\Core\Settings::json('phones', []);
$site = rtrim(url(\App\Core\Lang::path('/', $lang)), '/') . '/';
?><!DOCTYPE html>
<html lang="<?= $lang === 'uk' ? 'uk' : 'ru' ?>"><head><meta charset="utf-8"><title><?= e(t('Заказ {num}', ['num' => $num])) ?></title></head>
<body style="margin:0;padding:0;background:#f5f7f9;font-family:Arial,Helvetica,sans-serif;color:#14212b">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7f9;padding:24px 0"><tr><td align="center">
  <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e3e8ec;border-radius:14px">
    <tr><td style="padding:22px 28px 10px;font-size:22px;font-weight:bold;color:#0b7fc1;border-bottom:3px solid #0b7fc1"><?= e($store) ?></td></tr>
    <tr><td style="padding:20px 28px;font-size:15px;line-height:1.55">
      <p style="margin:0 0 12px"><?= e(t('Здравствуйте')) ?><?= trim((string) $order['name']) !== '' ? ', ' . e($order['name']) : '' ?>!</p>
      <p style="margin:0 0 12px"><?= t('Статус вашего заказа {num} от {date} изменён:', ['num' => '<b>' . e($num) . '</b>', 'date' => e(date('d.m.Y', strtotime((string) $order['created_at'])))]) ?>
        <b style="color:#a34700"><?= e($statusName) ?></b>.</p>
      <?php if ($comment !== ''): ?><p style="margin:0 0 12px;padding:12px 14px;background:#e8f4fb;border-radius:8px"><?= nl2br(e($comment)) ?></p><?php endif; ?>
      <p style="margin:0 0 16px"><?= e(t('Сумма заказа')) ?>: <b><?= e(price_format($order['total'])) ?></b> · <?= (int) $order['boxes'] ?> <?= e(t('ящ.')) ?> / <?= (int) $order['pairs'] ?> <?= e(t('пар')) ?></p>
      <p style="margin:0 0 18px"><a href="<?= e($orderUrl) ?>" style="display:inline-block;background:#c05500;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px"><?= e(t('Посмотреть заказ')) ?></a></p>
      <p style="margin:0;color:#5d6b76;font-size:13px"><?= e(t('Вопросы по заказу')) ?>: <?= e(implode(', ', array_map('strval', $phones))) ?></p>
    </td></tr>
    <tr><td style="padding:14px 28px 22px;font-size:13px;color:#5d6b76;border-top:1px solid #e3e8ec">
      <?= e(t('Оптовый интернет-магазин обуви')) ?> <?= e($store) ?> · <a href="<?= e($site) ?>" style="color:#0b7fc1"><?= e(preg_replace('#^https?://#', '', rtrim($site, '/'))) ?></a>
    </td></tr>
  </table>
</td></tr></table>
</body></html>
