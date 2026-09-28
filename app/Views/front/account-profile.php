<?php
/**
 * Кабинет: профиль покупателя и смена пароля.
 * @var array $d @var array $errors @var array $pwErrors @var bool $hasPassword @var int $minPassword @var ?string $flash @var View $view
 */
use App\Controllers\Front\AuthController as F;
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Личный кабинет'), 'url' => '/my/orders/'], ['name' => t('Профиль')]]]) ?>
    <h1><?= e(t('Профиль')) ?></h1>
  </div>
  <div class="layout-2 acc">
    <?= $view->partial('front/partials/account-nav', ['active' => 'profile']) ?>
    <div class="acc-main">
      <?php if (!empty($flash)): ?><div class="note ok acc-flash" role="status"><?= e($flash) ?></div><?php endif; ?>

      <section class="panel" aria-labelledby="p-data">
        <h2 id="p-data"><?= e(t('Контактные данные')) ?></h2>
        <?php if (!empty($errors['form'])): ?><div class="note warn" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
        <form class="form acc-form" method="post" action="/my/profile/" novalidate data-auth-form>
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="profile">
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
          <div class="row2">
            <div class="field">
              <label for="f-phone"><?= e(t('Телефон')) ?></label>
              <input class="input<?= F::inv($errors, 'phone') ?>" id="f-phone" type="tel" name="phone" value="<?= e($d['phone']) ?>" autocomplete="tel" placeholder="+38 (0__) ___-__-__" maxlength="32">
              <?= F::err($errors, 'phone') ?>
            </div>
            <div class="field">
              <label for="f-email">E-mail</label>
              <input class="input<?= F::inv($errors, 'email') ?>" id="f-email" type="email" name="email" value="<?= e($d['email']) ?>" autocomplete="email" placeholder="name@gmail.com" maxlength="190">
              <?= F::err($errors, 'email') ?>
            </div>
          </div>
          <div class="row2">
            <div class="field">
              <label for="f-company"><?= e(t('Компания / магазин')) ?></label>
              <input class="input" id="f-company" name="company" value="<?= e($d['company']) ?>" autocomplete="organization" maxlength="190">
            </div>
            <div class="field">
              <label for="f-city"><?= e(t('Город')) ?></label>
              <input class="input" id="f-city" name="city" value="<?= e($d['city']) ?>" autocomplete="address-level2" maxlength="190">
            </div>
          </div>
          <p class="hint"><?= e(t('По телефону или e-mail вы входите в кабинет. На e-mail придёт ссылка, если забудете пароль.')) ?></p>
          <div><button class="btn btn-o" type="submit"><?= e(t('Сохранить')) ?></button></div>
        </form>
      </section>

      <section class="panel" aria-labelledby="p-pass" id="password">
        <h2 id="p-pass"><?= e(t('Смена пароля')) ?></h2>
        <?php if (!empty($pwErrors['form'])): ?><div class="note warn" role="alert"><?= e($pwErrors['form']) ?></div><?php endif; ?>
        <?php if (!$hasPassword): ?><p class="muted acc-lead"><?= e(t('Пароль ещё не задан — придумайте его, чтобы входить по e-mail или телефону.')) ?></p><?php endif; ?>
        <form class="form acc-form" method="post" action="/my/profile/#password" novalidate data-auth-form>
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="password">
          <input type="text" name="username" value="<?= e($d['email'] ?: $d['phone']) ?>" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true">
          <?php if ($hasPassword): ?>
            <div class="field acc-f-half">
              <label for="f-current"><?= e(t('Текущий пароль')) ?></label>
              <div class="pw">
                <input class="input<?= F::inv($pwErrors, 'current') ?>" id="f-current" type="password" name="current" required autocomplete="current-password">
                <button type="button" class="pw-tg" aria-label="<?= e(t('Показать пароль')) ?>" aria-pressed="false"><?= icon('eye') ?></button>
              </div>
              <?= F::err($pwErrors, 'current') ?>
            </div>
          <?php endif; ?>
          <div class="row2">
            <div class="field">
              <label for="f-password"><?= e(t('Новый пароль')) ?></label>
              <div class="pw">
                <input class="input<?= F::inv($pwErrors, 'password') ?>" id="f-password" type="password" name="password" required minlength="<?= (int) ($minPassword ?? F::MIN_PASSWORD) ?>" autocomplete="new-password">
                <button type="button" class="pw-tg" aria-label="<?= e(t('Показать пароль')) ?>" aria-pressed="false"><?= icon('eye') ?></button>
              </div>
              <?= F::err($pwErrors, 'password') ?>
              <?php if (!isset($pwErrors['password'])): ?><div class="hint"><?= e(t('Не меньше {n} символов', ['n' => (int) ($minPassword ?? F::MIN_PASSWORD)])) ?></div><?php endif; ?>
            </div>
            <div class="field">
              <label for="f-password2"><?= e(t('Повторите новый пароль')) ?></label>
              <input class="input<?= F::inv($pwErrors, 'password2') ?>" id="f-password2" type="password" name="password2" required autocomplete="new-password">
              <?= F::err($pwErrors, 'password2') ?>
            </div>
          </div>
          <div><button class="btn btn-b" type="submit"><?= e(t('Изменить пароль')) ?></button></div>
        </form>
      </section>
    </div>
  </div>
</div>
