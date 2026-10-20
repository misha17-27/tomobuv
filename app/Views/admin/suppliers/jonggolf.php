<?php
/**
 * «Поставщики → Jong•Golf»: вкладки Обзор (по умолчанию, общий с другими поставщиками) | Настройки | Категории | Цены (API) | Журнал.
 * Источник — официальный сайт бренда www.jonggolf.com (по умолчанию) или API бренда; связи, категории и «Обзор» у них общие.
 * Кнопки «Запустить сейчас» / «Остановить» — в шапке (SupplierPages::headerActions) и на «Обзоре».
 * @var string $tab @var string $base @var array $cfg @var bool $site источник — сайт бренда @var ?array $markup @var bool $enabled @var string $keyHint
 * @var bool $debug @var array $due @var ?int $next @var ?array $active @var ?array $last @var ?array $lastFinished @var array $mapCount @var array $secCount
 * @var array $products @var int $manual @var array $links @var ?array $siteRun последний запуск с сайта @var bool $photosBlocked фото закрыты robots.txt бренда
 * @var bool $hasDict @var ?int $dictTime @var bool $hasPages страницы API @var bool $hasSitePages сохранённый список с сайта
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\SuppliersController;
use App\Services\Suppliers\JongGolf;
use App\Services\Suppliers\JongGolfMap;
use App\Services\Suppliers\SiteClient;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$on = static fn(string $k) => ($cfg[$k] ?? '') === '1';
$dt = static fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
$money = static fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ' '), '0'), '.');
$unmappedCount = count($lastFinished['stats']['unmapped'] ?? []);
$schedule = sprintf('%02d:00–%02d:59', (int) $cfg['window_from'], (int) $cfg['window_to']);
// проходов в сутки при окне 0–23: раз в period ч (5 ч → 4,8: 0, 5, 10, 15, 20, на следующий день с 1 ч); с сайта бренда проход — 1 запрос списка
$passes = $cfg['window_from'] === '0' && $cfg['window_to'] === '23' ? 24 / max(1, (int) $cfg['period']) : null;
$srcLabel = static fn(array $r): string => match ((string) $r['src']) {
    'web' => 'сайт бренда', 'pages' => 'сохранённые страницы сайта', 'api' => 'API', 'file' => 'файл API', default => (string) $r['src'],
};
?>
<?php if (!$enabled): ?>
<div class="flash warn jg-off" role="note">
  <?php if ($site): ?>
  <b>Загрузка Jong•Golf с сайта бренда по расписанию выключена.</b> <?= $markup === null ? 'Сначала укажите <b>наценку</b> (вкладка «Настройки»): цены на сайте бренда — <b>закупочные</b> для магазина, без коэффициента нельзя ни включить загрузку, ни запустить её вручную. ' : 'Ручной запуск («Запустить сейчас») доступен. ' ?>
  Перед включением проверьте вкладку «Категории» и сделайте пробный прогон. Боевой запуск сверит цены и наличие загруженных товаров, возьмёт под загрузчик найденные
  заведённые вручную (названия, адреса и SEO не меняются) и добавит новые цвета в наличии.
  <?php else: ?>
  <b>Автозагрузка Jong•Golf по API выключена.</b> Включайте её только <b>после переключения домена на новый сайт и отключения cron старого загрузчика</b>
  (wa_loader_jonggolf.php на старом сервере): очередь товаров у поставщика общая на ключ, и два загрузчика «крадут» друг у друга обновления.
  Проверка подключения и пробный прогон работают и при выключенной — поставщику они ничего не подтверждают.
  <?php endif; ?>
</div>
<?php else: ?>
<div class="flash jg-on">Автозагрузка включена (<?= $site ? 'сайт бренда' : 'API' ?>): запуск по расписанию из cron (bin/cron.php) <?= $schedule === '00:00–23:59' ? 'круглые сутки' : 'с ' . e($schedule) ?>, раз в <?= (int) $cfg['period'] ?> ч.
  <?= $next ? 'Следующий — около ' . e(date('d.m H:i', $next)) . '.' : e($due[1]) ?></div>
<?php endif; ?>
<?php if ($site && $photosBlocked && in_array($tab, ['overview', 'settings'], true)): ?>
<div class="flash warn" role="note"><b>Фото бренда закрыты его robots.txt.</b> Фото товаров лежат на public.jonggolf.com/files/products/, а robots.txt этого хоста (как у www) закрывает
  все пути с «/product» — загрузчик такие фото не запрашивает. <?= $on('add_without_photo') ? 'Новые цвета добавляются без фото (включено «Добавлять без фото»)' : 'Новые цвета без фото не добавляются (можно включить «Добавлять без фото»)' ?>.
  Попросите бренд разрешить фото, например добавить в robots.txt public.jonggolf.com: <code>User-agent: TomobuvLoader</code> / <code>Allow: /files/products/</code> — загрузчик подхватит это сам (robots.txt перечитывается раз в сутки).</div>
<?php endif; ?>
<?php if ($debug && !$site): ?>
<div class="flash warn">Режим разработки (config <code>debug = true</code>): подтверждения поставщику (callback) и полная выгрузка (clean_export) не отправляются никогда — запуск записывает только первую страницу очереди.</div>
<?php endif; ?>
<?php if ($active && $tab !== 'overview'): ?>
<div class="flash warn">Идёт <?= e(mb_strtolower(JongGolf::KINDS[$active['kind']])) ?> №<?= (int) $active['id'] ?> — <a href="<?= e($base) ?>">ход на «Обзоре»</a> · <a href="<?= e($base . 'runs/' . (int) $active['id'] . '/') ?>">отчёт</a>.</div>
<?php endif; ?>

<nav class="subtabs" aria-label="Разделы">
  <?php foreach (SuppliersController::TABS as $k => $label): ?>
    <a href="<?= e($base . ($k === 'overview' ? '' : '?tab=' . $k)) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?><?= $k === 'map' && $secCount['unresolved'] ? ' <i>' . $fmt($secCount['unresolved']) . '</i>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
<?php ob_start(); ?>
<div class="card jg-acts">
  <h2 class="sup-acts-h">Проверка и пробный прогон</h2>
  <div class="jg-actrow">
    <button class="btn" type="button" id="jg-test" data-url="<?= e($base . 'test/') ?>"<?= !$site && $cfg['api_key'] === '' ? ' disabled title="Сначала укажите ключ API"' : '' ?>><?= $site ? 'Проверить связь' : 'Проверить подключение' ?></button>
    <form class="jg-dry" id="jg-dry" action="<?= e($base . 'start/') ?>" method="post" enctype="multipart/form-data">
      <input type="hidden" name="mode" value="dry">
      <select name="source" aria-label="Данные для пробного прогона">
        <optgroup label="Сайт бренда">
          <option value="web"<?= $site ? ' selected' : '' ?>>С сайта бренда (весь каталог — 1 запрос)</option>
          <option value="site_last"<?= $hasSitePages ? '' : ' disabled' ?>>Список последнего запуска с сайта (без запросов)</option>
        </optgroup>
        <optgroup label="API бренда">
          <option value="api"<?= $cfg['api_key'] === '' ? ' disabled' : '' ?><?= !$site ? ' selected' : '' ?>>Текущая очередь API (1 страница, без подтверждения)</option>
          <option value="last"<?= $hasPages ? '' : ' disabled' ?>>Страницы последнего запуска по API</option>
          <option value="upload"<?= $hasDict ? '' : ' disabled' ?>>Файл с ответом export_product (JSON)</option>
        </optgroup>
      </select>
      <input type="file" name="feed" accept=".json,application/json" hidden>
      <button class="btn" type="submit">Пробный прогон</button>
    </form>
  </div>
  <p class="hint jg-res" id="jg-res" role="status"><?php if ($site): ?>Проверка связи — robots.txt и первая страница каталога бренда (1–3 запроса). Пробный прогон ничего не пишет в каталог: с сайта — весь каталог в наличии
    одним запросом (≈2 МБ, по сети ≈110 КБ), показывает, что будет добавлено, изменено, станет «нет в наличии» и скрыто. Пауза между запросами — <?= e($cfg['delay']) ?> с.
    <?php else: ?>Проверка связи — только справочники (export_product_setting): у поставщика ничего не меняется. Пробный прогон ничего не пишет в каталог и не подтверждает поставщику — показывает, что будет добавлено, изменено и скрыто.
    «Запустить сейчас» — только при включённой автозагрузке<?= $debug || !$enabled ? '' : ': поставщику уйдут подтверждения обработанных страниц' ?>.<?php endif; ?></p>
</div>
<?php $actionsCard = (string) ob_get_clean(); ?>
<?= $view->partial('admin/suppliers/overview', ['actionsCard' => $actionsCard]) ?>

<?php else: ?>
<div class="stats">
  <div class="stat<?= $enabled ? ' hot' : ' warn' ?>"><span><?= $enabled ? 'Вкл.' : 'Выкл.' ?></span>Автозагрузка по расписанию</div>
  <div class="stat"><span><?= $site ? 'Сайт' : 'API' ?></span>Источник (<?= $site ? 'www.jonggolf.com' : 'API бренда' ?>)</div>
  <?php if ($site): ?><div class="stat<?= $markup ? '' : ' warn' ?>"><span><?= $markup ? '×' . e($money($markup['k'])) . ($markup['plus'] ? ' +' . e($money($markup['plus'])) : '') : 'нет' ?></span>Наценка на цену бренда</div><?php endif; ?>
  <a class="stat" href="<?= e($base . '?tab=runs') ?>"><span><?= $last ? e(date('d.m H:i', strtotime((string) $last['started_at']))) : '—' ?></span>
    <?= $last ? e(JongGolf::KINDS[$last['kind']] . ': ' . mb_strtolower(JongGolf::STATUSES[$last['status']] ?? $last['status'])) : 'Запусков ещё не было' ?></a>
  <div class="stat"><span><?= $fmt($products['active'] ?? 0) ?> / <?= $fmt($products['total'] ?? 0) ?></span>Товаров Jong•Golf под загрузчиком (активных / всего)</div>
  <a class="stat<?= $secCount['unresolved'] || $unmappedCount ? ' warn' : '' ?>" href="<?= e($base . '?tab=map') ?>"><span><?= $fmt($secCount['active']) ?> / <?= $fmt($mapCount['total']) ?></span>Разделов бренда / сочетаний в таблице<?= $unmappedCount ? ' (несопоставленных в прогоне: ' . $fmt($unmappedCount) . ')' : '' ?></a>
</div>
<?php endif; ?>

<?php if ($tab === 'settings'): ?>
<form method="post" action="<?= e($base . 'settings/') ?>" class="setform" autocomplete="off">
  <?= BaseController::tokenField() ?>
  <div class="two-col">
    <div>
      <div class="card">
        <h2>Источник и расписание</h2>
        <fieldset class="jg-radios"><legend class="lbl">Откуда загружать</legend>
          <?php foreach (JongGolf::SOURCES as $k => $label): ?>
            <label class="check"><input type="radio" name="source" value="<?= e($k) ?>"<?= $cfg['source'] === $k || ($k === 'site' && $cfg['source'] !== 'api') ? ' checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <p class="hint">Связи с товарами (по коду цвета «B40678-3»), категории и «Обзор» у источников общие — переключать можно без перепривязки. API пускает только IP из белого списка и
          давно не обновлялся: основной источник — сайт бренда; API — запасной путь (очередь выгрузки с подтверждением, ключ ниже).</p>
        <label class="check jg-big"><input type="checkbox" name="enabled" value="1"<?= $on('enabled') ? ' checked' : '' ?>> <span><b>Автозагрузка по расписанию включена</b> — сайт бренда: только с наценкой, после проверки категорий и пробного прогона; API: только после отключения cron старого сайта</span></label>
        <div class="row3">
          <label class="fld"><span>Запуск с, час</span><input name="window_from" type="number" min="0" max="23" value="<?= e($cfg['window_from']) ?>"></label>
          <label class="fld"><span>по, час (включительно)</span><input name="window_to" type="number" min="0" max="23" value="<?= e($cfg['window_to']) ?>"></label>
          <label class="fld"><span>Сверка — раз в N часов</span><input name="period" type="number" min="1" max="168" value="<?= e($cfg['period']) ?>"></label>
        </div>
        <p class="hint">Время — Europe/Kiev (сейчас на сервере <?= e(date('H:i')) ?>). По умолчанию (решение владельца) — <b>полная сверка цен и наличия всех товаров каждые 5 ч круглые сутки</b>.
          С сайта бренда каждый проход полный: весь каталог в наличии — <b>1 запрос</b> (≈2 с)<?= $passes ? ', в сутки — ' . e(rtrim(rtrim(number_format($passes, 1, ',', ''), '0'), ',')) . ' прохода, ≈' . $fmt(ceil($passes) + 2) . ' запросов (с robots.txt двух хостов раз в сутки)' : '' ?>,
          плюс карточка модели и фото каждого нового цвета. Нужен cron <code>bin/cron.php</code> раз в час (docs/INSTALL.md). «Запустить сейчас» с сайта бренда работает и при выключенной автозагрузке (если задана наценка).</p>
      </div>

      <?= $view->partial('admin/suppliers/markup', ['sup' => JongGolf::class, 'lead' => 'Сайт бренда: цены там — <b>закупочные</b> для магазина (дилера). Цена на сайте за пару = цена бренда за пару (у цвета своя) × коэффициент + надбавка, затем округление;
          старая цена (скидка бренда) — так же. Закупочная цена сохраняется в карточке товара. У API наценка — по категориям (вкладка «Цены»), этот коэффициент ему не нужен.',
          'after' => 'Ящик и минимальный заказ — «Пар в ящике» бренда.']) ?>

      <div class="card">
        <h2>Сайт бренда: новые товары и бережность</h2>
        <div class="pair">
          <label class="fld"><span>Пауза между запросами, с (не меньше 1,5)</span><input name="delay" value="<?= e($cfg['delay']) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Не больше запросов за запуск</span><input name="max_requests" type="number" min="20" max="5000" value="<?= e($cfg['max_requests']) ?>"></label>
          <label class="fld"><span>Не больше новых цветов за запуск</span><input name="max_new" type="number" min="0" max="5000" value="<?= e($cfg['max_new']) ?>">
            <span class="hint">Новый цвет — фото (1–2 запроса), модель — карточка (1 запрос на все её цвета): 100 новых ≈ 250 запросов ≈ 10 мин. 0 — без ограничения.</span></label>
          <label class="fld"><span>Бренд новых товаров</span><input name="brand" value="<?= e($cfg['brand']) ?>" maxlength="100"><span class="hint">Для цветов «Jong•Golf™» и без бренда («Не указано») на сайте бренда.</span></label>
        </div>
        <label class="check"><input type="checkbox" name="card_features" value="1"<?= $on('card_features') ? ' checked' : '' ?>> Открывать карточку модели нового цвета (1 запрос на модель): материалы, повторные размеры, описание</label>
        <label class="check"><input type="checkbox" name="more_photos" value="1"<?= $on('more_photos') ? ' checked' : '' ?>> Доп. фото цвета из карточки модели (и найденным товарам без фото)</label>
        <p class="hint">Один поток, User-Agent «<?= e(SiteClient::UA) ?>», robots.txt соблюдается (обоих хостов: www.jonggolf.com и public.jonggolf.com — фото). На 429/5xx — пауза 60 с с удвоением,
          после 3 ошибок подряд — остановка; на 403, проверку браузера и блок-страницу сетевого фильтра — сразу остановка «нужна проверка». К API бренда этот путь не обращается.
          Список — /ru/catalog/?limit=1000 (весь каталог в наличии); если бренд отдаст меньше, чем по фасетам пола, — по 100 на странице (8 запросов), неполный список ничего не скрывает.</p>
      </div>

      <div class="card">
        <h2>Найденные товары</h2>
        <p class="hint jg-lead">Всегда обновляются цена (со скидкой — «старая цена»), закупочная (сайт бренда), ящик, ряд, наличие и видимость. С сайта бренда: цвета нет в списке (бренд не показывает
          цвета без остатка) — «нет в наличии» сразу, скрыт — только после N полных проходов подряд; по API: цвет закончился — скрыт.</p>
        <label class="check"><input type="checkbox" name="update_category" value="1"<?= $on('update_category') ? ' checked' : '' ?>> Менять категорию по таблице сочетаний / разделам бренда (отвязать от прежних)</label>
        <label class="check"><input type="checkbox" name="update_name" value="1"<?= $on('update_name') ? ' checked' : '' ?>> Менять название и мета-теги</label>
        <label class="check"><input type="checkbox" name="update_features" value="1"<?= $on('update_features') ? ' checked' : '' ?>> Перезаписывать характеристики, бренд и размер (с сайта бренда — поля списка: пол, ряд, бренд)</label>
        <label class="check"><input type="checkbox" name="update_description" value="1"<?= $on('update_description') ? ' checked' : '' ?>> Менять описание (API)</label>
        <label class="check"><input type="checkbox" name="keep_manual_hidden" value="1"<?= $on('keep_manual_hidden') ? ' checked' : '' ?>> Не показывать снова товары, скрытые вручную (старый загрузчик показывал)</label>
        <label class="check"><input type="checkbox" name="adopt_manual" value="1"<?= $on('adopt_manual') ? ' checked' : '' ?>> Брать под загрузчик товары Jong•Golf, заведённые вручную (найдены по коду цвета<?= $manual ? ', сейчас таких до ' . $fmt($manual) : '' ?>; название, адрес и SEO не меняются)</label>
        <p class="hint">Без этой галочки такой товар не меняется, но и дубль не создаётся — в отчёте строка «есть на сайте». Товары других загрузчиков не берутся никогда.</p>
        <label class="check"><input type="checkbox" name="hide_missing" value="1"<?= $on('hide_missing') ? ' checked' : '' ?>> Сайт бренда: скрывать товары, которых нет у бренда</label>
        <label class="fld"><span>…после стольких полных проходов подряд без цвета</span><input name="missing_runs" type="number" min="1" max="10" value="<?= e($cfg['missing_runs']) ?>"></label>
        <p class="hint">Скрывается (не удаляется) только после полного прохода и только <?= (int) $cfg['missing_runs'] ?>-й раз подряд: остановленный, прерванный, по сохранённым страницам или неполный список
          ничего не скрывает; пропало больше половины активных — скрытие отменяется. Вернулся цвет — снова показан и в наличии.</p>
      </div>

      <div class="card">
        <h2>Новые товары</h2>
        <label class="check"><input type="checkbox" name="add_new" value="1"<?= $on('add_new') ? ' checked' : '' ?>> Добавлять новые товары (только в наличии у бренда, с ценой, категорией и рядом)</label>
        <label class="check"><input type="checkbox" name="add_without_photo" value="1"<?= $on('add_without_photo') ? ' checked' : '' ?>> Добавлять без фото (иначе товар без скачанного фото не создаётся; фото добавится позже, когда его можно будет получить)</label>
        <fieldset class="jg-radios"><legend class="lbl">Название</legend>
          <?php foreach (JongGolf::NAME_MODES as $k => $label): ?>
            <label class="check"><input type="radio" name="name_mode" value="<?= e($k) ?>"<?= $cfg['name_mode'] === $k ? ' checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <p class="hint">По правилу сайта: «Зимняя обувь Jong•Golf B40678-3» / «Зимове взуття Jong•Golf B40678-3», артикул — код цвета, адрес — транслит названия (занятый — с «-2», «-3»),
          ящик и минимальный заказ — «пар в ящике» бренда, остаток не ограничен. Характеристики — по-русски (украинские слова — словарём), значения — существующие на сайте.</p>
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
        <h2>API бренда (запасной источник)</h2>
        <label class="fld"><span>Ключ API (access_key)</span>
          <input name="api_key" type="password" value="" autocomplete="new-password" placeholder="<?= $keyHint !== '' ? e($keyHint) . ' — пусто: не менять, «-»: удалить' : 'не задан' ?>">
          <span class="hint">Хранится в базе сайта, на экране и в отчётах не показывается. Поставщик пускает только IP из белого списка — сообщите ему IP нового хостинга.</span></label>
        <label class="check jg-big"><input type="checkbox" name="full_update" value="1"<?= $on('full_update') ? ' checked' : '' ?>> <span>Полное обновление: каждый запуск — весь каталог поставщика; товары, которых у поставщика нет, <b>скрывать</b></span></label>
        <p class="hint">Первая страница запрашивается с clean_export (поставщик отдаёт каталог целиком). Товары, не пришедшие в выгрузке, скрываются (старый загрузчик их удалял вместе с фото).
          Защита: если скрыть пришлось бы больше половины активных товаров — скрытие отменяется. clean_export и подтверждения уходят только при источнике «API» и включённой автозагрузке.</p>
      </div>

      <div class="card">
        <h2>Пропуск и характеристики</h2>
        <div class="pair">
          <label class="fld"><span>Пропускаемые бренды (по одному в строке)</span><textarea name="skip_brands" rows="3"><?= e($cfg['skip_brands']) ?></textarea></label>
          <label class="fld"><span>Пропускаемые категории («Кроссовки», «Кросівки» или «Летняя обувь|Кроссовки»)</span><textarea name="skip_categories" rows="3"><?= e($cfg['skip_categories']) ?></textarea></label>
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
        <p class="hint">Фото вписывается в холст по центру на белом фоне (0 — сохранить как есть) и пересохраняется через GD в папку фото сайта (как у Webasyst). С сайта бренда — самое крупное (b_, 800×705).</p>
        <label class="check"><input type="checkbox" name="photo_replace" value="1"<?= $on('photo_replace') ? ' checked' : '' ?>> Заменять имеющиеся фото (API)</label>
        <label class="check"><input type="checkbox" name="photo_check_file" value="1"<?= $on('photo_check_file') ? ' checked' : '' ?>> Проверять, что файл главного фото есть на диске (иначе скачать заново)</label>
        <div class="row3">
          <label class="fld"><span>Фильтр категории</span><select name="category_filter"><?php foreach (JongGolf::FILTER_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= $cfg['category_filter'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label class="fld"><span>Хранить отчёты, дней</span><input name="log_days" type="number" min="1" max="90" value="<?= e($cfg['log_days']) ?>"></label>
          <label class="fld"><span>Историю цен и наличия («Обзор»), дней</span><input name="history_days" type="number" min="30" max="3650" value="<?= e($cfg['history_days']) ?>"></label>
        </div>
      </div>
      <div class="savebar"><button class="btn btn-p">Сохранить</button><span class="hint">Настройки применяются со следующего запуска.</span></div>
    </div>

    <aside>
      <div class="card">
        <h2>Как включить (сайт бренда)</h2>
        <ol class="hint jg-steps">
          <li>Задайте <b>наценку</b> (без неё включить нельзя).</li>
          <li>Проверьте вкладку «Категории»: разделы бренда и таблицу сочетаний.</li>
          <li>«Проверить связь», затем «Пробный прогон» — найденные товары, цены до и после наценки, что станет «нет в наличии», что добавится.</li>
          <li>Включите загрузку. «Хит обуви» товары Jong•Golf не загружает (бренд в пропускаемых).</li>
        </ol>
      </div>
      <div class="card">
        <h2>Связи</h2>
        <p class="hint">Цветов бренда, связанных с товарами сайта: <?= $fmt($links['total']) ?>; скрыто загрузчиком: <?= $fmt($links['hidden']) ?>; не было в последних полных проходах: <?= $fmt($links['missed']) ?>.</p>
      </div>
      <div class="card">
        <h2>Перенос со старого загрузчика (API)</h2>
        <ol class="hint jg-steps">
          <li>Настройки: загрузите <b>wa_loader_jonggolf.cfg.php</b> (файл только читается, логин и пароль старой админки не переносятся).</li>
          <li>Таблицу категорий <b>table/category_table_jonggolf.xls</b> — на вкладке «Категории».</li>
          <li>Для пробного прогона без связи с API — справочники <b>tmp/product_setting.json</b> (с сайта бренда из них берутся названия цветов).</li>
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
    <p class="hint"><?= $hasDict ? 'Сохранённая копия от ' . e(date('d.m.Y H:i', (int) $dictTime)) . ' (обновляется при каждой проверке связи и запуске по API).' : 'Копии нет — нажмите «Проверить подключение» (API) или загрузите product_setting.json.' ?></p>
    <label class="fld"><span>product_setting.json</span><input type="file" name="dict" accept=".json" required></label>
    <button class="btn">Загрузить</button>
  </form>
</div>

<?php elseif ($tab === 'map'): ?>
<?php /** @var array $rows @var string $filter @var string $q @var \App\Core\Paginator $pg @var array $choices @var array $unmapped @var array $sections */ ?>
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

