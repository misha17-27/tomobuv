<?php
/**
 * Общий макет HTML-писем (таблицы и встроенные стили — так письма одинаково выглядят в Gmail, Outlook, Ukr.net).
 * Использование: View::render('emails/layout', ['title' => …, 'content' => View::render('emails/…', $d, null)], null)
 * Тексты — на текущем языке (письмо покупателю Orders::notify рендерит на языке заказа, администратору — на русском).
 * @var string $title   тема/заголовок письма
 * @var string $content HTML тела письма
 * @var ?string $preheader короткий текст для превью в почтовом ящике
 */
use App\Core\Lang;
use App\Core\Settings;

$store = (string) Settings::get('store_name', 'Tomobuv');
$phones = Settings::json('phones', []);
$email = (string) Settings::get('store_email', '');
$site = url(Lang::path('/'));
$host = (string) (parse_url($site, PHP_URL_HOST) ?: 'tomobuv.com.ua');
?><!DOCTYPE html>
<html lang="<?= Lang::isUk() ? 'uk' : 'ru' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? $store) ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f7f9;font-family:Arial,Helvetica,sans-serif;color:#14212b">
<?php if (!empty($preheader)): ?><div style="display:none;max-height:0;overflow:hidden;opacity:0"><?= e($preheader) ?></div><?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f7f9;border-collapse:collapse">
  <tr><td align="center" style="padding:24px 12px">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e8ec;border-radius:14px;border-collapse:separate;overflow:hidden">
      <tr><td style="padding:18px 24px;border-bottom:3px solid #0b7fc1">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
          <td><a href="<?= e($site) ?>" style="text-decoration:none"><img src="<?= e(url('/assets/img/logo.png')) ?>" alt="<?= e($store) ?>" width="154" height="40" style="display:block;border:0;height:40px;width:auto"></a></td>
          <td align="right" style="font-size:13px;color:#5d6b76"><?= e(t('Оптовый интернет-магазин обуви')) ?></td>
        </tr></table>
      </td></tr>
      <tr><td style="padding:24px;font-size:15px;line-height:1.5">
        <?= $content ?>
      </td></tr>
      <tr><td style="padding:18px 24px;background:#14212b;color:#b8c4cc;font-size:13px;line-height:1.6">
        <b style="color:#ffffff"><?= e($store) ?></b> — <?= e(t('опт обуви в Одессе')) ?><br>
        <?php foreach ($phones as $p): ?><a href="tel:+<?= e(preg_replace('/\D/', '', $p)) ?>" style="color:#ffffff;text-decoration:none"><?= e($p) ?></a> &nbsp; <?php endforeach; ?><br>
        <?php if ($email !== ''): ?><a href="mailto:<?= e($email) ?>" style="color:#6fc2f0;text-decoration:none"><?= e($email) ?></a> · <?php endif; ?><a href="<?= e($site) ?>" style="color:#6fc2f0;text-decoration:none"><?= e($host) ?></a>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
