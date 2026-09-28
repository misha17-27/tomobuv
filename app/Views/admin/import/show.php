<?php
/**
 * Задание импорта: сопоставление колонок, настройки, предпросмотр первых 20 строк.
 * @var array $job @var array $sample @var ?array $preview @var array $choices @var array $categories @var array $profiles
 * @var array $suppliers @var array $problems @var array $delimiters
 */
use App\Controllers\Admin\BaseController;
use App\Services\Import\Importer;

$o = $job['options'];
$map = $job['mapping'];
$cols = $job['columns'];
$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$isXml = in_array($job['format'], ['xml', 'yml'], true);
$fmtNames = ['csv' => 'CSV', 'xlsx' => 'Excel XLSX', 'xml' => 'XML', 'yml' => 'YML (Prom / Rozetka / Яндекс.Маркет)'];

// группы полей в выпадающем списке
$groups = [
    'Поиск и основное' => ['id', 'sku', 'supplier_code', 'name', 'url', 'supplier', 'updated_at'],
    'Цены и опт'       => ['price', 'price_box', 'compare_price', 'purchase_price', 'box_qty', 'min_qty', 'stock', 'in_stock', 'status'],
    'Карточка товара'  => ['size', 'brand', 'category', 'images', 'description', 'summary'],
    'SEO и украинская версия' => ['meta_title', 'meta_description', 'meta_keywords', 'name_uk', 'description_uk', 'summary_uk', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk'],
];
$features = array_filter($choices, static fn($k) => str_starts_with((string) $k, 'feature:'), ARRAY_FILTER_USE_KEY);
$select = static function (int $i, string $cur) use ($choices, $groups, $features): string {
    $h = '<select name="map[' . $i . ']" class="im-map" aria-label="Поле товара для колонки ' . ($i + 1) . '"><option value="">— не загружать —</option>';
    foreach ($groups as $label => $keys) {
        $h .= '<optgroup label="' . e($label) . '">';
        foreach ($keys as $k) if (isset($choices[$k])) $h .= '<option value="' . e($k) . '"' . ($cur === $k ? ' selected' : '') . '>' . e($choices[$k]) . '</option>';
        $h .= '</optgroup>';
    }
    if ($features) {
        $h .= '<optgroup label="Характеристики">';
        foreach ($features as $k => $label) $h .= '<option value="' . e($k) . '"' . ($cur === $k ? ' selected' : '') . '>' . e(preg_replace('/^Характеристика:\s*/u', '', $label)) . '</option>';
        $h .= '</optgroup>';
    }
    return $h . '</select>';
};
// примеры значений каждой колонки (первые 3 разных непустых)
$examples = [];
foreach ($cols as $c) {
    $ex = [];
    foreach ($sample as $row) {
        $v = trim((string) ($row[$c] ?? ''));
        if ($v !== '' && !in_array($v, $ex, true)) $ex[] = $v;
        if (count($ex) >= 3) break;
    }
    $examples[$c] = $ex;
}
$actNames = ['create' => 'Создать', 'update' => 'Обновить', 'same' => 'Без изменений', 'conflict' => 'Изменён на сайте', 'skip' => 'Пропустить', 'error' => 'Ошибка'];
$actClass = ['create' => 'st-completed', 'update' => 'st-processing', 'same' => 'st-deleted', 'conflict' => 'st-paid', 'skip' => 'st-refunded', 'error' => 'st-refunded im-err'];
$stampMapped = in_array('updated_at', $map, true);   // файл нашего экспорта: есть время изменения товаров
$yes = static fn(bool $b) => $b ? ' checked' : '';
$parsedInfo = [];
if ($job['format'] === 'csv') {
    $parsedInfo[] = 'кодировка ' . ($o['encoding'] ?? 'UTF-8');
    $parsedInfo[] = 'разделитель «' . ($o['delimiter'] === 'tab' ? 'табуляция' : $o['delimiter']) . '»';
}
if ($job['format'] === 'xlsx' && !empty($o['sheet'])) $parsedInfo[] = 'лист «' . $o['sheet'] . '»';
if ($isXml) $parsedInfo[] = 'товар — элемент «' . $o['item_path'] . '»';
?>
<div class="card im-file">
  <dl class="detail im-facts">
    <div><dt>Файл</dt><dd><b><?= e($job['name']) ?></b> <span class="muted">· <?= e($fmtNames[$job['format']] ?? $job['format']) ?></span></dd></div>
    <div><dt>Строк с товарами</dt><dd><b><?= $fmt($job['total']) ?></b><?php if (!empty($job['state']['truncated'])): ?> <span class="im-bad">— прочитаны первые <?= $fmt($job['state']['truncated']) ?>, остальное не поместилось</span><?php endif; ?>
      <?php if ($parsedInfo): ?><small class="muted"> · <?= e(implode(', ', $parsedInfo)) ?></small><?php endif; ?></dd></div>
    <?php if ($o['source_url'] !== ''): ?><div><dt>Ссылка</dt><dd class="im-url"><?= e($o['source_url']) ?></dd></div><?php endif; ?>
  </dl>
  <form method="post" action="/admin/import/<?= (int) $job['id'] ?>/delete/" data-confirm="Удалить задание? Товары на сайте не изменятся.">
    <?= BaseController::tokenField() ?><button class="btn btn-sm btn-d" type="submit">Удалить задание</button></form>
</div>

<?php if ($job['status'] === 'error'): ?>
  <div class="flash bad">Файл не удалось разобрать: <?= e((string) $job['errors']) ?></div>
<?php endif; ?>

<form method="post" action="/admin/import/<?= (int) $job['id'] ?>/" id="im-settings" class="im-settings">
  <?= BaseController::tokenField() ?>
  <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">

<?php if ($cols): ?>
  <div class="card">
    <div class="card-hd"><h2>1. Сопоставление колонок</h2><span class="hint im-m0">Колонки, для которых выбрано «не загружать», не меняют товар</span></div>
    <div class="table-scroll"><table class="grid im-maptable">
      <thead><tr><th>Колонка файла</th><th>Примеры значений</th><th>Поле товара</th></tr></thead>
      <tbody>
      <?php foreach ($cols as $i => $c): $cur = (string) ($map[$c] ?? ''); ?>
        <tr class="<?= $cur !== '' ? 'im-on' : '' ?>">
          <td><b class="im-col"><?= e($c) ?></b></td>
          <td class="im-ex"><?php foreach ($examples[$c] as $v): ?><span title="<?= e(mb_substr($v, 0, 300)) ?>"><?= e(mb_strimwidth($v, 0, 60, '…')) ?></span><?php endforeach; ?>
            <?php if (!$examples[$c]): ?><span class="muted">пусто в первых строках</span><?php endif; ?></td>
          <td><?= $select($i, $cur) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="hint im-pad">Цена за ящик пересчитывается в цену за пару: цена ящика ÷ пар в ящике. Фото — одна или несколько ссылок через запятую или «|». Бренды, которых нет на сайте, создаются автоматически.</p>
  </div>

  <div class="card">
    <div class="card-hd"><h2>2. Поиск товаров и режим</h2></div>
    <div class="pad">
      <div class="row2">
        <label class="fld"><span>Ключ поиска существующих товаров</span>
          <select name="opt[key]" id="im-key"><?php foreach (Importer::KEYS as $k => $label): ?><option value="<?= e($k) ?>"<?= $o['key'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
          <small class="hint">Товар из файла считается уже существующим, если значение этой колонки совпало с товаром на сайте. С поставщиком — ищется только среди его товаров.</small></label>
        <label class="fld"><span>Поставщик</span>
          <input type="text" name="opt[supplier]" id="im-supplier" value="<?= e($o['supplier']) ?>" maxlength="64" list="im-suppliers" placeholder="например, forsage" autocomplete="off">
          <datalist id="im-suppliers"><?php foreach ($suppliers as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
          <small class="hint">Записывается новым товарам в поле «Поставщик»; нужен для поиска по коду поставщика и для скрытия отсутствующих товаров.</small></label>
      </div>
      <fieldset class="im-modes"><legend class="lbl">Что делать с товарами</legend>
        <?php foreach (Importer::MODES as $k => $label): ?>
          <label class="chk"><input type="radio" name="opt[mode]" value="<?= e($k) ?>"<?= $yes($o['mode'] === $k) ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <div class="checks im-checks">
        <label class="chk"><input type="checkbox" name="opt[price_only]" value="1" id="im-price-only"<?= $yes(!empty($o['price_only'])) ?>> Обновлять у найденных товаров только цену и наличие</label>
        <label class="chk"><input type="checkbox" name="opt[hide_missing]" value="1" id="im-hide"<?= $yes(!empty($o['hide_missing'])) ?>> Скрыть товары этого поставщика, которых нет в файле</label>
        <label class="chk"><input type="checkbox" name="opt[unhide]" value="1"<?= $yes(!empty($o['unhide'])) ?>> Показывать на сайте скрытые товары, если они снова есть в файле</label>
        <label class="chk"><input type="checkbox" name="opt[images]" value="1"<?= $yes(!empty($o['images'])) ?>> Загружать фото по ссылкам (новым товарам и товарам без фото)</label>
        <label class="chk"><input type="checkbox" name="opt[overwrite]" value="1"<?= $yes(!empty($o['overwrite'])) ?>> Перезаписать всё равно товары, изменённые на сайте после выгрузки</label>
      </div>
      <p class="hint" id="im-hide-hint">Скрытие сработает, только если в файле найден хотя бы один товар поставщика и скрыть нужно не больше половины его товаров — защита от неполного файла.</p>
      <p class="hint">Файл нашего экспорта с колонкой <code>updated_at</code> (поле «<?= e(Importer::FIELDS['updated_at']) ?>»<?= $stampMapped ? '' : ', сейчас не сопоставлено' ?>): товар, который изменили на сайте позже, чем выгружен файл, не перезаписывается — он попадёт в итог импорта со ссылкой. Галочка «Перезаписать всё равно» записывает данные файла поверх. Прайсы поставщиков без этой колонки обрабатываются как обычно.</p>
    </div>
  </div>

  <div class="card">
    <div class="card-hd"><h2>3. Цены</h2></div>
    <div class="pad">
      <div class="row3">
        <label class="fld"><span>Наценка, %</span><input type="text" inputmode="decimal" name="opt[markup]" value="<?= e(rtrim(rtrim(number_format((float) $o['markup'], 2, '.', ''), '0'), '.')) ?>" placeholder="0">
          <small class="hint">К цене за пару и старой цене. Отрицательная — скидка.</small></label>
        <label class="fld"><span>Округление цены</span>
          <select name="opt[round]"><?php foreach (Importer::ROUNDS as $k => $label): ?><option value="<?= (int) $k ?>"<?= (int) $o['round'] === (int) $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label class="fld"><span>Пар в ящике по умолчанию</span><input type="number" min="0" max="1000" name="opt[default_box_qty]" value="<?= (int) $o['default_box_qty'] ?: '' ?>" placeholder="из файла">
          <small class="hint">Для новых товаров, если в файле нет колонки «Пар в ящике».</small></label>
      </div>
      <label class="chk"><input type="checkbox" name="opt[purchase_from_price]" value="1"<?= $yes(!empty($o['purchase_from_price'])) ?>> Записывать цену поставщика (до наценки) как закупочную</label>
    </div>
  </div>

  <div class="card">
    <div class="card-hd"><h2>4. Новые товары и категории</h2></div>
    <div class="pad">
      <div class="row2">
        <label class="fld"><span>Категория по умолчанию</span>
          <select name="opt[default_category]"><option value="0">— нет (строки без категории — ошибка) —</option>
            <?php foreach ($categories as $cid => $path): ?><option value="<?= (int) $cid ?>"<?= (int) $o['default_category'] === (int) $cid ? ' selected' : '' ?>><?= e($path) ?></option><?php endforeach; ?></select>
          <small class="hint">Для строк без категории или с категорией, которой нет на сайте.</small></label>
        <label class="fld"><span>Новые товары</span>
          <select name="opt[new_status]"><option value="1"<?= (int) $o['new_status'] ? ' selected' : '' ?>>Сразу показывать на сайте</option><option value="0"<?= (int) $o['new_status'] ? '' : ' selected' ?>>Добавлять скрытыми (проверю вручную)</option></select></label>
      </div>
      <label class="fld"><span>Соответствие категорий поставщика нашим</span>
        <textarea name="opt[category_map]" id="im-catmap" rows="4" placeholder="Кроссовки мужские = Мужская обувь > Кроссовки&#10;Детская обувь = 57"><?= e($o['category_map']) ?></textarea>
        <small class="hint">По строке на категорию: «категория в файле = наша категория» (название, путь через «&gt;», url или ID). Без соответствия категория ищется по ID, url, пути и названию.</small></label>
      <?php if ($preview && $preview['badCategories']): ?>
        <div class="im-badcats"><span class="lbl">Не найдены на сайте (нажмите, чтобы добавить строку):</span>
          <?php foreach ($preview['badCategories'] as $bc): ?><button type="button" class="chip im-addcat" data-cat="<?= e($bc) ?>"><?= e(mb_strimwidth($bc, 0, 60, '…')) ?> <b>+</b></button><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

  <div class="card">
    <div class="card-hd"><h2><?= $cols ? '5. ' : '' ?>Чтение файла и автоимпорт</h2></div>
    <div class="pad">
      <div class="row3">
        <?php if ($job['format'] === 'csv'): ?>
          <label class="fld"><span>Разделитель колонок</span>
            <select name="opt[delimiter]"><?php foreach ($delimiters as $k => $label): if ($k === '') continue; ?><option value="<?= e($k) ?>"<?= $o['delimiter'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <?php if (!$isXml): ?>
          <label class="chk im-hdr"><input type="checkbox" name="opt[header]" value="1"<?= $yes(!empty($o['header'])) ?>> Первая строка — названия колонок</label>
        <?php else: ?>
          <label class="fld"><span>Элемент товара в XML</span><input type="text" name="opt[item_path]" value="<?= e($o['item_path']) ?>" maxlength="200" placeholder="offers/offer">
            <small class="hint">Путь к повторяющемуся элементу: <code>offers/offer</code>, <code>items/item</code>, <code>product</code>.</small></label>
        <?php endif; ?>
        <label class="fld"><span>Строк за один шаг</span><input type="number" name="opt[batch]" min="50" max="1000" step="50" value="<?= (int) $o['batch'] ?>">
          <small class="hint">400 — для обычного хостинга; меньше — если шаг не успевает.</small></label>
      </div>
      <label class="fld"><span>Ссылка на файл поставщика (для импорта по расписанию)</span>
        <input type="url" name="opt[source_url]" value="<?= e($o['source_url']) ?>" maxlength="1000" placeholder="https://supplier.com/feed.xml">
        <small class="hint">Сохраните настройки как профиль — cron будет забирать файл сам: <code>php bin/import.php ID_профиля</code></small></label>
    </div>
  </div>

<?php if ($problems && $cols): ?>
  <div class="flash warn im-problems"><b>Перед запуском:</b><ul><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

  <div class="savebar im-savebar">
    <?php if ($cols && $job['status'] === 'new'): ?>
      <button class="btn btn-p" type="submit" name="do" value="start" id="im-start"
        data-confirm="Запустить импорт <?= $fmt($job['total']) ?> <?= plural((int) $job['total'], 'строки', 'строк', 'строк') ?>? Товары на сайте будут созданы и изменены по этим настройкам.">Запустить импорт</button>
      <button class="btn" type="submit" name="do" value="preview">Сохранить и обновить предпросмотр</button>
      <span class="im-prof">
        <label class="sr-only" for="im-prof-id">Профиль</label>
        <select name="profile_id" id="im-prof-id">
          <option value="0">Новый профиль</option>
          <?php foreach ($profiles as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) $job['profile_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="sr-only" for="im-prof-name">Название профиля</label>
        <input type="text" name="profile_name" id="im-prof-name" maxlength="190" placeholder="Название, напр. Forsage"
          value="<?= e($job['profile_id'] ? '' : ($o['supplier'] !== '' ? $o['supplier'] : '')) ?>">
        <button class="btn" type="submit" formaction="/admin/import/profiles/" name="do" value="profile">Сохранить как профиль</button>
      </span>
    <?php else: ?>
      <button class="btn btn-p" type="submit" name="do" value="preview">Разобрать файл заново</button>
    <?php endif; ?>
  </div>
</form>

<?php if ($preview !== null): ?>
<div class="card" id="preview">
  <div class="card-hd"><h2>Предпросмотр: первые <?= count($preview['rows']) ?> <?= plural(count($preview['rows']), 'строка', 'строки', 'строк') ?></h2>
    <div class="im-sum">
      <?php foreach ($actNames as $k => $label): if (empty($preview['summary'][$k])) continue; ?>
        <span class="st <?= e($actClass[$k]) ?>"><?= e($label) ?>: <?= (int) $preview['summary'][$k] ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!empty($preview['error'])): ?><div class="pad"><div class="flash bad"><?= e($preview['error']) ?></div></div><?php endif; ?>
  <?php if (!empty($preview['summary']['conflict'])): $nc = (int) $preview['summary']['conflict']; ?>
    <div class="pad"><div class="flash warn"><?= $nc ?> <?= plural($nc, 'товар', 'товара', 'товаров') ?> в этих строках <?= $nc === 1 ? 'изменён' : 'изменены' ?> на сайте после выгрузки файла — <?= $nc === 1 ? 'он не будет перезаписан' : 'они не будут перезаписаны' ?>.
      Список по всему файлу будет в итоге импорта. Чтобы записать данные файла поверх, отметьте «Перезаписать всё равно» и сохраните настройки.</div></div>
  <?php endif; ?>
  <?php if ($preview['rows']): ?>
  <div class="table-scroll"><table class="grid im-preview">
    <thead><tr><th class="right">№</th><th>Действие</th><th>Товар</th><th class="right">Цена/пара</th><th>Категория</th><th>Бренд, размеры</th><th>Наличие</th><th>Что изменится</th></tr></thead>
    <tbody>
    <?php foreach ($preview['rows'] as $r): ?>
      <tr class="im-a-<?= e($r['action']) ?>">
        <td class="num"><?= (int) $r['n'] ?></td>
        <td><span class="st <?= e($actClass[$r['action']] ?? '') ?>"><?= e($actNames[$r['action']] ?? $r['action']) ?></span></td>
        <td class="im-pname"><?php if ($r['id']): ?><a href="/admin/products/<?= (int) $r['id'] ?>/" target="_blank" rel="noopener"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name'] !== '' ? $r['name'] : '—') ?><?php endif; ?>
          <small><?= $r['key'] !== '' ? e(Importer::KEYS[$o['key']] . ': ' . $r['key']) : '' ?><?= $r['id'] ? ' · ID ' . (int) $r['id'] : '' ?></small></td>
        <td class="right nowrap"><?= $r['price'] !== null ? e(price_format($r['price'])) : '—' ?>
          <?php if ($r['box_qty'] !== null): ?><small><?= (int) $r['box_qty'] ?> <?= plural((int) $r['box_qty'], 'пара', 'пары', 'пар') ?> в ящике</small><?php endif; ?></td>
        <td class="im-cat"><?= e($r['category'] !== '' ? $r['category'] : '—') ?></td>
        <td><?= e($r['brand'] !== '' ? $r['brand'] : '—') ?><?php if ($r['size'] !== ''): ?><small><?= e($r['size']) ?></small><?php endif; ?></td>
        <td class="nowrap"><?= $r['in_stock'] === null ? '—' : ((int) $r['in_stock'] ? '<span class="in-stock">есть</span>' : '<span class="out-stock">нет</span>') ?>
          <?php if ((int) $r['images']): ?><small>фото: <?= (int) $r['images'] ?></small><?php endif; ?></td>
        <td class="im-changes">
          <?php if ($r['msg'] !== ''): ?><b><?= e($r['msg']) ?></b><?php endif; ?>
          <?php if ($r['changes']): ?><?= e(implode('; ', $r['changes'])) ?><?php elseif ($r['action'] === 'create'): ?><span class="muted">новый товар, адрес /product/<?= e($r['url']) ?>/</span><?php endif; ?>
          <?php if ($r['warn'] !== ''): ?><small class="im-warn"><?= e($r['warn']) ?></small><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint im-pad">Предпросмотр ничего не меняет на сайте. Цены показаны с наценкой и округлением.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($sample && $cols): ?>
<details class="card im-raw">
  <summary><b>Строки файла как есть</b> <span class="muted">(первые <?= count($sample) ?>)</span></summary>
  <div class="table-scroll"><table class="grid">
    <thead><tr><th>№</th><?php foreach ($cols as $c): ?><th><?= e(mb_strimwidth($c, 0, 40, '…')) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($sample as $n => $row): ?>
      <tr><td class="num"><?= (int) $n ?></td><?php foreach ($cols as $c): ?><td><?= e(mb_strimwidth((string) ($row[$c] ?? ''), 0, 80, '…')) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</details>
<?php endif; ?>
