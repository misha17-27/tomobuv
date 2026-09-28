<?php
/**
 * Подтверждение выхода: /logout/ открыли без токена (закладка, ссылка со старого сайта, чужая страница) —
 * сами не разлогиниваем, выход только кнопкой (POST с CSRF-токеном). Страница без кэша, noindex.
 * @var bool $stale @var View $view
 */
use App\Core\Auth;

$u = Auth::user() ?? [];
$who = trim((string) ($u['name'] ?? '')) ?: (string) (($u['email'] ?? '') ?: ($u['phone'] ?? ''));
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Личный кабинет'), 'url' => '/my/orders/'], ['name' => t('Выход')]]]) ?>
    <h1><?= e(t('Выход')) ?></h1>
  </div>
  <div class="auth">
    <section class="panel auth-card" aria-labelledby="auth-t">
      <div class="auth-done">
        <span class="ic"><?= icon('logout', 'width:30px;height:30px') ?></span>
        <h2 id="auth-t" class="auth-t"><?= e(t('Выйти из личного кабинета?')) ?></h2>
        <?php if ($stale): ?><div class="note warn" role="alert"><?= e(t('Страница устарела. Обновите её и попробуйте ещё раз.')) ?></div><?php endif; ?>
        <?php if ($who !== ''): ?><p><?= t('Вы вошли как <b>{name}</b>.', ['name' => e($who)]) ?></p><?php endif; ?>
        <form method="post" action="/logout/" class="btn-block">
          <?= csrf_field() ?>
          <button class="btn btn-o btn-block" type="submit"><?= e(t('Выйти')) ?></button>
        </form>
        <a class="link" href="/my/orders/"><?= e(t('Вернуться в кабинет')) ?></a>
      </div>
    </section>
  </div>
</div>
