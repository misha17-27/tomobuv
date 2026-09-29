<?php
/**
 * «Поставщики → Jong•Golf»: вкладки Настройки | Категории | Цены | Журнал.
 * @var string $tab @var string $base @var array $cfg @var bool $enabled @var string $keyHint @var bool $debug @var array $due @var ?int $next
 * @var ?array $active @var ?array $last @var ?array $lastFinished @var array $mapCount @var array $products @var int $manual
 * @var bool $hasDict @var ?int $dictTime @var bool $hasPages
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\SuppliersController;
use App\Services\Suppliers\JongGolf;
use App\Services\Suppliers\JongGolfSync;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$on = static fn(string $k) => ($cfg[$k] ?? '') === '1';
$dt = static fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
$unmappedCount = count($lastFinished['stats']['unmapped'] ?? []);
?>
<?php if (!$enabled): ?>
<div class="flash warn jg-off" role="note">
  <b>Автозагрузка Jong•Golf выключена.</b> Включайте её только <b>после переключения домена на новый сайт и отключения cron старого загрузчика</b>
  (wa_loader_jonggolf.php на старом сервере): очередь товаров у поставщика общая на ключ, и два загрузчика «крадут» друг у друга обновления.
  Проверка подключения и пробный прогон работают и при выключенной — поставщику они ничего не подтверждают.
</div>
<?php else: ?>
<div class="flash jg-on">Автозагрузка включена: запуск по расписанию из cron (bin/cron.php) с <?= sprintf('%02d:00 до %02d:59', (int) $cfg['window_from'], (int) $cfg['window_to']) ?>, раз в <?= (int) $cfg['period'] ?> ч.
  <?= $next ? 'Следующий — около ' . e(date('d.m H:i', $next)) . '.' : e($due[1]) ?></div>
<?php endif; ?>
<?php if ($debug): ?>
<div class="flash warn">Режим разработки (config <code>debug = true</code>): подтверждения поставщику (callback) и полная выгрузка (clean_export) не отправляются никогда — запуск записывает только первую страницу очереди.</div>
<?php endif; ?>
<?php if ($active): ?>
<div class="flash warn">Идёт <?= e(mb_strtolower(JongGolf::KINDS[$active['kind']])) ?> №<?= (int) $active['id'] ?> — <a href="<?= e($base . 'runs/' . (int) $active['id'] . '/') ?>">открыть</a>.</div>
<?php endif; ?>

<div class="stats">
  <div class="stat<?= $enabled ? ' hot' : ' warn' ?>"><span><?= $enabled ? 'Вкл.' : 'Выкл.' ?></span>Автозагрузка</div>
  <a class="stat" href="<?= e($base . '?tab=runs') ?>"><span><?= $last ? e(date('d.m H:i', strtotime((string) $last['started_at']))) : '—' ?></span>
    <?= $last ? e(JongGolf::KINDS[$last['kind']] . ': ' . mb_strtolower(JongGolf::STATUSES[$last['status']] ?? $last['status'])) : 'Запусков ещё не было' ?></a>
  <div class="stat"><span><?= $fmt($products['active'] ?? 0) ?> / <?= $fmt($products['total'] ?? 0) ?></span>Товаров Jong•Golf на сайте (активных / всего)</div>
  <a class="stat<?= $mapCount['unresolved'] ? ' warn' : '' ?>" href="<?= e($base . '?tab=map') ?>"><span><?= $fmt($mapCount['total']) ?></span>Сочетаний в таблице категорий<?= $mapCount['unresolved'] ? ' (без категории: ' . $fmt($mapCount['unresolved']) . ')' : '' ?></a>
  <a class="stat<?= $unmappedCount ? ' warn' : '' ?>" href="<?= e($base . '?tab=map') ?>"><span><?= $fmt($unmappedCount) ?></span>Несопоставленных сочетаний в последнем прогоне</a>
</div>

<div class="card jg-acts">
  <div class="jg-actrow">
    <button class="btn" type="button" id="jg-test" data-url="<?= e($base . 'test/') ?>"<?= $cfg['api_key'] === '' ? ' disabled title="Сначала укажите ключ API"' : '' ?>>Проверить подключение</button>
    <form class="jg-dry" id="jg-dry" action="<?= e($base . 'start/') ?>" method="post" enctype="multipart/form-data">
      <input type="hidden" name="mode" value="dry">
      <select name="source" aria-label="Данные для пробного прогона">
        <option value="api"<?= $cfg['api_key'] === '' ? ' disabled' : '' ?>>Текущая очередь поставщика (1 страница, без подтверждения)</option>
        <option value="last"<?= $hasPages ? '' : ' disabled' ?>>Страницы последнего запуска</option>
        <option value="upload"<?= $hasDict ? '' : ' disabled' ?>>Файл с ответом export_product (JSON)</option>
      </select>
      <input type="file" name="feed" accept=".json,application/json" hidden>
      <button class="btn" type="submit">Пробный прогон</button>
    </form>
    <form id="jg-run" action="<?= e($base . 'start/') ?>" method="post" data-ask="Запустить загрузку сейчас? Товары будут записаны на сайт<?= $debug || !$enabled ? '' : ', поставщику уйдут подтверждения обработанных страниц' ?>.">
      <input type="hidden" name="mode" value="run">
      <button class="btn btn-p" type="submit"<?= $enabled ? '' : ' disabled title="Автозагрузка выключена"' ?>>Запустить сейчас</button>
    </form>
  </div>
  <p class="hint jg-res" id="jg-res" role="status">Проверка связи — только справочники (export_product_setting): у поставщика ничего не меняется. Пробный прогон ничего не пишет в каталог и не подтверждает поставщику — показывает, что будет добавлено, изменено и скрыто.</p>
</div>

<nav class="subtabs" aria-label="Разделы">
  <?php foreach (SuppliersController::TABS as $k => $label): ?>
    <a href="<?= e($base . ($k === 'settings' ? '' : '?tab=' . $k)) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?><?= $k === 'map' ? ' <i>' . $fmt($mapCount['total']) . '</i>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'settings'): ?>
<form method="post" action="<?= e($base . 'settings/') ?>" class="setform" autocomplete="off">
  <?= BaseController::tokenField() ?>
  <div class="two-col">
    <div>
      <div class="card">
        <h2>Подключение и расписание</h2>
        <label class="check jg-big"><input type="checkbox" name="enabled" value="1"<?= $on('enabled') ? ' checked' : '' ?>> <span><b>Автозагрузка включена</b> — только после отключения cron старого сайта</span></label>
        <label class="fld"><span>Ключ API (access_key)</span>
          <input name="api_key" type="password" value="" autocomplete="new-password" placeholder="<?= $keyHint !== '' ? e($keyHint) . ' сохранён (пусто — не менять, «-» — удалить)' : 'не задан' ?>">
          <span class="hint">Хранится в базе сайта, на экране и в отчётах не показывается. Поставщик пускает только IP из белого списка — сообщите ему IP нового хостинга.</span></label>
        <div class="row3">
          <label class="fld"><span>Запуск с, час</span><input name="window_from" type="number" min="0" max="23" value="<?= e($cfg['window_from']) ?>"></label>
          <label class="fld"><span>по, час (включительно)</span><input name="window_to" type="number" min="0" max="23" value="<?= e($cfg['window_to']) ?>"></label>
          <label class="fld"><span>Не чаще, чем раз в N часов</span><input name="period" type="number" min="1" max="24" value="<?= e($cfg['period']) ?>"></label>
        </div>
        <p class="hint">Время — Europe/Kiev (сейчас на сервере <?= e(date('H:i')) ?>). Как у старого загрузчика: окно 1–16 ч и 5 ч → запуски около 01, 06, 11, 16 ч. Нужен cron <code>bin/cron.php</code> раз в час (docs/INSTALL.md).</p>
      </div>

      <div class="card">
        <h2>Найденные товары</h2>
        <p class="hint jg-lead">Всегда обновляются цена (со скидкой — «старая цена»), наличие и видимость: цвет есть у поставщика — товар показан, закончился — скрыт и «нет в наличии».</p>
        <label class="check"><input type="checkbox" name="update_category" value="1"<?= $on('update_category') ? ' checked' : '' ?>> Менять категорию по таблице соответствия (отвязать от прежних)</label>
        <label class="check"><input type="checkbox" name="update_name" value="1"<?= $on('update_name') ? ' checked' : '' ?>> Менять название и мета-теги</label>
        <label class="check"><input type="checkbox" name="update_features" value="1"<?= $on('update_features') ? ' checked' : '' ?>> Перезаписывать характеристики, бренд и размер</label>
        <label class="check"><input type="checkbox" name="update_description" value="1"<?= $on('update_description') ? ' checked' : '' ?>> Менять описание</label>
        <label class="check"><input type="checkbox" name="keep_manual_hidden" value="1"<?= $on('keep_manual_hidden') ? ' checked' : '' ?>> Не показывать снова товары, скрытые вручную (старый загрузчик показывал)</label>
        <label class="check"><input type="checkbox" name="adopt_manual" value="1"<?= $on('adopt_manual') ? ' checked' : '' ?>> Брать под загрузчик товары Jong•Golf, заведённые вручную (найдены по коду цвета<?= $manual ? ', сейчас таких до ' . $fmt($manual) : '' ?>)</label>
        <p class="hint">Без этой галочки такой товар не меняется, но и дубль не создаётся — в отчёте строка «есть товар, заведённый вручную».</p>
      </div>

      <div class="card">
        <h2>Новые товары</h2>
        <label class="check"><input type="checkbox" name="add_new" value="1"<?= $on('add_new') ? ' checked' : '' ?>> Добавлять новые товары</label>
        <label class="check"><input type="checkbox" name="add_without_photo" value="1"<?= $on('add_without_photo') ? ' checked' : '' ?>> Добавлять без фото (иначе товар без скачанного фото не создаётся)</label>
        <fieldset class="jg-radios"><legend class="lbl">Название</legend>
          <?php foreach (JongGolf::NAME_MODES as $k => $label): ?>
            <label class="check"><input type="radio" name="name_mode" value="<?= e($k) ?>"<?= $cfg['name_mode'] === $k ? ' checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <p class="hint">Адрес страницы — транслит названия, как у старого загрузчика (krossovki-jonggolf-b11751-12), занятый — с «-2», «-3». Ящик и минимальный заказ — «пар в ящике» поставщика, остаток не ограничен.</p>
        <fieldset class="seo-set"><legend>SEO новых товаров</legend>
          <p class="hint">Пусто (рекомендуется) — title и description строят SEO-шаблоны сайта (раздел «SEO»), как у остальных товаров. Свой шаблон — с {name} (название). «{name}» и «купить {name} в Одессе» старого загрузчика SEO-стандарт сайта считает машинными и очищает.</p>
          <div class="pair">
            <label class="fld"><span>Title</span><input name="meta_title" value="<?= e($cfg['meta_title']) ?>"></label>
            <label class="fld"><span>Title (укр.)</span><input name="meta_title_uk" value="<?= e($cfg['meta_title_uk']) ?>"></label>
            <label class="fld"><span>Description</span><input name="meta_description" value="<?= e($cfg['meta_description']) ?>"></label>
            <label class="fld"><span>Description (укр.)</span><input name="meta_description_uk" value="<?= e($cfg['meta_description_uk']) ?>"></label>
            <label class="fld"><span>Keywords</span><input name="meta_keywords" value="<?= e($cfg['meta_keywords']) ?>"></label>
            <label class="fld"><span>Keywords (укр.)</span><input name="meta_keywords_uk" value="<?= e($cfg['meta_keywords_uk']) ?>"></label>
          </div>
        </fieldset>
      </div>

      <div class="card">
        <h2>Полное обновление</h2>
        <label class="check jg-big"><input type="checkbox" name="full_update" value="1"<?= $on('full_update') ? ' checked' : '' ?>> <span>Каждый запуск — весь каталог поставщика; товары Jong•Golf, которых у поставщика нет, <b>скрывать</b></span></label>
        <p class="hint">Первая страница запрашивается с clean_export (поставщик отдаёт каталог целиком). Товары, не пришедшие в выгрузке, скрываются (старый загрузчик их удалял вместе с фото).
          Защита: если скрыть пришлось бы больше половины активных товаров — скрытие отменяется (неполная выгрузка). Не скрывается и при пробном прогоне по одной странице.</p>
      </div>

      <div class="card">
        <h2>Пропуск и характеристики</h2>
        <div class="pair">
          <label class="fld"><span>Пропускаемые бренды (по одному в строке)</span><textarea name="skip_brands" rows="3"><?= e($cfg['skip_brands']) ?></textarea></label>
          <label class="fld"><span>Пропускаемые категории поставщика («Кросівки» или «Літнє взуття|Кросівки»)</span><textarea name="skip_categories" rows="3"><?= e($cfg['skip_categories']) ?></textarea></label>
        </div>
        <label class="fld"><span>Характеристики: поле поставщика=характеристика сайта (название или код)</span>
          <textarea name="attributes" rows="7" class="code"><?= e($cfg['attributes']) ?></textarea>
          <span class="hint">Поля: <?= e(implode(', ', array_map(static fn($k, $v) => $k . ' — ' . $v, array_keys(JongGolf::FIELDS), JongGolf::FIELDS))) ?>.</span>
          <?php if (!empty($attrErr)): ?><span class="wa-err" role="alert"><?= e(implode('; ', $attrErr)) ?></span><?php endif; ?></label>
      </div>

      <div class="card">
        <h2>Фото и прочее</h2>
        <div class="row3">
          <label class="fld"><span>Ширина холста, px</span><input name="photo_width" type="number" min="0" max="3000" value="<?= e($cfg['photo_width']) ?>"></label>
          <label class="fld"><span>Высота холста, px</span><input name="photo_height" type="number" min="0" max="3000" value="<?= e($cfg['photo_height']) ?>"></label>
          <label class="fld"><span>Качество JPEG</span><input name="photo_quality" type="number" min="30" max="100" value="<?= e($cfg['photo_quality']) ?>"></label>
        </div>
        <p class="hint">Фото вписывается в холст по центру на белом фоне (0 — сохранить как есть) и пересохраняется через GD в папку фото сайта (как у Webasyst).</p>
        <label class="check"><input type="checkbox" name="photo_replace" value="1"<?= $on('photo_replace') ? ' checked' : '' ?>> Заменять имеющиеся фото</label>
        <label class="check"><input type="checkbox" name="photo_check_file" value="1"<?= $on('photo_check_file') ? ' checked' : '' ?>> Проверять, что файл главного фото есть на диске (иначе скачать заново)</label>
        <div class="pair">
          <label class="fld"><span>Фильтр категории</span><select name="category_filter"><?php foreach (JongGolf::FILTER_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= $cfg['category_filter'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label class="fld"><span>Хранить отчёты, дней</span><input name="log_days" type="number" min="1" max="90" value="<?= e($cfg['log_days']) ?>"></label>
        </div>
      </div>
      <div class="savebar"><button class="btn btn-p">Сохранить</button><span class="hint">Настройки применяются со следующего запуска.</span></div>
    </div>

    <aside>
      <div class="card">
        <h2>Перенос со старого загрузчика</h2>
        <ol class="hint jg-steps">
          <li>Настройки: загрузите <b>wa_loader_jonggolf.cfg.php</b> (файл только читается, логин и пароль старой админки не переносятся).</li>
          <li>Таблицу категорий <b>table/category_table_jonggolf.xls</b> — на вкладке «Категории».</li>
          <li>Для пробного прогона без связи с API — справочники <b>tmp/product_setting.json</b>.</li>
          <li>После переключения домена: отключить cron старого сайта → включить автозагрузку здесь.</li>
        </ol>
      </div>
    </aside>
  </div>
</form>
<div class="two-col jg-uploads">
  <form class="card" method="post" action="<?= e($base . 'import-cfg/') ?>" enctype="multipart/form-data">
    <?= BaseController::tokenField() ?>
    <h2>Настройки из wa_loader_jonggolf.cfg.php</h2>
    <label class="fld"><span>Файл настроек старого загрузчика</span><input type="file" name="cfg" accept=".php,.txt" required></label>
    <button class="btn">Перенести настройки</button>
  </form>
  <form class="card" method="post" action="<?= e($base . 'dictionary/') ?>" enctype="multipart/form-data">
    <?= BaseController::tokenField() ?>
    <h2>Справочники поставщика</h2>
    <p class="hint"><?= $hasDict ? 'Сохранённая копия от ' . e(date('d.m.Y H:i', (int) $dictTime)) . ' (обновляется при каждой проверке связи и запуске).' : 'Копии нет — нажмите «Проверить подключение» или загрузите product_setting.json.' ?></p>
    <label class="fld"><span>product_setting.json</span><input type="file" name="dict" accept=".json" required></label>
    <button class="btn">Загрузить</button>
  </form>
</div>

<?php elseif ($tab === 'map'): ?>
<?php /** @var array $rows @var string $filter @var string $q @var \App\Core\Paginator $pg @var array $choices @var array $unmapped */ ?>
<div class="two-col">
  <form class="card" method="post" action="<?= e($base . 'map/upload/') ?>" enctype="multipart/form-data" data-confirm="Заменить таблицу соответствия загруженным файлом? Правки, сделанные здесь, пропадут (сначала можно скачать CSV).">
    <?= BaseController::tokenField() ?>
    <h2>Загрузить таблицу</h2>
    <p class="hint">Формат старого загрузчика (table/category_table_jonggolf.xls): первый лист, строка 1 — заголовок; A — сезон, B — подкатегория, C — пол (тексты справочников поставщика), D — размерный ряд (пусто — любой), E — категория сайта «ДЕТСКАЯ ОБУВЬ&gt;Кеды&gt;12-26». Загрузка заменяет таблицу целиком; повтор сочетания — действует последняя строка.</p>
    <label class="fld"><span>Файл XLS, XLSX или CSV</span><input type="file" name="table" accept=".xls,.xlsx,.csv,.txt" required></label>
    <div class="jg-actrow"><button class="btn btn-p">Загрузить</button><a class="btn" href="<?= e($base . 'map.csv') ?>">Скачать таблицу (CSV)</a></div>
  </form>
  <aside class="card">
    <h2>Как ищется категория</h2>
    <p class="hint">Сначала строка «сезон | категория | пол» без размерного ряда, потом — с точным рядом товара. Не нашлось — новый товар не создаётся (найденный обновляется без смены категории), сочетание попадает в список ниже.</p>
  </aside>
