<?php
/**
 * Новый заказ вручную (телефонный).
 * @var array $v значения формы @var array $items @var string $error @var ?array $customer @var array $shipping @var array $payment
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\OrdersController as O;
?>
<?php if ($error !== ''): ?><div class="flash bad"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/admin/orders/new/" class="grid3" data-oi-form data-new-order>
  <?= BaseController::tokenField() ?>
  <input type="hidden" name="request_id" value="<?= (int) $v['request_id'] ?>">
  <div class="sl-col">
    <div class="card">
      <h2>Товары</h2>
      <?= $view->partial('admin/orders/_items', ['items' => $items, 'shippingCost' => (float) str_replace(',', '.', (string) $v['shipping_cost']), 'discount' => (float) str_replace(',', '.', (string) $v['discount'])]) ?>
    </div>
    <div class="card">
      <h2>Комментарии</h2>
      <label class="fld"><span>Комментарий клиента</span><textarea class="plain" name="comment" rows="3" maxlength="5000"><?= e($v['comment']) ?></textarea></label>
      <label class="fld"><span>Комментарий менеджера (виден только в админке)</span><textarea class="plain" name="manager_comment" rows="2" maxlength="5000"><?= e($v['manager_comment']) ?></textarea></label>
    </div>
  </div>

  <aside class="sl-col">
    <div class="card">
      <h2>Клиент</h2>
      <input type="hidden" name="customer_id" value="<?= (int) $v['customer_id'] ?>" data-cust-id>
      <div class="sl-picker" data-cust-picker>
        <label class="fld"><span>Найти клиента</span>
          <input type="search" placeholder="Телефон, имя или e-mail" autocomplete="off" data-cust-input aria-autocomplete="list"></label>
        <div class="sl-drop hidden" data-cust-drop role="listbox" aria-label="Найденные клиенты"></div>
      </div>
      <p class="sl-custsel<?= $customer ? '' : ' hidden' ?>" data-cust-sel>
        <?= icon('user') ?> <span data-cust-name><?= $customer ? e(($customer['name'] ?: 'Клиент') . ' · #' . $customer['id'] . ' · заказов: ' . $customer['orders_count']) : '' ?></span>
        <button type="button" class="btn btn-sm" data-cust-clear>Новый клиент</button>
      </p>
      <p class="muted sl-hint" data-cust-hint<?= $customer ? ' hidden' : '' ?>>Если клиента нет в базе — заполните поля, учётная запись будет создана (или найдена по телефону).</p>
      <label class="fld"><span>Имя и фамилия</span><input name="name" value="<?= e($v['name']) ?>" maxlength="190" required autocomplete="off" data-cust-f="name"></label>
      <label class="fld"><span>Телефон</span><input type="tel" name="phone" value="<?= e($v['phone']) ?>" maxlength="32" required placeholder="+38 (0XX) XXX-XX-XX" autocomplete="off" data-cust-f="phone"></label>
      <label class="fld"><span>E-mail</span><input type="email" name="email" value="<?= e($v['email']) ?>" maxlength="190" autocomplete="off" data-cust-f="email"></label>
    </div>

    <div class="card">
      <h2>Доставка и оплата</h2>
      <label class="fld"><span>Способ доставки</span><select name="shipping_method" required>
        <?php foreach ($shipping as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $v['shipping_method'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
      <label class="fld"><span>Город</span><input name="city" value="<?= e($v['city']) ?>" maxlength="190" data-cust-f="city"></label>
      <label class="fld"><span>Отделение / адрес</span><input name="address" value="<?= e($v['address']) ?>" maxlength="500" placeholder="Например: отделение №5"></label>
      <label class="fld"><span>Способ оплаты</span><select name="payment_method" required>
        <?php foreach ($payment as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $v['payment_method'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
      <label class="fld"><span>Язык писем клиенту</span><select name="lang" data-cust-f="lang">
        <?php foreach (\App\Core\Lang::NAMES as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $v['lang'] ? ' selected' : '' ?>><?= $k === 'uk' ? 'Украинский (UA)' : 'Русский (RU)' ?></option><?php endforeach; ?>
      </select></label>
      <label class="fld"><span>Статус</span><select name="status">
        <?php foreach (['new', 'processing', 'paid'] as $k): ?><option value="<?= e($k) ?>"<?= $k === $v['status'] ? ' selected' : '' ?>><?= e(O::STATUSES[$k]) ?></option><?php endforeach; ?>
      </select></label>
    </div>
    <button class="btn btn-p sl-wide" type="submit"><?= icon('check') ?> Создать заказ</button>
  </aside>
</form>
