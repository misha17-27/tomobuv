<?php
/**
 * Редиректы: добавление, импорт CSV «старый адрес;новый адрес», список с поиском и массовым удалением.
 * @var array $rows @var App\Core\Paginator $pg @var string $q @var string $sort @var int $total @var int $all
 * @var ?array $report @var array $form
 */
use App\Controllers\Admin\BaseController;

$sorts = ['new' => 'Сначала новые', 'old' => 'Сначала старые', 'hits' => 'По переходам', 'from' => 'По адресу'];
$code = (int) ($form['code'] ?? 301);
?>
<div class="grid2">
  <div class="card">
    <h2>Добавить редирект</h2>
    <form method="post" action="/admin/redirects/add/" class="rd-add" novalidate>
      <?= BaseController::tokenField() ?>
      <label class="fld"><span>Старый адрес</span><input type="text" name="from_url" value="<?= e($form['from'] ?? '') ?>" placeholder="/old-page/" required maxlength="500" autocomplete="off"></label>
      <label class="fld"><span>Новый адрес</span><input type="text" name="to_url" value="<?= e($form['to'] ?? '') ?>" placeholder="/category/dyetskaya-obuv/" required maxlength="500" autocomplete="off"></label>
      <div class="rd-row">
        <label class="fld"><span>Код</span>
          <select name="code"><option value="301"<?= $code === 301 ? ' selected' : '' ?>>301 — навсегда</option><option value="302"<?= $code === 302 ? ' selected' : '' ?>>302 — временно</option></select>
        </label>
        <button class="btn btn-p">Сохранить</button>
      </div>
      <p class="hint">Можно вставить полный адрес https://tomobuv.com.ua/… — домен уберётся. Если такой старый адрес уже есть, редирект обновится.
        Срабатывает только для адресов, которых нет на сайте; на украинской версии — тоже (/ua/старый → /ua/новый).</p>
    </form>
  </div>
  <div class="card">
    <h2>Импорт из CSV</h2>
    <form method="post" action="/admin/redirects/import/" enctype="multipart/form-data">
      <?= BaseController::tokenField() ?>
      <label class="fld"><span>Файл CSV (Excel, UTF-8 или Windows-1251)</span><input type="file" name="file" accept=".csv,.txt,text/csv,text/plain"></label>
      <label class="fld"><span>или вставьте строки</span>
        <textarea name="csv" rows="4" placeholder="/old-page/;/new-page/&#10;/pages/stati/;/blog/;301"></textarea></label>
      <div class="rd-row"><button class="btn btn-p">Импортировать</button><span class="hint">Строка: <code>старый адрес;новый адрес</code>, третья колонка — код 301/302 (необязательно).</span></div>
    </form>
  </div>
</div>

<?php if ($report): ?>
  <div class="card rd-report">
    <h2>Результат импорта</h2>
    <p>Добавлено: <b><?= (int) $report['added'] ?></b>, обновлено: <b><?= (int) $report['updated'] ?></b>, пропущено с ошибками: <b><?= (int) $report['skipped'] ?></b>.</p>
    <?php if ($report['errors']): ?>
      <details open><summary>Ошибки (<?= (int) $report['errors_total'] ?>)</summary><ul class="rd-list err"><?php foreach ($report['errors'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
        <?php if ($report['errors_total'] > count($report['errors'])): ?><p class="hint">…и ещё <?= (int) ($report['errors_total'] - count($report['errors'])) ?></p><?php endif; ?></details>
    <?php endif; ?>
    <?php if ($report['warns']): ?>
      <details><summary>Предупреждения (<?= (int) $report['warns_total'] ?>)</summary><ul class="rd-list"><?php foreach ($report['warns'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form class="toolbar" method="get" action="/admin/redirects/" role="search">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск по адресу" aria-label="Поиск редиректов">
  <select name="sort" aria-label="Сортировка"><?php foreach ($sorts as $k => $l): ?><option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-p">Показать</button>
  <?php if ($q !== ''): ?><a class="btn" href="/admin/redirects/">Сбросить</a><?php endif; ?>
  <span class="sp"></span><span class="muted"><?= $q !== '' ? 'Найдено ' . (int) $total . ' из ' . (int) $all : 'Всего: ' . (int) $all ?></span>
</form>

<?php if (!$rows): ?>
  <div class="card empty-card"><h2><?= $q !== '' ? 'Ничего не найдено' : 'Редиректов пока нет' ?></h2><p><?= $q !== '' ? 'Измените условия поиска.' : 'Добавьте первый или загрузите список из CSV.' ?></p></div>
<?php else: ?>
<form method="post" action="/admin/redirects/delete/" id="rd-bulk" data-confirm="Удалить отмеченные редиректы?"><?= BaseController::tokenField() ?></form>
<div class="card flush">
<div class="bulk-bar"><label class="check"><input type="checkbox" data-check-all="ids[]"> Отметить все на странице</label><button class="btn btn-sm btn-d" form="rd-bulk">Удалить отмеченные</button></div>
<div class="table-scroll">
<table class="grid rd-tbl">
  <thead><tr><th class="tick"><span class="sr">Отметить</span></th><th>Старый адрес → новый</th><th class="opt">Код</th><th class="right opt">Переходов</th><th><span class="sr">Действия</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="tick"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="rd-bulk" aria-label="Отметить <?= e($r['from_url']) ?>"></td>
      <td class="rd-url"><a href="<?= e($r['from_url']) ?>" target="_blank" rel="noopener" title="Проверить"><?= e($r['from_url']) ?></a>
        <small>→ <a href="<?= e($r['to_url']) ?>" target="_blank" rel="noopener"><?= e($r['to_url']) ?></a><span class="show-sm"><?= (int) $r['code'] ?> · переходов: <?= (int) $r['hits'] ?></span></small></td>
      <td class="opt"><span class="pill"><?= (int) $r['code'] ?></span></td>
      <td class="right opt"><?= number_format((int) $r['hits'], 0, '', ' ') ?></td>
      <td class="nowrap right">
        <button type="button" class="btn btn-sm" data-edit-redirect data-from="<?= e($r['from_url']) ?>" data-to="<?= e($r['to_url']) ?>" data-code="<?= (int) $r['code'] ?>">Изменить</button>
        <form method="post" action="/admin/redirects/<?= (int) $r['id'] ?>/delete/" class="inline" data-confirm="Удалить редирект <?= e($r['from_url']) ?>?">
          <?= BaseController::tokenField() ?><button class="btn btn-sm btn-d" aria-label="Удалить редирект <?= e($r['from_url']) ?>" title="Удалить"><?= icon('trash', 'width:15px;height:15px') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?= $pg->html() ?>
<?php endif; ?>
