<?php
/**
 * Письмо клиенту от менеджера: временный пароль или ссылка для смены пароля.
 * Язык — язык клиента (OrdersController::inLang, тексты через t()).
 * @var array $c клиент @var string $lang @var string $pass временный пароль ('' — ссылка) @var string $link @var int $hours
 */
$store = (string) \App\Core\Settings::get('store_name', 'Tomobuv');
$phones = \App\Core\Settings::json('phones', []);
$login = url(\App\Core\Lang::path('/login/', $lang));
$site = rtrim(url(\App\Core\Lang::path('/', $lang)), '/') . '/';
$name = trim((string) (($c['firstname'] ?? '') ?: ($c['name'] ?? '')));
?><!DOCTYPE html>
<html lang="<?= $lang === 'uk' ? 'uk' : 'ru' ?>"><head><meta charset="utf-8"><title><?= e($pass !== '' ? t('Новый пароль') : t('Смена пароля')) ?></title></head>
<body style="margin:0;padding:0;background:#f5f7f9;font-family:Arial,Helvetica,sans-serif;color:#14212b">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7f9;padding:24px 0"><tr><td align="center">
  <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e3e8ec;border-radius:14px">
    <tr><td style="padding:22px 28px 10px;font-size:22px;font-weight:bold;color:#0b7fc1;border-bottom:3px solid #0b7fc1"><?= e($store) ?></td></tr>
    <tr><td style="padding:20px 28px;font-size:15px;line-height:1.6">
      <p style="margin:0 0 12px"><?= e(t('Здравствуйте')) ?><?= $name !== '' ? ', ' . e($name) : '' ?>!</p>
      <?php if ($pass !== ''): ?>
        <p style="margin:0 0 12px"><?= e(t('Для вашей учётной записи в интернет-магазине {store} установлен новый пароль:', ['store' => $store])) ?></p>
        <p style="margin:0 0 16px;font:bold 20px monospace;letter-spacing:1px;background:#fff8e6;padding:12px 14px;border-radius:8px"><?= e($pass) ?></p>
        <p style="margin:0 0 16px"><?= e(t('Логин — ваш e-mail или телефон. После входа пароль можно сменить в личном кабинете.')) ?></p>
        <p style="margin:0 0 18px"><a href="<?= e($login) ?>" style="display:inline-block;background:#ff7a1a;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px"><?= e(t('Войти в кабинет')) ?></a></p>
      <?php else: ?>
        <p style="margin:0 0 12px"><?= e(t('Менеджер интернет-магазина {store} отправил вам ссылку для установки нового пароля.', ['store' => $store])) ?></p>
        <p style="margin:0 0 18px"><a href="<?= e($link) ?>" style="display:inline-block;background:#ff7a1a;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px"><?= e(t('Задать новый пароль')) ?></a></p>
        <p style="margin:0 0 12px;font-size:13px;color:#5d6b76"><?= e(t('Ссылка действует {n} ч. Если кнопка не открывается, скопируйте ссылку в браузер:', ['n' => $hours])) ?><br>
          <a href="<?= e($link) ?>" style="color:#0b7fc1;word-break:break-all"><?= e($link) ?></a></p>
      <?php endif; ?>
      <p style="margin:0;color:#5d6b76;font-size:13px"><?= e(t('Если вы не просили сменить пароль, позвоните нам')) ?>: <?= e(implode(', ', array_map('strval', $phones))) ?></p>
    </td></tr>
    <tr><td style="padding:14px 28px 22px;font-size:13px;color:#5d6b76;border-top:1px solid #e3e8ec">
      <?= e(t('Оптовый интернет-магазин обуви')) ?> <?= e($store) ?> · <a href="<?= e($site) ?>" style="color:#0b7fc1"><?= e(preg_replace('#^https?://#', '', rtrim($site, '/'))) ?></a>
    </td></tr>
  </table>
</td></tr></table>
</body></html>
