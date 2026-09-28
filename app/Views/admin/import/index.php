<?php
/**
 * Импорт / экспорт: загрузка файла поставщика, профили, последние задания.
 * @var array $jobs @var App\Core\Paginator $pg @var array $profiles @var int $maxSize @var int $chunkSize @var int $formLimit
 * @var array $statuses @var array $keys
 */
use App\Controllers\Admin\BaseController;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$mb = static fn(int $b) => $b >= 1048576 ? round($b / 1048576) . ' МБ' : round($b / 1024) . ' КБ';
$stClass = ['parsing' => 'st-processing', 'new' => 'st-new', 'running' => 'st-processing', 'finishing' => 'st-processing',
    'images' => 'st-processing', 'done' => 'st-completed', 'error' => 'st-refunded'];
$fmtNames = ['csv' => 'CSV', 'xlsx' => 'XLSX', 'xml' => 'XML', 'yml' => 'YML'];
?>
<div class="im-top">
  <form class="card im-upload" id="im-upload" method="post" action="/admin/import/upload/" enctype="multipart/form-data"
        data-chunk="<?= (int) $chunkSize ?>" data-max="<?= (int) $maxSize ?>">
    <?= BaseController::tokenField() ?>
    <h2>Загрузить прайс поставщика</h2>
    <label class="im-drop" id="im-drop">
      <input type="file" name="file" id="im-file" accept=".csv,.txt,.tsv,.xlsx,.xml,.yml">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3M7 8l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>
      <b id="im-file-name">Выберите файл или перетащите его сюда</b>
      <span class="hint">CSV (разделитель ; , или табуляция, UTF-8 или Windows-1251), Excel XLSX, XML / YML (Prom, Rozetka, Яндекс.Маркет) — до <?= e($mb($maxSize)) ?></span>
    </label>
    <label class="fld"><span>или ссылка на файл (фид YML/XML, CSV, XLSX)</span>
      <input type="url" name="source_url" id="im-url" placeholder="https://supplier.com/export/prices.xml" maxlength="1000"></label>
    <label class="fld"><span>Профиль поставщика</span>
      <select name="profile_id" id="im-profile">
        <option value="0">Без профиля — сопоставлю колонки сам</option>
        <?php foreach ($profiles as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($fmtNames[$p['format']] ?? strtoupper((string) $p['format'])) ?>)</option><?php endforeach; ?>
      </select>
      <small class="hint">Профиль помнит сопоставление колонок, наценку, ключ поиска и другие настройки поставщика.</small></label>
    <div class="im-upbar" id="im-upbar" hidden><span class="meter"><i id="im-upmeter" style="width:0"></i></span><span id="im-uptext" class="hint">Загрузка…</span></div>
    <div class="im-upacts">
      <button class="btn btn-p" type="submit" id="im-upbtn">Загрузить и настроить</button>
      <span class="hint">Сначала файл разбирается и показывается предпросмотр — на сайте ничего не меняется, пока вы не нажмёте «Запустить импорт».</span>
    </div>
  </form>

  <div class="card im-howto">
    <h2>Как это работает</h2>
    <ol class="im-steps">
      <li><b>Загрузите файл</b> поставщика или выгрузку с сайта (<a href="/admin/export/">экспорт в CSV</a>).</li>
      <li><b>Сопоставьте колонки</b> с полями товара: артикул, название, цена за пару или за ящик, пар в ящике, размеры, бренд, категория, фото…</li>
      <li><b>Выберите ключ поиска</b> — по нему находятся товары, которые уже есть на сайте (артикул, код поставщика, ID, адрес или название).</li>
      <li><b>Проверьте предпросмотр</b> первых 20 строк: что будет создано, что обновлено и что пропущено.</li>
      <li><b>Запустите импорт</b> — он идёт частями по 400 строк, страницу можно не закрывать до конца. Если связь оборвалась — нажмите «Продолжить».</li>
    </ol>
    <p class="hint">Новые товары получают дату добавления «сегодня» и попадают в «Новинки». После импорта каталог и кэш страниц обновляются автоматически.</p>
    <p class="hint">Автоматический импорт по расписанию (cron): <code>php bin/import.php ID_профиля [файл или ссылка]</code></p>
  </div>
</div>