</div>

<template id="jg-cats"><?php foreach ($choices as $id => $path): ?><option value="<?= (int) $id ?>"><?= e($path) ?></option><?php endforeach; ?></template>
<?php if ($unmapped): ?>
<div class="card">
  <div class="card-hd"><h2>Несопоставленные сочетания последнего прогона (№<?= (int) $lastFinished['id'] ?>)</h2><span class="hint"><?= $fmt(count($unmapped)) ?></span></div>
  <div class="table-scroll"><table class="grid jg-unmapped"><thead><tr><th>Сезон</th><th>Категория</th><th>Пол</th><th>Ряд</th><th class="right">Цветов</th><th>Добавить сопоставление</th></tr></thead><tbody>
  <?php foreach (array_slice($unmapped, 0, 100, true) as $key => $n): [$s, $c, $g, $z] = array_pad(explode('|', (string) $key), 4, ''); ?>
    <tr><td><?= e($s) ?></td><td><?= e($c) ?></td><td><?= e($g) ?></td><td><?= e($z) ?></td><td class="right"><?= $fmt($n) ?></td>
      <td><form method="post" action="<?= e($base . 'map/add/') ?>" class="jg-add">
        <?= BaseController::tokenField() ?>
        <input type="hidden" name="season" value="<?= e($s) ?>"><input type="hidden" name="category" value="<?= e($c) ?>"><input type="hidden" name="gender" value="<?= e($g) ?>"><input type="hidden" name="size" value="<?= e($z) ?>">
        <select name="category_id" required aria-label="Категория сайта" data-cats><option value="">— категория сайта —</option></select>
        <label class="check"><input type="checkbox" name="any_size" value="1"> любой ряд</label>
        <button class="btn btn-sm">Добавить</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="filter-bar">
    <div class="tabs">
      <a href="<?= e($base . '?tab=map') ?>" class="<?= $filter === 'all' ? 'on' : '' ?>">Все <i><?= $fmt($mapCount['total']) ?></i></a>
      <a href="<?= e($base . '?tab=map&f=unresolved') ?>" class="<?= $filter === 'unresolved' ? 'on' : '' ?>">Без категории сайта <i><?= $fmt($mapCount['unresolved']) ?></i></a>
    </div>
    <form method="get" action="<?= e($base) ?>" class="jg-search"><input type="hidden" name="tab" value="map"><?php if ($filter !== 'all'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск: сезон, категория, пол, путь" aria-label="Поиск"><button class="btn">Найти</button></form>
  </div>
  <?php if ($rows): ?>
  <form method="post" action="<?= e($base . 'map/save/') ?>" id="jg-map">
    <?= BaseController::tokenField() ?>
    <div class="table-scroll"><table class="grid jg-map"><thead><tr><th>Сезон</th><th>Категория поставщика</th><th>Пол</th><th>Ряд</th><th>Категория на сайте</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $cid = (int) $r['category_id']; ?>
      <tr class="<?= $cid ? '' : 'jg-bad' ?>"><td><?= e($r['season']) ?></td><td><?= e($r['category']) ?></td><td><?= e($r['gender']) ?></td><td class="nowrap"><?= $r['size'] !== '' ? e($r['size']) : '<span class="muted">любой</span>' ?></td>
        <td><select name="cat[<?= (int) $r['id'] ?>]" data-was="<?= $cid ?>" data-cats aria-label="Категория сайта">
          <option value="0"<?= $cid ? '' : ' selected' ?>>— не выбрана<?= $r['target'] !== '' && !$cid ? ' (в файле: ' . e($r['target']) . ')' : '' ?> —</option>
          <?php if ($cid): ?><option value="<?= $cid ?>" selected><?= e($choices[$cid] ?? ('#' . $cid)) ?></option><?php endif; ?>
        </select></td>
        <td><button class="btn btn-sm btn-d" type="submit" form="jg-del-<?= (int) $r['id'] ?>" aria-label="Удалить строку">✕</button></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="savebar"><button class="btn btn-p">Сохранить сопоставления</button><span class="hint" id="jg-map-note">Изменённые строки подсвечиваются.</span></div>
  </form>
  <?php foreach ($rows as $r): ?><form id="jg-del-<?= (int) $r['id'] ?>" method="post" action="<?= e($base . 'map/' . (int) $r['id'] . '/delete/') ?>" data-confirm="Удалить строку «<?= e($r['season'] . ' | ' . $r['category'] . ' | ' . $r['gender'] . ' | ' . $r['size']) ?>»?" hidden><?= BaseController::tokenField() ?></form><?php endforeach; ?>
  <?= $pg->html() ?>
  <?php else: ?>
  <div class="empty-card"><h2><?= $mapCount['total'] ? 'Ничего не найдено' : 'Таблица соответствия пуста' ?></h2><p><?= $mapCount['total'] ? 'Измените поиск или фильтр.' : 'Загрузите table/category_table_jonggolf.xls старого загрузчика — без неё новые товары не создаются.' ?></p></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'prices'): ?>
