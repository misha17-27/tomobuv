<?php
/**
 * Применения промокода: сводка, график по дням, заказы со ссылками.
 * @var array $c @var array $sum @var array $rows @var App\Core\Paginator $pg @var array $byDay
 */
use App\Controllers\Admin\CouponsController as C;
use App\Services\Coupons;
use App\Services\Orders;

$fmt = static fn($n) => number_format((float) $n, 0, '', ' ');
$n = (int) $sum['n'];
$max = $byDay ? max($byDay) : 1;
$st = Coupons::state($c);
$pill = ['active' => 'ok', 'scheduled' => 'confirmed', 'expired' => 'refunded', 'exhausted' => 'invoiced', 'off' => 'cancelled'];
?>
<nav class="subtabs" aria-label="Разделы промокода">
  <a href="/admin/coupons/<?= (int) $c['id'] ?>/">Настройки</a>
  <a class="on" href="/admin/coupons/<?= (int) $c['id'] ?>/usages/">Применения <i class="cp-cnt"><?= $fmt($n) ?></i></a>
</nav>

<p class="cp-head"><span class="cp-code big"><?= e($c['code']) ?></span> <b><?= e(Coupons::label($c)) ?></b> <span class="pill <?= $pill[$st] ?>"><?= e(Coupons::STATES[$st]) ?></span>
  <?php if ($c['comment']): ?><span class="muted"><?= e($c['comment']) ?></span><?php endif; ?></p>

<div class="stats">
  <div class="stat hot"><span><?= $fmt($c['used']) ?><?= $c['usage_limit'] > 0 ? '<small class="cp-of"> / ' . $fmt($c['usage_limit']) . '</small>' : '' ?></span>Применений<?= $c['usage_limit'] > 0 ? ' из лимита' : '' ?></div>
  <div class="stat"><span><?= e(price_format($sum['disc'], false)) ?></span>Скидок выдано, грн</div>
  <div class="stat"><span><?= e(price_format($sum['total'], false)) ?></span>Сумма заказов, грн</div>
  <div class="stat"><span><?= $n ? e(price_format((float) $sum['total'] / $n, false)) : '—' ?></span>Средний заказ, грн</div>
  <div class="stat"><span><?= $fmt($sum['clients']) ?></span><?= e(plural((int) $sum['clients'], 'Клиент', 'Клиента', 'Клиентов')) ?></div>
</div>

<?php if ($byDay): ?>
<div class="card">
  <div class="card-hd"><h2>Применения за 30 дней</h2><span class="muted"><?= $fmt(array_sum($byDay)) ?></span></div>
  <div class="pad">
    <div class="bars" role="img" aria-label="Применения промокода по дням">
      <?php foreach ($byDay as $d => $v): ?><div class="bar" style="height:<?= $v ? max(4, round($v / $max * 100)) : 1 ?>%;<?= $v ? '' : 'background:var(--line)' ?>" title="<?= e(date('d.m', strtotime($d))) ?>: <?= (int) $v ?>"></div><?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-hd"><h2>Заказы с промокодом</h2><?php if ($n): ?><span class="muted"><?= $fmt($n) ?> <?= plural($n, 'заказ', 'заказа', 'заказов') ?><?= $sum['first'] ? ' с ' . e(date('d.m.Y', strtotime((string) $sum['first']))) : '' ?></span><?php endif; ?></div>
  <?php if (!$rows): ?>
    <div class="empty-card">
      <h2>Промокод ещё не применяли</h2>
      <p>Здесь появятся заказы, оформленные с кодом <?= e($c['code']) ?>, — с суммой, скидкой и клиентом.</p>
      <?php if ($c['used'] > 0): ?><p class="hint">Счётчик показывает <?= $fmt($c['used']) ?> — это применения на старом сайте или до обнуления, заказы по ним не сохранились.</p><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-scroll">
    <table class="grid">
      <thead><tr><th>Заказ</th><th>Дата</th><th>Клиент</th><th class="right opt">Ящиков</th><th class="right">Сумма заказа</th><th class="right">Скидка</th><th>Статус</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $oid = (int) $r['order_id'];
        $phone = (string) ($r['o_phone'] ?: $r['phone']); ?>
        <tr class="<?= $r['status'] === 'new' ? 'unread' : '' ?>">
          <td><?php if ($r['o_id']): ?><a href="/admin/orders/<?= $oid ?>/"><b><?= e(Orders::number($oid)) ?></b></a><?php else: ?><span class="muted">#<?= $oid ?> удалён</span><?php endif; ?></td>
          <td class="nowrap"><?= e(date('d.m.Y H:i', strtotime((string) $r['created_at']))) ?></td>
          <td>
            <?php if ($r['customer_id']): ?><a href="/admin/customers/<?= (int) $r['customer_id'] ?>/"><?= e($r['name'] ?: 'Клиент #' . $r['customer_id']) ?></a><?php else: ?><?= e($r['name'] ?: '—') ?><?php endif; ?>
            <?php if ($phone !== ''): ?><small><?= e(Orders::formatPhone($phone)) ?></small><?php endif; ?>
          </td>
          <td class="right opt"><?= $r['o_id'] ? (int) $r['boxes'] : '—' ?></td>
          <td class="right"><?= $r['o_id'] ? e(price_format($r['total'])) : '—' ?></td>
          <td class="right"><b>−<?= e(price_format($r['discount'])) ?></b></td>
          <td><?php if ($r['status']): ?><span class="st st-<?= e($r['status']) ?>"><?= e(C::ORDER_STATUSES[$r['status']] ?? $r['status']) ?></span><?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?= $pg->html() ?>
