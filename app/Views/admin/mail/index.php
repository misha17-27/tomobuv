<?php
/**
 * Почта (SMTP): какие письма шлёт сайт, отправитель и адрес уведомлений, SMTP, тестовое письмо.
 * @var array $v сохранённые в админке значения (smtp_pass — только чтобы понять, задан ли) @var array $cfg значения config.php (smtp_pass — bool)
 * @var array $eff действующие значения (admin — Mailer::adminEmail(), adminFrom — откуда он взят) @var bool $isAdmin @var array $errors
 * @var string $storeEmail @var string $testTo @var array $notices @var bool $whatsapp @var array $log @var bool $dev @var int $devFiles
 * @var ?array $lastTest @var array $presets
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\MailController as M;

$err = static fn(string $k): string => isset($errors[$k]) ? '<small class="sys-err" role="alert">' . e($errors[$k]) . '</small>' : '';
$inv = static fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$ph = static fn(string $k, string $fallback = ''): string => $cfg[$k] !== '' ? 'из config.php: ' . $cfg[$k] : $fallback;
$passSaved = ($v['smtp_pass'] ?? '') !== '';
$smtp = $eff['host'] !== '';
$adminFallback = $cfg['admin_to'] !== '' ? 'из config.php: ' . $cfg['admin_to'] : ($storeEmail !== '' ? 'e-mail магазина: ' . $storeEmail : 'tomobuv@gmail.com');
?>
<div class="stats">
  <a class="stat<?= $smtp ? '' : ' hot' ?>" href="#smtp"><span><?= $smtp ? 'SMTP' : 'mail()' ?></span><?= $smtp ? 'Отправка через ' . e($eff['host']) : 'Отправка функцией хостинга' ?></a>
  <a class="stat<?= $eff['admin'] === '' ? ' warn' : '' ?>" href="#sender"><span class="sys-stat-sm"><?= $eff['admin'] !== '' ? str_replace('@', '@<wbr>', e($eff['admin'])) : 'не задан' ?></span>Куда приходят заказы и заявки</a>
  <a class="stat<?= $lastTest && !$lastTest['ok'] ? ' warn' : '' ?>" href="#test"><span><?= $lastTest ? ($lastTest['ok'] ? 'Отправлено' : 'Ошибка') : '—' ?></span>
    <?= $lastTest ? 'Последняя проверка ' . e(date('d.m.Y H:i', strtotime($lastTest['at']))) : 'Тестовое письмо ещё не отправляли' ?></a>
</div>

<?php if (!$isAdmin): ?><div class="flash warn">Только просмотр: настройки почты меняет администратор.</div><?php endif; ?>
<?php if ($errors): ?><div class="flash bad">Не сохранено — исправьте отмеченные поля.</div><?php endif; ?>
<?php if ($dev): ?>
  <div class="flash warn">Локальная разработка (env=dev): письма о заказах, заявках и приглашения не уходят адресатам, а сохраняются в <code>storage/logs/mail/</code>
    (сейчас <?= (int) $devFiles ?> <?= plural($devFiles, 'файл', 'файла', 'файлов') ?>). Тестовое письмо отсюда отправляется по-настоящему.</div>
<?php endif; ?>

<div class="card" id="notices">
  <div class="card-hd"><h2>Письма, которые отправляет сайт</h2><span class="muted"><?= count($notices) ?> <?= plural(count($notices), 'вид', 'вида', 'видов') ?> · шаблоны в <code>app/Views/emails/</code></span></div>
  <div class="table-scroll"><table class="grid sys-notices">
    <thead><tr><th>Письмо</th><th>Кому</th><th class="opt">Когда</th><th>Адрес</th></tr></thead>
    <tbody>
      <?php foreach ($notices as [$name, $whom, $when, $addr, $bad]): ?>
        <tr>
          <td><b><?= e($name) ?></b><small class="sys-when-m"><?= e($when) ?></small></td>
          <td class="nowrap"><?= e($whom) ?></td>
          <td class="opt muted"><?= e($when) ?></td>
          <td><?php if ($bad): ?><span class="sys-bad"><?= e($addr) ?></span><?php else: ?><?= e($addr) ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="pad sys-pad-sm">
    <p class="hint sys-m0">Все письма администратору идут на один адрес — поле «Куда присылать новые заказы и заявки» ниже.
      Новые заказы и заявки можно дублировать в WhatsApp: сейчас <b><?= $whatsapp ? 'включено' : 'выключено' ?></b> — <a href="/admin/whatsapp/">настроить WhatsApp</a>.</p>
  </div>
</div>

<form method="post" action="/admin/mail/" class="two-col" autocomplete="off" novalidate>
  <?= BaseController::tokenField() ?>
  <fieldset class="sys-fs"<?= $isAdmin ? '' : ' disabled' ?>>
    <div class="card" id="sender">
      <div class="card-hd"><h2>Отправитель и адрес уведомлений</h2></div>
      <div class="pad">
        <div class="pair">
          <label class="fld"><span>Имя отправителя</span><input type="text" name="from_name" value="<?= e($v['from_name']) ?>" maxlength="100" placeholder="<?= e($ph('from_name', 'Tomobuv')) ?>"<?= $inv('from_name') ?>><?= $err('from_name') ?></label>
          <label class="fld"><span>E-mail отправителя (От кого)</span><input type="email" name="from" value="<?= e($v['from']) ?>" maxlength="190" placeholder="<?= e($ph('from', 'noreply@tomobuv.com.ua')) ?>"<?= $inv('from') ?>><?= $err('from') ?></label>
        </div>
        <p class="hint sys-hint-gap">С SMTP указывайте тот же ящик, что и логин SMTP, — иначе Gmail и Ukr.net помечают письма как подделку. Пустое поле — значение из <code>config/config.php</code>.</p>
        <label class="fld"><span>Куда присылать новые заказы и заявки</span>
          <input type="email" name="admin_to" value="<?= e($v['admin_to']) ?>" maxlength="190" placeholder="<?= e($adminFallback) ?>"<?= $inv('admin_to') ?>><?= $err('admin_to') ?>
          <small class="hint">Один адрес: новые заказы и «Купить в 1 клик», заявки, отзывы. Пусто — адрес из <code>config.php</code> (запасной вариант), если и там нет — e-mail магазина из «Настроек».
            Это же поле — в «Настройки → Почта».</small></label>
        <p class="sys-now">Сейчас письма уходят на: <?php if ($eff['admin'] !== ''): ?><b><?= e($eff['admin']) ?></b> <span class="muted">(<?= e($eff['adminFrom']) ?>)</span><?php else: ?><span class="sys-bad">адрес не задан — уведомления о заказах не отправляются</span><?php endif; ?></p>
      </div>
    </div>

    <div class="card" id="smtp">
      <div class="card-hd"><h2>Отправка через SMTP</h2><span class="pill <?= $smtp ? 'ok' : 'new' ?>">сейчас: <?= $smtp ? 'SMTP' : 'mail() хостинга' ?></span></div>
      <div class="pad">
        <p class="muted">Письма через почтовый ящик (SMTP) доходят заметно надёжнее, чем через функцию <code>mail()</code> хостинга, и реже попадают в спам.
          Оставьте сервер пустым — будет использоваться <?= $cfg['smtp_host'] !== '' ? 'SMTP из config.php (' . e($cfg['smtp_host']) . ')' : '<code>mail()</code>' ?>.</p>
        <?php if ($isAdmin): ?>
          <div class="sys-presets"><span class="muted">Заполнить для:</span>
            <?php foreach ($presets as $k => [$label, $host, $port, $sec, $how]): ?>
              <button type="button" class="chip" data-preset="<?= e(json_encode(['host' => $host, 'port' => $port, 'secure' => $sec, 'how' => $how], JSON_UNESCAPED_UNICODE)) ?>"><?= e($label) ?></button>
            <?php endforeach; ?>
          </div>
          <p class="hint sys-preset-how hidden" id="sys-preset-how" aria-live="polite"></p>
        <?php endif; ?>
        <label class="fld"><span>SMTP-сервер</span><input type="text" name="smtp_host" id="sys-smtp-host" value="<?= e($v['smtp_host']) ?>" maxlength="190" placeholder="<?= e($ph('smtp_host', 'smtp.gmail.com')) ?>" spellcheck="false"<?= $inv('smtp_host') ?>><?= $err('smtp_host') ?></label>
        <div class="triple">
          <label class="fld"><span>Порт</span><input type="number" name="smtp_port" id="sys-smtp-port" value="<?= e($v['smtp_port']) ?>" min="1" max="65535" placeholder="<?= e($cfg['smtp_port'] !== '' ? $cfg['smtp_port'] : '465') ?>"<?= $inv('smtp_port') ?>><?= $err('smtp_port') ?></label>
          <label class="fld"><span>Шифрование</span>
            <select name="smtp_secure" id="sys-smtp-secure"<?= $inv('smtp_secure') ?>>
              <option value="">как в config.php (<?= e($cfg['smtp_secure'] !== '' ? $cfg['smtp_secure'] : 'ssl') ?>)</option>
              <?php foreach (M::SECURE as $k => $label): ?><option value="<?= e($k) ?>"<?= $v['smtp_secure'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select><?= $err('smtp_secure') ?></label>
          <label class="fld"><span>Логин (полный адрес ящика)</span><input type="text" name="smtp_user" value="<?= e($v['smtp_user']) ?>" maxlength="190" autocomplete="off" spellcheck="false" placeholder="<?= e($ph('smtp_user', 'tomobuv@gmail.com')) ?>"<?= $inv('smtp_user') ?>><?= $err('smtp_user') ?></label>
        </div>
        <label class="fld"><span>Пароль SMTP</span>
          <span class="sys-pass"><input type="password" name="smtp_pass" maxlength="200" autocomplete="new-password"
            placeholder="<?= $passSaved ? 'сохранён — оставьте пустым, чтобы не менять' : ($cfg['smtp_pass'] ? 'задан в config.php — оставьте пустым' : 'пароль от ящика или «пароль приложения»') ?>"<?= $inv('smtp_pass') ?>>
            <?php if ($isAdmin): ?><button type="button" class="btn btn-sm" data-toggle-pass>Показать</button><?php endif; ?></span><?= $err('smtp_pass') ?>
          <small class="hint">Сохранённый пароль нигде не показывается — ни здесь, ни в журнале. Пустое поле — пароль не меняется; чтобы заменить, введите новый.</small></label>
        <?php if ($passSaved): ?>
          <label class="check"><input type="checkbox" name="smtp_pass_clear" value="1"> Забыть сохранённый пароль<?= $cfg['smtp_pass'] ? ' (будет использоваться пароль из config.php)' : '' ?></label>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" id="test">
      <div class="card-hd"><h2>Проверка</h2>
        <?php if ($lastTest): ?><span class="pill <?= $lastTest['ok'] ? 'ok' : 'refunded' ?>"><?= $lastTest['ok'] ? 'последний тест принят сервером' : 'последний тест с ошибкой' ?></span><?php endif; ?></div>
      <div class="pad">
        <label class="fld"><span>Куда отправить тестовое письмо</span>
          <input type="email" name="test_to" value="<?= e($testTo) ?>" maxlength="190" placeholder="<?= e($eff['admin'] ?: 'ваш e-mail') ?>"></label>
        <p class="hint">Отправьте и на ящик у другого почтового сервиса (Gmail, Ukr.net, i.ua) — так видно, дойдёт ли письмо покупателю и не попадёт ли в «Спам».
          «Отправлено» значит, что сервер принял письмо; дошло ли оно — смотрите в ящике.</p>
        <?php if ($lastTest): ?>
          <p class="sys-lasttest<?= $lastTest['ok'] ? '' : ' sys-bad' ?>"><?= e(date('d.m.Y H:i', strtotime($lastTest['at']))) ?> · <?= e($lastTest['who'] ?: 'сотрудник') ?> → <?= e($lastTest['to']) ?> через <?= e($lastTest['via']) ?>:
            <?= $lastTest['ok'] ? 'отправлено' : 'ошибка — ' . e($lastTest['error']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isAdmin): ?>
      <div class="savebar">
        <button class="btn btn-p" name="act" value="save">Сохранить</button>
        <button class="btn" name="act" value="test">Сохранить и отправить тестовое письмо</button>
        <span class="hint">Тест — до 10 писем в час.</span>
      </div>
    <?php endif; ?>
  </fieldset>

  <aside>
    <div class="card pad-card">
      <h2>Сейчас используется</h2>
      <dl class="detail sys-dl">
        <div><dt>От кого</dt><dd><?= e($eff['from_name']) ?><small class="muted"><?= e($eff['from'] !== '' ? $eff['from'] : 'noreply@ (адрес сайта)') ?></small></dd></div>
        <div><dt>Уведомления</dt><dd><?= $eff['admin'] !== '' ? e($eff['admin']) . '<small class="muted">' . e($eff['adminFrom']) . '</small>' : '<span class="sys-bad">не задан</span>' ?></dd></div>
        <div><dt>Способ</dt><dd><?= $smtp ? 'SMTP ' . e($eff['host']) . ':' . (int) $eff['port'] . ' · ' . e($eff['secure']) : 'mail() хостинга' ?></dd></div>
        <?php if ($smtp): ?>
          <div><dt>Логин SMTP</dt><dd><?= $eff['user'] !== '' ? e($eff['user']) : '<span class="muted">без авторизации</span>' ?></dd></div>
          <div><dt>Пароль SMTP</dt><dd><?= $passSaved ? 'сохранён в админке' : ($cfg['smtp_pass'] ? 'задан в config.php' : '<span class="muted">не задан</span>') ?></dd></div>
        <?php endif; ?>
      </dl>
    </div>

    <div class="card pad-card">
      <h2>Где взять настройки SMTP</h2>
      <ul class="sys-plain">
        <?php foreach ($presets as [$label, $host, $port, $sec, $how]): ?>
          <li><b><?= e($label) ?></b> — <code><?= e($host) ?></code>, <?= (int) $port ?>, <?= e(strtoupper($sec)) ?>. <?= e($how) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card pad-card">
      <h2>Журнал почты</h2>
      <?php if ($log): ?>
        <pre class="log"><?= e(implode("\n", $log)) ?></pre>
        <p class="hint">Последние записи <code>storage/logs/mail-*.log</code>: ошибки отправки и сохранённые письма.</p>
      <?php else: ?><p class="muted sys-m0">Ошибок отправки не было.</p><?php endif; ?>
    </div>
  </aside>
</form>