<?php /** @var array $targets @var array $prices */ ?>
<form class="card" method="post" action="<?= e($base . 'prices/') ?>">
  <?= BaseController::tokenField() ?>
  <div class="card-hd"><h2>Коэффициенты цен по категориям сайта</h2></div>
  <p class="hint jg-pad">Цена на сайте = цена поставщика × множитель + надбавка (за пару, грн); так же — старая цена. У старого загрузчика наценки не было (×1, +0) — цены как у поставщика.</p>
  <?php if ($targets): ?>
  <div class="table-scroll"><table class="grid"><thead><tr><th>Категория</th><th class="right">Множитель</th><th class="right">Надбавка, грн</th><th class="right">500 грн →</th></tr></thead><tbody>
  <?php foreach ($targets as $cid => $path): $k = $prices[$cid]['k'] ?? 1; $plus = $prices[$cid]['plus'] ?? 0; ?>
    <tr><td><?= e($path) ?></td>
      <td class="right"><input class="jg-num" name="k[<?= (int) $cid ?>]" value="<?= e(rtrim(rtrim(number_format((float) $k, 4, '.', ''), '0'), '.')) ?>" inputmode="decimal" aria-label="Множитель"></td>
      <td class="right"><input class="jg-num" name="plus[<?= (int) $cid ?>]" value="<?= e(rtrim(rtrim(number_format((float) $plus, 2, '.', ''), '0'), '.')) ?>" inputmode="decimal" aria-label="Надбавка"></td>
      <td class="right nowrap"><?= e(price_format(round(500 * $k + $plus, 2))) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="savebar"><button class="btn btn-p">Сохранить</button></div>
  <?php else: ?><div class="empty-card"><h2>Нет категорий</h2><p>Сначала загрузите таблицу соответствия.</p></div><?php endif; ?>
