<?php
/**
 * Восстановление пароля: шаг 1 — e-mail.
 * @var array $errors @var string $email @var bool $sent @var View $view
 */
use App\Controllers\Front\AuthController as F;
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Вход'), 'url' => '/login/'], ['name' => t('Восстановление пароля')]]]) ?>
    <h1><?= e(t('Восстановление пароля')) ?></h1>
  </div>
  <div class="auth">
    <section class="panel auth-card" aria-labelledby="auth-t">
      <?php if ($sent): ?>
        <div class="auth-done" role="status">
          <span class="ic"><?= icon('mail', 'width:30px;height:30px') ?></span>
          <h2 id="auth-t" class="auth-t"><?= e(t('Проверьте почту')) ?></h2>
          <p><?= t('Если <b>{email}</b> зарегистрирован в магазине, мы отправили на него письмо со ссылкой для смены пароля. Ссылка действует 1 час.', ['email' => e($email)]) ?></p>
          <p class="muted"><?= e(t('Письма нет несколько минут? Проверьте папку «Спам» или позвоните нам — поможем войти.')) ?></p>
          <a class="btn btn-b btn-block" href="/login/"><?= e(t('Вернуться ко входу')) ?></a>
        </div>
      <?php else: ?>
        <h2 id="auth-t" class="auth-t"><?= e(t('Забыли пароль?')) ?></h2>
        <p class="muted auth-lead"><?= e(t('Укажите e-mail, который вы указывали при регистрации или оформлении заказа, — пришлём ссылку для установки нового пароля.')) ?></p>
        <?php if (!empty($errors['form'])): ?><div class="note warn" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
        <form class="form" method="post" action="/forgotpassword/" novalidate data-auth-form>
          <?= csrf_field() ?>
          <div class="field">
            <label for="f-email">E-mail</label>
            <input class="input<?= F::inv($errors, 'email') ?>" id="f-email" type="email" name="email" value="<?= e($email) ?>" required autocomplete="email" placeholder="name@gmail.com" maxlength="190">
            <?= F::err($errors, 'email') ?>
          </div>
          <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
          <button class="btn btn-o btn-block" type="submit"><?= e(t('Восстановить пароль')) ?></button>
        </form>
        <p class="auth-alt"><?= e(t('Вспомнили пароль?')) ?> <a class="link" href="/login/"><?= e(t('Вход')) ?></a></p>
      <?php endif; ?>
    </section>
    <?= $view->partial('front/partials/account-nav', ['mode' => 'benefits']) ?>
  </div>
</div>
