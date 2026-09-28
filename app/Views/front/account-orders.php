<?php
/**
 * Кабинет: список заказов покупателя.
 * @var array $orders @var App\Core\Paginator $pg @var int $total @var ?string $flash @var View $view
 */
use App\Controllers\Front\AccountController as A;
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Личный кабинет'), 'url' => '/my/orders/'], ['name' => t('Мои заказы')]]]) ?>
    <h1><?= e(t('Мои заказы')) ?></h1>
    <?php if ($total): ?><p class="acc-sub"><?= e(t('Всего заказов: {n}', ['n' => $total])) ?></p><?php endif; ?>
  </div>
  <div class="layout-2 acc">
    <?= $view->partial('front/partials/account-nav', ['active' => 'orders']) ?>
    <div class="acc-main">
      <?php if (!empty($flash)): ?><div class="note ok acc-flash" role="status"><?= e($flash) ?></div><?php endif; ?>
      <?php if (!$orders): ?>
        <div class="empty-state">
          <div class="ic"><?= icon('box', 'width:30px;height:30px') ?></div>
          <h2><?= e(t('Заказов пока нет')) ?></h2>
          <p><?= e(t('Здесь появятся ваши заказы: статус, состав и сумма.')) ?><br><?= e(t('Подберите обувь в каталоге — от одного ящика.')) ?></p>
          <a class="btn btn-o" href="/category/dyetskaya-obuv/"><?= e(t('Перейти в каталог')) ?></a>
        </div>
      <?php else: ?>
        <div class="tblwrap">
          <table class="tbl acc-orders">
            <thead><tr>
              <th scope="col"><?= e(t('Заказ')) ?></th>
              <th scope="col" class="hide-m"><?= e(t('Дата')) ?></th>
              <th scope="col"><?= e(t('Статус')) ?></th>
              <th scope="col" class="num hide-m"><?= e(t('Ящиков')) ?></th>
              <th scope="col" class="num"><?= e(t('Сумма')) ?></th>
              <th scope="col" class="hide-m"><span class="visually-hidden"><?= e(t('Подробнее')) ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($orders as $o): $url = '/my/order/' . (int) $o['id'] . '/'; ?>
              <tr>
                <td><a class="link" href="<?= e($url) ?>"><?= e(A::number((int) $o['id'])) ?></a><small class="acc-m-date"><?= e(A::date($o['created_at'], false)) ?></small></td>
                <td class="hide-m"><?= e(A::date($o['created_at'])) ?></td>
                <td><span class="acc-st acc-st-<?= e($o['status']) ?>"><?= e(A::statusName((string) $o['status'])) ?></span></td>
                <td class="num hide-m"><?= (int) $o['boxes'] ?: '—' ?></td>
                <td class="num"><b><?= e(A::money($o['total'], (string) $o['currency'])) ?></b></td>
                <td class="hide-m"><a class="btn btn-g btn-sm" href="<?= e($url) ?>"><?= e(t('Подробнее')) ?></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= $pg->html() ?>
      <?php endif; ?>
    </div>
  </div>
</div>
