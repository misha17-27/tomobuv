<?php
/**
 * Безопасность: журнал входов, блокировки, доступ по IP, рекомендации.
 * @var string $tab @var string $q @var int $days @var array $counts @var array $log @var App\Core\Paginator $pg @var int $total
 * @var array $stats30 @var array $blocks @var string $ip @var array $configIps @var array $settingIps @var array $allowed @var array $tips
 * @var array $tried введённый логин неудачной попытки (в нижнем регистре) → найденная учётная запись (SecurityController::triedAccounts)
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\SecurityController;
use App\Core\Request;
use App\Core\Session;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$periods = [0 => 'За всё время', 1 => 'За сутки', 7 => 'За 7 дней', 30 => 'За 30 дней', 90 => 'За 90 дней'];
$draft = Session::flash('sys_ips_draft');
$ipsText = $draft !== null ? (string) $draft : implode("\n", $settingIps);
$left = static function (int $ts): string {
    $s = max(0, $ts - time());
    $m = (int) ceil($s / 60);
    return $m <= 1 ? 'меньше минуты' : $m . ' ' . plural($m, 'минуту', 'минуты', 'минут');
};
$isActions = $tab === 'actions';
?>
<div class="stats">
  <a class="stat" href="?tab=ok&amp;days=30"><span><?= $fmt($stats30['ok']) ?></span>Входов за 30 дней</a>
  <a class="stat<?= $stats30['failed'] ? ' hot' : '' ?>" href="?tab=failed&amp;days=30"><span><?= $fmt($stats30['failed']) ?></span>Неудачных попыток за 30 дней</a>
  <a class="stat<?= $blocks ? ' warn' : '' ?>" href="#blocks"><span><?= count($blocks) ?></span>Заблокировано сейчас</a>
  <a class="stat" href="#ips"><span><?= $allowed ? count($allowed) : '—' ?></span><?= $allowed ? 'Разрешённых IP' : 'Вход с любого IP' ?></a>
</div>

<div class="two-col sys-sec">
  <div>
    <div class="card" id="blocks">
      <div class="card-hd"><h2>Текущие блокировки</h2><span class="muted"><?= $blocks ? count($blocks) . ' ' . plural(count($blocks), 'блокировка', 'блокировки', 'блокировок') : 'нет' ?></span></div>
      <?php if (!$blocks): ?>
        <div class="pad"><p class="muted sys-m0">Сейчас никто не заблокирован. После 10 попыток входа в админку с одного IP за 15 минут вход с этого IP закрывается до конца
          15-минутного окна; вход в кабинет покупателя — после 10 неудачных попыток. Здесь блокировку можно снять раньше (например, если сотрудник забыл пароль и перебирал варианты).</p></div>
      <?php else: ?>
        <div class="table-scroll"><table class="grid">
          <thead><tr><th>Что закрыто</th><th>Для кого</th><th class="right">Попыток</th><th>Снимется через</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($blocks as $b): ?>
            <tr>
              <td><b><?= e($b['what']) ?></b></td>
              <td><?= e($b['who']) ?><?= $b['ipv'] !== '' && $b['ipv'] === $ip ? '<small>это ваш IP</small>' : '' ?></td>
              <td class="right"><?= (int) $b['hits'] ?> / <?= (int) $b['max'] ?></td>
              <td class="nowrap"><?= e($left($b['reset_at'])) ?><small>до <?= e(date('H:i', $b['reset_at'])) ?></small></td>
              <td class="right">
                <form method="post" action="/admin/security/unblock/" data-confirm="Снять блокировку «<?= e($b['what']) ?>» для <?= e($b['who']) ?>?">
                  <?= BaseController::tokenField() ?><input type="hidden" name="k" value="<?= e($b['k']) ?>">
                  <button class="btn btn-sm">Разблокировать</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <nav class="tabs" aria-label="Журнал">
      <?php foreach (SecurityController::TABS as $k => [$label]): ?>
        <a href="<?= e(Request::withQuery(['tab' => $k === 'all' ? null : $k, 'page' => null])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> <i><?= $fmt($counts[$k]) ?></i></a>
      <?php endforeach; ?>
    </nav>

    <form class="filter-bar" method="get" action="/admin/security/" role="search">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= $isActions ? 'IP, сотрудник, подробности' : 'IP, e-mail или логин' ?>" aria-label="Поиск по журналу">
      <select name="days" aria-label="Период"><?php foreach ($periods as $d => $l): ?><option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <button class="btn btn-p">Показать</button>
      <?php if ($q !== '' || $days): ?><a class="btn" href="<?= e('/admin/security/' . ($tab !== 'all' ? '?tab=' . $tab : '')) ?>">Сбросить</a><?php endif; ?>
      <span class="toolbar-sp muted"><?= ($q !== '' || $days) ? 'Найдено: ' . $fmt($total) : '' ?></span>
    </form>

    <div class="card">
      <div class="card-hd"><h2><?= $isActions ? 'Журнал действий в админке' : 'Журнал входов в админку' ?></h2><span class="muted"><?= $fmt($total) ?> <?= plural($total, 'запись', 'записи', 'записей') ?></span></div>
      <?php if (!$log): ?>
        <div class="empty-card"><h2><?= ($q !== '' || $days) ? 'Ничего не найдено' : 'Записей пока нет' ?></h2>
          <p><?= ($q !== '' || $days) ? 'Измените поиск или период.' : ($isActions ? 'Здесь появятся изменения настроек, статусов заказов, сотрудников.' : 'Каждый вход и каждая неудачная попытка записываются сюда с IP-адресом.') ?></p></div>
      <?php elseif ($isActions): ?>
        <div class="table-scroll"><table class="grid">
          <thead><tr><th>Время</th><th>Сотрудник</th><th>Действие</th><th class="opt">Подробности</th><th>IP</th></tr></thead>
          <tbody>
          <?php foreach ($log as $r): $u = SecurityController::entityUrl($r['entity'], $r['entity_id']); $ent = SecurityController::ENTITIES[$r['entity'] ?? ''] ?? (string) $r['entity']; ?>
            <tr>
              <td class="nowrap"><?= e(date('d.m.Y H:i', strtotime((string) $r['created_at']))) ?></td>
              <td><?= $r['role'] !== null ? e($r['name'] ?: ($r['email'] ?? '#' . $r['user_id'])) . '<small>' . e((string) $r['email']) . '</small>' : '<span class="muted">' . ($r['user_id'] ? 'учётная запись #' . (int) $r['user_id'] : '—') . '</span>' ?></td>
              <td><?= e(SecurityController::actionLabel((string) $r['action'])) ?>
                <?php if ($r['entity_id']): ?><small><?php if ($u): ?><a href="<?= e($u) ?>"><?= e($ent) ?> #<?= (int) $r['entity_id'] ?></a><?php else: ?><?= e($ent) ?> #<?= (int) $r['entity_id'] ?><?php endif; ?></small><?php endif; ?></td>
              <td class="opt sys-details"><?= $r['details'] !== null ? '<code>' . e(str_limit((string) $r['details'], 140)) . '</code>' : '' ?></td>
              <td><code><?= e((string) $r['ip']) ?></code></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="table-scroll"><table class="grid">
          <thead><tr><th>Время</th><th>Событие</th><th>Сотрудник / введённый логин</th><th>IP</th></tr></thead>
          <tbody>
          <?php foreach ($log as $r): $failed = $r['action'] === 'login_failed'; ?>
            <tr class="<?= $failed ? 'sys-failed' : '' ?>">
              <td class="nowrap"><?= e(date('d.m.Y H:i:s', strtotime((string) $r['created_at']))) ?></td>
              <td><span class="pill <?= $failed ? 'refunded' : 'ok' ?>"><?= $failed ? 'Неудачно' : 'Вход' ?></span></td>
              <td>
                <?php if ($failed):
                  // user_id есть — пароль подошёл, но это не сотрудник; нет — неверный пароль или такой учётной записи нет (ищем по введённому логину)
                  $who = $r['user_id'] ? null : ($tried[mb_strtolower(trim((string) $r['details']))] ?? null);
                  $isStaffAcc = $who && in_array($who['role'], ['admin', 'manager'], true); ?>
                  <b><?= e($r['details'] !== null && $r['details'] !== '' ? $r['details'] : '—') ?></b>
                  <small><?php if ($r['user_id']): ?><?= $r['role'] === null ? 'удалённая учётная запись #' . (int) $r['user_id'] : (in_array($r['role'], ['admin', 'manager'], true) ? 'пароль верный, но тогда учётная запись не была сотрудником' : 'пароль верный, но это <a href="/admin/customers/' . (int) $r['user_id'] . '/">покупатель #' . (int) $r['user_id'] . '</a>, а не сотрудник') ?>
                    <?php elseif ($who): ?><?= $who['off'] ? 'учётная запись отключена' : 'неверный пароль' ?> · <a href="/admin/<?= $isStaffAcc ? 'users' : 'customers' ?>/<?= (int) $who['id'] ?>/"><?= e($who['name'] !== '' ? $who['name'] : $who['email']) ?></a><?= $isStaffAcc ? '' : ' (покупатель)' ?>
                    <?php else: ?>такой учётной записи нет<?php endif; ?></small>
                <?php elseif ($r['role'] !== null): ?>
                  <a href="/admin/<?= in_array($r['role'], ['admin', 'manager'], true) ? 'users' : 'customers' ?>/<?= (int) $r['user_id'] ?>/"><b><?= e($r['name'] ?: ($r['email'] ?? '#' . $r['user_id'])) ?></b></a><small><?= e((string) $r['email']) ?></small>
                <?php else: ?><span class="muted">удалённая учётная запись #<?= (int) $r['user_id'] ?></span><?php endif; ?>
              </td>
              <td><code><?= e((string) $r['ip']) ?></code><?= $r['ip'] === $ip ? '<small>ваш IP</small>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
    <?= $pg->html() ?>

  </div>

  <aside>
    <form method="post" action="/admin/security/ips/" class="card pad-card" id="ips" novalidate>
      <?= BaseController::tokenField() ?>
      <h2>Доступ к админке по IP</h2>
      <p class="muted">Если список заполнен, админка (и страница входа) открывается только с этих адресов. Пусто — с любого адреса, по паролю.</p>
      <div class="sys-myip">Ваш IP сейчас: <code id="sys-my-ip"><?= e($ip) ?></code>
        <button type="button" class="btn btn-sm" data-add-ip="<?= e($ip) ?>">Добавить мой IP</button></div>
      <?php if ($configIps): ?>
        <p class="hint">Из <code>config/config.php</code> (меняются только в файле): <?= e(implode(', ', $configIps)) ?></p>
      <?php endif; ?>
      <label class="fld"><span>Разрешённые IP — по одному в строке</span>
        <textarea name="ips" rows="5" id="sys-ips" placeholder="например:&#10;93.184.216.34" spellcheck="false"><?= e($ipsText) ?></textarea></label>
      <div class="flash warn sys-warn"><b>Не заблокируйте себя.</b> Список без вашего текущего IP не сохранится. Домашний и мобильный интернет часто меняют IP —
        тогда вход закроется. Восстановить доступ: удалите строку <code>admin_ips</code> в таблице <code>settings</code> (phpMyAdmin) и выполните <code>php bin/cache-clear.php</code>.</div>
      <button class="btn btn-p">Сохранить список</button>
    </form>

    <div class="card pad-card">
      <h2>Рекомендации</h2>
      <ul class="sys-tips">
        <?php foreach ($tips as [$state, $text, $why]): ?>
          <li><i class="seo-dot <?= $state === 'bad' ? 'none' : e($state) ?>" aria-hidden="true"></i><div><b><?= e($text) ?></b><span><?= e($why) ?></span></div></li>
        <?php endforeach; ?>
      </ul>
      <p class="hint">Полная проверка сервера — в разделе <a href="/admin/status/">«Состояние системы»</a>.</p>
    </div>

    <div class="card pad-card">
      <h2>Уже работает</h2>
      <ul class="sys-plain">
        <li>Пароли хранятся только в виде хеша; старые md5-пароли из Webasyst перехешируются при первом входе.</li>
        <li>10 попыток входа в админку с одного IP за 15 минут — дальше вход с этого IP закрыт до конца окна; каждая попытка — в журнале с IP и введённым логином.</li>
        <li>Каждая форма админки защищена токеном (CSRF), чужой сайт не может отправить её за вас.</li>
        <li>Cookie сессии недоступна скриптам (HttpOnly, SameSite=Lax), при входе выдаётся новый идентификатор.</li>
        <li>Отключённый сотрудник теряет доступ сразу, даже если у него открыта админка.</li>
        <li>Служебные папки (config, storage, app, bin, database) закрыты от веба через .htaccess.</li>
      </ul>
    </div>
  </aside>
</div>
