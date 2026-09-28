<?php
/**
 * Письмо администратору о новой заявке (обратный звонок, подписка, сообщение, отзыв о магазине) —
 * тело для общего макета emails/layout. Всегда на русском (RequestController::notifyAdmin ставит язык ru).
 * @var string $typeName @var array $fields [['Имя', 'Иван'], …] @var string $adminUrl @var string $page
 */
?>
<p style="margin:0 0 4px;font-size:13px;color:#5d6b76">Новая заявка с сайта</p>
<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#0b7fc1"><?= e($typeName) ?></h1>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:15px;line-height:1.5;border-collapse:collapse;margin-bottom:18px">
  <?php foreach ($fields as [$label, $value]): if ((string) $value === '') continue; ?>
    <tr>
      <td style="padding:8px 12px 8px 0;border-bottom:1px solid #e3e8ec;color:#5d6b76;vertical-align:top;white-space:nowrap;width:120px"><?= e($label) ?></td>
      <td style="padding:8px 0;border-bottom:1px solid #e3e8ec;vertical-align:top"><?= nl2br(e($value)) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
  <td style="background:#0b7fc1;border-radius:10px"><a href="<?= e($adminUrl) ?>" style="display:inline-block;color:#ffffff;text-decoration:none;font-weight:bold;padding:10px 20px">Открыть в админке</a></td>
</tr></table>
<?php if (!empty($page)): ?><p style="margin:12px 0 0;font-size:12px;color:#5d6b76;word-break:break-all">Отправлено со страницы: <?= e($page) ?></p><?php endif; ?>
