<?php
/**
 * Состояние системы — что умеет сервер, что доступно на запись, что ещё не настроено.
 * @var array $groups (группа null — догружается из catalog.json) @var array $tally @var array $tables @var bool $isAdmin @var bool $local
 */
use App\Services\SystemStatus;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$shown = array_slice($tables, 0, 14);
$rest = array_slice($tables, 14);
?>
<div class="stats" id="sys-tally">
  <div class="stat" data-tally="ok"><span><?= (int) $tally['ok'] ?></span>В порядке</div>
  <div class="stat<?= $tally['warn'] ? ' hot' : '' ?>" data-tally="warn"><span><?= (int) $tally['warn'] ?></span>Стоит посмотреть</div>
  <div class="stat<?= $tally['bad'] ? ' warn' : '' ?>" data-tally="bad"><span><?= (int) $tally['bad'] ?></span>Нужно исправить</div>
</div>

<?php if (!$tally['bad'] && !$tally['warn']): ?>
  <div class="flash" id="sys-summary">Все проверки пройдены — сайт готов к работе.</div>
<?php elseif (!$tally['bad']): ?>
  <div class="flash warn" id="sys-summary">Ничего не сломано. Пункты с жёлтым знаком стоит посмотреть до запуска.</div>
<?php else: ?>
  <div class="flash bad" id="sys-summary">Есть что исправить до запуска сайта — см. строки с красным крестиком.</div>
<?php endif; ?>

<?php foreach ($groups as $title => $rows): ?>
  <div class="card">
    <div class="card-hd"><h2><?= e($title) ?></h2><?php if ($rows === null): ?><span class="muted" data-defer-note>считаем…</span><?php endif; ?></div>
    <div class="table-scroll">
      <table class="grid status sys-status">
        <?php if ($rows === null): ?>
          <tbody data-defer="/admin/status/catalog.json">
            <tr><td class="mark" aria-hidden="true">…</td><td class="sys-label"><b>Товары и индекс каталога</b></td>
              <td class="sys-value">Считаем по всем товарам…</td><td class="muted opt sys-note">Результат запомнится на 10 минут</td></tr>
          </tbody>
        <?php else: ?>
          <tbody><?= $view->partial('admin/status/_rows', ['rows' => $rows]) ?></tbody>
        <?php endif; ?>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<div class="card">
  <div class="card-hd"><h2>Таблицы базы</h2><span class="muted">строки InnoDB — приблизительно</span></div>
  <div class="table-scroll">
    <table class="grid">
      <thead><tr><th>Таблица</th><th class="right">Строк</th><th class="right">Размер</th></tr></thead>
      <tbody>
        <?php foreach ($shown as $t): ?>
          <tr>
            <td><b><?= e($t['label'] !== '' ? $t['label'] : $t['name']) ?></b><small><code><?= e($t['name']) ?></code></small></td>
            <td class="right">≈ <?= $fmt($t['rows']) ?></td>
            <td class="right"><?= e(SystemStatus::size($t['size'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tables): ?>
          <tr><td colspan="3" class="muted">Хостинг не даёт прочитать список таблиц (information_schema) — размер базы видно в phpMyAdmin.</td></tr>
        <?php endif; ?>
        <?php if ($rest): $rs = array_sum(array_column($rest, 'size')); ?>
          <tr><td class="muted">Ещё <?= count($rest) ?> <?= plural(count($rest), 'таблица', 'таблицы', 'таблиц') ?></td><td></td><td class="right muted"><?= e(SystemStatus::size($rs)) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card pad-card">
  <h2>Перед запуском на хостинге</h2>
  <ol class="sys-steps">
    <li>Корень сайта (DocumentRoot) — папка <code>public/</code>. Если хостинг так не умеет — загрузите проект в <code>public_html</code> целиком: корневой <code>.htaccess</code> закроет служебные папки.</li>
    <li>В <code>config/config.php</code>: <code>debug =&gt; false</code>, <code>base_url =&gt; 'https://tomobuv.com.ua'</code>, свой <code>app_key</code>, пустой <code>images.remote_base</code>.</li>
    <li>Дайте права на запись папкам <code>storage/</code>, <code>public/uploads/</code> и <code>public/wa-data/</code>; скопируйте <code>wa-data</code> со старого сайта.</li>
    <li>Добавьте cron раз в час: <code>0 * * * * php <?= e(str_replace('\\', '/', ROOT)) ?>/bin/cron.php</code></li>
    <li>Настройте <a href="/admin/mail/">почту (SMTP)</a> и отправьте себе тестовое письмо.</li>
    <li>Проверьте <?= $isAdmin ? '<a href="/admin/users/">сотрудников</a>' : 'сотрудников (раздел администратора)' ?>: отключите лишние учётные записи, у каждого — свой вход.</li>
    <li>Вернитесь на эту страницу и убедитесь, что служебные адреса отдают 403/404, а красных крестиков нет.</li>
  </ol>
</div>
