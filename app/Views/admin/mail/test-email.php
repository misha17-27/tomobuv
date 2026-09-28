<?php
/**
 * Тело тестового письма из раздела «Почта (SMTP)» (вставляется в emails/layout).
 * @var string $via @var string $who @var string $from @var string $admin @var string $at
 */
?>
<p style="margin:0 0 12px;font-size:15px;line-height:1.6"><b>Почта сайта работает.</b></p>
<p style="margin:0 0 12px;font-size:15px;line-height:1.6">Это тестовое письмо из админки<?= $who !== '' ? ' — отправил(а) ' . e($who) : '' ?>, <?= e($at) ?>.
  Если оно пришло во «Входящие», письма о заказах и заявках тоже будут доходить.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:14px;margin:0 0 14px">
  <tr><td style="padding:4px 14px 4px 0;color:#5d6b76">Способ отправки</td><td style="padding:4px 0"><?= e($via) ?></td></tr>
  <tr><td style="padding:4px 14px 4px 0;color:#5d6b76">От кого</td><td style="padding:4px 0"><?= e($from !== '' ? $from : '—') ?></td></tr>
  <tr><td style="padding:4px 14px 4px 0;color:#5d6b76">Уведомления о заказах</td><td style="padding:4px 0"><?= e($admin !== '' ? $admin : 'адрес не задан') ?></td></tr>
</table>
<p style="margin:0;font-size:13px;color:#5d6b76;line-height:1.5">Письмо попало в «Спам»? Отметьте «Не спам» и проверьте, что адрес «От кого» совпадает с ящиком SMTP.</p>
