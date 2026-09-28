<?php
/**
 * Форма оформления заказа (шаги 2–4 страницы /cart/): контакты, доставка, оплата.
 * Подключается из front/cart; отправляется на /order/ (без JS — обычный POST, с JS — AJAX, см. js/checkout.js).
 * Поля как на старом сайте: имя, телефон, e-mail, область, город, способ доставки + отделение/адрес, оплата, комментарий.
 * @var array $form @var array $errors @var ?array $user @var array $shipping @var array $payment @var int $free
 */
use App\Services\Orders;

$ship = $shipping[$form['shipping']] ?? null;
$pickup = $ship && $ship['pickup'];
// сообщение об ошибке под полем и aria-атрибуты поля
$err = static fn(string $k): string => '<div class="errtxt" id="e-' . $k . '" role="alert"' . (isset($errors[$k]) ? '>' . e($errors[$k]) : ' hidden>') . '</div>';
$aria = static fn(string $k): string => ' aria-describedby="e-' . $k . '"' . (isset($errors[$k]) ? ' aria-invalid="true"' : '');
$bad = static fn(string $k): string => isset($errors[$k]) ? ' err' : '';
?>
<form class="co-form" id="order" method="post" action="/order/" novalidate data-checkout data-guest="<?= $user ? 0 : 1 ?>">
  <?= csrf_field() ?>
  <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
  <div class="note warn co-formerr" role="alert" data-form-error<?= isset($errors['_form']) ? '' : ' hidden' ?>><?= e($errors['_form'] ?? '') ?></div>

  <section class="panel co-sec" aria-labelledby="co-h2">
    <div class="co-sec-h"><span class="co-step" aria-hidden="true">2</span><h2 id="co-h2"><?= e(t('Контактные данные')) ?></h2></div>
    <?php if ($user): ?>
      <p class="co-hint"><?= t('Вы вошли как {name} — заказ появится в {link}.', [
          'name' => '<b>' . e($form['name'] !== '' ? $form['name'] : ($user['email'] ?? '')) . '</b>',
          'link' => '<a class="link" href="/my/orders/">' . e(t('личном кабинете')) . '</a>']) ?></p>
    <?php else: ?>
      <p class="co-hint"><?= t('Уже покупали у нас? {link} — данные подставятся автоматически. Регистрация не обязательна.', [
          'link' => '<a class="link" href="/login/?back=' . e(rawurlencode('/cart/')) . '">' . e(t('Войдите')) . '</a>']) ?></p>
    <?php endif; ?>
    <div class="form">
      <div class="row2">
        <div class="field">
          <label for="co-name"><?= e(t('Имя и фамилия')) ?> <span class="req">*</span></label>
          <input class="input<?= $bad('name') ?>" id="co-name" name="name" value="<?= e($form['name']) ?>" required maxlength="100" autocomplete="name"<?= $aria('name') ?>>
          <?= $err('name') ?>
        </div>
        <div class="field">
          <label for="co-phone"><?= e(t('Телефон')) ?> <span class="req">*</span></label>
          <input class="input<?= $bad('phone') ?>" id="co-phone" name="phone" type="tel" value="<?= e($form['phone']) ?>" required maxlength="40" inputmode="tel" autocomplete="tel" placeholder="+38 (0__) ___-__-__" data-phone<?= $aria('phone') ?>>
          <?= $err('phone') ?>
        </div>
      </div>
      <div class="field">
        <label for="co-email">E-mail</label>
        <input class="input<?= $bad('email') ?>" id="co-email" name="email" type="email" value="<?= e($form['email']) ?>" maxlength="190" autocomplete="email" placeholder="name@gmail.com"<?= $aria('email') ?>>
        <span class="hint"><?= e(t('Пришлём подтверждение и состав заказа')) ?></span>
        <?= $err('email') ?>
      </div>
    </div>
  </section>

  <section class="panel co-sec" aria-labelledby="co-h3">
    <div class="co-sec-h"><span class="co-step" aria-hidden="true">3</span><h2 id="co-h3"><?= e(t('Доставка')) ?></h2></div>
    <p class="co-hint"><?= e(t('Отправляем по всей Украине. При заказе от {n} ящ. — доставка бесплатная.', ['n' => $free])) ?></p>
    <fieldset class="co-fs"<?= $aria('shipping') ?>>
      <legend class="visually-hidden"><?= e(t('Способ доставки')) ?></legend>
      <div class="opt-cards co-opts">
        <?php foreach ($shipping as $m): ?>
          <label class="opt-card">
            <input type="radio" name="shipping" value="<?= e($m['code']) ?>"<?= $m['code'] === $form['shipping'] ? ' checked' : '' ?>
              data-pickup="<?= $m['pickup'] ? 1 : 0 ?>" data-kind="<?= e($m['kind']) ?>" data-field="<?= e($m['field']) ?>" data-ph="<?= e($m['placeholder']) ?>" data-req="<?= $m['required'] ? 1 : 0 ?>">
            <span><b><?= e($m['name']) ?></b><small><?= e($m['description'] !== '' ? $m['description'] . ' · ' . $m['rate'] : $m['rate']) ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?= $err('shipping') ?>
    </fieldset>
    <div class="form co-addr">
      <div class="row2" data-geo<?= $pickup ? ' hidden' : '' ?>>
        <div class="field">
          <label for="co-region"><?= e(t('Область')) ?></label>
          <select class="select" id="co-region" name="region" autocomplete="address-level1">
            <option value=""><?= e(t('— выберите —')) ?></option>
            <?php foreach (Orders::REGIONS as $code => $rn): ?><option value="<?= e((string) $code) ?>"<?= (string) $code === $form['region'] ? ' selected' : '' ?>><?= e(t($rn)) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="co-city"><?= e(t('Город')) ?> <span class="req">*</span></label>
          <input class="input<?= $bad('city') ?>" id="co-city" name="city" value="<?= e($form['city']) ?>" maxlength="100" autocomplete="address-level2" placeholder="<?= e(t('Например: Одесса')) ?>"<?= $aria('city') ?>>
          <?= $err('city') ?>
        </div>
      </div>
      <div class="field">
        <label for="co-address"><span data-field-label><?= e($ship['field'] ?? t('Номер отделения / склада')) ?></span> <span class="req" data-field-req<?= ($ship['required'] ?? true) ? '' : ' hidden' ?>>*</span></label>
        <input class="input<?= $bad('address') ?>" id="co-address" name="address" value="<?= e($form['address']) ?>" maxlength="255" placeholder="<?= e($ship['placeholder'] ?? '') ?>"<?= $aria('address') ?>>
        <?= $err('address') ?>
      </div>
    </div>
  </section>

  <section class="panel co-sec" aria-labelledby="co-h4">
    <div class="co-sec-h"><span class="co-step" aria-hidden="true">4</span><h2 id="co-h4"><?= e(t('Оплата')) ?></h2></div>
    <fieldset class="co-fs"<?= $aria('payment') ?>>
      <legend class="visually-hidden"><?= e(t('Способ оплаты')) ?></legend>
      <div class="opt-cards">
        <?php foreach ($payment as $m): ?>
          <label class="opt-card">
            <input type="radio" name="payment" value="<?= e($m['code']) ?>"<?= $m['code'] === $form['payment'] ? ' checked' : '' ?>>
            <span><b><?= e($m['name']) ?></b><?php if ($m['description'] !== ''): ?><small><?= e($m['description']) ?></small><?php endif; ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?= $err('payment') ?>
    </fieldset>
    <div class="form co-comment">
      <div class="field">
        <label for="co-comment"><?= e(t('Комментарий к заказу')) ?></label>
        <textarea class="textarea" id="co-comment" name="comment" maxlength="2000" rows="3" placeholder="<?= e(t('Ваши пожелания или замечания к сборке или составу заказа')) ?>"><?= e($form['comment']) ?></textarea>
      </div>
      <div class="field">
        <label class="check co-agree"><input type="checkbox" name="agree" value="1"<?= $form['agree'] ? ' checked' : '' ?><?= $aria('agree') ?>><span><?= t('Я согласен с {link} и даю согласие на обработку персональных данных', [
            'link' => '<a class="link" href="/usloviya-sotrudnichestva/" target="_blank" rel="noopener">' . e(t('условиями сотрудничества')) . '</a>']) ?> <span class="req">*</span></span></label>
        <?= $err('agree') ?>
      </div>
    </div>
  </section>
  <noscript><div class="panel co-nojs"><button class="btn btn-o btn-block" type="submit"><?= e(t('Оформить заказ')) ?></button></div></noscript>
</form>
