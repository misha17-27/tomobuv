<?php
/**
 * Меню личного кабинета (боковая колонка).
 *   $view->partial('front/partials/account-nav', ['active' => 'orders'])   — меню кабинета (orders | order | profile)
 *   $view->partial('front/partials/account-nav', ['mode' => 'benefits'])  — блок «Зачем кабинет» на страницах входа/регистрации
 * @var ?string $active @var ?string $mode
 */
use App\Core\Auth;
use App\Core\Settings;

$mode ??= 'nav';
$active ??= '';

if ($mode === 'benefits'):
    $phones = Settings::json('phones', []);
?>
<aside class="panel auth-info" aria-label="<?= e(t('Возможности личного кабинета')) ?>">
  <h2><?= e(t('Личный кабинет оптовика')) ?></h2>
  <ul class="acc-ben">
    <li><span class="ic"><?= icon('history') ?></span><span><b><?= e(t('История заказов')) ?></b><?= e(t('Статусы, состав и суммы всех ваших заказов в одном месте')) ?></span></li>
    <li><span class="ic"><?= icon('refresh') ?></span><span><b><?= e(t('Повтор заказа')) ?></b><?= e(t('Добавьте позиции прошлого заказа в корзину одной кнопкой')) ?></span></li>
    <li><span class="ic"><?= icon('user') ?></span><span><b><?= e(t('Ваши данные')) ?></b><?= e(t('Имя, телефон, компания и город — всегда под рукой')) ?></span></li>
    <li><span class="ic"><?= icon('box') ?></span><span><b><?= e(t('Оптом ящиками')) ?></b><?= e(t('Цены за пару и за ящик, бесплатная доставка от {n} ящиков', ['n' => (int) Settings::get('free_shipping_boxes', 20)])) ?></span></li>
  </ul>
  <?php if ($phones): ?>
    <div class="acc-help"><?= icon('phone', 'width:18px;height:18px') ?><span><?= e(t('Нужна помощь со входом?')) ?><br><?php foreach ($phones as $i => $p): ?><?= $i ? ', ' : '' ?><a class="link" href="tel:+<?= e(preg_replace('/\D/', '', $p)) ?>"><?= e($p) ?></a><?php endforeach; ?></span></div>
  <?php endif; ?>
</aside>
<?php
    return;
endif;

$u = Auth::user() ?? [];
$name = trim((string) ($u['name'] ?? '')) ?: trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
$contact = (string) (($u['email'] ?? '') ?: \App\Controllers\Front\AccountController::phoneView((string) ($u['phone'] ?? '')));
$initial = mb_strtoupper(mb_substr($name !== '' ? $name : ($contact !== '' ? $contact : 'T'), 0, 1));
$items = [
    ['orders', '/my/orders/', 'box', t('Мои заказы')],
    ['profile', '/my/profile/', 'user', t('Профиль')],
    ['fav', '/search/?_balance_type=favorites', 'heart', t('Избранное')],
    ['cmp', '/compare/', 'cmp', t('Сравнение')],
];
?>
<aside class="acc-side">
  <div class="panel acc-user">
    <span class="av" aria-hidden="true"><?= e($initial) ?></span>
    <div class="who"><b><?= e($name !== '' ? $name : t('Покупатель')) ?></b><?php if ($contact !== ''): ?><small><?= e($contact) ?></small><?php endif; ?></div>
  </div>
  <nav class="panel side-nav acc-nav" aria-label="<?= e(t('Личный кабинет')) ?>">
    <?php foreach ($items as [$key, $href, $ic, $label]): $on = $key === $active || ($key === 'orders' && $active === 'order'); ?>
      <a href="<?= e($href) ?>"<?= $on ? ' class="on"' . ($key === $active ? ' aria-current="page"' : '') : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
    <?php /* выход — только с токеном (Auth::logoutToken): меню кабинета не кэшируется */ ?>
    <a href="/logout/?t=<?= e(Auth::logoutToken()) ?>" class="acc-out" rel="nofollow"><?= icon('logout') ?><span><?= e(t('Выйти')) ?></span></a>
  </nav>
</aside>
