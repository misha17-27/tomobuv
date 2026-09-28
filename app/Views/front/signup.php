<?php
/**
 * Регистрация покупателя (поля как на старом сайте: имя, фамилия, пароль, e-mail, телефон).
 * @var array $errors @var array $d (firstname, lastname, phone, email) @var string $back @var View $view
 */
use App\Controllers\Front\AuthController as F;

$q = $back !== '' ? '?back=' . rawurlencode($back) : '';
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Регистрация')]]]) ?>
    <h1><?= e(t('Регистрация')) ?></h1>
  </div>
  <div class="auth">
    <section class="panel auth-card" aria-labelledby="auth-t">
      <h2 id="auth-t" class="auth-t"><?= e(t('Новый покупатель')) ?></h2>
      <?php if (!empty($errors['form'])): ?><div class="note warn" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
      <form class="form" method="post" action="/signup/<?= e($q) ?>" novalidate data-auth-form>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="row2">
          <div class="field">
            <label for="f-firstname"><?= e(t('Имя')) ?></label>
            <input class="input" id="f-firstname" name="firstname" value="<?= e($d['firstname']) ?>" autocomplete="given-name" maxlength="100">
          </div>
          <div class="field">
            <label for="f-lastname"><?= e(t('Фамилия')) ?></label>
            <input class="input" id="f-lastname" name="lastname" value="<?= e($d['lastname']) ?>" autocomplete="family-name" maxlength="100">
          </div>
        </div>
        <div class="field">
          <label for="f-phone"><?= e(t('Телефон')) ?> <span class="req">*</span></label>
          <input class="input<?= F::inv($errors, 'phone') ?>" id="f-phone" type="tel" name="phone" value="<?= e($d['phone']) ?>" required autocomplete="tel" placeholder="+38 (0__) ___-__-__" maxlength="32">
          <?= F::err($errors, 'phone') ?>
        </div>
        <div class="field">
          <label for="f-email">E-mail <span class="req">*</span></label>
          <input class="input<?= F::inv($errors, 'email') ?>" id="f-email" type="email" name="email" value="<?= e($d['email']) ?>" required autocomplete="email" placeholder="name@gmail.com" maxlength="190">
          <?= F::err($errors, 'email') ?>
          <?php if (!empty($errors['email_exists'])): ?><div class="auth-links"><a class="link" href="/login/<?= e($q) ?>"><?= e(t('Войти')) ?></a><a class="link" href="/forgotpassword/"><?= e(t('Восстановить пароль')) ?></a></div><?php endif; ?>
        </div>
        <div class="row2">
          <div class="field">
            <label for="f-password"><?= e(t('Пароль')) ?> <span class="req">*</span></label>
            <div class="pw">
              <input class="input<?= F::inv($errors, 'password') ?>" id="f-password" type="password" name="password" required minlength="<?= F::MIN_PASSWORD ?>" autocomplete="new-password">
              <button type="button" class="pw-tg" aria-label="<?= e(t('Показать пароль')) ?>" aria-pressed="false"><?= icon('eye') ?></button>
            </div>
            <?= F::err($errors, 'password') ?>
            <?php if (!isset($errors['password'])): ?><div class="hint"><?= e(t('Не меньше {n} символов', ['n' => F::MIN_PASSWORD])) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <label for="f-password2"><?= e(t('Подтвердите пароль')) ?> <span class="req">*</span></label>
            <input class="input<?= F::inv($errors, 'password2') ?>" id="f-password2" type="password" name="password2" required autocomplete="new-password">
            <?= F::err($errors, 'password2') ?>
          </div>
        </div>
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button class="btn btn-o btn-block" type="submit"><?= e(t('Зарегистрироваться')) ?></button>
      </form>
      <p class="auth-alt"><?= e(t('Уже регистрировались?')) ?> <a class="link" href="/login/<?= e($q) ?>"><?= e(t('Вход')) ?></a></p>
    </section>
    <?= $view->partial('front/partials/account-nav', ['mode' => 'benefits']) ?>
  </div>
</div>
