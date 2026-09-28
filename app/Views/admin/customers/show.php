<?php
/**
 * Карточка клиента.
 * @var array $c @var array $orders @var App\Core\Paginator $opg @var array $stats @var array $requests @var bool $canEdit @var bool $isAdmin @var bool $isSelf
 * @var array $shipping @var array $payment @var ?string $tempPass @var ?string $resetLink
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\CustomersController as C;
use App\Controllers\Admin\OrdersController as O;
use App\Controllers\Admin\RequestsController as R;

$cid = (int) $c['id'];
$blocked = !(int) $c['status'];
?>
<div class="sl-head">
  <span class="sl-role sl-role-<?= e($c['role']) ?>"><?= e(C::ROLES[$c['role']] ?? $c['role']) ?></span>
  <?php if ($blocked): ?><span class="sl-role sl-blocked">Заблокирован</span><?php endif; ?>
  <span class="badge sl-lang sl-lang-<?= e($c['lang'] ?? 'ru') ?>" title="Язык сайта клиента — на нём уходят письма">Язык: <?= e(O::langName($c['lang'] ?? 'ru')) ?></span>
  <span class="muted">ID <?= $cid ?> · зарегистрирован <?= e(date('d.m.Y', strtotime((string) $c['created_at']))) ?>
    <?= $c['last_login_at'] ? ' · последний вход ' . e(date('d.m.Y H:i', strtotime((string) $c['last_login_at']))) : '' ?></span>
</div>

<?php if ($tempPass): ?>
<div class="card">
  <h2>Временный пароль</h2>
  <p class="sl-pass"><?= e($tempPass) ?></p>
  <p class="muted sl-mt">Логин — e-mail <?= $c['email'] ? '(' . e($c['email']) . ')' : '' ?> или телефон <?= $c['phone'] ? '(' . e(O::phone($c['phone'])) . ')' : '' ?>. Пароль показан один раз.</p>
</div>
<?php endif; ?>
<?php if ($resetLink): ?>
<div class="card">
  <h2>Ссылка для смены пароля</h2>
  <label class="fld"><span class="sl-sr">Ссылка для смены пароля</span><input type="text" class="sl-link" value="<?= e($resetLink) ?>" readonly onclick="this.select()"></label>
  <p class="muted">Отправьте ссылку клиенту (Viber, Telegram, e-mail): по ней он сам задаст новый пароль. Ссылка действует 24 часа и показывается один раз.</p>
</div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><small>Заказов</small><b><?= (int) $stats['cnt'] ?></b></div>
  <div class="kpi"><small>Сумма покупок, грн</small><b><?= e(price_format($stats['sum_paid'], false)) ?></b><em>оплачены, отправлены, выполнены</em></div>
  <div class="kpi"><small>Сумма всех заказов, грн</small><b><?= e(price_format($stats['sum_all'], false)) ?></b><em>кроме удалённых</em></div>
  <div class="kpi"><small>Ящиков в заказах</small><b><?= number_format((int) $stats['boxes'], 0, '', ' ') ?></b><em>кроме удалённых</em></div>
  <div class="kpi"><small>Последний заказ</small><b><?= $stats['last_at'] ? e(date('d.m.Y', strtotime((string) $stats['last_at']))) : '—' ?></b></div>
</div>

<div class="grid3">
  <div class="sl-col">
    <div class="card" id="orders">
      <h2>Заказы клиента<?php if ($opg->pages > 1): ?> <small class="muted">· <?= (int) $stats['cnt'] ?>, страница <?= $opg->page ?> из <?= $opg->pages ?></small><?php endif; ?></h2>
      <?php if (!$orders): ?><p class="muted">Заказов пока нет. <a href="/admin/orders/new/?customer=<?= $cid ?>">Создать заказ</a></p>
      <?php else: ?>
      <div class="tblwrap">
      <table class="tbl sl-stack">
        <thead><tr><th>Номер</th><th>Дата</th><th class="num">Ящ. / пар</th><th class="num">Сумма</th><th>Доставка и оплата</th><th>Статус</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): $ts = strtotime((string) $o['created_at']); ?>
          <tr class="<?= $o['status'] === 'deleted' ? 'is-draft' : '' ?>">
            <td data-l="Номер"><a class="sl-num" href="/admin/orders/<?= (int) $o['id'] ?>/"><?= e(O::number((int) $o['id'])) ?></a></td>
            <td data-l="Дата" class="nowrap"><?= e(date('d.m.Y', $ts)) ?><small><?= e(date('H:i', $ts)) ?></small></td>
            <td data-l="Ящ. / пар" class="num"><?= (int) $o['boxes'] ?> / <?= (int) $o['pairs'] ?></td>
            <td data-l="Сумма" class="num"><b><?= e(price_format($o['total'])) ?></b></td>
            <td data-l="Доставка и оплата"><?= e(O::methodName($o['shipping_method'], $o['shipping_name'], $shipping) ?: '—') ?><?= $o['city'] ? ', ' . e(str_limit($o['city'], 40)) : '' ?>
              <small><?= e(O::methodName($o['payment_method'], $o['payment_name'], $payment) ?: '—') ?></small></td>
            <td data-l="Статус"><span class="st st-<?= e($o['status']) ?>"><?= e(O::STATUSES[$o['status']] ?? $o['status']) ?></span><?php if (($o['lang'] ?? 'ru') === 'uk'): ?> <span class="badge sl-lang sl-lang-uk" title="Оформлен на украинской версии сайта">UA</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?= preg_replace('/href="([^"#]*)"/', 'href="$1#orders"', $opg->html()) ?>
      <?php endif; ?>
    </div>

    <?php if ($requests): ?>
    <div class="card">
      <h2>Заявки с этого телефона</h2>
      <dl class="detail sl-detail-sm">
        <?php foreach ($requests as $r): ?>
          <div><dt><?= e(date('d.m.Y H:i', strtotime((string) $r['created_at']))) ?></dt>
            <dd><a href="/admin/requests/?<?= e(http_build_query(['type' => $r['type'], 'q' => (string) $c['phone']])) ?>"><?= e(R::TYPES[$r['type']] ?? $r['type']) ?></a> · <?= $r['status'] === 'new' ? 'новая' : 'обработана' ?><?= $r['text'] ? ' — ' . e(str_limit($r['text'], 120)) : '' ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </div>
    <?php endif; ?>
  </div>

  <aside class="sl-col">
    <form class="card" method="post" action="/admin/customers/<?= $cid ?>/">
      <h2>Данные клиента</h2>
      <?= BaseController::tokenField() ?>
      <input type="hidden" name="action" value="save">
      <fieldset class="sl-fs"<?= $canEdit ? '' : ' disabled' ?>>
        <label class="fld"><span>Имя (как в заказах)</span><input name="name" value="<?= e($c['name']) ?>" maxlength="190"></label>
        <div class="row2">
          <label class="fld"><span>Имя</span><input name="firstname" value="<?= e($c['firstname']) ?>" maxlength="100"></label>
          <label class="fld"><span>Фамилия</span><input name="lastname" value="<?= e($c['lastname']) ?>" maxlength="100"></label>
        </div>
        <label class="fld"><span>Телефон</span><input type="tel" name="phone" value="<?= e($c['phone'] ? O::phone($c['phone']) : '') ?>" maxlength="32"></label>
        <label class="fld"><span>E-mail</span><input type="email" name="email" value="<?= e($c['email']) ?>" maxlength="190"></label>
        <label class="fld"><span>Город</span><input name="city" value="<?= e($c['city']) ?>" maxlength="190"></label>
        <label class="fld"><span>Компания / магазин</span><input name="company" value="<?= e($c['company']) ?>" maxlength="190"></label>
        <label class="fld"><span>Заметка (видна только в админке)</span><textarea class="plain" name="note" rows="3" maxlength="5000"><?= e($c['note']) ?></textarea></label>
        <label class="fld"><span>Роль</span>
          <select name="role"<?= $isAdmin && !$isSelf ? '' : ' disabled' ?>>
            <?php foreach (C::ROLES as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $c['role'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
          <?php if (!$isAdmin): ?><small class="hint">Роль меняет только администратор.</small>
          <?php elseif ($isSelf): ?><small class="hint">Свою роль изменить нельзя.</small>
          <?php else: ?><small class="hint">Менеджер и администратор получают доступ в админку.</small><?php endif; ?>
        </label>
        <button class="btn btn-p" type="submit">Сохранить</button>
      </fieldset>
      <?php if (!$canEdit): ?><p class="muted sl-mt">Данные сотрудников изменяет только администратор.</p><?php endif; ?>
    </form>

    <?php if ($canEdit): ?>
    <div class="card sl-actions">
      <h2>Доступ</h2>
      <form method="post" action="/admin/customers/<?= $cid ?>/" data-confirm="Установить клиенту новый временный пароль? Старый пароль перестанет работать.">
        <?= BaseController::tokenField() ?>
        <input type="hidden" name="action" value="password">
        <?php if ($c['email']): ?><label class="chk"><input type="checkbox" name="send" value="1"> Отправить пароль на <?= e($c['email']) ?></label><?php endif; ?>
        <button class="btn" type="submit"><?= icon('lock') ?> Сбросить пароль (временный)</button>
      </form>
      <form method="post" action="/admin/customers/<?= $cid ?>/">
        <?= BaseController::tokenField() ?>
        <input type="hidden" name="action" value="link">
        <?php if ($c['email']): ?><label class="chk"><input type="checkbox" name="send" value="1"> Отправить ссылку на <?= e($c['email']) ?></label><?php endif; ?>
        <button class="btn" type="submit"><?= icon('share') ?> Ссылка для смены пароля</button>
        <small class="hint">Клиент сам задаст новый пароль; старый действует, пока он его не сменит.</small>
      </form>
      <?php if (!$isSelf): ?>
      <form method="post" action="/admin/customers/<?= $cid ?>/"<?= $blocked ? '' : ' data-confirm="Заблокировать клиента? Он не сможет войти на сайт."' ?>>
        <?= BaseController::tokenField() ?>
        <input type="hidden" name="action" value="<?= $blocked ? 'unblock' : 'block' ?>">
        <button class="btn<?= $blocked ? '' : ' btn-d' ?>" type="submit"><?= icon($blocked ? 'check' : 'x') ?> <?= $blocked ? 'Разблокировать' : 'Заблокировать' ?></button>
      </form>
      <?php endif; ?>
      <form method="post" action="/admin/customers/<?= $cid ?>/">
        <?= BaseController::tokenField() ?>
        <input type="hidden" name="action" value="recalc">
        <button class="btn" type="submit"><?= icon('refresh') ?> Пересчитать заказы и сумму</button>
      </form>
    </div>
    <?php endif; ?>
  </aside>
</div>
