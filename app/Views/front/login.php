<?php
/**
 * Вход в личный кабинет.
 * @var array $errors @var string $login @var string $back @var View $view
 */
use App\Controllers\Front\AuthController as F;

$q = $back !== '' ? '?back=' . rawurlencode($back) : '';
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Вход')]]]) ?>
    <h1><?= e(t('Логин')) ?></h1><?php /* H1 как на старом сайте; title — «Вход» */ ?>
  </div>
  <div class="auth">
    <section class="panel auth-card" aria-labelledby="auth-t">
      <h2 id="auth-t" class="auth-t"><?= e(t('Вход в личный кабинет')) ?></h2>
      <?php if (preg_match('#^(/ua)?/my/#', $back) && !$errors): ?><div class="note"><?= e(t('Войдите, чтобы открыть личный кабинет.')) ?></div><?php endif; ?>
      <?php if (!empty($errors['form'])): ?><div class="note warn" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
      <form class="form" method="post" action="/login/<?= e($q) ?>" novalidate data-auth-form>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="field">
          <label for="f-login"><?= e(t('E-mail или телефон')) ?></label>
          <input class="input<?= F::inv($errors, 'login') ?>" id="f-login" name="login" value="<?= e($login) ?>" required autocomplete="username" placeholder="<?= e(t('name@gmail.com или 093 275 30 70')) ?>" maxlength="190">
          <?= F::err($errors, 'login') ?>
        </div>
        <div class="field">
          <label for="f-password"><?= e(t('Пароль')) ?></label>
          <div class="pw">
            <input class="input<?= F::inv($errors, 'password') ?>" id="f-password" type="password" name="password" required autocomplete="current-password">
            <button type="button" class="pw-tg" aria-label="<?= e(t('Показать пароль')) ?>" aria-pressed="false"><?= icon('eye') ?></button>
          </div>
          <?= F::err($errors, 'password') ?>
        </div>
        <div class="auth-row"><a class="link" href="/forgotpassword/"><?= e(t('Забыли пароль?')) ?></a></div>
        <button class="btn btn-o btn-block" type="submit"><?= e(t('Войти')) ?></button>
      </form>
      <p class="auth-alt"><?= e(t('Впервые у нас?')) ?> <a class="link" href="/signup/<?= e($q) ?>"><?= e(t('Зарегистрироваться')) ?></a></p>
    </section>
    <?= $view->partial('front/partials/account-nav', ['mode' => 'benefits']) ?>
  </div>
</div>
