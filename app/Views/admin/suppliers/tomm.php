<?php
/**
 * «Поставщики → Tom.m»: вкладки Обзор (по умолчанию) | Настройки | Категории | Журнал. Кнопки «Запустить сейчас» /
 * «Остановить» — в шапке (SupplierPages::headerActions) и на «Обзоре».
 * @var string $tab @var string $base @var array $cfg @var bool $enabled @var ?array $markup @var bool $debug @var array $due @var ?int $next
 * @var string $nextMode @var ?array $active @var ?array $last @var ?array $lastFinished @var array $mapCount @var array $products @var array $links
 * @var bool $hasPages @var array $ours [total, forsage, manual, tomm] — наши товары брендов Tom.m @var array $brandNames [id => название]
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\TommController;
use App\Services\Suppliers\SiteClient;
use App\Services\Suppliers\Tomm;
use App\Services\Suppliers\TommMap;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$on = static fn(string $k) => ($cfg[$k] ?? '') === '1';
$money = static fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ' '), '0'), '.');
$schedule = sprintf('%02d:00–%02d:59', (int) $cfg['window_from'], (int) $cfg['window_to']);
$perPage = max(1, (int) $cfg['per_page']);
$pagesPerPass = (int) ceil(1700 / $perPage);                                  // ≈1 700 товаров у бренда
// проходов в сутки при окне 0–23: раз в period ч (5 ч → 4,8 в сутки: 0, 5, 10, 15, 20, на следующий день с 1 ч)
$passes = $cfg['window_from'] === '0' && $cfg['window_to'] === '23' ? 24 / max(1, (int) $cfg['period']) : null;
?>
<?php if (!$enabled): ?>
<div class="flash warn jg-off" role="note">
  <b>Автозагрузка Tom.m по расписанию выключена.</b> <?= $markup === null ? 'Сначала укажите <b>наценку</b> (вкладка «Настройки»): цены на сайте бренда — <b>закупочные</b> для магазина, без коэффициента нельзя ни включить загрузку, ни запустить её вручную. ' : 'Ручной запуск («Запустить сейчас») доступен. ' ?>
  Перед включением проверьте таблицу разделов и сделайте пробный прогон. Боевой запуск возьмёт под загрузчик найденные у нас товары Tom.m (Forsage и заведённые вручную — названия, адреса и SEO не меняются),
  добавит новые (с фото) и будет сверять цены и наличие.
</div>
<?php else: ?>
<div class="flash jg-on">Автозагрузка включена: полная сверка цен и наличия по расписанию из cron (bin/cron.php) <?= $schedule === '00:00–23:59' ? 'круглые сутки' : 'с ' . e($schedule) ?>, раз в <?= (int) $cfg['period'] ?> ч.
  <?= $next ? 'Следующий запуск — около ' . e(date('d.m H:i', $next)) . ' (' . e(Tomm::MODES[$nextMode]) . ').' : e($due[1]) ?></div>
<?php endif; ?>
<?php if ($active && $tab !== 'overview'): ?>
<div class="flash warn">Идёт <?= e(mb_strtolower(Tomm::KINDS[$active['kind']])) ?> №<?= (int) $active['id'] ?> — <a href="<?= e($base) ?>">ход на «Обзоре»</a> · <a href="<?= e($base . 'runs/' . (int) $active['id'] . '/') ?>">отчёт</a>.</div>
<?php endif; ?>

<nav class="subtabs" aria-label="Разделы">
  <?php foreach (TommController::TABS as $k => $label): ?>
    <a href="<?= e($base . ($k === 'overview' ? '' : '?tab=' . $k)) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?><?= $k === 'map' && $mapCount['unresolved'] ? ' <i>' . $fmt($mapCount['unresolved']) . '</i>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
<?php ob_start(); ?>
<div class="card jg-acts">
  <h2 class="sup-acts-h">Проверка и пробный прогон</h2>
  <div class="jg-actrow">
    <button class="btn" type="button" id="jg-test" data-url="<?= e($base . 'test/') ?>">Проверить связь</button>
    <form class="jg-dry" id="jg-dry" action="<?= e($base . 'start/') ?>" method="post">
      <input type="hidden" name="mode" value="dry">
      <select name="source" aria-label="Данные для пробного прогона">
        <option value="web">С сайта бренда</option>
        <option value="last"<?= $hasPages ? '' : ' disabled' ?>>Страницы последнего запуска (без запросов)</option>
      </select>
      <select name="pass" aria-label="Режим прохода">
        <option value="full">Полный проход</option>
        <option value="quick">Быстрый (только новые сверху)</option>
      </select>
      <button class="btn" type="submit">Пробный прогон</button>
    </form>
    <label class="sup-pass"><span>Режим «Запустить сейчас»</span>
      <select id="sup-run-pass" aria-label="Режим боевого запуска">
        <option value="auto">по расписанию (сейчас: <?= e(Tomm::MODES[$nextMode]) ?>)</option>
        <?php foreach (Tomm::MODES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
  </div>
  <p class="hint jg-res" id="jg-res" role="status">Проверка связи — robots.txt и первая страница списка (2 запроса). Пробный прогон ничего не пишет в каталог: полный — весь каталог бренда
    (<?= $fmt($pagesPerPass) ?> запросов по <?= $fmt($perPage) ?> товаров, около минуты), быстрый — первые страницы (до <?= (int) $cfg['quick_pages'] ?>). Пауза между запросами — <?= e($cfg['delay']) ?> с.</p>
</div>
<?php $actionsCard = (string) ob_get_clean(); ?>
<?= $view->partial('admin/suppliers/overview', ['actionsCard' => $actionsCard]) ?>

<?php else: ?>
<div class="stats">
  <div class="stat<?= $enabled ? ' hot' : ' warn' ?>"><span><?= $enabled ? 'Вкл.' : 'Выкл.' ?></span>Автозагрузка по расписанию</div>
  <div class="stat<?= $markup ? '' : ' warn' ?>"><span><?= $markup ? '×' . e($money($markup['k'])) . ($markup['plus'] ? ' +' . e($money($markup['plus'])) : '') : 'нет' ?></span>Наценка на цену бренда</div>
  <a class="stat" href="<?= e($base . '?tab=runs') ?>"><span><?= $last ? e(date('d.m H:i', strtotime((string) $last['started_at']))) : '—' ?></span>
    <?= $last ? e(Tomm::KINDS[$last['kind']] . ': ' . mb_strtolower(Tomm::STATUSES[$last['status']] ?? $last['status'])) : 'Запусков ещё не было' ?></a>
  <div class="stat"><span><?= $fmt($products['active']) ?> / <?= $fmt($products['total']) ?></span>Товаров под загрузчиком (активных / всего)</div>
  <div class="stat"><span><?= $fmt($ours['total']) ?></span>Наших товаров Tom.m (Forsage <?= $fmt($ours['forsage']) ?>, вручную <?= $fmt($ours['manual']) ?>)</div>
  <a class="stat<?= $mapCount['unresolved'] ? ' warn' : '' ?>" href="<?= e($base . '?tab=map') ?>"><span><?= $fmt($mapCount['active']) ?> / <?= $fmt($mapCount['cats']) ?></span>Разделов бренда загружается<?= $mapCount['unresolved'] ? ' (без категории сайта: ' . $fmt($mapCount['unresolved']) . ')' : '' ?></a>
</div>
<?php endif; ?>

<?php if ($tab === 'settings'): ?>
<form method="post" action="<?= e($base . 'settings/') ?>" class="setform" autocomplete="off">
  <?= BaseController::tokenField() ?>
  <div class="two-col">
    <div>
      <div class="card">
        <h2>Загрузка и расписание</h2>
        <label class="check jg-big"><input type="checkbox" name="enabled" value="1"<?= $on('enabled') ? ' checked' : '' ?>> <span><b>Автозагрузка по расписанию включена</b> — только после того, как задана наценка, проверены разделы и сделан пробный прогон</span></label>
        <div class="row3">
          <label class="fld"><span>Запуск с, час</span><input name="window_from" type="number" min="0" max="23" value="<?= e($cfg['window_from']) ?>"></label>
          <label class="fld"><span>по, час (включительно)</span><input name="window_to" type="number" min="0" max="23" value="<?= e($cfg['window_to']) ?>"></label>
          <label class="fld"><span>Сверка — раз в N часов</span><input name="period" type="number" min="1" max="168" value="<?= e($cfg['period']) ?>"></label>
        </div>
        <label class="fld"><span>Полный проход (цены и наличие всех товаров) — не чаще раза в N часов; остальные запуски — быстрые (только новые сверху списка)</span><input name="full_hours" type="number" min="1" max="720" value="<?= e($cfg['full_hours']) ?>"></label>
        <div class="pair">
          <label class="fld"><span>Полный проход — с часа</span><input name="full_from" type="number" min="0" max="23" value="<?= e($cfg['full_from']) ?>"></label>
          <label class="fld"><span>по час (включительно)</span><input name="full_to" type="number" min="0" max="23" value="<?= e($cfg['full_to']) ?>"></label>
        </div>
        <p class="hint">Время — Europe/Kiev (сейчас на сервере <?= e(date('H:i')) ?>). По умолчанию (решение владельца) — <b>полная сверка цен и наличия всех товаров каждые 5 ч круглые сутки</b>:
          весь каталог бренда — <?= $fmt($pagesPerPass) ?> запросов (по <?= $fmt($perPage) ?> товаров на странице) и около минуты<?= $passes ? ', в сутки — ' . e(rtrim(rtrim(number_format($passes, 1, ',', ''), '0'), ',')) . ' прохода, ≈' . $fmt(round($passes * $pagesPerPass)) . ' запросов (и robots.txt раз в сутки)' : '' ?>,
          плюс карточка и фото каждого нового товара. Быстрый проход нужен только для ручного запуска. Нужен cron <code>bin/cron.php</code> раз в час (docs/INSTALL.md).
          «Запустить сейчас» работает и при выключенной автозагрузке (если задана наценка).</p>
      </div>

      <?= $view->partial('admin/suppliers/markup', ['sup' => Tomm::class, 'lead' => 'Цены на сайте бренда — <b>закупочные</b> для магазина (дилера). Цена на сайте за пару = цена бренда за пару × коэффициент + надбавка, затем округление;
          старая цена (скидка бренда) — так же. Закупочная цена сохраняется в карточке товара. Товар «нет в наличии» у бренда (там цена 0) — на сайте «нет в наличии», цена прежняя.',
          'after' => 'Ящик и минимальный заказ — «Пар в ящике» бренда.']) ?>

      <div class="card">
        <h2>Новые товары</h2>
        <label class="check"><input type="checkbox" name="add_new" value="1"<?= $on('add_new') ? ' checked' : '' ?>> Добавлять новые товары бренда (только в наличии, с ценой, сопоставленным разделом и фото)</label>
        <div class="pair">
          <label class="fld"><span>Не больше новых за запуск</span><input name="max_new" type="number" min="0" max="5000" value="<?= e($cfg['max_new']) ?>">
            <span class="hint">Каждый новый — карточка и фото (2–3 запроса с паузой): 100 новых ≈ 10 мин. 0 — без ограничения.</span></label>
          <label class="fld"><span>Пол (характеристика)</span><input name="gender" value="<?= e($cfg['gender']) ?>" maxlength="100"><span class="hint">Tom.m — детская обувь: «дети», как у наших товаров.</span></label>
        </div>
        <label class="check"><input type="checkbox" name="card_features" value="1"<?= $on('card_features') ? ' checked' : '' ?>> Открывать карточку нового товара (1 запрос): линейка бренда (BiKi …), сезон, материалы, все фото</label>
        <p class="hint">Название — по правилу сайта «Категория Tom.m Код» (RU и UA: «Зимняя обувь Tom.m 60112A» / «Зимове взуття Tom.m 60112A»), артикул — код бренда, адрес — транслит названия,
          бренд — <?= e(implode(', ', $brandNames) ?: 'нет на сайте!') ?>, SEO — шаблоны сайта. Значения характеристик — как на сайте, украинские слова — по-русски (не «чорний» рядом с «черный»).</p>
        <label class="fld"><span>Характеристики: поле бренда=характеристика сайта (название или код)</span>
          <textarea name="attributes" rows="7" class="code"><?= e($cfg['attributes']) ?></textarea>
          <span class="hint">Поля: <?= e(implode(', ', array_map(static fn($k, $v) => $k . ' — ' . $v, array_keys(Tomm::FIELDS), Tomm::FIELDS))) ?>. Значения — существующие (без учёта регистра), новые создаются.</span>
          <?php if (!empty($attrErr)): ?><span class="wa-err" role="alert"><?= e(implode('; ', $attrErr)) ?></span><?php endif; ?></label>
        <div class="row3">
          <label class="fld"><span>Ширина холста фото, px</span><input name="photo_width" type="number" min="0" max="3000" value="<?= e($cfg['photo_width']) ?>"></label>
          <label class="fld"><span>Высота холста, px</span><input name="photo_height" type="number" min="0" max="3000" value="<?= e($cfg['photo_height']) ?>"></label>
          <label class="fld"><span>Качество JPEG</span><input name="photo_quality" type="number" min="30" max="100" value="<?= e($cfg['photo_quality']) ?>"></label>
        </div>
        <p class="hint">Берутся все фото товара в самом крупном размере на сайте бренда (970 px), вписываются в холст через GD и сохраняются в папку фото сайта.</p>
      </div>

      <div class="card">
        <h2>Уже загруженные и найденные товары</h2>
        <label class="check jg-big"><input type="checkbox" name="adopt" value="1"<?= $on('adopt') ? ' checked' : '' ?>> <span>Брать под загрузчик найденные товары Tom.m (Forsage — <?= $fmt($ours['forsage']) ?>, заведённые вручную — <?= $fmt($ours['manual']) ?>)</span></label>
        <p class="hint">Название, адрес и SEO найденного товара не меняются. Без галочки найденный товар не меняется и дубль не создаётся — строка «есть на сайте» в отчёте.
          Поиск: связь → точный код (кириллица = латиница) и ряд → код без приставки Forsage (T, B, F, CT, AB) и ряд — только единственный; товары других загрузчиков не берутся.</p>
        <label class="check"><input type="checkbox" name="update_existing" value="1"<?= $on('update_existing') ? ' checked' : '' ?>> Обновлять цену, старую и закупочную цену, ящик, ряд, наличие</label>
        <label class="check"><input type="checkbox" name="update_category" value="1"<?= $on('update_category') ? ' checked' : '' ?>> Менять категорию по таблице разделов</label>
        <p class="hint">По умолчанию выключено: часть детских Tom.m у нас лежит только в МУЖСКОЙ / ЖЕНСКОЙ или в «Весна-Осень» без ряда; переложить их — решение владельца (включить, сделать пробный прогон и посмотреть, что переедет).</p>
        <label class="check"><input type="checkbox" name="add_photos" value="1"<?= $on('add_photos') ? ' checked' : '' ?>> Найденным товарам без фото — добавлять фото с сайта бренда (свои фото не заменяются)</label>
        <label class="check"><input type="checkbox" name="keep_manual_hidden" value="1"<?= $on('keep_manual_hidden') ? ' checked' : '' ?>> Не показывать снова товары, скрытые вручную</label>
        <label class="check"><input type="checkbox" name="hide_missing" value="1"<?= $on('hide_missing') ? ' checked' : '' ?>> Скрывать товары, пропавшие с сайта бренда</label>
        <label class="fld"><span>…после стольких полных проходов подряд без товара</span><input name="missing_runs" type="number" min="1" max="10" value="<?= e($cfg['missing_runs']) ?>"></label>
        <p class="hint">«Нет в наличии» у бренда — у нас «нет в наличии» (товар не скрывается). Пропал с сайта бренда — скрывается (не удаляется) только после полного прохода по всем страницам
          и только <?= (int) $cfg['missing_runs'] ?>-й раз подряд: остановленный, прерванный, быстрый или неполный проход ничего не скрывает; пропало больше половины активных — скрытие отменяется.</p>
        <label class="fld"><span>Бренды Tom.m на сайте (по одному в строке; новые товары — в первый)</span><textarea name="brands" rows="2"><?= e($cfg['brands']) ?></textarea></label>
      </div>

      <div class="card">
        <h2>Бережность к сайту бренда</h2>
        <div class="pair">
          <label class="fld"><span>Пауза между запросами, с (не меньше 1,5)</span><input name="delay" value="<?= e($cfg['delay']) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Не больше запросов за запуск</span><input name="max_requests" type="number" min="20" max="5000" value="<?= e($cfg['max_requests']) ?>"></label>
          <label class="fld"><span>Товаров на странице списка</span><select name="per_page"><?php foreach (Tomm::PER_PAGE as $k => $label): ?><option value="<?= e((string) $k) ?>"<?= (string) $cfg['per_page'] === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label class="fld"><span>Быстрый проход: страниц, не больше</span><input name="quick_pages" type="number" min="1" max="40" value="<?= e($cfg['quick_pages']) ?>"></label>
        </div>
        <p class="hint">Один поток, User-Agent «<?= e(SiteClient::UA) ?>», robots.txt соблюдается (у бренда закрыты только кабинет и оформление заказа). На 429/5xx — пауза 60 с с удвоением,
          после 3 ошибок подряд — остановка; на 403, проверку браузера и блок-страницу сетевого фильтра — сразу остановка «нужна проверка» (обходить защиту загрузчик не будет).
          100 товаров на странице сайт бренда принимает, хотя в меню его нет (24 / 36 / 48); если бренд против — выберите 48.</p>
      </div>

      <div class="card">
        <h2>Отчёты</h2>
        <div class="pair">
          <label class="fld"><span>Хранить отчёты запусков, дней</span><input name="log_days" type="number" min="1" max="90" value="<?= e($cfg['log_days']) ?>"></label>
          <label class="fld"><span>Историю цен и наличия («Обзор»), дней</span><input name="history_days" type="number" min="30" max="3650" value="<?= e($cfg['history_days']) ?>"></label>
        </div>
      </div>
      <div class="savebar"><button class="btn btn-p">Сохранить</button><span class="hint">Настройки применяются со следующего запуска.</span></div>
    </div>

    <aside>
      <div class="card">
        <h2>Как включить</h2>
        <ol class="hint jg-steps">
          <li>Задайте <b>наценку</b> (без неё включить нельзя).</li>
          <li>Проверьте вкладку «Категории» — черновик по разбору сайта бренда.</li>
          <li>«Проверить связь», затем «Пробный прогон» — посмотрите отчёт: найденные товары, цены до и после наценки, что станет «нет в наличии», что добавится.</li>
          <li>Включите загрузку. «Хит обуви» товары Tom.m не загружает (бренд в пропускаемых).</li>
        </ol>
      </div>
      <div class="card">
        <h2>Связи</h2>
        <p class="hint">Товаров бренда, связанных с товарами сайта: <?= $fmt($links['total']) ?>; скрыто загрузчиком: <?= $fmt($links['hidden']) ?>; не было в последних полных проходах: <?= $fmt($links['missed']) ?>.</p>
      </div>
    </aside>
  </div>
</form>

<?php elseif ($tab === 'map'): ?>
<?php /** @var array $rows @var array $choices @var array $unmapped */ ?>
<template id="jg-cats"><?php foreach ($choices as $id => $path): ?><option value="<?= (int) $id ?>"><?= e($path) ?></option><?php endforeach; ?></template>
<?php if ($unmapped): ?>
<div class="card">
  <div class="card-hd"><h2>Несопоставленное в последнем прогоне (№<?= (int) $lastFinished['id'] ?>)</h2><span class="hint"><?= $fmt(count($unmapped)) ?></span></div>
  <div class="pad"><ul class="sup-unm"><?php foreach (array_slice($unmapped, 0, 40, true) as $k => $n): [$slug, $nm] = array_pad(explode('|', (string) $k), 2, ''); ?>
    <li><b><?= e($nm !== '' ? $nm : $slug) ?></b> <small class="muted"><?= e($slug) ?></small> — <?= $fmt($n) ?> тов.</li><?php endforeach; ?></ul></div>
