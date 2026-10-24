<?php
/**
 * «Поставщики → Хит обуви»: вкладки Обзор (по умолчанию) | Настройки | Категории | Журнал. Кнопки «Запустить сейчас» /
 * «Остановить» — в шапке (SupplierPages::headerActions) и на «Обзоре».
 * @var string $tab @var string $base @var array $cfg @var bool $enabled @var ?array $markup @var bool $debug @var array $due @var ?int $next
 * @var string $nextMode @var ?array $active @var ?array $last @var ?array $lastFinished @var array $mapCount @var array $products @var array $links
 * @var array $roots @var bool $hasPages @var int $forsage
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\HitObuviController;
use App\Services\Suppliers\HitObuvi;
use App\Services\Suppliers\HitObuviMap;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$on = static fn(string $k) => ($cfg[$k] ?? '') === '1';
$money = static fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ' '), '0'), '.');
$unmappedCount = count($lastFinished['stats']['unmapped'] ?? []);
$selRoots = array_flip(array_filter(array_map('trim', explode("\n", (string) $cfg['roots']))));
$schedule = sprintf('%02d:00–%02d:59', (int) $cfg['window_from'], (int) $cfg['window_to']);
?>
<?php if (!$enabled): ?>
<div class="flash warn jg-off" role="note">
  <b>Автозагрузка «Хит обуви» по расписанию выключена.</b> <?= $markup === null ? 'Сначала укажите <b>наценку</b> (вкладка «Настройки»): цены на сайте поставщика — <b>закупочные</b>, без коэффициента нельзя ни включить загрузку, ни запустить её вручную. ' : 'Ручной запуск («Запустить сейчас») доступен. ' ?>
  Перед включением проверьте таблицу категорий и сделайте пробный прогон. Боевой запуск добавит товары поставщика на сайт (с фото) и будет обновлять цены и наличие загруженных;
  товары Forsage и заведённые вручную, найденные у поставщика, не меняются (только строка в отчёте), пока не отмечено «брать под загрузчик».
</div>
<?php else: ?>
<div class="flash jg-on">Автозагрузка включена: сверка по расписанию из cron (bin/cron.php) <?= $schedule === '00:00–23:59' ? 'круглые сутки' : 'с ' . e($schedule) ?>, раз в <?= (int) $cfg['period'] ?> ч;
  полный проход (цены и наличие всех товаров) — не чаще раза в <?= (int) $cfg['full_hours'] ?> ч, в <?= (int) $cfg['full_from'] ?>:00–<?= (int) $cfg['full_to'] ?>:59. <?= $next ? 'Следующий — около ' . e(date('d.m H:i', $next)) . ' (' . e(HitObuvi::MODES[$nextMode]) . ').' : e($due[1]) ?></div>
<?php endif; ?>
<?php if ($active && $tab !== 'overview'): ?>
<div class="flash warn">Идёт <?= e(mb_strtolower(HitObuvi::KINDS[$active['kind']])) ?> №<?= (int) $active['id'] ?> — <a href="<?= e($base) ?>">ход на «Обзоре»</a> · <a href="<?= e($base . 'runs/' . (int) $active['id'] . '/') ?>">отчёт</a>.</div>
<?php endif; ?>

<nav class="subtabs" aria-label="Разделы">
  <?php foreach (HitObuviController::TABS as $k => $label): ?>
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
        <option value="web">С сайта поставщика</option>
        <option value="last"<?= $hasPages ? '' : ' disabled' ?>>Страницы последнего запуска (без запросов)</option>
      </select>
      <select name="pass" aria-label="Режим прохода">
        <?php foreach (HitObuvi::MODES as $k => $label): ?><option value="<?= e($k) ?>"><?= e(mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1)) ?></option><?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Пробный прогон</button>
    </form>
    <label class="sup-pass"><span>Режим «Запустить сейчас»</span>
      <select id="sup-run-pass" aria-label="Режим боевого запуска">
        <option value="auto">по расписанию (сейчас: <?= e(HitObuvi::MODES[$nextMode]) ?>)</option>
        <?php foreach (HitObuvi::MODES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
  </div>
  <p class="hint jg-res" id="jg-res" role="status">Проверка связи — robots.txt и первая страница раздела (2 запроса). Пробный прогон ничего не пишет в каталог: быстрый — первые страницы разделов (до <?= (int) $cfg['quick_pages'] ?> на раздел),
    полный — все страницы (≈1 100 запросов при <?= (int) $cfg['page_limit'] ?> товарах на странице, около 1,5–2 ч). Пауза между запросами — <?= e($cfg['delay']) ?> с.</p>
</div>
<?php $actionsCard = (string) ob_get_clean(); ?>
<?= $view->partial('admin/suppliers/overview', ['actionsCard' => $actionsCard]) ?>

<?php else: ?>
<div class="stats">
  <div class="stat<?= $enabled ? ' hot' : ' warn' ?>"><span><?= $enabled ? 'Вкл.' : 'Выкл.' ?></span>Автозагрузка по расписанию</div>
  <div class="stat<?= $markup ? '' : ' warn' ?>"><span><?= $markup ? '×' . e($money($markup['k'])) . ($markup['plus'] ? ' +' . e($money($markup['plus'])) : '') : 'нет' ?></span>Наценка на цену поставщика</div>
  <a class="stat" href="<?= e($base . '?tab=runs') ?>"><span><?= $last ? e(date('d.m H:i', strtotime((string) $last['started_at']))) : '—' ?></span>
    <?= $last ? e(HitObuvi::KINDS[$last['kind']] . ': ' . mb_strtolower(HitObuvi::STATUSES[$last['status']] ?? $last['status'])) : 'Запусков ещё не было' ?></a>
  <div class="stat"><span><?= $fmt($products['active']) ?> / <?= $fmt($products['total']) ?></span>Товаров «Хит обуви» на сайте (активных / всего)</div>
  <a class="stat<?= $mapCount['unresolved'] || $unmappedCount ? ' warn' : '' ?>" href="<?= e($base . '?tab=map') ?>"><span><?= $fmt($mapCount['active']) ?> / <?= $fmt($mapCount['cats']) ?></span>Категорий поставщика загружается<?= $mapCount['unresolved'] ? ' (без категории сайта: ' . $fmt($mapCount['unresolved']) . ')' : '' ?></a>
</div>
<?php endif; ?>

<?php if ($tab === 'settings'): ?>
<form method="post" action="<?= e($base . 'settings/') ?>" class="setform" autocomplete="off">
  <?= BaseController::tokenField() ?>
  <div class="two-col">
    <div>
      <div class="card">
        <h2>Загрузка и расписание</h2>
        <label class="check jg-big"><input type="checkbox" name="enabled" value="1"<?= $on('enabled') ? ' checked' : '' ?>> <span><b>Автозагрузка по расписанию включена</b> — только после того, как задана наценка, проверены категории и сделан пробный прогон</span></label>
        <div class="row3">
          <label class="fld"><span>Запуск с, час</span><input name="window_from" type="number" min="0" max="23" value="<?= e($cfg['window_from']) ?>"></label>
          <label class="fld"><span>по, час (включительно)</span><input name="window_to" type="number" min="0" max="23" value="<?= e($cfg['window_to']) ?>"></label>
          <label class="fld"><span>Сверка — раз в N часов</span><input name="period" type="number" min="1" max="168" value="<?= e($cfg['period']) ?>"></label>
        </div>
        <label class="fld"><span>Полный проход (цены и наличие всех товаров) — не чаще раза в N часов; остальные запуски — быстрые (только новые товары сверху списков)</span><input name="full_hours" type="number" min="1" max="720" value="<?= e($cfg['full_hours']) ?>"></label>
        <div class="pair">
          <label class="fld"><span>Полный проход — с часа</span><input name="full_from" type="number" min="0" max="23" value="<?= e($cfg['full_from']) ?>"></label>
          <label class="fld"><span>по час (включительно)</span><input name="full_to" type="number" min="0" max="23" value="<?= e($cfg['full_to']) ?>"><span class="hint">Ночью — меньше нагрузка на сайт поставщика и ваш хостинг. Запоздавший больше чем на сутки полный проход идёт и вне окна.</span></label>
        </div>
        <p class="hint">Время — Europe/Kiev (сейчас на сервере <?= e(date('H:i')) ?>). По умолчанию — сверка каждые 5 ч круглые сутки (окно 0–23), каждый запуск — полный проход:
          ≈1 100 запросов к сайту поставщика и 1,5–2 ч (cron продолжает запуск каждый час, пока не закончит). Быстрый проход — ≈10–20 запросов. Чтобы реже нагружать сайт поставщика,
          поставьте, например, полный проход раз в 24 ч — между ними пойдут быстрые. Нужен cron <code>bin/cron.php</code> раз в час (docs/INSTALL.md).
          «Запустить сейчас» работает и при выключенной автозагрузке (если задана наценка).</p>
        <fieldset class="jg-radios"><legend class="lbl">Разделы поставщика для обхода</legend>
          <?php foreach ($roots as $path => $name): ?>
            <label class="check"><input type="checkbox" name="roots[]" value="<?= e($path) ?>"<?= isset($selRoots[$path]) ? ' checked' : '' ?>> <?= e($name) ?> <small class="muted"><?= e($path) ?></small></label>
          <?php endforeach; ?>
        </fieldset>
        <p class="hint">Какие категории внутри разделов загружать — вкладка «Категории» (по умолчанию только обувь: одежда, сумки, перчатки — «не загружать»).
          Уже загруженные товары раздела, снятого с обхода, перестают обновляться и после <?= (int) $cfg['missing_runs'] ?> полных проходов скрываются как пропавшие.</p>
      </div>

      <?= $view->partial('admin/suppliers/markup', ['sup' => HitObuvi::class, 'lead' => 'Цены на сайте «Хит обуви» — <b>закупочные</b> для магазина. Цена на сайте за пару = цена поставщика за пару (цена ящика ÷ пар в ящике) × коэффициент + надбавка, затем округление.
          Старая цена (у товаров со скидкой) — так же. Закупочная цена товара сохраняется в карточке («Закупочная цена»).', 'after' => 'Ящик и минимальный заказ — «пар в упаковке» поставщика (по ценам ящика и пары).']) ?>

      <div class="card">
        <h2>Новые товары</h2>
        <label class="check"><input type="checkbox" name="add_new" value="1"<?= $on('add_new') ? ' checked' : '' ?>> Добавлять новые товары (только с фото — без фото товар не создаётся)</label>
        <div class="pair">
          <label class="fld"><span>Только товары поставщика с id не ниже</span><input name="min_id" value="<?= e($cfg['min_id']) ?>" inputmode="numeric" placeholder="пусто — все">
            <span class="hint">Поставщик не снимает архив с 2016 г.: без порога загрузчик завёл бы ≈110 тыс. старых моделей. 1 442 000 — с января 2025 (≈86 тыс. в 4 обувных разделах), 1 550 000 — с 9 октября 2025 (≈57 тыс.). Порог снизили — массовая загрузка (bin/load-hitobuvi.sh) пройдёт ещё раз и добавит только недостающие.</span></label>
          <label class="fld"><span>Не больше новых за запуск</span><input name="max_new" type="number" min="0" max="20000" value="<?= e($cfg['max_new']) ?>">
            <span class="hint">Каждое новое фото — отдельный запрос с паузой: 300 новых ≈ 15 мин. 0 — без ограничения.</span></label>
        </div>
        <p class="hint">Название — как у поставщика («Тип Бренд Артикул»; тип, записанный по-украински, — по-русски), название-код — по правилу сайта. Украинское — словарём сайта
          (как <code>bin/i18n-seed-uk.php</code>). Бренд — из названия (иначе «Производитель» без страны: «ASHIGULI(Китай)» → ASHIGULI). Адрес — транслит названия. SEO — шаблоны сайта.</p>
        <label class="fld"><span>Характеристики: поле поставщика=характеристика сайта (название или код)</span>
          <textarea name="attributes" rows="7" class="code"><?= e($cfg['attributes']) ?></textarea>
          <span class="hint">Поля: <?= e(implode(', ', array_map(static fn($k, $v) => $k . ' — ' . $v, array_keys(HitObuvi::FIELDS), HitObuvi::FIELDS))) ?>. Значения — существующие (без учёта регистра), новые создаются. Страна — если на сайте есть такая характеристика (строка «country=…»).</span>
          <?php if (!empty($attrErr)): ?><span class="wa-err" role="alert"><?= e(implode('; ', $attrErr)) ?></span><?php endif; ?></label>
        <div class="row3">
          <label class="fld"><span>Ширина холста фото, px</span><input name="photo_width" type="number" min="0" max="3000" value="<?= e($cfg['photo_width']) ?>"></label>
          <label class="fld"><span>Высота холста, px</span><input name="photo_height" type="number" min="0" max="3000" value="<?= e($cfg['photo_height']) ?>"></label>
          <label class="fld"><span>Качество JPEG</span><input name="photo_quality" type="number" min="30" max="100" value="<?= e($cfg['photo_quality']) ?>"></label>
        </div>
        <p class="hint">Берётся большое фото (796×596), вписывается в холст через GD и сохраняется в папку фото сайта. На фото поставщика — его водяной знак «hit-obuvi.com.ua».</p>
        <input type="hidden" name="watermark_form" value="1"><label class="check"><input type="checkbox" name="watermark" value="1"<?= $on('watermark') ? ' checked' : '' ?>> <span>Заменять надпись «hit-obuvi.com.ua» на фото на «tomobuv.com.ua» — у новых фото сразу, у загруженных раньше — по cron (раз в час, под новым адресом фото); фото без надписи поставщика не меняются. Надпись ищется на холсте 700×700 (как выше по умолчанию) — при другом размере холста может не найтись</span></label>
      </div>

      <div class="card">
        <h2>Уже загруженные и найденные товары</h2>
        <label class="check"><input type="checkbox" name="update_existing" value="1"<?= $on('update_existing') ? ' checked' : '' ?>> Обновлять цену, старую и закупочную цену, ящик, ряд, наличие у товаров поставщика</label>
        <label class="check"><input type="checkbox" name="update_category" value="1"<?= $on('update_category') ? ' checked' : '' ?>> Менять категорию по таблице категорий</label>
        <label class="check"><input type="checkbox" name="keep_manual_hidden" value="1"<?= $on('keep_manual_hidden') ? ' checked' : '' ?>> Не показывать снова товары, скрытые вручную</label>
        <label class="check"><input type="checkbox" name="hide_missing" value="1"<?= $on('hide_missing') ? ' checked' : '' ?>> Скрывать товары, пропавшие у поставщика</label>
        <label class="fld"><span>…после стольких полных проходов подряд без товара</span><input name="missing_runs" type="number" min="1" max="10" value="<?= e($cfg['missing_runs']) ?>"></label>
        <p class="hint">Скрываются (не удаляются) только товары, связанные с «Хит обуви», и только после полного прохода по всем страницам: сбой одной страницы, лимит запросов или ошибка ничего не скрывают.
          Защита: если пропало больше половины активных — скрытие отменяется. Вернулся у поставщика — показывается снова.</p>
        <label class="check jg-big"><input type="checkbox" name="adopt" value="1"<?= $on('adopt') ? ' checked' : '' ?>> <span>Брать под загрузчик найденные товары, заведённые вручную или Forsage (на сайте <?= $fmt($forsage) ?> товаров Forsage)</span></label>
        <p class="hint">Без галочки такой товар не меняется и дубль не создаётся — строка «есть на сайте» в отчёте. Поиск: связь → forsage-код из адреса товара → точное название и ряд → тип, бренд, артикул и ряд (только единственный).</p>
      </div>

      <div class="card">
        <h2>Бережность к сайту поставщика</h2>
        <div class="pair">
          <label class="fld"><span>Пауза между запросами, с (не меньше 1,5)</span><input name="delay" value="<?= e($cfg['delay']) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Не больше запросов за запуск</span><input name="max_requests" type="number" min="10" max="5000" value="<?= e($cfg['max_requests']) ?>"></label>
          <label class="fld"><span>Товаров на странице списка</span><input name="page_limit" type="number" min="20" max="200" value="<?= e($cfg['page_limit']) ?>"></label>
          <label class="fld"><span>Быстрый проход: страниц на раздел, не больше</span><input name="quick_pages" type="number" min="1" max="50" value="<?= e($cfg['quick_pages']) ?>"></label>
        </div>
        <p class="hint">Один поток, User-Agent «<?= e(\App\Services\Suppliers\HitObuviClient::UA) ?>», robots.txt соблюдается. На 429/5xx — пауза 60 с с удвоением, после 3 ошибок подряд — остановка;
          на 403 и проверку Cloudflare — сразу остановка «нужна проверка» (обходить защиту загрузчик не будет). Лимит кончился — остальное в следующий запуск.</p>
      </div>

      <div class="card">
        <h2>Пропуск и отчёты</h2>
        <label class="fld"><span>Пропускаемые бренды (по одному в строке)</span><textarea name="skip_brands" rows="3"><?= e($cfg['skip_brands']) ?></textarea>
          <span class="hint">Jong•Golf и Tom.m — их товары загружают свои загрузчики с сайтов брендов (иначе дубли): проверяется бренд в названии и «Производитель»,
            написание не важно (JONG GOLF = Jong•Golf = J&amp;G, TOM.M = Tom.m = ТОМ.М). Товары сайта, связанные с другим поставщиком, «Хит обуви» не берёт никогда.</span></label>
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
          <li>Проверьте вкладку «Категории» — черновик составлен по разбору сайта поставщика, подсвечены строки без категории сайта.</li>
          <li>«Проверить связь», затем «Пробный прогон» (быстрый) — посмотрите отчёт: что добавится, цены до и после наценки, найденные на сайте товары.</li>
          <li>Решите порог id новых товаров и лимит за запуск, включите загрузку.</li>
        </ol>
      </div>
      <div class="card">
        <h2>Связи</h2>
        <p class="hint">Товаров поставщика, связанных с товарами сайта: <?= $fmt($links['total']) ?>; скрыто загрузчиком: <?= $fmt($links['hidden']) ?>; не было в последних полных проходах: <?= $fmt($links['missed']) ?>.</p>
      </div>
    </aside>
  </div>
</form>

<?php elseif ($tab === 'map'): ?>
<?php /** @var array $cats @var array $types @var array $choices @var array $unmapped @var array $newCats */ ?>
<template id="jg-cats"><?php foreach ($choices as $id => $path): ?><option value="<?= (int) $id ?>"><?= e($path) ?></option><?php endforeach; ?></template>
<?php if ($unmapped || $newCats): ?>
<div class="card">
  <div class="card-hd"><h2>Несопоставленное в последнем прогоне (№<?= (int) $lastFinished['id'] ?>)</h2><span class="hint"><?= $fmt(count($unmapped)) ?></span></div>
  <div class="pad">
    <?php if ($newCats): ?><p class="hint">Новые категории в меню поставщика: <?= e(implode('; ', array_map(static fn($p, $n) => $n . ' (' . $p . ')', array_keys($newCats), $newCats))) ?>.</p><?php endif; ?>
    <?php if ($unmapped): ?><ul class="sup-unm"><?php foreach (array_slice($unmapped, 0, 40, true) as $k => $n): [$r, $p, $nm] = array_pad(explode('|', (string) $k), 3, ''); ?>
      <li><b><?= e($nm !== '' ? $nm : $p) ?></b> <small class="muted"><?= e($p) ?></small> — <?= $fmt($n) ?> тов.</li><?php endforeach; ?></ul><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<form method="post" action="<?= e($base . 'map/save/') ?>" id="jg-map">
  <?= BaseController::tokenField() ?>
  <div class="card">
    <div class="card-hd"><h2>Категории поставщика → категории сайта</h2><span class="hint">загружается <?= $fmt($mapCount['active']) ?> из <?= $fmt($mapCount['cats']) ?></span></div>
    <p class="hint jg-pad">Категория товара берётся из его адреса у поставщика (основная). Правила «тип из названия» (ниже) с условием «всегда» важнее этой таблицы: шлепки из «Летней обуви» — в шлепанцы.
      «По рядам» — детские подкатегории 12-26 / 26-32 / 32-38 / 36-41 по размерам товара (22-27 → 12-26, 27-32 → 26-32, 32-37 → 32-38). Красные строки — загружаемые без категории сайта: их товары пропускаются.</p>
    <div class="table-scroll"><table class="grid jg-map sup-map"><thead><tr><th>Категория поставщика</th><th>Загружать</th><th>Категория на сайте</th><th>По рядам</th><th>Заметка</th></tr></thead><tbody>
    <?php foreach ($cats as $root => $rows): ?>
      <tr class="sup-root"><th colspan="5"><?= e($roots[$root] ?? $root) ?> <small class="muted"><?= e($root) ?></small></th></tr>
      <?php foreach ($rows as $r): $cid = (int) $r['category_id']; $bad = $r['mode'] === '' && !$cid; ?>
      <tr class="<?= $bad ? 'jg-bad' : '' ?><?= $r['mode'] === 'skip' ? ' is-draft' : '' ?>">
        <td><b><?= e($r['gender']) ?></b><small><?= e($r['category']) ?><?= $r['ext_id'] !== null ? ' · id ' . e((string) $r['ext_id']) : ' · пусто у поставщика' ?></small></td>
        <td><select name="map[<?= (int) $r['id'] ?>][mode]" data-was="<?= e($r['mode']) ?>" aria-label="Загружать">
          <?php foreach (HitObuviMap::CAT_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= $r['mode'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></td>
        <td><select name="map[<?= (int) $r['id'] ?>][category_id]" data-was="<?= $cid ?>" data-cats aria-label="Категория сайта">
          <option value="0"<?= $cid ? '' : ' selected' ?>>— не выбрана —</option>
          <?php if ($cid): ?><option value="<?= $cid ?>" selected><?= e($choices[$cid] ?? ('#' . $cid)) ?></option><?php endif; ?>
        </select></td>
        <td class="tick"><input type="checkbox" name="map[<?= (int) $r['id'] ?>][by_size]" value="1" data-was="<?= (int) $r['by_size'] ?>"<?= (int) $r['by_size'] ? ' checked' : '' ?> aria-label="По размерным рядам"></td>
        <td><small><?= e($r['note']) ?></small></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>

  <div class="card">
    <div class="card-hd"><h2>Тип из названия → категория</h2><span class="hint">правил <?= $fmt($mapCount['types']) ?></span></div>
    <p class="hint jg-pad">Тип — первое слово названия (RU и UA: «Шлепки», «Шльопанці»; «Сапоги из пены» — три слова). «Всегда» — важнее категории поставщика; «если нет своей» — только для категорий «по типу из названия» (кожаная, великаны) и без категории сайта.</p>
    <div class="table-scroll"><table class="grid jg-map sup-map"><thead><tr><th>Тип</th><th>Когда</th><th>Категория на сайте</th><th>По рядам</th><th></th></tr></thead><tbody>
    <?php foreach ($types as $root => $rows): ?>
      <tr class="sup-root"><th colspan="5"><?= e($roots[$root] ?? $root) ?></th></tr>
      <?php foreach ($rows as $r): $cid = (int) $r['category_id']; ?>
      <tr class="<?= $cid ? '' : 'jg-bad' ?>">
        <td><b><?= e($r['category']) ?></b></td>
        <td><select name="map[<?= (int) $r['id'] ?>][mode]" data-was="<?= e($r['mode']) ?>" aria-label="Когда">
          <?php foreach (HitObuviMap::TYPE_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= $r['mode'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></td>
        <td><select name="map[<?= (int) $r['id'] ?>][category_id]" data-was="<?= $cid ?>" data-cats aria-label="Категория сайта">
          <option value="0"<?= $cid ? '' : ' selected' ?>>— не выбрана —</option>
          <?php if ($cid): ?><option value="<?= $cid ?>" selected><?= e($choices[$cid] ?? ('#' . $cid)) ?></option><?php endif; ?>
        </select></td>
        <td class="tick"><input type="checkbox" name="map[<?= (int) $r['id'] ?>][by_size]" value="1" data-was="<?= (int) $r['by_size'] ?>"<?= (int) $r['by_size'] ? ' checked' : '' ?> aria-label="По размерным рядам"></td>
        <td><button class="btn btn-sm btn-d" type="submit" form="sup-del-<?= (int) $r['id'] ?>" aria-label="Удалить правило «<?= e($r['category']) ?>»">✕</button></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="savebar"><button class="btn btn-p">Сохранить сопоставления</button><span class="hint" id="jg-map-note">Изменённые строки подсвечиваются.</span></div>
</form>
<?php foreach ($types as $rows): foreach ($rows as $r): ?><form id="sup-del-<?= (int) $r['id'] ?>" method="post" action="<?= e($base . 'map/' . (int) $r['id'] . '/delete/') ?>" data-confirm="Удалить правило «<?= e($r['category']) ?>»?" hidden><?= BaseController::tokenField() ?></form><?php endforeach; endforeach; ?>

<form class="card" method="post" action="<?= e($base . 'map/type/') ?>">
  <?= BaseController::tokenField() ?>
  <h2>Добавить правило «тип из названия»</h2>
  <div class="jg-add sup-addtype">
    <select name="root" aria-label="Раздел поставщика" required><?php foreach ($roots as $p => $n): ?><option value="<?= e($p) ?>"><?= e($n) ?></option><?php endforeach; ?></select>
    <input name="type" placeholder="тип: шлепки, кросівки…" aria-label="Тип" required maxlength="60">
    <select name="category_id" required aria-label="Категория сайта" data-cats><option value="">— категория сайта —</option></select>
    <select name="when" aria-label="Когда"><?php foreach (HitObuviMap::TYPE_MODES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="by_size" value="1"> по рядам</label>
    <button class="btn">Добавить</button>
  </div>
</form>

<?php elseif ($tab === 'runs'): ?>
<?= $view->partial('admin/suppliers/web-runs', ['sup' => HitObuvi::class]) ?>
<?php endif; ?>
