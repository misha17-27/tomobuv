<?php
/** @var array $cfg @var ?array $post @var array $errors @var bool $enabled @var bool $canEdit @var string $preview @var array $log @var ?array $stats */
use App\Controllers\Admin\BaseController;
use App\Services\WhatsApp;

$v = static fn(string $k) => (string) ($post[$k] ?? $cfg[$k] ?? '');
$on = static fn(string $k, string $def = '1') => ($post !== null ? isset($post[$k]) : (($cfg[$k] ?? '') === '' ? $def === '1' : $cfg[$k] === '1'));
$err = static fn(string $k) => isset($errors[$k]) ? '<span class="hint" style="color:var(--bad)">' . e($errors[$k]) . '</span>' : '';
$prov = $v('provider');
$dis = $canEdit ? '' : ' disabled';
?>
<div class="stats">
  <div class="stat<?= $enabled ? '' : ' warn' ?>"><span><?= $enabled ? 'Вкл.' : 'Выкл.' ?></span>Отправка заказов в WhatsApp</div>
  <div class="stat"><span><?= count(WhatsApp::recipients($cfg['to'])) ?></span>Номеров получателей</div>
  <div class="stat"><span><?= (int) ($stats['sent'] ?? 0) ?></span>Отправлено за 30 дней</div>
  <div class="stat<?= (int) ($stats['failed'] ?? 0) ? ' warn' : '' ?>"><span><?= (int) ($stats['failed'] ?? 0) ?></span>Ошибок за 30 дней</div>
</div>

<form method="post" class="setform" autocomplete="off">
  <?= BaseController::tokenField() ?>
  <div class="two-col">
    <div>
      <div class="card">
        <h2>Куда отправлять</h2>
        <label class="check"><input type="checkbox" name="enabled" value="1"<?= $on('enabled', '0') ? ' checked' : '' ?><?= $dis ?>> Отправлять новые заказы в WhatsApp автоматически</label>
        <label class="fld" style="margin-top:12px"><span>Номер WhatsApp (можно несколько через запятую)</span>
          <input name="to" value="<?= e($v('to')) ?>" placeholder="+38 093 275 30 70"<?= $dis ?>><?= $err('to') ?>
          <span class="hint">На эти номера придёт каждый новый заказ: номер, клиент, телефон, доставка, позиции в ящиках и сумма, ссылка на заказ в админке.</span></label>
        <div class="checks">
          <label class="check"><input type="checkbox" name="notify_orders" value="1"<?= $on('notify_orders') ? ' checked' : '' ?><?= $dis ?>> Заказы с сайта</label>
          <label class="check"><input type="checkbox" name="notify_quickorder" value="1"<?= $on('notify_quickorder') ? ' checked' : '' ?><?= $dis ?>> «Купить в 1 клик»</label>
          <label class="check"><input type="checkbox" name="notify_requests" value="1"<?= $on('notify_requests') ? ' checked' : '' ?><?= $dis ?>> Заявки: обратный звонок и сообщения с контактов</label>
        </div>
      </div>

      <div class="card">
        <h2>Сервис отправки</h2>
        <p class="muted" style="font-size:14px">Сайт не может написать в WhatsApp сам — нужен шлюз. Выберите один.</p>
        <?= $err('provider') ?>
        <div class="subtabs" role="tablist">
          <?php foreach (WhatsApp::PROVIDERS as $k => $name): ?>
            <label class="check" style="margin-right:18px"><input type="radio" name="provider" value="<?= $k ?>"<?= $prov === $k ? ' checked' : '' ?><?= $dis ?> onchange="document.querySelectorAll('[data-prov]').forEach(function(b){b.hidden=b.dataset.prov!=='<?= $k ?>'})"> <?= e($name) ?></label>
          <?php endforeach; ?>
        </div>

        <div data-prov="green"<?= $prov === 'green' ? '' : ' hidden' ?>>
          <ol class="hint" style="font-size:13.5px;line-height:1.7;margin:0 0 14px 18px">
            <li>Зарегистрируйтесь на <a href="https://green-api.com" target="_blank" rel="noopener">green-api.com</a> и создайте инстанс.</li>
            <li>Отсканируйте QR-код в WhatsApp на телефоне, с которого будут уходить сообщения (Настройки → Связанные устройства).</li>
            <li>Скопируйте сюда <b>apiUrl</b>, <b>idInstance</b> и <b>apiTokenInstance</b> из кабинета Green-API.</li>
          </ol>
          <div class="pair">
            <label class="fld"><span>apiUrl</span><input name="green_url" value="<?= e($v('green_url')) ?>" placeholder="https://api.green-api.com"<?= $dis ?>><?= $err('green_url') ?></label>
            <label class="fld"><span>idInstance</span><input name="green_instance" value="<?= e($v('green_instance')) ?>" inputmode="numeric"<?= $dis ?>></label>
          </div>
          <label class="fld"><span>apiTokenInstance</span><input name="green_token" type="password" value="" placeholder="<?= $cfg['green_token'] !== '' ? '•••••••• сохранён (пусто — не менять)' : '' ?>"<?= $dis ?>></label>
        </div>

        <div data-prov="cloud"<?= $prov === 'cloud' ? '' : ' hidden' ?>>
          <ol class="hint" style="font-size:13.5px;line-height:1.7;margin:0 0 14px 18px">
            <li>Нужен аккаунт Meta Business и отдельный номер для WhatsApp Business Platform (<a href="https://developers.facebook.com/docs/whatsapp/cloud-api/get-started" target="_blank" rel="noopener">инструкция</a>).</li>
            <li>Скопируйте <b>Phone number ID</b> и постоянный <b>токен доступа</b>.</li>
            <li>Правило Meta: обычный текст доходит, только если получатель писал бизнес-номеру за последние 24 часа. Для гарантированной доставки создайте шаблон сообщения с одним параметром {{1}} и укажите его имя.</li>
          </ol>
          <div class="pair">
            <label class="fld"><span>Phone number ID</span><input name="cloud_phone_id" value="<?= e($v('cloud_phone_id')) ?>" inputmode="numeric"<?= $dis ?>></label>
            <label class="fld"><span>Токен доступа</span><input name="cloud_token" type="password" value="" placeholder="<?= $cfg['cloud_token'] !== '' ? '•••••••• сохранён (пусто — не менять)' : '' ?>"<?= $dis ?>></label>
          </div>
          <div class="pair">
            <label class="fld"><span>Шаблон (необязательно)</span><input name="cloud_template" value="<?= e($v('cloud_template')) ?>" placeholder="new_order"<?= $dis ?>></label>
            <label class="fld"><span>Язык шаблона</span><input name="cloud_lang" value="<?= e($v('cloud_lang') ?: 'ru') ?>"<?= $dis ?>><?= $err('cloud_lang') ?></label>
          </div>
        </div>
      </div>

      <?php if ($canEdit): ?>
      <div class="savebar"><button class="btn btn-p">Сохранить</button>
        <button type="button" class="btn" id="wa-test"<?= $enabled || ($prov !== '' && $cfg['to'] !== '') ? '' : ' disabled' ?>>Отправить тестовое сообщение</button>
        <span class="hint" id="wa-test-res">Тест уходит с сохранёнными настройками.</span></div>
      <?php else: ?><div class="flash warn">Менять настройки WhatsApp может только администратор.</div><?php endif; ?>
    </div>

    <aside>
      <div class="card">
        <h2>Так выглядит сообщение</h2>
        <?php if ($preview !== ''): ?>
          <div style="background:#e7fcd8;border-radius:10px;padding:12px 14px;white-space:pre-wrap;font-size:13.5px;line-height:1.5;max-height:520px;overflow:auto"><?= e($preview) ?></div>
          <p class="hint">Пример на последнем заказе.</p>
        <?php else: ?><p class="muted">Заказов пока нет.</p><?php endif; ?>
      </div>
    </aside>
  </div>