<form method="post" action="<?= e($base . 'map/sections/') ?>" id="jg-sec">
  <?= BaseController::tokenField() ?>
  <div class="card">
    <div class="card-hd"><h2>Разделы сайта бренда → категории сайта</h2><span class="hint">загружается <?= $fmt($secCount['active']) ?> из <?= $fmt($secCount['cats']) ?></span></div>
    <p class="hint jg-pad">Как ищется категория цвета с сайта бренда: 1) таблица сочетаний ниже (как у API и старого загрузчика: «сезон | категория | пол», без ряда, затем с рядом) — по русским названиям сайта,
      затем по украинским (как в справочниках API); 2) не нашлось — раздел бренда «сезон | категория» отсюда: категория сайта, «по рядам» — детские подкатегории по нижнему размеру ряда
      (≤ 22 → 12-26, 23–28 → 26-32, ≥ 29 → 32-38; буква ряда бренда бывает ошибочной — только размер). Красные — загружаемые без категории сайта: их новые цвета пропускаются.
      Новый раздел у бренда без строки здесь попадёт в «Несопоставленные» отчёта.</p>
    <div class="table-scroll"><table class="grid jg-map sup-map"><thead><tr><th>Раздел бренда</th><th>Загружать</th><th>Категория на сайте</th><th>По рядам</th><th>Заметка</th></tr></thead><tbody>
    <?php foreach ($sections as $r): $cid = (int) $r['category_id']; $bad = $r['mode'] === '' && !$cid; ?>
      <tr class="<?= $bad ? 'jg-bad' : '' ?><?= $r['mode'] === 'skip' ? ' is-draft' : '' ?>">
        <td><b><?= e($r['gender']) ?></b><small><?= e($r['season']) ?></small></td>
        <td><select name="sec[<?= (int) $r['id'] ?>][mode]" data-was="<?= e($r['mode']) ?>" aria-label="Загружать">
          <?php foreach (JongGolfMap::SECTION_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= $r['mode'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></td>
        <td><select name="sec[<?= (int) $r['id'] ?>][category_id]" data-was="<?= $cid ?>" data-cats aria-label="Категория сайта">
          <option value="0"<?= $cid ? '' : ' selected' ?>>— не выбрана<?= $r['target'] !== '' && !$cid ? ' (в черновике: ' . e($r['target']) . ')' : '' ?> —</option>
          <?php if ($cid): ?><option value="<?= $cid ?>" selected><?= e($choices[$cid] ?? ('#' . $cid)) ?></option><?php endif; ?>
        </select></td>
        <td class="tick"><input type="checkbox" name="sec[<?= (int) $r['id'] ?>][by_size]" value="1" data-was="<?= (int) $r['by_size'] ?>"<?= (int) $r['by_size'] ? ' checked' : '' ?> aria-label="По размерным рядам"></td>
        <td><small><?= e($r['note']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="savebar"><button class="btn btn-p">Сохранить разделы</button><span class="hint" id="jg-sec-note">Изменённые строки подсвечиваются.</span></div>
</form>

<div class="two-col">
  <form class="card" method="post" action="<?= e($base . 'map/upload/') ?>" enctype="multipart/form-data" data-confirm="Заменить таблицу соответствия загруженным файлом? Правки, сделанные здесь, пропадут (сначала можно скачать CSV).">
    <?= BaseController::tokenField() ?>
    <h2>Загрузить таблицу сочетаний</h2>
    <p class="hint">Формат старого загрузчика (table/category_table_jonggolf.xls): первый лист, строка 1 — заголовок; A — сезон, B — подкатегория, C — пол (тексты справочников поставщика или сайта бренда), D — размерный ряд (пусто — любой), E — категория сайта «ДЕТСКАЯ ОБУВЬ&gt;Кеды&gt;12-26». Загрузка заменяет таблицу целиком (разделы бренда выше — не трогает); повтор сочетания — действует последняя строка.</p>
    <label class="fld"><span>Файл XLS, XLSX или CSV</span><input type="file" name="table" accept=".xls,.xlsx,.csv,.txt" required></label>
    <div class="jg-actrow"><button class="btn btn-p">Загрузить</button><a class="btn" href="<?= e($base . 'map.csv') ?>">Скачать таблицу (CSV)</a></div>
  </form>
  <aside class="card">
    <h2>Как ищется категория (API)</h2>
    <p class="hint">Сначала строка «сезон | категория | пол» без размерного ряда, потом — с точным рядом товара. Не нашлось — новый товар не создаётся (найденный обновляется без смены категории), сочетание попадает в список «Несопоставленные».</p>
  </aside>
</div>

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
  <div class="empty-card"><h2><?= $mapCount['total'] ? 'Ничего не найдено' : 'Таблица сочетаний пуста' ?></h2><p><?= $mapCount['total'] ? 'Измените поиск или фильтр.' : 'Загрузите table/category_table_jonggolf.xls старого загрузчика — без неё по API новые товары не создаются (с сайта бренда — по разделам выше).' ?></p></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'prices'): ?>
<?php /** @var array $targets @var array $prices */ ?>
<form class="card" method="post" action="<?= e($base . 'prices/') ?>">
  <?= BaseController::tokenField() ?>
  <div class="card-hd"><h2>Коэффициенты цен по категориям сайта (API)</h2></div>
  <p class="hint jg-pad">Только для источника «API»: цена на сайте = цена поставщика × множитель + надбавка (за пару, грн); так же — старая цена. У старого загрузчика наценки не было (×1, +0) — цены как у поставщика.
    С сайта бренда — общая наценка во вкладке «Настройки».</p>
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

<?php elseif ($tab === 'runs'): ?>
<?php /** @var array $runs */ ?>
<div class="card">
  <?php if ($runs): ?>
  <div class="table-scroll"><table class="grid"><thead><tr><th>№</th><th>Начат</th><th>Вид</th><th>Статус</th><th class="right">Запросов</th><th class="right">Товаров</th><th class="right">Добавлено</th><th class="right">Изменено</th>
    <th class="right">Есть на сайте</th><th class="right">Скрыто</th><th class="right">Пропущено</th><th class="right">Ошибок</th></tr></thead><tbody>
  <?php foreach ($runs as $r): $s = $r['stats']; $web = JongGolf::webRun($r); ?>
    <tr class="<?= $r['status'] === 'running' ? 'unread' : '' ?>"><td><a href="<?= e($base . 'runs/' . (int) $r['id'] . '/') ?>">№<?= (int) $r['id'] ?></a></td>
      <td class="nowrap"><?= e($dt($r['started_at'])) ?><small><?= e(['cron' => 'по расписанию', 'cli' => 'командная строка', 'admin' => 'из админки'][$r['origin']] ?? $r['origin']) ?></small></td>
      <td><?= e(JongGolf::KINDS[$r['kind']] ?? $r['kind']) ?><small><?= e($srcLabel($r)) ?></small></td>
      <td><span class="st <?= ['done' => 'st-completed', 'error' => 'st-refunded', 'stopped' => 'st-deleted', 'running' => 'st-processing'][$r['status']] ?? '' ?>"><?= e(JongGolf::STATUSES[$r['status']] ?? $r['status']) ?></span>
        <?php if ($r['error']): ?><small><?= e(mb_strimwidth((string) $r['error'], 0, 140, '…')) ?></small><?php endif; ?></td>
      <td class="right"><?= $web ? $fmt($s['requests'] ?? 0) : '—' ?></td><td class="right"><?= $r['kind'] === 'test' ? '—' : $fmt($s['products']) ?></td><td class="right"><?= $fmt($s['created']) ?></td><td class="right"><?= $fmt($s['updated']) ?></td>
      <td class="right"><?= $web ? $fmt($s['existing'] ?? 0) : '—' ?></td><td class="right"><?= $fmt($s['hidden_color'] + $s['hidden_missing']) ?></td><td class="right"><?= $fmt($s['skipped']) ?></td><td class="right"><?= $fmt($s['errors']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <p class="hint jg-pad">Отчёты хранятся <?= (int) $cfg['log_days'] ?> дн. (последние 5 запусков — всегда). Причины пропусков: <?= e(implode('; ', array_unique(JongGolf::skipReasons()))) ?>.</p>
  <?php else: ?><div class="empty-card"><h2>Запусков ещё не было</h2><p>Начните с «Проверить связь» и «Пробный прогон».</p></div><?php endif; ?>
</div>
<?php endif; ?>
