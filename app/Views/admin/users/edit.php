<?php
/**
 * Карточка сотрудника: данные, роль и доступ, пароль, приглашение, история входов и действий.
 * @var array $u @var bool $isSelf @var bool $lastAdmin @var array $logins @var array $did @var array $about @var int $loginCount
 * @var ?array $newPass @var array $errors @var array $form
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\SecurityController as S;
use App\Controllers\Admin\UsersController as U;
use App\Services\Orders;
use App\Services\SystemStatus;

$uid = (int) $u['id'];
$off = !(int) $u['status'];
$locked = $isSelf || $lastAdmin;            // роль и доступ менять нельзя
$err = static fn(string $k): string => isset($errors[$k]) ? '<small class="sys-err" role="alert">' . e($errors[$k]) . '</small>' : '';
$inv = static fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$dt = static fn($d): string => e(date('d.m.Y H:i', strtotime((string) $d)));
$phoneVal = (string) ($form['phone'] ?? '');
if ($phoneVal !== '' && ctype_digit($phoneVal)) $phoneVal = Orders::formatPhone($phoneVal);
$inviteLeft = $u['reset_expires'] && strtotime((string) $u['reset_expires']) > time();
?>
<div class="sys-head">
  <span class="pill <?= $u['role'] === 'admin' ? 'confirmed' : '' ?>"><?= e(U::ROLES[$u['role']] ?? $u['role']) ?></span>
  <span class="pill <?= $off ? 'cancelled' : 'ok' ?>"><?= $off ? 'Доступ закрыт' : 'Доступ открыт' ?></span>
  <?php if ($isSelf): ?><span class="pill">это вы</span><?php endif; ?>
  <span class="muted">ID <?= $uid ?> · <?= e((string) $u['email']) ?></span>
</div>

<?php if ($newPass): ?>
  <div class="card pad-card sys-newpass" id="newpass">
    <h2>Новый пароль — показан один раз</h2>
    <p class="sys-secret"><code data-copy-src><?= e($newPass['password']) ?></code> <button type="button" class="btn btn-sm" data-copy>Копировать</button></p>
    <p class="muted">Логин — <b><?= e($newPass['email']) ?></b>, вход: <code><?= e(url('/admin/')) ?></code>. Передайте пароль лично или в мессенджере —
      после обновления страницы он больше не покажется. Сотрудник может сменить его в «Мой аккаунт».</p>
  </div>
<?php endif; ?>

<?php if ($lastAdmin): ?>
  <div class="flash warn">Это единственный действующий администратор — его роль и доступ изменить нельзя. Сначала назначьте администратором кого-то ещё.</div>
<?php endif; ?>
<?php if ($errors): ?><div class="flash bad">Не сохранено — исправьте отмеченные поля.</div><?php endif; ?>

<div class="two-col">
  <div>
    <form method="post" action="/admin/users/<?= $uid ?>/" novalidate>
      <?= BaseController::tokenField() ?>
      <div class="card">
      <div class="card-hd"><h2>Учётная запись</h2></div>
      <div class="pad">
        <div class="pair">
          <label class="fld"><span>Имя</span><input type="text" name="name" value="<?= e((string) $form['name']) ?>" maxlength="190" required<?= $inv('name') ?>><?= $err('name') ?></label>
          <label class="fld"><span>E-mail (логин)</span><input type="email" name="email" value="<?= e((string) $form['email']) ?>" maxlength="190" required<?= $isSelf ? ' readonly' : '' ?><?= $inv('email') ?>><?= $err('email') ?>
            <?php if ($isSelf): ?><small class="hint">Свой логин меняйте в <a href="/admin/account/">«Мой аккаунт»</a>.</small><?php endif; ?></label>
        </div>
        <div class="pair">
          <label class="fld"><span>Телефон — можно входить и по нему</span><input type="tel" name="phone" value="<?= e($phoneVal) ?>" maxlength="32" placeholder="+38 0XX XXX XX XX"<?= $inv('phone') ?>><?= $err('phone') ?></label>
          <label class="fld"><span>Роль</span>
            <select name="role" data-role-hint="#sys-role-hint"<?= $locked ? ' disabled' : '' ?><?= $inv('role') ?>>
              <?php foreach (U::ROLES as $k => $label): ?><option value="<?= e($k) ?>"<?= $form['role'] === $k ? ' selected' : '' ?> data-hint="<?= e(U::ROLE_HINTS[$k]) ?>"><?= e($label) ?></option><?php endforeach; ?>
            </select><?= $err('role') ?>
            <?php if ($locked): ?><input type="hidden" name="role" value="<?= e((string) $u['role']) ?>"><?php endif; ?>
          </label>
        </div>
        <p class="hint" id="sys-role-hint"><?= e(U::ROLE_HINTS[$form['role']] ?? '') ?></p>
        <label class="check"><input type="checkbox" name="status" value="1"<?= (int) $form['status'] ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>> Доступ в админку открыт</label>
        <?php if ($locked): ?><input type="hidden" name="status" value="<?= (int) $u['status'] ?>"><?php endif; ?>
        <p class="hint"><?= $isSelf ? 'Свою роль и доступ может изменить только другой администратор.' : 'Снимите галочку, чтобы временно закрыть вход (отпуск, увольнение) — сотрудник выйдет из админки сразу. Закрывает и вход в кабинет покупателя.' ?></p>
      </div>
      </div>
      <div class="savebar"><button class="btn btn-p">Сохранить</button><span class="hint">Изменения записываются в журнал безопасности.</span></div>
    </form>

    <div class="grid2">
      <div class="card">
        <div class="card-hd"><h2>Входы в админку</h2><a href="/admin/security/?q=<?= e(rawurlencode((string) $u['email'])) ?>">Журнал</a></div>
        <div class="pad">
          <?php if (!$logins): ?><p class="muted sys-m0">Ещё не входил.</p>
          <?php else: ?><ul class="events">
            <?php foreach ($logins as $l): $bad = $l['action'] === 'login_failed'; ?>
              <li class="<?= $bad ? 'sys-ev-bad' : '' ?>"><b><?= $bad ? 'Неверный пароль' : 'Вход' ?></b><span><?= $dt($l['created_at']) ?> · IP <?= e((string) $l['ip']) ?></span></li>
            <?php endforeach; ?>
          </ul><?php endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-hd"><h2>Действия в админке</h2><a href="/admin/security/?tab=actions&amp;q=<?= e(rawurlencode((string) $u['email'])) ?>">Все</a></div>
        <div class="pad">
          <?php if (!$did): ?><p class="muted sys-m0">Пока ничего не менял.</p>
          <?php else: ?><ul class="events">
            <?php foreach ($did as $a): ?>
              <?php $au = S::entityUrl($a['entity'], $a['entity_id']); $ent = e(S::ENTITIES[$a['entity'] ?? ''] ?? (string) $a['entity']) . ' #' . (int) $a['entity_id']; ?>
              <li><b><?= e(S::actionLabel((string) $a['action'])) ?></b><span><?= $dt($a['created_at']) ?><?php if ($a['entity_id']): ?> · <?= $au ? '<a href="' . e($au) . '">' . $ent . '</a>' : $ent ?><?php endif; ?></span></li>
            <?php endforeach; ?>
          </ul><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <aside>
    <div class="card pad-card">
      <h2>Сведения</h2>
      <dl class="detail">
        <div><dt>Создан</dt><dd><?= $dt($u['created_at']) ?></dd></div>
        <div><dt>Последний вход</dt><dd><?= $u['last_login_at'] ? $dt($u['last_login_at']) . '<small class="muted"> · ' . e(SystemStatus::ago(time() - strtotime((string) $u['last_login_at']))) . '</small>' : '<span class="muted">ни разу</span>' ?></dd></div>
        <div><dt>Входов в админку</dt><dd><?= (int) $loginCount ?></dd></div>
        <div><dt>Пароль</dt><dd><?= (int) $u['nopass'] ? '<span class="sys-bad">не задан</span>' : ((int) $u['legacy'] ? '<span class="sys-warn-t">старого формата (md5 из Webasyst)</span> — задайте новый' : 'хранится как хеш') ?></dd></div>
        <?php if ($inviteLeft): ?><div><dt>Приглашение</dt><dd>ссылка действует до <?= $dt($u['reset_expires']) ?></dd></div><?php endif; ?>
        <?php if ((int) $u['orders_count']): ?><div><dt>Заказов как покупатель</dt><dd><a href="/admin/customers/<?= $uid ?>/"><?= (int) $u['orders_count'] ?></a></dd></div><?php endif; ?>
      </dl>
    </div>

    <?php if ($isSelf): ?>
      <div class="card pad-card">
        <h2>Пароль</h2>
        <p class="muted">Свой пароль меняйте в разделе «Мой аккаунт» — там нужно ввести текущий.</p>
        <a class="btn" href="/admin/account/#password">Мой аккаунт</a>
      </div>
    <?php else: ?>
      <form method="post" action="/admin/users/<?= $uid ?>/password/" class="card pad-card" id="password" autocomplete="off"
            data-confirm="Задать сотруднику новый пароль? Старый сразу перестанет подходить." novalidate>
        <?= BaseController::tokenField() ?>
        <h2>Сбросить пароль</h2>
        <label class="fld"><span>Новый пароль</span>
          <span class="sys-pass"><input type="password" name="password" maxlength="200" autocomplete="new-password"<?= $inv('password') ?>>
            <button type="button" class="btn btn-sm" data-toggle-pass>Показать</button></span><?= $err('password') ?>
          <small class="hint">Не меньше <?= U::MIN_PASSWORD ?> символов. Пусто — сгенерируем и покажем один раз.</small></label>
        <button class="btn">Задать новый пароль</button>
      </form>

      <?php if ($u['email'] && !$off): ?>
        <form method="post" action="/admin/users/<?= $uid ?>/invite/" class="card pad-card">
          <?= BaseController::tokenField() ?>
          <h2>Приглашение</h2>
          <p class="muted">Письмо на <?= e((string) $u['email']) ?> со ссылкой, чтобы сотрудник сам задал пароль (72 часа). Старая ссылка перестанет работать.</p>
          <button class="btn"><?= $inviteLeft ? 'Отправить ещё раз' : 'Отправить приглашение' ?></button>
        </form>
      <?php endif; ?>

      <?php if (!$lastAdmin): ?>
        <form method="post" action="/admin/users/<?= $uid ?>/revoke/" class="card pad-card"
              data-confirm="Лишить «<?= e($u['name'] ?: $u['email']) ?>» доступа к админке? Учётная запись станет обычным покупателем.">
          <?= BaseController::tokenField() ?>
          <h2>Лишить доступа</h2>
          <p class="muted">Учётная запись станет обычным покупателем: вход в админку закроется сразу, заказы и кабинет на сайте останутся.</p>
          <button class="btn btn-d">Убрать из сотрудников</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($about): ?>
      <div class="card pad-card">
        <h2>История учётной записи</h2>
        <ul class="events">
          <?php foreach ($about as $a): ?>
            <li><b><?= e(S::actionLabel((string) $a['action'])) ?></b><span><?= $dt($a['created_at']) ?> · <?= e($a['name'] ?: ($a['email'] ?? '—')) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </aside>
</div>