</form>

<?php else: ?>
<?php /** @var array $runs */ ?>
<div class="card">
  <?php if ($runs): ?>
  <div class="table-scroll"><table class="grid"><thead><tr><th>№</th><th>Начат</th><th>Вид</th><th>Статус</th><th class="right">Товаров</th><th class="right">Добавлено</th><th class="right">Изменено</th><th class="right">Скрыто</th><th class="right">Пропущено</th><th class="right">Ошибок</th></tr></thead><tbody>
  <?php foreach ($runs as $r): $s = $r['stats']; ?>
    <tr class="<?= $r['status'] === 'running' ? 'unread' : '' ?>"><td><a href="<?= e($base . 'runs/' . (int) $r['id'] . '/') ?>">№<?= (int) $r['id'] ?></a></td>
      <td class="nowrap"><?= e($dt($r['started_at'])) ?><small><?= e(['cron' => 'по расписанию', 'cli' => 'командная строка', 'admin' => 'из админки'][$r['origin']] ?? $r['origin']) ?></small></td>
      <td><?= e(JongGolf::KINDS[$r['kind']] ?? $r['kind']) ?><?= $r['src'] === 'file' ? '<small>по файлу</small>' : '' ?></td>
      <td><span class="st <?= ['done' => 'st-completed', 'error' => 'st-refunded', 'stopped' => 'st-deleted', 'running' => 'st-processing'][$r['status']] ?? '' ?>"><?= e(JongGolf::STATUSES[$r['status']] ?? $r['status']) ?></span>
        <?php if ($r['error']): ?><small><?= e(mb_strimwidth((string) $r['error'], 0, 140, '…')) ?></small><?php endif; ?></td>
      <td class="right"><?= $r['kind'] === 'test' ? '—' : $fmt($s['products']) ?></td><td class="right"><?= $fmt($s['created']) ?></td><td class="right"><?= $fmt($s['updated']) ?></td>
      <td class="right"><?= $fmt($s['hidden_color'] + $s['hidden_missing']) ?></td><td class="right"><?= $fmt($s['skipped']) ?></td><td class="right"><?= $fmt($s['errors']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <p class="hint jg-pad">Отчёты хранятся <?= (int) $cfg['log_days'] ?> дн. (последние 5 запусков — всегда). Причины пропусков: <?= e(implode('; ', JongGolfSync::SKIP)) ?>.</p>
  <?php else: ?><div class="empty-card"><h2>Запусков ещё не было</h2><p>Начните с «Проверить подключение» и «Пробный прогон».</p></div><?php endif; ?>
</div>
<?php endif; ?>
