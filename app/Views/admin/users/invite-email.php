<?php
/**
 * Тело письма-приглашения сотруднику (вставляется в emails/layout). Пароль в письме не передаётся.
 * @var array $u (name, email) @var string $role @var string $store @var string $link @var string $admin @var int $hours @var string $from
 */
$name = trim((string) ($u['name'] ?? ''));
?>
<p style="margin:0 0 12px;font-size:15px;line-height:1.6">Здравствуйте<?= $name !== '' ? ', ' . e($name) : '' ?>!</p>
<p style="margin:0 0 12px;font-size:15px;line-height:1.6"><?= $from !== '' ? e($from) . ' открыл(а)' : 'Вам открыт' ?> вам доступ в админку интернет-магазина <?= e($store) ?> — роль «<?= e($role) ?>».</p>
<p style="margin:0 0 6px;font-size:15px;line-height:1.6">Ваш логин: <b><?= e((string) $u['email']) ?></b></p>
<p style="margin:0 0 18px;font-size:15px;line-height:1.6">Сначала задайте себе надёжный пароль — от 10 символов, не такой, как на других сайтах. Ссылка действует <?= (int) $hours ?> <?= e(plural((int) $hours, 'час', 'часа', 'часов')) ?>:</p>
<p style="margin:0 0 20px"><a href="<?= e($link) ?>" style="display:inline-block;background:#ff7a1a;color:#fff;text-decoration:none;font-weight:bold;padding:12px 24px;border-radius:10px">Задать пароль</a></p>
<p style="margin:0 0 12px;font-size:15px;line-height:1.6">После этого входите в админку по адресу <a href="<?= e($admin) ?>" style="color:#0b7fc1"><?= e($admin) ?></a> с e-mail и новым паролем.</p>
<p style="margin:0 0 12px;font-size:13px;color:#5d6b76;line-height:1.5">Если кнопка не открывается, скопируйте ссылку в браузер:<br><a href="<?= e($link) ?>" style="color:#0b7fc1;word-break:break-all"><?= e($link) ?></a></p>
<p style="margin:0;font-size:13px;color:#5d6b76;line-height:1.5">Если вы не ждали этого письма — просто проигнорируйте его и сообщите администратору магазина.</p>