<div class="card">
  <div class="card-hd"><h2>Профили поставщиков</h2><span class="hint im-m0">Создаются на странице задания кнопкой «Сохранить как профиль»</span></div>
  <?php if (!$profiles): ?>
    <div class="empty-card"><h2>Профилей пока нет</h2><p>Настройте первый импорт и сохраните настройки как профиль — в следующий раз колонки сопоставятся сами.</p></div>
  <?php else: ?>
  <div class="table-scroll"><table class="grid">
    <thead><tr><th>Профиль</th><th>Формат</th><th>Поставщик</th><th>Ключ поиска</th><th class="right">Наценка</th><th>Последний импорт</th><th class="right">ID</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($profiles as $p): $o = $p['options']; ?>
      <tr>
        <td><b><?= e($p['name']) ?></b><?php if (!empty($o['source_url'])): ?><small class="im-url" title="<?= e($o['source_url']) ?>"><?= e($o['source_url']) ?></small><?php endif; ?></td>
        <td><?= e($fmtNames[$p['format']] ?? $p['format']) ?></td>
        <td><?= e($o['supplier'] !== '' ? $o['supplier'] : '—') ?></td>
        <td><?= e($keys[$o['key']] ?? $o['key']) ?></td>
        <td class="right"><?= (float) $o['markup'] ? e(rtrim(rtrim(number_format((float) $o['markup'], 2, '.', ''), '0'), '.')) . ' %' : '—' ?></td>
        <td><?= $p['last_run'] ? e(date('d.m.Y H:i', strtotime((string) $p['last_run']))) : '<span class="muted">ещё не было</span>' ?></td>
        <td class="right"><code><?= (int) $p['id'] ?></code></td>
        <td class="right">
          <form method="post" action="/admin/import/profiles/<?= (int) $p['id'] ?>/delete/" data-confirm="Удалить профиль «<?= e($p['name']) ?>»? Задания и товары останутся.">
            <?= BaseController::tokenField() ?><button class="btn btn-sm btn-d" type="submit">Удалить</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-hd"><h2>Задания импорта</h2><?php if ($pg->total): ?><span class="hint im-m0">всего <?= $fmt($pg->total) ?></span><?php endif; ?></div>
  <?php if (!$jobs): ?>
    <div class="empty-card"><h2>Импортов ещё не было</h2><p>Загрузите первый файл поставщика в форме выше.</p></div>
  <?php else: ?>
  <div class="table-scroll"><table class="grid im-jobs">
    <thead><tr><th>№</th><th>Файл</th><th>Статус</th><th class="right">Строк</th><th class="right">Создано</th><th class="right">Обновлено</th><th class="right">Без изм.</th><th class="right">Пропущено</th><th class="right">Ошибок</th><th>Дата</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j):
      $href = '/admin/import/' . (int) $j['id'] . '/' . ($j['status'] === 'done' ? 'log/' : '');
      $pct = (int) $j['total'] ? min(100, (int) floor((int) $j['processed'] / (int) $j['total'] * 100)) : 0; ?>
      <tr class="<?= $j['status'] === 'new' ? 'unread' : '' ?>">
        <td><a href="<?= e($href) ?>"><b><?= (int) $j['id'] ?></b></a></td>
        <td class="im-fname"><a href="<?= e($href) ?>"><?= e($j['name'] !== '' ? $j['name'] : 'файл') ?></a>
          <small><?= e($fmtNames[$j['format']] ?? $j['format']) ?><?= $j['profile_name'] ? ' · ' . e($j['profile_name']) : '' ?></small></td>
        <td><span class="st <?= e($stClass[$j['status']] ?? '') ?>"><?= e($statuses[$j['status']] ?? $j['status']) ?></span>
          <?php if ($j['status'] === 'running'): ?><small><?= $pct ?>%</small><?php endif; ?></td>
        <td class="right"><?= $fmt($j['total']) ?></td>
        <td class="right"><?= (int) $j['created'] ? '<b class="im-plus">+' . $fmt($j['created']) . '</b>' : '0' ?></td>
        <td class="right"><?= $fmt($j['updated']) ?></td>
        <td class="right"><?= $fmt($j['unchanged']) ?></td>
        <td class="right"><?= $fmt($j['skipped']) ?></td>
        <td class="right"><?= (int) $j['error_count'] ? '<b class="im-bad">' . $fmt($j['error_count']) . '</b>' : '0' ?></td>
        <td class="nowrap"><?= e(date('d.m.Y H:i', strtotime((string) $j['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?= $pg->html() ?>
