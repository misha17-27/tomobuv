<?php
/**
 * Ход задания: разбор файла или импорт. Шаги выполняет import.js (POST /admin/import/{id}/run/).
 * @var array $job @var array $progress @var bool $autostart
 */
use App\Controllers\Admin\BaseController;

$p = $progress;
$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$id = (int) $job['id'];
$parsing = $job['status'] === 'parsing';
?>
<div class="card im-run" id="im-run" data-run="/admin/import/<?= $id ?>/run/" data-skip="/admin/import/<?= $id ?>/skip-images/"
     data-page="/admin/import/<?= $id ?>/" data-log="/admin/import/<?= $id ?>/log/" data-auto="<?= $autostart ? '1' : '0' ?>" data-status="<?= e($job['status']) ?>">
  <div class="card-hd">
    <h2 id="im-label"><?= e($p['label']) ?></h2>
    <span class="hint im-m0" id="im-elapsed"></span>
  </div>
  <div class="pad">
    <div class="im-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $p['percent'] ?>" aria-labelledby="im-label">
      <i id="im-fill" style="width:<?= (int) $p['percent'] ?>%"></i><b id="im-pct"><?= (int) $p['percent'] ?>%</b>
    </div>
    <p class="im-sub" id="im-sub">
      <?php if ($parsing): ?>Файл читается и складывается во временную таблицу — прочитано строк: <b id="im-total"><?= $fmt($p['total']) ?></b>
      <?php else: ?>Обработано <b id="im-done"><?= $fmt($p['processed']) ?></b> из <b id="im-total"><?= $fmt($p['total']) ?></b> строк<?php endif; ?>
    </p>
    <?php if (!$parsing): ?>
    <div class="stats im-counters">
      <div class="stat"><span id="im-c-created"><?= $fmt($p['created']) ?></span>Создано</div>
      <div class="stat"><span id="im-c-updated"><?= $fmt($p['updated']) ?></span>Обновлено</div>
      <div class="stat"><span id="im-c-unchanged"><?= $fmt($p['unchanged']) ?></span>Без изменений</div>
      <div class="stat"><span id="im-c-skipped"><?= $fmt($p['skipped']) ?></span>Пропущено</div>
      <div class="stat"><span id="im-c-errors"><?= $fmt($p['errors']) ?></span>Ошибок</div>
      <div class="stat"><span id="im-c-images"><?= $fmt($p['images']['done']) ?> / <?= $fmt($p['images']['total']) ?></span>Фото загружено</div>
    </div>
    <?php endif; ?>
    <div class="flash bad" id="im-error" hidden></div>
    <p class="hint" id="im-note"><?= $parsing ? 'Разбор идёт частями — большие файлы (50 000+ строк) читаются за несколько шагов.' : 'Импорт идёт частями по ' . (int) $job['options']['batch'] . ' строк. Не закрывайте страницу; если связь оборвётся — нажмите «Продолжить», импорт продолжится с того же места.' ?></p>
    <div class="im-runacts">
      <button class="btn btn-p" type="button" id="im-go"<?= $autostart ? ' hidden' : '' ?>><?= $job['processed'] ? 'Продолжить импорт' : ($parsing ? 'Продолжить разбор' : 'Запустить') ?></button>
      <button class="btn" type="button" id="im-pause"<?= $autostart ? '' : ' hidden' ?>>Пауза</button>
      <button class="btn" type="button" id="im-skipimg"<?= $job['status'] === 'images' ? '' : ' hidden' ?>>Не загружать оставшиеся фото</button>
      <a class="btn" href="/admin/import/<?= $id ?>/log/">Журнал</a>
    </div>
  </div>
</div>
<?php if (!$parsing): ?>
<form method="post" action="/admin/import/<?= $id ?>/delete/" data-confirm="Удалить задание? Уже созданные и изменённые товары останутся на сайте." class="im-del">
  <?= BaseController::tokenField() ?><button class="btn btn-sm btn-d" type="submit">Остановить и удалить задание</button></form>
<?php endif; ?>
