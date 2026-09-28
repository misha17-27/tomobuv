<?php
/** @var array $stats @var array $lastOrders @var array $byDay */
use App\Controllers\Admin\OrdersController;
$statusNames = ['new' => 'Новый', 'processing' => 'В обработке', 'paid' => 'Оплачен', 'shipped' => 'Отправлен', 'completed' => 'Выполнен', 'refunded' => 'Возврат', 'deleted' => 'Удалён'];
$max = $byDay ? max($byDay) : 1;
$fmt = static fn($n) => number_format((float) $n, 0, '', ' ');
?>
<div class="stats">
  <a class="stat<?= $stats['orders_new'] ? ' hot' : '' ?>" href="<?= e(OrdersController::freshUrl()) ?>" title="Статус «Новый», оформлены за <?= OrdersController::FRESH_DAYS ?> дней"><span><?= $fmt($stats['orders_new']) ?></span>Новых заказов</a>
  <a class="stat" href="/admin/orders/"><span><?= $fmt($stats['orders_today']) ?></span>Заказов сегодня</a>
  <a class="stat" href="/admin/reports/"><span><?= $fmt($stats['sum_month']) ?></span>Сумма заказов за месяц, грн</a>
  <a class="stat<?= $stats['requests_new'] ? ' hot' : '' ?>" href="/admin/requests/"><span><?= $fmt($stats['requests_new']) ?></span>Новых заявок</a>
  <a class="stat" href="/admin/products/"><span><?= $fmt($stats['products']) ?></span>Товаров на сайте</a>
  <a class="stat" href="/admin/customers/"><span><?= $fmt($stats['customers']) ?></span>Клиентов</a>
</div>
<?php if ($stats['orders_stale']): ?>
<p class="flash warn sl-newhint">Ещё <?= $fmt($stats['orders_stale']) ?> <?= plural($stats['orders_stale'], 'заказ', 'заказа', 'заказов') ?> в статусе «Новый» старше <?= OrdersController::FRESH_DAYS ?> дней —
  в основном необработанные со старого сайта; в плитке и в меню они не считаются.
  <a href="<?= e(OrdersController::staleUrl()) ?>">Открыть и закрыть массово</a></p>
<?php endif; ?>

<div class="grid2">
  <div class="card">
    <div class="card-hd"><h2>Заказы за 30 дней</h2><a href="/admin/reports/">Отчёты</a></div>
    <div class="pad">
      <?php if ($byDay): ?>
        <div class="bars" role="img" aria-label="Количество заказов по дням">
          <?php foreach ($byDay as $d => $n): ?><div class="bar" style="height:<?= $n ? max(4, round($n / $max * 100)) : 1 ?>%;<?= $n ? '' : 'background:var(--line)' ?>" title="<?= e(date('d.m', strtotime($d))) ?>: <?= (int) $n ?>"></div><?php endforeach; ?>
        </div>
      <?php else: ?><p class="muted">За последние 30 дней заказов нет — они появятся здесь, как только клиенты начнут оформлять.</p><?php endif; ?>
      <p class="hint">Скрыто товаров: <?= $fmt($stats['hidden']) ?> · нет в наличии: <?= $fmt($stats['out_of_stock']) ?></p>
    </div>
  </div>
  <div class="card">
    <div class="card-hd"><h2>Быстрые действия</h2></div>
    <div class="quick">
      <a href="/admin/products/new/">Добавить товар</a>
      <a href="/admin/import/">Загрузить прайс</a>
      <a href="/admin/orders/new/">Заказ по телефону</a>
      <a href="/admin/blog/new/">Написать статью</a>
      <a href="/admin/banners/">Баннеры главной</a>
      <a href="/admin/settings/">Контакты и телефоны</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-hd"><h2>Последние заказы</h2><a href="/admin/orders/">Все заказы</a></div>
  <?php if ($lastOrders): ?>
  <div class="table-scroll"><table class="grid"><thead><tr><th>Заказ</th><th>Клиент</th><th class="right">Ящиков</th><th class="right">Сумма</th><th>Статус</th><th>Дата</th></tr></thead><tbody>
  <?php foreach ($lastOrders as $o): ?>
    <tr class="<?= $o['status'] === 'new' ? 'unread' : '' ?>">
      <td><a href="/admin/orders/<?= (int) $o['id'] ?>/"><b>#100<?= (int) $o['id'] ?></b></a></td>
      <td><?= e($o['name'] ?: '—') ?><small><?= e($o['phone']) ?></small></td>
      <td class="right"><?= (int) $o['boxes'] ?></td>
      <td class="right"><?= e(price_format($o['total'])) ?></td>
      <td><span class="st st-<?= e($o['status']) ?>"><?= e($statusNames[$o['status']] ?? $o['status']) ?></span></td>
      <td><?= e(date('d.m.Y H:i', strtotime((string) $o['created_at']))) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><div class="empty-card"><h2>Заказов пока нет</h2><p>Они появятся здесь, как только клиент оформит заказ.</p></div><?php endif; ?>
</div>
