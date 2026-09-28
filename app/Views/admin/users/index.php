<?php
/**
 * Сотрудники: список + форма «Новый сотрудник».
 * @var array $rows @var array $counts @var int $stale @var string $tab @var string $q @var array $tabs @var array $lastIp @var int $me
 * @var array $form @var array $errors
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\UsersController as U;
use App\Core\Request;
use App\Services\Orders;
use App\Services\SystemStatus;

$err = static fn(string $k): string => isset($errors[$k]) ? '<small class="sys-err" role="alert">' . e($errors[$k]) . '</small>' : '';
$inv = static fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true"' : '';
?>
<div class="stats">
  <a class="stat" href="/admin/users/"><span><?= (int) $counts['all'] ?></span>Сотрудников</a>
  <a class="stat" href="?tab=admin"><span><?= (int) $counts['admin'] ?></span>Администраторов</a>
  <a class="stat" href="?tab=manager"><span><?= (int) $counts['manager'] ?></span>Менеджеров</a>
  <div class="stat<?= $stale ? ' hot' : '' ?>" title="Отключите учётные записи, которыми не пользуются"><span><?= (int) $stale ?></span>Не входили полгода</div>
</div>

<?php if ($errors): ?><div class="flash bad">Сотрудник не добавлен — исправьте поля в форме «Новый сотрудник».</div><?php endif; ?>

<div class="two-col">
  <div>
    <nav class="tabs" aria-label="Роли">
      <?php foreach ($tabs as $k => [$label]): ?>
        <a href="<?= e(Request::withQuery(['tab' => $k === 'all' ? null : $k], '/admin/users/')) ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> <i><?= (int) $counts[$k] ?></i></a>
      <?php endforeach; ?>
    </nav>

    <form class="filter-bar" method="get" action="/admin/users/" role="search">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Имя, e-mail или телефон" aria-label="Поиск сотрудника">
      <button class="btn btn-p">Найти</button>
      <?php if ($q !== ''): ?><a class="btn" href="/admin/users/<?= $tab !== 'all' ? '?tab=' . e($tab) : '' ?>">Сбросить</a><?php endif; ?>
    </form>

    <div class="card">
      <div class="card-hd"><h2>Учётные записи с доступом в админку</h2><span class="muted"><?= count($rows) ?> <?= plural(count($rows), 'человек', 'человека', 'человек') ?></span></div>
      <?php if (!$rows): ?>
        <div class="empty-card"><h2><?= $q !== '' ? 'Никого не нашли' : 'Здесь пока пусто' ?></h2>
          <p><?= $q !== '' ? 'Проверьте написание или сбросьте поиск.' : 'Добавьте сотрудника в форме справа.' ?></p></div>
      <?php else: ?>
      <div class="table-scroll"><table class="grid sys-users">
        <thead><tr><th>Сотрудник</th><th class="opt">Телефон</th><th>Роль</th><th>Последний вход</th><th>Доступ</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $rid = (int) $r['id']; $off = !(int) $r['status']; ?>
          <tr class="<?= $off ? 'is-draft' : '' ?>">
            <td><a href="/admin/users/<?= $rid ?>/"><b><?= e($r['name'] !== '' ? $r['name'] : '—') ?></b></a><?= $rid === $me ? ' <span class="pill">это вы</span>' : '' ?>
              <small><?= e((string) $r['email']) ?></small></td>
            <td class="opt nowrap"><?= $r['phone'] ? e(Orders::formatPhone($r['phone'])) : '<span class="muted">—</span>' ?></td>
            <td><span class="pill <?= $r['role'] === 'admin' ? 'confirmed' : '' ?>"><?= e(U::ROLES[$r['role']] ?? $r['role']) ?></span>
              <?= (int) $r['legacy'] ? '<small title="Пароль перенесён из Webasyst (md5)">старый пароль</small>' : '' ?></td>
            <td class="nowrap"><?php if ($r['last_login_at']): $ago = time() - strtotime((string) $r['last_login_at']); ?>
                <?= e(date('d.m.Y H:i', strtotime((string) $r['last_login_at']))) ?>
                <small><?= e(SystemStatus::ago($ago)) ?><?= isset($lastIp[$rid]) ? ' · ' . e($lastIp[$rid]) : '' ?></small>
              <?php else: ?><span class="muted">ни разу</span><?php endif; ?></td>
            <td><span class="pill <?= $off ? 'cancelled' : 'ok' ?>"><?= $off ? 'Отключён' : 'Открыт' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <p class="hint">Покупателей и их заказы смотрите в разделе <a href="/admin/customers/">«Клиенты»</a>. Сотрудник — та же учётная запись сайта, но с доступом в админку.</p>
  </div>

  <aside>
    <form method="post" action="/admin/users/create/" class="card pad-card" id="new" autocomplete="off" novalidate>
      <?= BaseController::tokenField() ?>
      <h2>Новый сотрудник</h2>
      <label class="fld"><span>E-mail (логин)</span>
        <input type="email" name="email" value="<?= e($form['email']) ?>" maxlength="190" required<?= $inv('email') ?>><?= $err('email') ?></label>
      <label class="fld"><span>Имя</span>
        <input type="text" name="name" value="<?= e($form['name']) ?>" maxlength="190" placeholder="Как подписывать в журнале"<?= $inv('name') ?>><?= $err('name') ?></label>
      <label class="fld"><span>Телефон — необязательно</span>
        <input type="tel" name="phone" value="<?= e($form['phone']) ?>" maxlength="32" placeholder="+38 0XX XXX XX XX"<?= $inv('phone') ?>><?= $err('phone') ?></label>
      <label class="fld"><span>Роль</span>
        <select name="role" data-role-hint="#sys-role-hint"<?= $inv('role') ?>>
          <?php foreach (U::ROLES as $k => $label): ?><option value="<?= e($k) ?>"<?= $form['role'] === $k ? ' selected' : '' ?> data-hint="<?= e(U::ROLE_HINTS[$k]) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select><?= $err('role') ?>
        <small class="hint" id="sys-role-hint"><?= e(U::ROLE_HINTS[$form['role']] ?? U::ROLE_HINTS['manager']) ?></small></label>
      <label class="fld"><span>Пароль</span>
        <span class="sys-pass"><input type="password" name="password" minlength="<?= U::MIN_PASSWORD ?>" maxlength="200" autocomplete="new-password"<?= $inv('password') ?>>
          <button type="button" class="btn btn-sm" data-toggle-pass>Показать</button></span><?= $err('password') ?>
        <small class="hint">Не меньше <?= U::MIN_PASSWORD ?> символов. Оставьте пустым — пароль будет сгенерирован и показан один раз.</small></label>
      <label class="check"><input type="checkbox" name="invite" value="1"<?= $form['invite'] ? ' checked' : '' ?>> Отправить приглашение на e-mail</label>
      <p class="hint">В письме — адрес админки, логин и ссылка, чтобы сотрудник сам задал пароль (действует 72 часа). Пароль по почте не отправляется.</p>
      <p class="hint">Если этот e-mail уже зарегистрирован на сайте как покупатель, его учётная запись получит доступ в админку, а пароль будет заменён.</p>
      <button class="btn btn-p sys-mt">Добавить сотрудника</button>
    </form>

    <div class="card pad-card">
      <h2>Роли</h2>
      <dl class="detail sys-roles">
        <div><dt>Администратор</dt><dd><?= e(U::ROLE_HINTS['admin']) ?></dd></div>
        <div><dt>Менеджер</dt><dd><?= e(U::ROLE_HINTS['manager']) ?></dd></div>
      </dl>
      <p class="hint">Полный доступ лучше оставить 1–3 людям. Себя и последнего администратора понизить или отключить нельзя.
        Отключённый сотрудник теряет доступ сразу, даже с открытой админкой.</p>
    </div>
  </aside>
</div>
