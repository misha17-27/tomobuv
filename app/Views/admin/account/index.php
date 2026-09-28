<?php
/**
 * Мой аккаунт: профиль, смена пароля, свои последние входы.
 * @var array $u @var array $events @var int $failed30 @var string $ip @var array $errors @var array $form @var bool $isAdmin
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\UsersController as U;
use App\Services\Orders;
use App\Services\SystemStatus;

$err = static fn(string $k): string => isset($errors[$k]) ? '<small class="sys-err" role="alert">' . e($errors[$k]) . '</small>' : '';
$inv = static fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$dt = static fn($d): string => e(date('d.m.Y H:i', strtotime((string) $d)));
$phoneVal = (string) ($form['phone'] ?? '');
if ($phoneVal !== '' && ctype_digit($phoneVal)) $phoneVal = Orders::formatPhone($phoneVal);
$pwErr = isset($errors['pw_current']) || isset($errors['password']) || isset($errors['password2']);
?>
<?php if ($errors): ?><div class="flash bad"><?= $pwErr ? 'Пароль не изменён' : 'Профиль не сохранён' ?> — исправьте отмеченные поля.</div><?php endif; ?>

<div class="two-col">
  <div>
    <form method="post" action="/admin/account/profile/" class="card" id="profile" novalidate>
      <?= BaseController::tokenField() ?>
      <div class="card-hd"><h2>Профиль</h2><span class="pill <?= $u['role'] === 'admin' ? 'confirmed' : '' ?>"><?= e(U::ROLES[$u['role']] ?? $u['role']) ?></span></div>
      <div class="pad">
        <label class="fld"><span>Имя</span><input type="text" name="name" value="<?= e((string) $form['name']) ?>" maxlength="190" autocomplete="name" required<?= $inv('name') ?>><?= $err('name') ?>
          <small class="hint">Видно в журнале действий и в истории заказов.</small></label>
        <div class="pair">
          <label class="fld"><span>E-mail (логин)</span><input type="email" name="email" value="<?= e((string) $form['email']) ?>" maxlength="190" autocomplete="email" required data-orig="<?= e((string) $u['email']) ?>"<?= $inv('email') ?>><?= $err('email') ?></label>
          <label class="fld"><span>Телефон — можно входить и по нему</span><input type="tel" name="phone" value="<?= e($phoneVal) ?>" maxlength="32" autocomplete="tel" placeholder="+38 0XX XXX XX XX"<?= $inv('phone') ?>><?= $err('phone') ?></label>
        </div>
        <label class="fld<?= isset($errors['current']) ? '' : ' sys-if-email' ?>" id="sys-email-current"><span>Текущий пароль — нужен для смены e-mail</span>
          <input type="password" name="current" maxlength="200" autocomplete="current-password"<?= $inv('current') ?>><?= $err('current') ?></label>
        <div class="sys-actions"><button class="btn btn-p">Сохранить профиль</button><span class="hint">Роль меняет другой администратор в разделе «Сотрудники».</span></div>
      </div>
    </form>

    <form method="post" action="/admin/account/password/" class="card" id="password" novalidate>
      <?= BaseController::tokenField() ?>
      <div class="card-hd"><h2>Смена пароля</h2><?php if ((int) $u['legacy']): ?><span class="pill new">старый формат — смените</span><?php endif; ?></div>
      <div class="pad">
        <label class="fld"><span>Текущий пароль</span><input type="password" name="current" maxlength="200" autocomplete="current-password" required<?= $inv('pw_current') ?>><?= $err('pw_current') ?></label>
        <div class="pair">
          <label class="fld"><span>Новый пароль</span>
            <span class="sys-pass"><input type="password" name="password" minlength="<?= U::MIN_PASSWORD ?>" maxlength="200" autocomplete="new-password" required<?= $inv('password') ?>>
              <button type="button" class="btn btn-sm" data-toggle-pass>Показать</button></span><?= $err('password') ?>
            <small class="hint">Не меньше <?= U::MIN_PASSWORD ?> символов, не такой, как на других сайтах.</small></label>
          <label class="fld"><span>Повторите новый пароль</span><input type="password" name="password2" maxlength="200" autocomplete="new-password" required<?= $inv('password2') ?>><?= $err('password2') ?></label>
        </div>
        <p class="hint">Удобно придумать фразу из 3–4 слов, например «синие-кеды-опт-2026». После смены пароля эта сессия продолжится с новым идентификатором, а входы с других устройств и браузеров завершатся.</p>
        <div class="sys-actions"><button class="btn btn-p">Сменить пароль</button><span class="hint">10 неверных попыток за 15 минут временно закрывают смену пароля.</span></div>
      </div>
    </form>
  </div>

  <aside>
    <div class="card pad-card">
      <h2>Учётная запись</h2>
      <dl class="detail">
        <div><dt>Роль</dt><dd><?= e(U::ROLES[$u['role']] ?? $u['role']) ?></dd></div>
        <div><dt>Создана</dt><dd><?= $dt($u['created_at']) ?></dd></div>
        <div><dt>Этот вход</dt><dd><?= $u['last_login_at'] ? $dt($u['last_login_at']) : '—' ?></dd></div>
        <div><dt>Ваш IP сейчас</dt><dd><code><?= e($ip) ?></code></dd></div>
      </dl>
      <?php if (!$isAdmin): ?><p class="hint"><?= e(U::ROLE_HINTS['manager']) ?></p><?php endif; ?>
    </div>

    <div class="card pad-card">
      <h2>Мои последние входы</h2>
      <?php if ($failed30): ?><div class="flash warn sys-mb">Неудачных попыток входа под вашим логином за 30 дней: <b><?= (int) $failed30 ?></b>.
        Если это были не вы — смените пароль.</div><?php endif; ?>
      <?php if (!$events): ?><p class="muted sys-m0">Записей пока нет.</p>
      <?php else: ?><ul class="events">
        <?php foreach ($events as $ev): $bad = $ev['action'] === 'login_failed'; ?>
          <li class="<?= $bad ? 'sys-ev-bad' : '' ?>"><b><?= $bad ? 'Неверный пароль' : 'Вход' ?><?= $ev['ip'] === $ip ? ' · этот IP' : '' ?></b>
            <span><?= $dt($ev['created_at']) ?> · <?= e(SystemStatus::ago(time() - strtotime((string) $ev['created_at']))) ?> · IP <?= e((string) $ev['ip']) ?></span></li>
        <?php endforeach; ?>
      </ul><?php endif; ?>
      <p class="hint">Незнакомый IP или время — смените пароль и сообщите администратору.</p>
    </div>
  </aside>
</div>
