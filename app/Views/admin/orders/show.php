<?php
/**
 * Карточка заказа.
 * @var array $order @var string $num @var array $items @var array $log @var ?array $customer @var array $legacy @var string $addr
 * @var array $shipping @var array $payment @var ?array $coupon промокод (Coupons::forOrder; 'released' — отменён вместе с заказом)
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\OrdersController as O;

$oid = (int) $order['id'];
$st = (string) $order['status'];
$shipName = O::methodName($order['shipping_method'], $order['shipping_name'], $shipping);
$payName = O::methodName($order['payment_method'], $order['payment_name'], $payment);
$region = O::region($order['region']);
$hasEmail = (bool) filter_var((string) $order['email'], FILTER_VALIDATE_EMAIL);
?>
<div class="sl-ohead">
  <span class="st st-<?= e($st) ?> sl-big"><?= e(O::STATUSES[$st] ?? $st) ?></span>
  <span class="muted">от <?= e(date('d.m.Y H:i', strtotime((string) $order['created_at']))) ?></span>
  <span class="badge sl-src-<?= e($order['source']) ?>"><?= e(O::SOURCES[$order['source']] ?? $order['source']) ?></span>
  <span class="badge sl-lang sl-lang-<?= e($order['lang'] ?? 'ru') ?>" title="Язык, на котором оформлен заказ (на нём же уходят письма клиенту)">Язык: <?= e(O::langName($order['lang'] ?? 'ru')) ?></span>
  <?php if ($order['updated_at'] && $order['updated_at'] !== $order['created_at']): ?><span class="muted">изменён <?= e(date('d.m.Y H:i', strtotime((string) $order['updated_at']))) ?></span><?php endif; ?>
  <span class="sl-ohead-sum"><?= (int) $order['boxes'] ?> ящ. / <?= (int) $order['pairs'] ?> пар · <b><?= e(price_format($order['total'])) ?></b></span>
</div>

<div class="grid3">
  <div class="sl-col">
    <form class="card" method="post" action="/admin/orders/<?= $oid ?>/items/" data-oi-form>
      <h2>Состав заказа</h2>
      <?= BaseController::tokenField() ?>
      <?= $view->partial('admin/orders/_items', ['items' => $items, 'shippingCost' => (float) $order['shipping_cost'], 'discount' => (float) $order['discount']]) ?>
      <?php if ($coupon): ?>
      <p class="sl-coupon<?= empty($coupon['released']) ? '' : ' off' ?>"><?= icon('tag') ?>
        <span>Промокод <a href="/admin/coupons/<?= (int) $coupon['coupon_id'] ?>/"><b><?= e($coupon['code'] !== '' ? $coupon['code'] : '#' . $coupon['coupon_id']) ?></b></a>
        <?php if (empty($coupon['released'])): ?> — скидка <?= e(price_format($coupon['discount'])) ?><?php if (!empty($coupon['created_at'])): ?>, применён <?= e(date('d.m.Y H:i', strtotime((string) $coupon['created_at']))) ?><?php endif; ?>
        <?php else: ?> — был применён, возвращён в лимит при отмене заказа<?php endif; ?>
        · <a href="/admin/coupons/<?= (int) $coupon['coupon_id'] ?>/usages/">все использования</a></span></p>
      <?php endif; ?>
      <div class="sl-save">
        <button class="btn btn-p" type="submit"><?= icon('check') ?> Сохранить состав</button>
        <span class="sl-dirty hidden" data-oi-dirty>Есть несохранённые изменения</span>
      </div>
    </form>

    <div class="card">
      <h2>История заказа</h2>
      <?php if (!$log): ?><p class="muted">Записей нет.</p><?php else: ?>
      <ol class="events">
        <?php foreach ($log as $l):
          $txt = trim(html_entity_decode(strip_tags((string) $l['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
          $changed = $l['status_to'] && $l['status_from'] !== $l['status_to'];
          $who = $l['user_name'] ? $l['user_name'] . (in_array($l['user_role'], ['admin', 'manager'], true) ? '' : ' (клиент)') : 'Система'; ?>
          <li>
            <div class="sl-ev-h">
              <b><?= e($who) ?></b>
              <?php if ($changed): ?>
                <?php if ($l['status_from']): ?><small class="st st-<?= e($l['status_from']) ?>"><?= e(O::STATUSES[$l['status_from']] ?? $l['status_from']) ?></small> →<?php endif; ?>
                <small class="st st-<?= e($l['status_to']) ?>"><?= e(O::STATUSES[$l['status_to']] ?? $l['status_to']) ?></small>
              <?php endif; ?>
            </div>
            <span><?= e(date('d.m.Y H:i', strtotime((string) $l['created_at']))) ?></span>
            <?php if ($txt !== ''): ?><em><?= nl2br(e($txt)) ?></em><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>
  </div>

  <aside class="sl-col">
    <form class="card" method="post" action="/admin/orders/<?= $oid ?>/status/">
      <h2>Статус заказа</h2>
      <?= BaseController::tokenField() ?>
      <label class="fld"><span>Новый статус</span>
        <select name="status">
          <?php foreach (O::STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $st ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></label>
      <label class="fld"><span>Комментарий (в историю и письмо)</span><textarea class="plain" name="comment" rows="2" maxlength="2000" placeholder="Например: ТТН 20450000000000"></textarea></label>
      <label class="chk<?= $hasEmail ? '' : ' muted' ?>"><input type="checkbox" name="notify" value="1"<?= $hasEmail ? '' : ' disabled' ?>> Уведомить клиента по e-mail<?= $hasEmail ? (($order['lang'] ?? 'ru') === 'uk' ? ' (письмо на украинском)' : '') : ' (не указан)' ?></label>
      <button class="btn btn-p sl-mt" type="submit">Применить</button>
    </form>

    <div class="card">
      <h2>Клиент</h2>
      <p class="sl-cname">
        <?php if ($customer): ?><a href="/admin/customers/<?= (int) $customer['id'] ?>/"><?= e($order['name'] !== '' ? $order['name'] : ($customer['name'] ?: 'Клиент #' . $customer['id'])) ?></a>
        <?php else: ?><?= e($order['name'] !== '' ? $order['name'] : '—') ?><?php endif; ?>
      </p>
      <ul class="sl-dl">
        <?php if ($order['phone'] !== ''): ?><li><?= icon('phone') ?><a href="<?= e(O::tel($order['phone'])) ?>"><?= e(O::phone($order['phone'])) ?></a></li><?php endif; ?>
        <?php if ($order['email']): ?><li><?= icon('mail') ?><a href="mailto:<?= e($order['email']) ?>"><?= e($order['email']) ?></a></li><?php endif; ?>
        <?php if ($customer): ?>
          <li><?= icon('history') ?><span>Заказов: <a href="/admin/customers/<?= (int) $customer['id'] ?>/#orders"><?= (int) $customer['orders_count'] ?></a>, покупок на <?= e(price_format($customer['total_spent'])) ?></span></li>
          <?php if (!(int) $customer['status']): ?><li><span class="sl-warn">Клиент заблокирован</span></li><?php endif; ?>
        <?php else: ?><li class="muted">Без учётной записи</li><?php endif; ?>
      </ul>
    </div>

    <div class="card">
      <h2>Доставка и оплата</h2>
      <dl class="detail">
        <div><dt>Доставка</dt><dd><?= e($shipName ?: '—') ?></dd></div>
        <div><dt>Город</dt><dd><?= e($order['city'] ?: '—') ?></dd></div>
        <?php if ($region !== ''): ?><div><dt>Область</dt><dd><?= e($region) ?></dd></div><?php endif; ?>
        <div><dt>Отделение / адрес</dt><dd><?= e($addr ?: '—') ?></dd></div>
        <div><dt>Оплата</dt><dd><?= e($payName ?: '—') ?></dd></div>
      </dl>
      <details class="sl-edit">
        <summary class="btn btn-sm">Изменить контакты и доставку</summary>
        <form method="post" action="/admin/orders/<?= $oid ?>/">
          <?= BaseController::tokenField() ?>
          <label class="fld"><span>Имя</span><input name="name" value="<?= e($order['name']) ?>" maxlength="190"></label>
          <label class="fld"><span>Телефон</span><input type="tel" name="phone" value="<?= e(O::phone($order['phone'])) ?>" maxlength="32"></label>
          <label class="fld"><span>E-mail</span><input type="email" name="email" value="<?= e($order['email']) ?>" maxlength="190"></label>
          <label class="fld"><span>Способ доставки</span><select name="shipping_method">
            <?php if (!isset($shipping[(string) $order['shipping_method']])): ?><option value="<?= e($order['shipping_method']) ?>" selected><?= e($shipName ?: '— не указан —') ?></option><?php endif; ?>
            <?php foreach ($shipping as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === (string) $order['shipping_method'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select></label>
          <label class="fld"><span>Город</span><input name="city" value="<?= e($order['city']) ?>" maxlength="190"></label>
          <label class="fld"><span>Отделение / адрес</span><input name="address" value="<?= e($order['address']) ?>" maxlength="500"></label>
          <label class="fld"><span>Способ оплаты</span><select name="payment_method">
            <?php if (!isset($payment[(string) $order['payment_method']])): ?><option value="<?= e($order['payment_method']) ?>" selected><?= e($payName ?: '— не указан —') ?></option><?php endif; ?>
            <?php foreach ($payment as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === (string) $order['payment_method'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select></label>
          <label class="fld"><span>Комментарий клиента</span><textarea class="plain" name="comment" rows="3" maxlength="5000"><?= e($order['comment']) ?></textarea></label>
          <button class="btn btn-p" type="submit">Сохранить</button>
        </form>
      </details>
    </div>

    <div class="card">
      <h2>Комментарий клиента</h2>
      <?php if (trim((string) $order['comment']) !== ''): ?><p class="sl-text"><?= nl2br(e($order['comment'])) ?></p>
      <?php else: ?><p class="muted sl-text">Нет комментария.</p><?php endif; ?>
    </div>

    <form class="card" method="post" action="/admin/orders/<?= $oid ?>/comment/">
      <h2>Комментарий менеджера</h2>
      <?= BaseController::tokenField() ?>
      <label class="fld"><span class="sl-sr">Комментарий менеджера</span><textarea class="plain" name="manager_comment" rows="3" maxlength="5000" placeholder="Виден только в админке"><?= e($order['manager_comment']) ?></textarea></label>
      <button class="btn" type="submit">Сохранить комментарий</button>
    </form>

    <?php if ($legacy): ?>
    <div class="card">
      <h2><?= $order['source'] === 'webasyst' ? 'Данные со старого сайта' : 'Дополнительно' ?></h2>
      <dl class="detail sl-detail-sm">
        <?php foreach ($legacy as $label => $val): ?><div><dt><?= e($label) ?></dt><dd><?= e(str_limit($val, 200)) ?></dd></div><?php endforeach; ?>
      </dl>
    </div>
    <?php elseif ($order['ip']): ?>
    <p class="muted">IP: <?= e($order['ip']) ?></p>
    <?php endif; ?>
  </aside>
</div>
