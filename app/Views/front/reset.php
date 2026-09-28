<?php
/**
 * Восстановление пароля: шаг 2 — новый пароль по ссылке из письма.
 * @var array $errors @var string $token @var bool $valid @var string $who @var View $view
 */
use App\Controllers\Front\AuthController as F;
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Вход'), 'url' => '/login/'], ['name' => t('Новый пароль')]]]) ?>
    <h1><?= e(t('Восстановление пароля')) ?></h1>
  </div>
  <div class="auth">
    <section class="panel auth-card" aria-labelledby="auth-t">
      <?php if (!$valid): ?>
        <div class="auth-done">
          <span class="ic warn"><?= icon('info', 'width:30px;height:30px') ?></span>
          <h2 id="auth-t" class="auth-t"><?= e(t('Ссылка недействительна')) ?></h2>
          <p><?= e(t('Ссылка для смены пароля устарела или уже была использована. Запросите новую — это займёт минуту.')) ?></p>
          <a class="btn btn-o btn-block" href="/forgotpassword/"><?= e(t('Получить новую ссылку')) ?></a>
        </div>
      <?php else: ?>
        <h2 id="auth-t" class="auth-t"><?= e(t('Придумайте новый пароль')) ?></h2>
        <?php if ($who !== ''): ?><p class="muted auth-lead"><?= t('Для входа с e-mail <b>{email}</b>', ['email' => e($who)]) ?></p><?php endif; ?>
        <?php if (!empty($errors['form'])): ?><div class="note warn" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
        <form class="form" method="post" action="/forgotpassword/reset/" novalidate data-auth-form>
          <?= csrf_field() ?>
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <input type="text" name="username" value="<?= e($who) ?>" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true">
          <div class="field">
            <label for="f-password"><?= e(t('Новый пароль')) ?></label>
            <div class="pw">
              <input class="input<?= F::inv($errors, 'password') ?>" id="f-password" type="password" name="password" required minlength="<?= F::MIN_PASSWORD ?>" autocomplete="new-password">
              <button type="button" class="pw-tg" aria-label="<?= e(t('Показать пароль')) ?>" aria-pressed="false"><?= icon('eye') ?></button>
            </div>
            <?= F::err($errors, 'password') ?>
            <?php if (!isset($errors['password'])): ?><div class="hint"><?= e(t('Не меньше {n} символов', ['n' => F::MIN_PASSWORD])) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <label for="f-password2"><?= e(t('Повторите пароль')) ?></label>
            <input class="input<?= F::inv($errors, 'password2') ?>" id="f-password2" type="password" name="password2" required autocomplete="new-password">
            <?= F::err($errors, 'password2') ?>
          </div>
          <button class="btn btn-o btn-block" type="submit"><?= e(t('Сохранить пароль и войти')) ?></button>
        </form>
      <?php endif; ?>
    </section>
    <?= $view->partial('front/partials/account-nav', ['mode' => 'benefits']) ?>
  </div>
</div>
