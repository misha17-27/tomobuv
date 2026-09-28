<?php
/**
 * Вкладка «Почта»: адрес для уведомлений (settings notify_email; тот же адрес пишется в mail.admin_to,
 * его читает Mailer::adminEmail() и правит экран «Почта (SMTP)»).
 * Параметры отправки (SMTP, тест) — /admin/mail/, уведомления в WhatsApp — /admin/whatsapp/ (здесь только ссылки).
 * @var array $values @var array $mail @var array $errors
 */
$inv = isset($errors['notify_email']);
?>
<div class="grid2">
  <div class="card">
    <h2>Уведомления на e-mail</h2>
    <div class="fld">
      <label class="lbl" for="notify-email">E-mail для уведомлений о заказах и заявках</label>
      <input id="notify-email" type="email" name="notify_email" value="<?= e($values['notify_email'] ?? '') ?>" maxlength="190" placeholder="<?= e($mail['store_email'] ?: 'tomobuv@gmail.com') ?>" autocomplete="off"<?= $inv ? ' aria-invalid="true"' : '' ?>>
      <?php if ($inv): ?><span class="fld-err" role="alert"><?= e($errors['notify_email']) ?></span><?php endif; ?>
      <small class="hint">Один адрес. На него приходят письма о новых заказах, «купить в 1 клик», обратных звонках и сообщениях с сайта. Пусто — на e-mail магазина<?= $mail['store_email'] !== '' ? ' (' . e($mail['store_email']) . ')' : '' ?>.</small>
    </div>
    <dl class="detail">
      <div><dt>Письма уходят на</dt><dd><?= $mail['admin'] !== '' ? e($mail['admin']) : '<span class="warn-txt">адрес не задан — укажите e-mail</span>' ?></dd></div>
    </dl>
  </div>
  <div class="card">
    <h2>Отправка писем и WhatsApp</h2>
    <dl class="detail">
      <div><dt>Адрес отправителя</dt><dd><?= $mail['from'] !== '' ? e($mail['from']) : '<span class="muted">не задан</span>' ?></dd></div>
      <div><dt>Способ отправки</dt><dd><?= $mail['smtp'] !== '' ? 'SMTP: ' . e($mail['smtp']) : 'функция mail() хостинга' ?></dd></div>
    </dl>
    <p class="hint">SMTP-сервер, логин и пароль, тестовое письмо — на отдельном экране. Уведомления о заказах в WhatsApp — тоже.</p>
    <p class="link-row"><a class="btn" href="/admin/mail/">Почта (SMTP) →</a><a class="btn" href="/admin/whatsapp/">WhatsApp →</a></p>
  </div>
</div>
