<?php
/**
 * Письмо со ссылкой восстановления пароля — тело для общего макета emails/layout.
 * Рендерится на языке клиента (AuthController переключает Lang на customers.lang) — все тексты через t().
 * @var array $user (name, firstname, email) @var string $link
 */
$name = trim((string) (($user['firstname'] ?? '') ?: ($user['name'] ?? '')));
?>
<h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#14212b"><?= e(t('Восстановление пароля')) ?></h1>
<p style="margin:0 0 12px"><?= e($name !== '' ? t('Здравствуйте, {name}!', ['name' => $name]) : t('Здравствуйте!')) ?></p>
<p style="margin:0 0 12px"><?= e(t('Кто-то (возможно, вы) запросил восстановление пароля для входа в личный кабинет оптового интернет-магазина обуви Tomobuv.')) ?></p>
<p style="margin:0 0 20px"><?= e(t('Чтобы задать новый пароль, нажмите на кнопку. Ссылка действует 1 час.')) ?></p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px"><tr>
  <td style="background:#ff7a1a;border-radius:10px"><a href="<?= e($link) ?>" style="display:inline-block;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 24px;font-size:15px"><?= e(t('Задать новый пароль')) ?></a></td>
</tr></table>
<p style="margin:0 0 12px;font-size:13px;color:#5d6b76"><?= e(t('Если кнопка не открывается, скопируйте ссылку в браузер:')) ?><br><a href="<?= e($link) ?>" style="color:#0b7fc1;word-break:break-all"><?= e($link) ?></a></p>
<p style="margin:0;font-size:13px;color:#5d6b76"><?= e(t('Если вы не запрашивали восстановление — просто проигнорируйте это письмо, пароль останется прежним.')) ?></p>
