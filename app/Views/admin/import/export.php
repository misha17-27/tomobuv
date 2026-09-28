<?php
/**
 * Экспорт товаров в CSV: фильтры, набор колонок, количество товаров.
 * @var array $f @var array $cats @var array $brands @var array $suppliers @var int $count @var array $cols
 */
$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
?>
<div class="two-col">
  <form class="card im-export" id="im-export" method="get" action="/admin/export/products.csv" data-count="/admin/export/count.json">
    <h2>Какие товары выгрузить</h2>
    <div class="row2">
      <label class="fld"><span>Категория (с подкатегориями)</span>
        <select name="category"><option value="">Все категории</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $f['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', (int) $c['depth']) . $c['name'] . ((int) $c['type'] === 1 ? ' (по условию)' : '') . ((int) $c['status'] ? '' : ' (скрыта)')) ?></option><?php endforeach; ?>
        </select></label>
      <label class="fld"><span>Бренд</span>
        <select name="brand"><option value="">Все бренды</option><option value="-1"<?= $f['brand'] === -1 ? ' selected' : '' ?>>Без бренда</option>
          <?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>"<?= $f['brand'] === (int) $b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select></label>
    </div>
    <div class="row3">
      <label class="fld"><span>Поставщик</span>
        <select name="supplier"><option value="">Все</option><option value="-"<?= $f['supplier'] === '-' ? ' selected' : '' ?>>Не указан</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= e($s) ?>"<?= $f['supplier'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select></label>
      <label class="fld"><span>Статус</span>
        <select name="status"><option value="">Любой</option><option value="1"<?= $f['status'] === '1' ? ' selected' : '' ?>>На сайте</option><option value="0"<?= $f['status'] === '0' ? ' selected' : '' ?>>Скрытые</option></select></label>
      <label class="fld"><span>Наличие</span>
        <select name="stock"><option value="">Любое</option><option value="1"<?= $f['stock'] === '1' ? ' selected' : '' ?>>В наличии</option><option value="0"<?= $f['stock'] === '0' ? ' selected' : '' ?>>Нет в наличии</option></select></label>
    </div>
    <fieldset class="im-colsets"><legend class="lbl">Дополнительные колонки</legend>
      <label class="chk"><input type="checkbox" name="seo" value="1"<?= $f['seo'] ? ' checked' : '' ?>> SEO: title, description, keywords</label>
      <label class="chk"><input type="checkbox" name="desc" value="1"<?= $f['desc'] ? ' checked' : '' ?>> Описание товара</label>
      <label class="chk"><input type="checkbox" name="uk" value="1"<?= $f['uk'] ? ' checked' : '' ?>> Украинская версия: название, описание, SEO</label>
    </fieldset>
    <div class="savebar im-exbar">
      <button class="btn btn-p" type="submit">Скачать CSV</button>
      <span class="hint" aria-live="polite">Будет выгружено: <b id="im-count"><?= $fmt($count) ?></b> <span id="im-count-word"><?= plural($count, 'товар', 'товара', 'товаров') ?></span></span>
      <a class="btn btn-sm" href="/admin/export/products.csv?template=1&amp;uk=1">Пустой шаблон</a>
    </div>
  </form>

  <aside class="card">
    <h2>Формат файла</h2>
    <p class="hint">CSV в кодировке UTF-8, разделитель «;» — открывается в Excel и Google Таблицах. Цена — за пару, <code>price_box</code> — за ящик (для справки).</p>
    <p class="hint"><b>Колонки:</b> <?= e(implode(', ', $cols['main'])) ?>.</p>
    <p class="hint"><b>Чтобы массово изменить товары</b>: выгрузите, поправьте цены, наличие или названия в Excel, сохраните как CSV и загрузите в <a href="/admin/import/">Импорт</a> с ключом «ID товара на сайте» и режимом «Только обновлять существующие». Неизменённые товары останутся как есть.</p>
    <p class="hint">Строки без ID в этом же файле можно добавить как новые товары — выберите режим «Создавать новые и обновлять найденные».</p>
    <p class="hint">Колонка <code>image</code> — ссылка на главное фото; при импорте фото загружаются только новым товарам и товарам без фото.</p>
  </aside>
</div>