</form>

<div class="card">
  <div class="card-hd"><h2>Последние отправки</h2></div>
  <?php if ($log): ?>
    <div class="table-scroll"><table class="grid"><thead><tr><th>Дата</th><th>Кому</th><th>Что</th><th>Результат</th></tr></thead><tbody>
      <?php foreach ($log as $l): ?>
        <tr><td class="nowrap"><?= e(date('d.m.Y H:i', strtotime((string) $l['created_at']))) ?></td><td>+<?= e($l['target']) ?></td>
          <td><?php if ($l['ref_type'] === 'order' && $l['ref_id']): ?><a href="/admin/orders/<?= (int) $l['ref_id'] ?>/">Заказ #100<?= (int) $l['ref_id'] ?></a><?php elseif ($l['ref_type'] === 'request'): ?><a href="/admin/requests/">Заявка №<?= (int) $l['ref_id'] ?></a><?php else: ?>Тест<?php endif; ?></td>
          <td><?= $l['ok'] ? '<span class="st st-completed">Доставлено в шлюз</span>' : '<span class="st st-refunded">Ошибка</span><small>' . e($l['error']) . '</small>' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty-card"><h2>Отправок ещё не было</h2><p>После настройки сюда попадёт каждое сообщение и его результат.</p></div><?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var b = document.getElementById('wa-test'), res = document.getElementById('wa-test-res');
  if (!b) return;
  b.addEventListener('click', function () {
    b.disabled = true; res.textContent = 'Отправляем…';
    Adm.post('/admin/whatsapp/test/', {}).then(function (r) {
      b.disabled = false; res.textContent = r.ok ? r.message : ('Ошибка: ' + r.error); res.style.color = r.ok ? 'var(--ok)' : 'var(--bad)';
    }).catch(function () { b.disabled = false; res.textContent = 'Нет связи с сервером'; });
  });
});
</script>