</div>
<?php endif; ?>
<form method="post" action="<?= e($base . 'map/save/') ?>" id="jg-map">
  <?= BaseController::tokenField() ?>
  <div class="card">
    <div class="card-hd"><h2>Разделы бренда → категории сайта</h2><span class="hint">загружается <?= $fmt($mapCount['active']) ?> из <?= $fmt($mapCount['cats']) ?></span></div>
    <p class="hint jg-pad">Раздел товара — из его адреса на tomm.ua (/ru/<b>раздел</b>/…). Подразделы бренда «малі / середні / великі розміри» не нужны: «по рядам» — детские подкатегории
      12-26 / 26-32 / 32-38 / 36-41 по «Размер» товара (22-27 → 12-26, 27-32 → 26-32, 32-37 → 32-38). Красные строки — загружаемые без категории сайта: их новые товары пропускаются.
      Новый раздел у бренда появится здесь сам после боевого запуска.</p>
    <div class="table-scroll"><table class="grid jg-map sup-map"><thead><tr><th>Раздел бренда</th><th>Загружать</th><th>Категория на сайте</th><th>По рядам</th><th>Заметка</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $cid = (int) $r['category_id']; $bad = $r['mode'] === '' && !$cid; ?>
      <tr class="<?= $bad ? 'jg-bad' : '' ?><?= $r['mode'] === 'skip' ? ' is-draft' : '' ?>">
        <td><b><?= e($r['gender']) ?></b><small><a href="<?= e(Tomm::SITE . '/ru/' . $r['category'] . '/') ?>" target="_blank" rel="noopener noreferrer"><?= e($r['category']) ?></a></small></td>
        <td><select name="map[<?= (int) $r['id'] ?>][mode]" data-was="<?= e($r['mode']) ?>" aria-label="Загружать">
          <?php foreach (TommMap::CAT_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= $r['mode'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></td>
        <td><select name="map[<?= (int) $r['id'] ?>][category_id]" data-was="<?= $cid ?>" data-cats aria-label="Категория сайта">
          <option value="0"<?= $cid ? '' : ' selected' ?>>— не выбрана —</option>
          <?php if ($cid): ?><option value="<?= $cid ?>" selected><?= e($choices[$cid] ?? ('#' . $cid)) ?></option><?php endif; ?>
        </select></td>
        <td class="tick"><input type="checkbox" name="map[<?= (int) $r['id'] ?>][by_size]" value="1" data-was="<?= (int) $r['by_size'] ?>"<?= (int) $r['by_size'] ? ' checked' : '' ?> aria-label="По размерным рядам"></td>
        <td><small><?= e($r['note']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="savebar"><button class="btn btn-p">Сохранить сопоставления</button><span class="hint" id="jg-map-note">Изменённые строки подсвечиваются.</span></div>
</form>

<?php elseif ($tab === 'runs'): ?>
<?= $view->partial('admin/suppliers/web-runs', ['sup' => Tomm::class]) ?>
<?php endif; ?>
